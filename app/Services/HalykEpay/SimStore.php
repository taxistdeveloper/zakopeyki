<?php

declare(strict_types=1);

namespace App\Services\HalykEpay;

/**
 * Сессионное хранилище интентов Halyk ePay + индекс invoiceId → token.
 */
final class SimStore
{
    private const KEY = 'halyk_epay_sim_intents';
    private const INDEX = 'halyk_epay_invoice_index';

    /**
     * @param array<string, mixed> $intent
     */
    public static function put(string $token, array $intent): void
    {
        self::boot();
        $all = $_SESSION[self::KEY] ?? [];
        if (!is_array($all)) {
            $all = [];
        }
        $intent['created_at'] = time();
        $all[$token] = $intent;
        $_SESSION[self::KEY] = $all;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $token): ?array
    {
        self::boot();
        $all = $_SESSION[self::KEY] ?? [];
        if (!is_array($all) || !isset($all[$token]) || !is_array($all[$token])) {
            return null;
        }
        $intent = $all[$token];
        $created = (int) ($intent['created_at'] ?? 0);
        if ($created > 0 && (time() - $created) > 3600) {
            self::forget($token);

            return null;
        }

        return $intent;
    }

    public static function forget(string $token): void
    {
        self::boot();
        $all = $_SESSION[self::KEY] ?? [];
        if (!is_array($all)) {
            return;
        }
        $invoice = (string) (($all[$token]['invoice_id'] ?? '') ?: '');
        unset($all[$token]);
        $_SESSION[self::KEY] = $all;
        if ($invoice !== '') {
            $idx = $_SESSION[self::INDEX] ?? [];
            if (is_array($idx)) {
                unset($idx[$invoice]);
                $_SESSION[self::INDEX] = $idx;
            }
        }
    }

    public static function indexInvoice(string $invoiceId, string $token): void
    {
        self::boot();
        $idx = $_SESSION[self::INDEX] ?? [];
        if (!is_array($idx)) {
            $idx = [];
        }
        $idx[$invoiceId] = $token;
        $_SESSION[self::INDEX] = $idx;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getByInvoice(string $invoiceId): ?array
    {
        self::boot();
        $idx = $_SESSION[self::INDEX] ?? [];
        if (!is_array($idx) || empty($idx[$invoiceId])) {
            return null;
        }

        return self::get((string) $idx[$invoiceId]);
    }

    private static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
