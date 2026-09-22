<?php

namespace App\Services\Cdek;

/**
 * Idempotent quote reuse (§21): одинаковый calculator request → свежий local quote.
 *
 * Ключ: request_hash + shipping_version + TTL (valid_until).
 * route_hash / package_hash — аудит и доп. сверка (входят в request_hash).
 *
 * Quote ≠ оплата: reuse только active+непросроченных расчётов.
 */
class CdekQuoteReuseService
{
    /**
     * Можно ли переиспользовать набор quotes без вызова CDEK Calculator.
     *
     * @param list<array<string, mixed>> $rows active quotes from DB
     * @return array{ok: bool, reason?: string, quotes?: list<array<string, mixed>>}
     */
    public function tryReuse(
        array $rows,
        string $requestHash,
        int $shippingVersion,
        ?string $routeHash = null,
        ?string $packageHash = null,
        ?int $nowTs = null
    ): array {
        $requestHash = trim($requestHash);
        if ($requestHash === '' || $shippingVersion <= 0) {
            return ['ok' => false, 'reason' => 'missing_key'];
        }
        if ($rows === []) {
            return ['ok' => false, 'reason' => 'empty'];
        }

        $now = $nowTs ?? time();
        $reusable = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['request_payload_hash'] ?? '') !== $requestHash) {
                return ['ok' => false, 'reason' => 'request_hash_mismatch'];
            }
            if ((int) ($row['shipping_version'] ?? 0) !== $shippingVersion) {
                return ['ok' => false, 'reason' => 'shipping_version_mismatch'];
            }
            $status = (string) ($row['quote_status'] ?? 'active');
            if ($status !== 'active') {
                return ['ok' => false, 'reason' => 'not_active'];
            }
            $validUntil = strtotime((string) ($row['valid_until'] ?? '')) ?: 0;
            if ($validUntil <= $now) {
                return ['ok' => false, 'reason' => 'expired'];
            }
            if ($routeHash !== null && $routeHash !== ''
                && !empty($row['route_hash'])
                && (string) $row['route_hash'] !== $routeHash) {
                return ['ok' => false, 'reason' => 'route_hash_mismatch'];
            }
            if ($packageHash !== null && $packageHash !== ''
                && !empty($row['package_hash'])
                && (string) $row['package_hash'] !== $packageHash) {
                return ['ok' => false, 'reason' => 'package_hash_mismatch'];
            }

            $reusable[] = $this->rowToQuote($row);
        }

        if ($reusable === []) {
            return ['ok' => false, 'reason' => 'empty'];
        }

        return ['ok' => true, 'quotes' => $reusable];
    }

    /**
     * @param array<string, mixed> $row delivery_quotes
     * @return array<string, mixed>
     */
    public function rowToQuote(array $row): array
    {
        $snap = $row['snapshot_json'] ?? null;
        if (is_string($snap) && $snap !== '') {
            $decoded = json_decode($snap, true);
            $snap = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($snap)) {
            $snap = [];
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'reused' => true,
            'service_code' => (string) ($row['service_code'] ?? ''),
            'service_name' => (string) ($row['service_name'] ?? ''),
            'tariff_version' => $row['tariff_version'] ?? null,
            'base_amount' => (int) ($row['base_amount'] ?? 0),
            'packaging_amount' => (int) ($row['packaging_amount'] ?? 0),
            'handling_amount' => (int) ($row['handling_amount'] ?? 0),
            'extra_services_amount' => (int) ($row['extra_services_amount'] ?? 0),
            'discount_amount' => (int) ($row['discount_amount'] ?? 0),
            'total_amount' => (int) ($row['total_amount'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'KZT'),
            'billable_weight' => $row['billable_weight'] ?? null,
            'billable_weight_method' => $row['billable_weight_method'] ?? null,
            'calculation_method' => $row['calculation_method'] ?? null,
            'shipping_version' => isset($row['shipping_version']) ? (int) $row['shipping_version'] : null,
            'eta_days_min' => $row['eta_days_min'] ?? null,
            'eta_days_max' => $row['eta_days_max'] ?? null,
            'valid_until' => $row['valid_until'] ?? null,
            'request_payload_hash' => $row['request_payload_hash'] ?? null,
            'route_hash' => $row['route_hash'] ?? null,
            'package_hash' => $row['package_hash'] ?? null,
            'response_hash' => $row['response_hash'] ?? null,
            'snapshot_json' => $snap,
            'quote_status' => 'active',
        ];
    }
}
