<?php

declare(strict_types=1);

namespace App\Services\TryOn\Providers;

/**
 * Тестовый провайдер без сети и оплаты. Результат — фото покупателя с плашкой «ТЕСТ — НЕ AI».
 * Никогда не выдаётся за AI: isTest() = true → tryon_jobs.is_test = 1.
 *
 * Сценарии: success | fail | retryable:N (N ошибок submit, затем успех) | slow:K (K опросов в processing) | never
 */
final class FakeTryOnProvider implements TryOnProviderInterface
{
    private int $submitFailuresLeft;
    private int $pollsBeforeDone;

    /** @param list<string> $types */
    public function __construct(
        private readonly string $scenario = 'success',
        private readonly array $types = ['upper', 'lower', 'dress'],
        private ?string $stateDir = null,
    ) {
        $this->stateDir ??= dirname(__DIR__, 4) . '/storage/cache/tryon_fake';
        $this->submitFailuresLeft = str_starts_with($scenario, 'retryable:') ? (int) substr($scenario, 10) : 0;
        $this->pollsBeforeDone = str_starts_with($scenario, 'slow:') ? (int) substr($scenario, 5) : 0;
    }

    public function id(): string
    {
        return 'fake';
    }

    public function isTest(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supports(string $tryonType): bool
    {
        return in_array($tryonType, $this->types, true);
    }

    public function estimateCostMicros(string $tryonType): int
    {
        return 0;
    }

    public function submit(TryOnSubmission $s): ProviderJobState
    {
        if ($this->submitFailuresLeft > 0) {
            $this->submitFailuresLeft--;
            throw new ProviderException('provider_error', true, 503);
        }
        $id = 'fake_' . bin2hex(random_bytes(8));
        $this->saveState($id, ['path' => $s->personJpegPath, 'polls' => 0]);

        return new ProviderJobState(ProviderJobState::PENDING, $id);
    }

    public function status(string $externalId): ProviderJobState
    {
        $job = $this->loadState($externalId);
        if ($job === null || !is_file((string) $job['path'])) {
            return new ProviderJobState(ProviderJobState::FAILED, $externalId, null, 'generation_failed', 0);
        }
        $job['polls']++;
        $this->saveState($externalId, $job);
        if ($this->scenario === 'never' || $job['polls'] <= $this->pollsBeforeDone) {
            return new ProviderJobState(ProviderJobState::PROCESSING, $externalId);
        }
        if ($this->scenario === 'fail') {
            return new ProviderJobState(ProviderJobState::FAILED, $externalId, null, 'generation_failed', 0);
        }

        return new ProviderJobState(
            ProviderJobState::COMPLETED,
            $externalId,
            null,
            null,
            0,
            self::stamp((string) file_get_contents($job['path']))
        );
    }

    public function purgeUser(string $externalUserId): bool
    {
        return true;
    }

    public function cancel(string $externalId): bool
    {
        $f = $this->stateFile($externalId);
        if ($f !== null && is_file($f)) {
            @unlink($f);
        }

        return true;
    }

    /** @param array{path:string,polls:int} $state */
    private function saveState(string $id, array $state): void
    {
        $f = $this->stateFile($id);
        if ($f === null) {
            return;
        }
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0700, true);
        }
        file_put_contents($f, json_encode($state), LOCK_EX);
    }

    /** @return array{path:string,polls:int}|null */
    private function loadState(string $id): ?array
    {
        $f = $this->stateFile($id);
        if ($f === null || !is_file($f)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($f), true);

        return is_array($j) ? ['path' => (string) ($j['path'] ?? ''), 'polls' => (int) ($j['polls'] ?? 0)] : null;
    }

    private function stateFile(string $id): ?string
    {
        return preg_match('/^fake_[a-f0-9]{16}$/', $id) ? rtrim((string) $this->stateDir, '/') . '/' . $id . '.json' : null;
    }

    /** Накладывает крупную плашку «ТЕСТ — НЕ AI» и рамку. */
    public static function stamp(string $jpeg): string
    {
        $img = @imagecreatefromstring($jpeg);
        if (!$img instanceof \GdImage) {
            return $jpeg;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $red = imagecolorallocate($img, 220, 20, 60);
        $white = imagecolorallocate($img, 255, 255, 255);
        $band = max(40, (int) ($h * 0.08));
        imagefilledrectangle($img, 0, (int) (($h - $band) / 2), $w, (int) (($h + $band) / 2), $red);
        for ($i = 0; $i < 6; $i++) {
            imagerectangle($img, $i, $i, $w - 1 - $i, $h - 1 - $i, $red);
        }
        $text = 'TEST - NOT AI / TEST - NE AI';
        $font = 5;
        $tw = imagefontwidth($font) * strlen($text);
        imagestring($img, $font, max(0, (int) (($w - $tw) / 2)), (int) (($h - imagefontheight($font)) / 2), $text, $white);
        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
