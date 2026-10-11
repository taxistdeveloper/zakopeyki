<?php

namespace App\Services\Cdek;

/**
 * Файловый cache справочников CDEK.
 * Ошибки API не кэшируются. Stampede — flock на время miss.
 */
class CdekDirectoryCache
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0750, true);
        }
    }

    /**
     * @param callable(): array{ok: bool, payload?: mixed, error_code?: string} $loader
     * @return array{ok: bool, payload?: mixed, hit?: bool, stale?: bool, error_code?: string}
     */
    public function remember(string $key, int $ttlSeconds, callable $loader, bool $allowStale = false): array
    {
        $path = $this->path($key);
        $fresh = $this->read($path, $ttlSeconds);
        if ($fresh !== null) {
            return ['ok' => true, 'payload' => $fresh, 'hit' => true, 'stale' => false];
        }

        $lockPath = $path . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            $loaded = $loader();
            return $this->afterLoad($path, $ttlSeconds, $loaded, $allowStale);
        }

        try {
            flock($lock, LOCK_EX);
            $fresh = $this->read($path, $ttlSeconds);
            if ($fresh !== null) {
                return ['ok' => true, 'payload' => $fresh, 'hit' => true, 'stale' => false];
            }
            $loaded = $loader();
            return $this->afterLoad($path, $ttlSeconds, $loaded, $allowStale);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @param array{ok: bool, payload?: mixed, error_code?: string} $loaded */
    private function afterLoad(string $path, int $ttlSeconds, array $loaded, bool $allowStale): array
    {
        if ($loaded['ok'] ?? false) {
            $this->write($path, $loaded['payload']);
            return ['ok' => true, 'payload' => $loaded['payload'], 'hit' => false, 'stale' => false];
        }
        if ($allowStale) {
            $stale = $this->read($path, PHP_INT_MAX);
            if ($stale !== null) {
                return ['ok' => true, 'payload' => $stale, 'hit' => true, 'stale' => true, 'error_code' => $loaded['error_code'] ?? null];
            }
        }
        return ['ok' => false, 'hit' => false, 'stale' => false, 'error_code' => $loaded['error_code'] ?? 'cdek_error'];
    }

    private function path(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    private function read(string $path, int $ttlSeconds): mixed
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['stored_at'], $decoded['payload'])) {
            return null;
        }
        if ($ttlSeconds !== PHP_INT_MAX && (time() - (int) $decoded['stored_at']) > $ttlSeconds) {
            return null;
        }
        return $decoded['payload'];
    }

    private function write(string $path, mixed $payload): void
    {
        $json = json_encode(['stored_at' => time(), 'payload' => $payload], JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json, LOCK_EX);
    }
}
