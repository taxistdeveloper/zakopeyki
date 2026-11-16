<?php

declare(strict_types=1);

namespace App\Services\TryOn;

use App\Helpers\ProductHelper;
use App\Models\Product;
use App\Models\TryOnAsset;
use App\Models\TryOnJob;
use App\Services\TryOn\Providers\TryOnProviderRouter;

/**
 * Бизнес-логика примерки для покупателя: создание, чтение, удаление заданий, возможности.
 * Платные действия здесь не выполняются — отправку провайдеру делает TryOnJobRunner (воркер).
 */
final class TryOnService
{
    private const IDEMPOTENCY_RE = '/^[A-Za-z0-9_-]{8,64}$/';

    private TryOnSettings $settings;
    private TryOnJob $jobs;
    private TryOnAsset $assets;
    private TryOnStorage $storage;
    private TryOnImageValidator $validator;
    private TryOnProviderRouter $router;
    private TryOnUsageService $usage;
    /** @var callable */
    private $clock;
    /** @var callable fn(int $productId): ?array */
    private $productLoader;
    private string $productsDir;

    public function __construct(
        ?TryOnSettings $settings = null,
        ?TryOnProviderRouter $router = null,
        ?TryOnStorage $storage = null,
        ?callable $clock = null,
        ?callable $productLoader = null,
        ?string $productsDir = null,
    ) {
        $this->settings = $settings ?? new TryOnSettings();
        $this->jobs = new TryOnJob();
        $this->assets = new TryOnAsset();
        $this->storage = $storage ?? new TryOnStorage($this->settings->storageDir());
        $this->validator = new TryOnImageValidator((array) ($this->settings->config()['upload'] ?? []));
        $this->router = $router ?? new TryOnProviderRouter($this->settings);
        $this->clock = $clock ?? static fn (): int => time();
        $this->usage = new TryOnUsageService($this->settings, $this->jobs, $this->clock);
        $this->productLoader = $productLoader ?? static fn (int $id): ?array => (new Product())->find($id);
        $this->productsDir = $productsDir ?? dirname(__DIR__, 3) . '/public/uploads/products';
    }

    public function settings(): TryOnSettings
    {
        return $this->settings;
    }

    /**
     * Создать задание. Порядок проверок — docs/tryon/02-api-contracts.md (без платных вызовов).
     *
     * @return array{job: array<string, mixed>, idempotent: bool, reused: bool}
     * @throws TryOnException
     */
    public function create(
        int $userId,
        int $productId,
        int $garmentIndex,
        bool $consent,
        string $consentVersion,
        string $idempotencyKey,
        string $photoPath,
        int $photoSize,
    ): array {
        if (!$this->settings->isEnabledFor($userId)) {
            throw new TryOnException('module_disabled', 403);
        }
        if (!preg_match(self::IDEMPOTENCY_RE, $idempotencyKey)) {
            throw new TryOnException('idempotency_key_invalid', 422);
        }

        $existing = $this->jobs->findByIdempotency($userId, $idempotencyKey);
        if ($existing) {
            if ((int) $existing['product_id'] !== $productId || (int) $existing['garment_image_index'] !== $garmentIndex) {
                throw new TryOnException('idempotency_conflict', 409);
            }

            return ['job' => $existing, 'idempotent' => true, 'reused' => false];
        }

        [$product, $type, $garmentPath] = $this->resolveProduct($productId, $garmentIndex);
        $provider = $this->router->pick($type);
        if ($provider === null) {
            throw new TryOnException('provider_unavailable', 503);
        }

        if (!$consent) {
            throw new TryOnException('consent_required', 422);
        }
        if ($consentVersion !== $this->settings->consentVersion()) {
            throw new TryOnException('consent_outdated', 422, ['consent_version' => $this->settings->consentVersion()]);
        }

        $img = $this->validator->processUpload($photoPath, $photoSize);

        $reusable = $this->jobs->findReusable($userId, $productId, $garmentIndex, $img['sha256']);
        if ($reusable) {
            return ['job' => $reusable, 'idempotent' => true, 'reused' => true];
        }

        // Быстрая проверка до записи файла; повторная — под блокировкой (гонки параллельных запросов).
        $this->usage->assertUserCanCreate($userId);

        $estimate = $provider->estimateCostMicros($type);
        $isTest = $provider->isTest() ? 1 : 0;
        $rel = $this->storage->put($img['bytes']);
        try {
            $job = $this->usage->withBudgetReservation($estimate, function () use (
                $userId, $productId, $garmentIndex, $type, $idempotencyKey, $estimate, $isTest, $rel, $img
            ): array {
                $this->usage->assertUserCanCreate($userId);
                $now = $this->now();
                try {
                    $id = $this->jobs->create([
                        'public_id' => self::newPublicId(),
                        'user_id' => $userId,
                        'product_id' => $productId,
                        'tryon_type' => $type,
                        'garment_image_index' => $garmentIndex,
                        'idempotency_key' => $idempotencyKey,
                        'status' => TryOnJobStatus::QUEUED,
                        'cost_estimate_micros' => $estimate,
                        'is_test' => $isTest,
                        'consent_version' => $this->settings->consentVersion(),
                        'consent_at' => $now,
                        'created_at' => $now,
                    ]);
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) === 1062) {
                        throw new TryOnException('idempotency_race', 409);
                    }
                    throw $e;
                }
                $this->assets->create([
                    'job_id' => $id,
                    'owner_user_id' => $userId,
                    'kind' => TryOnAsset::KIND_PERSON,
                    'storage_path' => $rel,
                    'bytes' => strlen($img['bytes']),
                    'width' => $img['width'],
                    'height' => $img['height'],
                    'sha256' => $img['sha256'],
                    'created_at' => $now,
                    'expires_at' => $this->dateAfterHours($this->settings->retentionHours()),
                ]);

                return (array) $this->jobs->find($id);
            });
        } catch (TryOnException $e) {
            $this->storage->delete($rel);
            if ($e->errorCode === 'idempotency_race') {
                $again = $this->jobs->findByIdempotency($userId, $idempotencyKey);
                if ($again) {
                    return ['job' => $again, 'idempotent' => true, 'reused' => false];
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->storage->delete($rel);
            throw $e;
        }

        return ['job' => $job, 'idempotent' => false, 'reused' => false];
    }

    /** @throws TryOnException not_found */
    public function getOwned(int $userId, string $publicId): array
    {
        $job = preg_match('/^[0-9A-Za-z]{26}$/', $publicId) ? $this->jobs->findByPublicId($publicId) : null;
        if (!$job || (int) $job['user_id'] !== $userId) {
            throw new TryOnException('not_found', 404);
        }

        return $job;
    }

    /** @return list<array<string, mixed>> */
    public function listOwned(int $userId, ?int $productId, int $limit = 10): array
    {
        return $this->jobs->listForUser($userId, $productId, $limit);
    }

    /**
     * Байты изображения задания для владельца.
     * @throws TryOnException not_found | not_ready | gone
     */
    public function imageFor(int $userId, string $publicId, string $kind): string
    {
        $job = $this->getOwned($userId, $publicId);
        if ($job['deleted_at'] !== null || $job['status'] === TryOnJobStatus::EXPIRED || $job['status'] === TryOnJobStatus::CANCELLED) {
            throw new TryOnException('gone', 410);
        }
        if ($kind === TryOnAsset::KIND_RESULT && $job['status'] !== TryOnJobStatus::SUCCEEDED) {
            throw new TryOnException('not_ready', 409);
        }
        $asset = $this->assets->findForJob((int) $job['id'], $kind);
        $bytes = ($asset && $asset['storage_path']) ? $this->storage->read((string) $asset['storage_path']) : null;
        if ($bytes === null) {
            throw new TryOnException('gone', 410);
        }

        return $bytes;
    }

    /** Удаление пользователем: файлы сразу, статус cancelled/expired, данные у провайдера — по возможности. */
    public function delete(int $userId, string $publicId): void
    {
        $job = $this->getOwned($userId, $publicId);
        $now = $this->now();
        $this->deleteAssets((int) $job['id'], $now);

        $status = (string) $job['status'];
        if (TryOnJobStatus::isActive($status)) {
            $this->jobs->transition((int) $job['id'], [$status], TryOnJobStatus::CANCELLED, ['deleted_at' => $now, 'finished_at' => $now]);
            if ($status === TryOnJobStatus::PROCESSING && $job['provider'] && $job['external_id']) {
                $this->router->get((string) $job['provider'])?->cancel((string) $job['external_id']);
            }
        } elseif (in_array($status, [TryOnJobStatus::SUCCEEDED, TryOnJobStatus::FAILED], true)) {
            $this->jobs->transition((int) $job['id'], [$status], TryOnJobStatus::EXPIRED, ['deleted_at' => $now]);
        } elseif ($job['deleted_at'] === null) {
            $this->jobs->updateFields((int) $job['id'], ['deleted_at' => $now]);
        }

        $this->purgeAtProvidersIfIdle($userId);
    }

    /** Полное удаление данных пользователя (удаление аккаунта). */
    public function purgeUser(int $userId): void
    {
        $now = $this->now();
        foreach ($this->jobs->listAllForUser($userId) as $job) {
            $this->deleteAssets((int) $job['id'], $now);
            $status = (string) $job['status'];
            $to = TryOnJobStatus::isActive($status) ? TryOnJobStatus::CANCELLED
                : (in_array($status, [TryOnJobStatus::SUCCEEDED, TryOnJobStatus::FAILED], true) ? TryOnJobStatus::EXPIRED : null);
            if ($to !== null) {
                $this->jobs->transition((int) $job['id'], [$status], $to, ['deleted_at' => $now]);
            }
        }
        $this->purgeAtProviders($userId);
    }

    /** @return array<string, mixed> */
    public function capabilities(?int $userId, ?int $productId): array
    {
        $enabled = $this->settings->isEnabledFor($userId);
        $upload = (array) ($this->settings->config()['upload'] ?? []);
        $out = [
            'enabled' => $enabled,
            'types' => $enabled ? array_values(array_filter(
                $this->settings->enabledTypes(),
                fn (string $t): bool => $this->router->pick($t) !== null
            )) : [],
            'product' => null,
            'limits' => ($enabled && $userId) ? $this->usage->remaining($userId) : null,
            'upload' => [
                'max_bytes' => (int) ($upload['max_bytes'] ?? 10485760),
                'formats' => ['image/jpeg', 'image/png', 'image/webp'],
                'min_side_px' => (int) ($upload['min_side_px'] ?? 512),
                'max_side_px' => (int) ($upload['max_side_px'] ?? 6000),
            ],
            'consent' => ['version' => $this->settings->consentVersion(), 'text_key' => 'tryon.consent_text'],
            'retention_hours' => $this->settings->retentionHours(),
        ];
        if ($productId !== null) {
            $out['product'] = $this->productCapability($productId, $enabled);
        }

        return $out;
    }

    /** Публичное представление задания для API. */
    public static function present(array $job): array
    {
        $status = (string) $job['status'];

        return [
            'id' => (string) $job['public_id'],
            'status' => $status,
            'product_id' => $job['product_id'] !== null ? (int) $job['product_id'] : null,
            'tryon_type' => (string) $job['tryon_type'],
            'garment_image_index' => (int) $job['garment_image_index'],
            'created_at' => self::iso($job['created_at']),
            'finished_at' => self::iso($job['finished_at']),
            'expires_at' => self::iso($job['expires_at']),
            'error_code' => $job['error_code'] !== null ? (string) $job['error_code'] : null,
            'result_available' => $status === TryOnJobStatus::SUCCEEDED && $job['deleted_at'] === null,
            'is_test' => (bool) (int) $job['is_test'],
            'poll_after_ms' => TryOnJobStatus::isActive($status) ? 3000 : null,
        ];
    }

    /** @return list<string> имена файлов галереи товара */
    public static function garmentFiles(array $product): array
    {
        return ProductHelper::decodeImages($product);
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string} [product, type, garmentPath]
     * @throws TryOnException
     */
    private function resolveProduct(int $productId, int $garmentIndex): array
    {
        $product = $productId > 0 ? ($this->productLoader)($productId) : null;
        if (!$product || ($product['status'] ?? '') !== 'active') {
            throw new TryOnException('not_found', 404);
        }
        $type = (string) ($product['tryon_type'] ?? '');
        if (!TryOnTypes::isValid($type) || !in_array($type, $this->settings->enabledTypes(), true)) {
            throw new TryOnException('product_not_supported', 422);
        }
        $files = self::garmentFiles($product);
        $file = $files[$garmentIndex] ?? null;
        $path = $file !== null ? $this->productsDir . '/' . basename($file) : null;
        if ($path === null || !is_file($path)) {
            throw new TryOnException('garment_image_missing', 422);
        }

        return [$product, $type, $path];
    }

    /** @return array<string, mixed> */
    private function productCapability(int $productId, bool $enabled): array
    {
        $product = $productId > 0 ? ($this->productLoader)($productId) : null;
        if (!$product || ($product['status'] ?? '') !== 'active') {
            return ['id' => $productId, 'tryon_type' => null, 'supported' => false, 'garment_images' => []];
        }
        $type = (string) ($product['tryon_type'] ?? '');
        $supported = $enabled && TryOnTypes::isValid($type)
            && in_array($type, $this->settings->enabledTypes(), true)
            && $this->router->pick($type) !== null;
        $images = [];
        if ($supported) {
            foreach (self::garmentFiles($product) as $i => $f) {
                $images[] = ['index' => $i, 'url' => ProductHelper::url('public/uploads/products/' . basename($f))];
            }
        }

        return [
            'id' => (int) $product['id'],
            'tryon_type' => $type !== '' ? $type : null,
            'supported' => $supported && $images !== [],
            'garment_images' => $images,
        ];
    }

    private function deleteAssets(int $jobId, string $now): void
    {
        foreach ($this->assets->listForJob($jobId) as $a) {
            if ($a['deleted_at'] === null) {
                $this->storage->delete($a['storage_path'] !== null ? (string) $a['storage_path'] : null);
                $this->assets->markDeleted((int) $a['id'], $now);
            }
        }
    }

    private function purgeAtProvidersIfIdle(int $userId): void
    {
        foreach ($this->jobs->listForUser($userId, null, 50) as $j) {
            if ($j['deleted_at'] === null) {
                return; // у пользователя остались видимые примерки — их данные у провайдера удалятся по сроку
            }
        }
        $this->purgeAtProviders($userId);
    }

    private function purgeAtProviders(int $userId): void
    {
        try {
            $ext = $this->settings->externalUserId($userId);
        } catch (\Throwable) {
            return;
        }
        foreach ($this->router->all() as $p) {
            try {
                if ($p->isConfigured()) {
                    $p->purgeUser($ext);
                }
            } catch (\Throwable) {
                // не мешаем удалению у нас; у провайдера данные истекут по keepForDays
            }
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }

    private function dateAfterHours(int $hours): string
    {
        return date('Y-m-d H:i:s', ($this->clock)() + $hours * 3600);
    }

    private static function iso(mixed $dt): ?string
    {
        if ($dt === null || $dt === '') {
            return null;
        }
        $ts = strtotime((string) $dt);

        return $ts ? date('c', $ts) : null;
    }

    /** 26 символов [0-9A-Za-z], ~154 бита случайности. */
    public static function newPublicId(): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $out = '';
        $bytes = random_bytes(26);
        for ($i = 0; $i < 26; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % 62];
        }

        return $out;
    }
}
