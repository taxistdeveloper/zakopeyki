<?php

declare(strict_types=1);

namespace App\Services\TryOn;

use App\Models\Product;
use App\Models\TryOnAsset;
use App\Models\TryOnJob;
use App\Services\TryOn\Providers\GenlookProvider;
use App\Services\TryOn\Providers\ProviderException;
use App\Services\TryOn\Providers\ProviderJobState;
use App\Services\TryOn\Providers\TryOnProviderInterface;
use App\Services\TryOn\Providers\TryOnProviderRouter;
use App\Services\TryOn\Providers\TryOnSubmission;

/**
 * Один цикл воркера: таймауты → отправка queued → опрос processing → очистка по сроку.
 * docs/tryon/01-architecture.md §4. Fallback на резервного провайдера — только до принятия задания.
 */
final class TryOnJobRunner
{
    private const POLL_BACKOFF = [3, 3, 5, 5, 10];
    private const MAX_FETCH_ATTEMPTS = 5;

    private TryOnJob $jobs;
    private TryOnAsset $assets;
    private TryOnImageValidator $validator;
    /** @var callable */
    private $clock;
    /** @var callable fn(int $productId): ?array */
    private $productLoader;
    /** @var array<string, int> */
    private array $stats = [];
    /** @var callable|null fn(string $line): void */
    private $logger;

    public function __construct(
        private readonly TryOnSettings $settings,
        private readonly TryOnProviderRouter $router,
        private readonly TryOnStorage $storage,
        private readonly TryOnResultFetcher $fetcher,
        ?callable $clock = null,
        ?callable $productLoader = null,
        private readonly ?string $productsDir = null,
        ?callable $logger = null,
    ) {
        $this->jobs = new TryOnJob();
        $this->assets = new TryOnAsset();
        $this->validator = new TryOnImageValidator((array) ($settings->config()['upload'] ?? []));
        $this->clock = $clock ?? static fn (): int => time();
        $this->productLoader = $productLoader ?? static fn (int $id): ?array => (new Product())->find($id);
        $this->logger = $logger;
    }

    /** @return array<string, int> счётчики цикла */
    public function runCycle(string $workerId, bool $cleanup = false): array
    {
        $this->stats = ['timeouts' => 0, 'submitted' => 0, 'polled' => 0, 'succeeded' => 0, 'failed' => 0, 'cleaned' => 0];
        $cfg = (array) ($this->settings->config()['worker'] ?? []);
        $lockTtl = (int) ($cfg['lock_ttl_sec'] ?? 60);

        $this->handleTimeouts($cfg);

        $free = max(0, $this->settings->int('tryon_max_concurrent') - $this->jobs->countByStatus(TryOnJobStatus::PROCESSING));
        foreach ($this->jobs->claim(TryOnJobStatus::QUEUED, $free, $workerId, $lockTtl, $this->now()) as $job) {
            $this->submitJob($job, (int) ($cfg['submit_max_attempts'] ?? 3));
        }

        foreach ($this->jobs->claim(TryOnJobStatus::PROCESSING, 20, $workerId, $lockTtl, $this->now()) as $job) {
            $this->pollJob($job);
        }

        if ($cleanup) {
            $this->cleanup();
        }

        return $this->stats;
    }

    /** @param array<string, mixed> $cfg */
    private function handleTimeouts(array $cfg): void
    {
        $now = ($this->clock)();
        $queuedBefore = date('Y-m-d H:i:s', $now - (int) ($cfg['queue_timeout_sec'] ?? 600));
        foreach ($this->jobs->listStale(TryOnJobStatus::QUEUED, $queuedBefore, 'created_at') as $job) {
            $this->fail($job, [TryOnJobStatus::QUEUED], 'queue_timeout', 0);
            $this->stats['timeouts']++;
        }
        $procBefore = date('Y-m-d H:i:s', $now - (int) ($cfg['processing_timeout_sec'] ?? 300));
        foreach ($this->jobs->listStale(TryOnJobStatus::PROCESSING, $procBefore, 'submitted_at') as $job) {
            // Расход неизвестен — учитываем оценку (консервативно для бюджета).
            $this->fail($job, [TryOnJobStatus::PROCESSING], 'provider_timeout', (int) $job['cost_estimate_micros']);
            $this->stats['timeouts']++;
        }
    }

    private function submitJob(array $job, int $maxAttempts): void
    {
        $id = (int) $job['id'];
        $type = (string) $job['tryon_type'];
        try {
            $submission = $this->buildSubmission($job);
        } catch (\RuntimeException $e) {
            $this->log('config error: ' . $e->getMessage());
            $this->fail($job, [TryOnJobStatus::QUEUED], 'config_error', 0);
            return;
        }
        if ($submission === null) {
            $this->fail($job, [TryOnJobStatus::QUEUED], 'garment_image_missing', 0);
            return;
        }

        $exclude = [];
        // Предпочитаем провайдера, на котором уже были попытки (бюджет считался по нему).
        $provider = ($job['provider'] ? $this->router->get((string) $job['provider']) : null) ?? $this->router->pick($type);
        while ($provider !== null) {
            try {
                $state = $provider->submit($submission);
                $ok = $this->jobs->transition($id, [TryOnJobStatus::QUEUED], TryOnJobStatus::PROCESSING, [
                    'provider' => $provider->id(),
                    'external_id' => $state->externalId,
                    'is_test' => $provider->isTest() ? 1 : 0,
                    'cost_estimate_micros' => $provider->estimateCostMicros($type),
                    'attempts' => (int) $job['attempts'] + 1,
                    'submitted_at' => $this->now(),
                    'next_poll_at' => $this->after(self::POLL_BACKOFF[0]),
                    'locked_until' => null,
                    'locked_by' => null,
                ]);
                if (!$ok) {
                    // Пользователь отменил задание, пока шла отправка.
                    $provider->cancel($state->externalId);
                }
                $this->stats['submitted']++;
                $this->log('submit ok ' . $job['public_id'] . ' via ' . $provider->id());
                return;
            } catch (ProviderException $e) {
                $attempts = (int) $job['attempts'] + 1;
                $this->log('submit error ' . $job['public_id'] . ' ' . $provider->id() . ' ' . $e->getMessage());
                if ($e->retryable && $attempts < $maxAttempts) {
                    $this->jobs->updateFields($id, [
                        'attempts' => $attempts,
                        'provider' => $provider->id(),
                        'next_poll_at' => $this->after(5 * $attempts),
                        'locked_until' => null,
                        'locked_by' => null,
                    ]);
                    return;
                }
                // Провайдер не принял задание → можно безопасно попробовать резервного.
                $exclude[] = $provider->id();
                $provider = $this->router->pick($type, $exclude);
                $job['attempts'] = 0;
                if ($provider === null) {
                    $code = in_array($e->errorCode, ['provider_rejected_image'], true) ? $e->errorCode : 'provider_unavailable';
                    $this->fail($job, [TryOnJobStatus::QUEUED], $code, 0);
                    return;
                }
            }
        }

        $this->fail($job, [TryOnJobStatus::QUEUED], 'provider_unavailable', 0);
    }

    private function pollJob(array $job): void
    {
        $this->stats['polled']++;
        $provider = $this->router->get((string) $job['provider']);
        if ($provider === null || !$job['external_id']) {
            $this->fail($job, [TryOnJobStatus::PROCESSING], 'provider_error', (int) $job['cost_estimate_micros']);
            return;
        }

        try {
            $state = $provider->status((string) $job['external_id']);
        } catch (ProviderException $e) {
            if ($e->retryable) {
                $this->reschedule($job);
            } else {
                $this->fail($job, [TryOnJobStatus::PROCESSING], 'provider_error', (int) $job['cost_estimate_micros']);
            }
            return;
        }

        match ($state->state) {
            ProviderJobState::COMPLETED => $this->complete($job, $provider, $state),
            ProviderJobState::FAILED => $this->fail($job, [TryOnJobStatus::PROCESSING], $state->errorCode ?? 'generation_failed', $state->costMicros ?? 0),
            default => $this->reschedule($job),
        };
    }

    private function complete(array $job, TryOnProviderInterface $provider, ProviderJobState $state): void
    {
        try {
            $raw = $state->resultBytes;
            if ($raw === null) {
                if ($state->resultUrl === null) {
                    throw new TryOnException('result_fetch_failed', 502);
                }
                $hosts = $provider instanceof GenlookProvider ? $provider->resultHosts() : [];
                $raw = $this->fetcher->fetch($state->resultUrl, $hosts);
            }
            $img = $this->validator->processBytes($raw);
        } catch (TryOnException $e) {
            $attempts = (int) $job['attempts'] + 1;
            if ($attempts >= self::MAX_FETCH_ATTEMPTS) {
                $this->fail($job, [TryOnJobStatus::PROCESSING], 'result_fetch_failed', (int) $job['cost_estimate_micros']);
            } else {
                $this->jobs->updateFields((int) $job['id'], [
                    'attempts' => $attempts,
                    'next_poll_at' => $this->after(5),
                    'locked_until' => null,
                    'locked_by' => null,
                ]);
            }
            $this->log('fetch error ' . $job['public_id'] . ' ' . json_encode($e->extra));
            return;
        }

        if (!$provider->isTest()) {
            $person = $this->assets->findForJob((int) $job['id'], TryOnAsset::KIND_PERSON);
            $personBytes = ($person && $person['storage_path']) ? $this->storage->read((string) $person['storage_path']) : null;
            if ($personBytes !== null && TryOnImageValidator::looksUnchanged($personBytes, $img['bytes'])) {
                // Провайдер вернул исходное фото без товара — это не успешная примерка (Genlook так не списывает кредит).
                $this->fail($job, [TryOnJobStatus::PROCESSING], 'product_not_supported', 0);
                return;
            }
        }

        $rel = $this->storage->put($img['bytes']);
        $now = $this->now();
        $expires = $this->after($this->settings->retentionHours() * 3600);
        $ok = $this->jobs->transition((int) $job['id'], [TryOnJobStatus::PROCESSING], TryOnJobStatus::SUCCEEDED, [
            'cost_actual_micros' => $state->costMicros ?? (int) $job['cost_estimate_micros'],
            'finished_at' => $now,
            'expires_at' => $expires,
            'next_poll_at' => null,
            'locked_until' => null,
            'locked_by' => null,
        ]);
        if (!$ok) {
            $this->storage->delete($rel); // отменено пользователем
            return;
        }
        $this->assets->create([
            'job_id' => (int) $job['id'],
            'owner_user_id' => (int) $job['user_id'],
            'kind' => TryOnAsset::KIND_RESULT,
            'storage_path' => $rel,
            'bytes' => strlen($img['bytes']),
            'width' => $img['width'],
            'height' => $img['height'],
            'sha256' => $img['sha256'],
            'created_at' => $now,
            'expires_at' => $expires,
        ]);
        $this->assets->setExpiry((int) $job['id'], $expires);
        $this->stats['succeeded']++;
        $this->log('succeeded ' . $job['public_id']);
    }

    /** @param list<string> $from */
    private function fail(array $job, array $from, string $code, int $costMicros): void
    {
        $expires = $this->after($this->settings->retentionHours() * 3600);
        $ok = $this->jobs->transition((int) $job['id'], $from, TryOnJobStatus::FAILED, [
            'error_code' => $code,
            'cost_actual_micros' => $costMicros,
            'finished_at' => $this->now(),
            'expires_at' => $expires,
            'next_poll_at' => null,
            'locked_until' => null,
            'locked_by' => null,
        ]);
        if ($ok) {
            $this->assets->setExpiry((int) $job['id'], $expires);
            $this->stats['failed']++;
            $this->log('failed ' . $job['public_id'] . ' ' . $code);
        }
    }

    private function reschedule(array $job): void
    {
        $elapsed = ($this->clock)() - (int) strtotime((string) ($job['submitted_at'] ?? 'now'));
        $step = self::POLL_BACKOFF[min(count(self::POLL_BACKOFF) - 1, intdiv(max(0, $elapsed), 10))];
        $this->jobs->updateFields((int) $job['id'], [
            'next_poll_at' => $this->after($step),
            'locked_until' => null,
            'locked_by' => null,
        ]);
    }

    private function cleanup(): void
    {
        $now = $this->now();
        foreach ($this->jobs->listExpired($now) as $job) {
            foreach ($this->assets->listForJob((int) $job['id']) as $a) {
                if ($a['deleted_at'] === null) {
                    $this->storage->delete($a['storage_path'] !== null ? (string) $a['storage_path'] : null);
                    $this->assets->markDeleted((int) $a['id'], $now);
                }
            }
            $this->jobs->transition((int) $job['id'], [(string) $job['status']], TryOnJobStatus::EXPIRED, []);
            $this->stats['cleaned']++;
        }
        // Файлы отменённых/зависших заданий без перехода статуса.
        foreach ($this->assets->listExpired($now) as $a) {
            $this->storage->delete($a['storage_path'] !== null ? (string) $a['storage_path'] : null);
            $this->assets->markDeleted((int) $a['id'], $now);
            $this->stats['cleaned']++;
        }
    }

    private function buildSubmission(array $job): ?TryOnSubmission
    {
        $product = $job['product_id'] !== null ? ($this->productLoader)((int) $job['product_id']) : null;
        if (!$product) {
            return null;
        }
        $files = TryOnService::garmentFiles($product);
        $file = $files[(int) $job['garment_image_index']] ?? null;
        $dir = $this->productsDir ?? dirname(__DIR__, 3) . '/public/uploads/products';
        $garment = $file !== null ? $dir . '/' . basename($file) : null;
        $person = $this->assets->findForJob((int) $job['id'], TryOnAsset::KIND_PERSON);
        $personPath = ($person && $person['storage_path']) ? $this->storage->path((string) $person['storage_path']) : null;
        if ($garment === null || !is_file($garment) || $personPath === null) {
            return null;
        }

        return new TryOnSubmission(
            (string) $job['public_id'],
            (string) $job['tryon_type'],
            $personPath,
            $garment,
            (string) ($product['title'] ?? ''),
            $this->settings->externalUserId((int) $job['user_id']),
        );
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    private function after(int $seconds): string
    {
        return date('Y-m-d H:i:s', ($this->clock)() + $seconds);
    }

    private function log(string $line): void
    {
        if ($this->logger !== null) {
            ($this->logger)($line);
        }
    }
}
