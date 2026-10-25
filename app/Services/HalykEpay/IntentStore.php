<?php

declare(strict_types=1);

namespace App\Services\HalykEpay;

/**
 * Файловое хранилище интентов (нужно для postLink без сессии).
 */
final class IntentStore
{
    private static function dir(): string
    {
        $dir = dirname(__DIR__, 3) . '/storage/halyk_intents';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * @param array<string, mixed> $intent
     */
    public static function put(string $token, array $intent): void
    {
        $intent['created_at'] = time();
        $intent['token'] = $token;
        $path = self::dir() . '/' . self::safe($token) . '.json';
        file_put_contents($path, json_encode($intent, JSON_UNESCAPED_UNICODE));

        $invoice = (string) ($intent['invoice_id'] ?? '');
        if ($invoice !== '') {
            file_put_contents(
                self::dir() . '/inv_' . self::safe($invoice) . '.json',
                json_encode(['token' => $token], JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $token): ?array
    {
        $path = self::dir() . '/' . self::safe($token) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $created = (int) ($data['created_at'] ?? 0);
        if ($created > 0 && (time() - $created) > 86400) {
            self::forget($token);

            return null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getByInvoice(string $invoiceId): ?array
    {
        $path = self::dir() . '/inv_' . self::safe($invoiceId) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($meta) || empty($meta['token'])) {
            return null;
        }

        return self::get((string) $meta['token']);
    }

    public static function forget(string $token): void
    {
        $intent = self::get($token);
        $path = self::dir() . '/' . self::safe($token) . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
        $invoice = (string) ($intent['invoice_id'] ?? '');
        if ($invoice !== '') {
            $invPath = self::dir() . '/inv_' . self::safe($invoice) . '.json';
            if (is_file($invPath)) {
                @unlink($invPath);
            }
        }
    }

    private static function safe(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $key) ?: 'x';
    }
}
