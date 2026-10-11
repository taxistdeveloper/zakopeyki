<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;

/**
 * Чистые решения tracking: порядок событий, backoff, разбор GET /v2/orders/{uuid}.
 * Не вызывает CDEK и не создаёт заказ.
 */
final class CdekTrackingPolicy
{
    public const MIN_OPEN_SECONDS = 600;
    public const EXCEPTION_SECONDS = 1800;
    public const RATE_LIMIT_BASE_SECONDS = 1800;
    public const TRANSIENT_BASE_SECONDS = 900;
    public const AUTH_SECONDS = 3600;
    public const NOT_FOUND_SECONDS = 3600;
    public const MAX_FAST_ATTEMPTS = 8;
    public const SLOW_SECONDS = 21600;
    public const CLAIM_LEASE_SECONDS = 120;

    public static function normalizeEventAt(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Входящее событие старше уже сохранённого — статус не перезаписываем.
     */
    public static function isStaleEvent(?string $incoming, ?string $stored): bool
    {
        $in = self::normalizeEventAt($incoming);
        $prev = self::normalizeEventAt($stored);
        if ($in === null || $prev === null) {
            return false;
        }
        return strtotime($in) < strtotime($prev);
    }

    public static function plannedDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public static function classifyFailure(int $http, ?string $errorType, ?string $error): string
    {
        $type = strtolower((string) $errorType);
        $err = strtolower((string) $error);
        if ($http === 401 || $http === 403 || str_contains($type, 'auth')) {
            return 'auth';
        }
        if ($http === 404 || str_contains($type, 'not_found')) {
            return 'not_found';
        }
        if ($http === 429 || str_contains($type, 'rate')) {
            return 'rate_limit';
        }
        if ($http >= 500) {
            return 'unavailable';
        }
        if (str_contains($type, 'timeout') || str_contains($err, 'timeout') || str_contains($err, 'timed out')) {
            return 'timeout';
        }
        if ($http === 0 || str_contains($type, 'network')) {
            return 'network';
        }
        return 'error';
    }

    public static function backoffSeconds(string $errorClass, int $attempts): int
    {
        $attempts = max(1, $attempts);
        $seconds = match ($errorClass) {
            'auth' => self::AUTH_SECONDS,
            'not_found' => self::NOT_FOUND_SECONDS,
            'rate_limit' => self::RATE_LIMIT_BASE_SECONDS * $attempts,
            'timeout', 'network', 'unavailable' => self::TRANSIENT_BASE_SECONDS * $attempts,
            default => self::TRANSIENT_BASE_SECONDS,
        };
        if ($attempts >= self::MAX_FAST_ATTEMPTS) {
            $seconds = max($seconds, self::SLOW_SECONDS);
        }
        return min(self::SLOW_SECONDS, $seconds);
    }

    public static function successInterval(string $deliveryStatus): int
    {
        if (in_array($deliveryStatus, [
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_CANCELLED,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
        ], true)) {
            return self::SLOW_SECONDS;
        }
        if ($deliveryStatus === DeliveryOrder::STATUS_EXCEPTION) {
            return self::EXCEPTION_SECONDS;
        }
        return self::MIN_OPEN_SECONDS;
    }

    public static function isTerminal(string $deliveryStatus): bool
    {
        return in_array($deliveryStatus, [
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_CANCELLED,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $statuses
     * @return array<string, mixed>|null
     */
    public static function latestStatus(array $statuses): ?array
    {
        $best = null;
        $bestTs = null;
        foreach ($statuses as $status) {
            if (!is_array($status)) {
                continue;
            }
            if (!empty($status['deleted'])) {
                continue;
            }
            $ts = strtotime((string) ($status['date_time'] ?? '')) ?: 0;
            if ($best === null || $ts >= $bestTs) {
                $best = $status;
                $bestTs = $ts;
            }
        }
        return $best;
    }

    /**
     * Разбор ответа CdekOrderService::getByUuid. HTTP 202 / ACCEPTED без статусов — не доставка.
     *
     * @param array<string, mixed> $fetched
     * @return array{
     *   ok: bool,
     *   apply: bool,
     *   error_class?: string,
     *   error?: string,
     *   registration_only?: bool,
     *   parsed?: array<string, mixed>,
     *   planned_delivery_date?: string|null,
     *   cdek_number?: string|null
     * }
     */
    public static function interpretGet(array $fetched, string $localUuid): array
    {
        $localUuid = trim($localUuid);
        if (empty($fetched['ok'])) {
            $http = (int) ($fetched['http_status'] ?? 0);
            $errorType = isset($fetched['error_type']) ? (string) $fetched['error_type'] : null;
            $error = (string) ($fetched['error'] ?? 'get_failed');
            return [
                'ok' => false,
                'apply' => false,
                'error_class' => self::classifyFailure($http, $errorType, $error),
                'error' => $error,
            ];
        }

        $remoteUuid = trim((string) ($fetched['uuid'] ?? ''));
        if ($localUuid !== '' && $remoteUuid !== '' && strcasecmp($localUuid, $remoteUuid) !== 0) {
            return [
                'ok' => false,
                'apply' => false,
                'error_class' => 'uuid_mismatch',
                'error' => 'uuid_mismatch',
            ];
        }

        $raw = is_array($fetched['raw'] ?? null) ? $fetched['raw'] : [];
        $planned = self::plannedDate(isset($raw['planned_delivery_date']) ? (string) $raw['planned_delivery_date'] : null);
        $cdekNumber = isset($fetched['cdek_number']) && $fetched['cdek_number'] !== ''
            ? (string) $fetched['cdek_number']
            : null;
        $statuses = is_array($fetched['statuses'] ?? null) ? $fetched['statuses'] : [];
        $latest = self::latestStatus($statuses);
        $code = $latest !== null ? trim((string) ($latest['code'] ?? '')) : '';

        if ($code === '') {
            $http = (int) ($fetched['http_status'] ?? 0);
            $internal = strtolower((string) ($fetched['internal_status'] ?? ''));
            $requestState = strtoupper((string) ($fetched['request_state'] ?? ''));
            $pending = $http === 202
                || in_array($internal, ['accepted', 'processing'], true)
                || in_array($requestState, ['ACCEPTED', 'WAITING'], true);
            return [
                'ok' => true,
                'apply' => false,
                'registration_only' => $pending,
                'planned_delivery_date' => $planned,
                'cdek_number' => $cdekNumber,
            ];
        }

        $group = CdekStatusCatalog::group($code);
        return [
            'ok' => true,
            'apply' => true,
            'registration_only' => false,
            'planned_delivery_date' => $planned,
            'cdek_number' => $cdekNumber,
            'parsed' => [
                'status' => $group ?? CdekStatusCatalog::GROUP_UNKNOWN,
                'raw_code' => $code,
                'event_at' => self::normalizeEventAt((string) ($latest['date_time'] ?? '')),
                'tracking_number' => $cdekNumber,
                'message' => CdekStatusCatalog::text($code, 'buyer'),
                'location' => isset($latest['city']) && is_scalar($latest['city']) ? (string) $latest['city'] : null,
                'source' => 'reconciliation',
            ],
        ];
    }
}
