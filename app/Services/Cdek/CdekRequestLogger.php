<?php

namespace App\Services\Cdek;

/**
 * Техническое логирование CDEK HTTP без PII/секретов.
 * Пишет в storage/logs/cdek-http.log (создаёт каталог при необходимости).
 */
final class CdekRequestLogger
{
    private string $path;
    private bool $enabled;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];
        $this->enabled = (int) ($config['http_log_enabled'] ?? 1) === 1;
        $custom = trim((string) ($config['http_log_path'] ?? ''));
        $this->path = $custom !== ''
            ? $custom
            : dirname(__DIR__, 3) . '/storage/logs/cdek-http.log';
    }

    /**
     * @param array{
     *   method: string,
     *   path: string,
     *   http_status: int,
     *   duration_ms: int,
     *   request_id: string,
     *   internal_status?: string|null,
     *   cdek_uuid?: string|null,
     *   error_type?: string|null,
     *   retry?: int|null
     * } $entry
     */
    public function log(array $entry): void
    {
        if (!$this->enabled) {
            return;
        }

        $line = json_encode([
            'ts' => date('c'),
            'method' => strtoupper((string) ($entry['method'] ?? '')),
            'path' => $this->sanitizePath((string) ($entry['path'] ?? '')),
            'http_status' => (int) ($entry['http_status'] ?? 0),
            'duration_ms' => (int) ($entry['duration_ms'] ?? 0),
            'request_id' => (string) ($entry['request_id'] ?? ''),
            'internal_status' => $entry['internal_status'] ?? null,
            'cdek_uuid' => $entry['cdek_uuid'] ?? null,
            'error_type' => $entry['error_type'] ?? null,
            'retry' => $entry['retry'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($line)) {
            return;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function sanitizePath(string $path): string
    {
        // Убираем query (могут быть токены/PII) — оставляем только path.
        $path = explode('?', $path, 2)[0];
        return mb_substr($path, 0, 200);
    }
}
