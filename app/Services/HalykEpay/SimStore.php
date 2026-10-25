<?php

declare(strict_types=1);

namespace App\Services\HalykEpay;

/**
 * Сессионное хранилище интентов имитации Halyk ePay (только локальная разработка).
 */
final class SimStore
{
    private const KEY = 'halyk_epay_sim_intents';

    /**
     * @param array{
     *   order_id: string,
     *   amount: int,
     *   description: string,
     *   currency?: string,
     *   param1?: string,
     *   success_url?: string,
     *   failure_url?: string
     * } $intent
     */
    public static function put(string $token, array $intent): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
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
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $all = $_SESSION[self::KEY] ?? [];
        if (!is_array($all) || !isset($all[$token]) || !is_array($all[$token])) {
            return null;
        }
        $intent = $all[$token];
        $created = (int) ($intent['created_at'] ?? 0);
        if ($created > 0 && (time() - $created) > 3600) {
            unset($all[$token]);
            $_SESSION[self::KEY] = $all;

            return null;
        }

        return $intent;
    }

    public static function forget(string $token): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $all = $_SESSION[self::KEY] ?? [];
        if (!is_array($all)) {
            return;
        }
        unset($all[$token]);
        $_SESSION[self::KEY] = $all;
    }
}
