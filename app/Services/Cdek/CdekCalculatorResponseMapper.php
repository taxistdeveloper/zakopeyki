<?php

namespace App\Services\Cdek;

/**
 * Маппинг CalculatorTariffListResponseDto → внутренняя нормализованная модель тарифа.
 *
 * Поля TariffCodeDto (OpenAPI):
 * tariff_code, tariff_name, tariff_description, delivery_mode,
 * delivery_sum, period_min, period_max, calendar_min, calendar_max, delivery_date_range.
 *
 * Внутреннее: price = delivery_sum в целых тенге (MoneyAmount),
 * delivery_sum_raw сохраняется строкой для аудита.
 *
 * @see openapi_api_v2_integration.json TariffCodeDto / CalculatorTariffListResponseDto
 */
class CdekCalculatorResponseMapper
{
    private CdekErrorMapper $errors;

    public function __construct(?CdekErrorMapper $errors = null)
    {
        $this->errors = $errors ?? new CdekErrorMapper();
    }

    /**
     * @param array<string, mixed>|null $responseBody decoded CDEK JSON
     * @param array{
     *   packaging_amount?: int,
     *   handling_amount?: int,
     *   fragile_amount?: int,
     *   currency_label?: string,
     *   request_hash?: string,
     *   route_hash?: string,
     *   package_hash?: string,
     *   quote_ttl_seconds?: int,
     *   delivery_mode_filter?: string|null,
     *   billable_weight_kg?: string|float|int|null,
     *   meta?: array<string, mixed>
     * } $context
     * @return array{
     *   ok: bool,
     *   tariffs: list<array<string, mixed>>,
     *   quotes: list<array<string, mixed>>,
     *   warnings: list<array<string, mixed>>,
     *   error?: array
     * }
     */
    public function map(?array $responseBody, array $context = []): array
    {
        if ($responseBody === null) {
            return [
                'ok' => false,
                'tariffs' => [],
                'quotes' => [],
                'warnings' => [],
                'error' => $this->errors->mapLocal(CdekErrorMapper::INTERNAL_UNKNOWN, 'Empty CDEK calculator response'),
            ];
        }

        $warnings = [];
        if (!empty($responseBody['warnings']) && is_array($responseBody['warnings'])) {
            foreach ($responseBody['warnings'] as $w) {
                if (is_array($w)) {
                    $warnings[] = [
                        'code' => isset($w['code']) ? (string) $w['code'] : null,
                        'message' => (string) ($w['message'] ?? ''),
                    ];
                }
            }
        }

        if (!empty($responseBody['errors']) && is_array($responseBody['errors']) && $responseBody['errors'] !== []) {
            return [
                'ok' => false,
                'tariffs' => [],
                'quotes' => [],
                'warnings' => $warnings,
                'error' => $this->errors->map($responseBody),
            ];
        }

        $rawTariffs = $responseBody['tariff_codes'] ?? [];
        if (!is_array($rawTariffs) || $rawTariffs === []) {
            return [
                'ok' => false,
                'tariffs' => [],
                'quotes' => [],
                'warnings' => $warnings,
                'error' => $this->errors->mapLocal(CdekErrorMapper::INTERNAL_TARIFF, 'CDEK returned no tariff_codes'),
            ];
        }

        $modeFilter = $context['delivery_mode_filter'] ?? null;
        $packaging = (int) ($context['packaging_amount'] ?? 0);
        $handling = (int) ($context['handling_amount'] ?? 0);
        $fragile = (int) ($context['fragile_amount'] ?? 0);
        $currency = (string) ($context['currency_label'] ?? 'KZT');
        $requestHash = (string) ($context['request_hash'] ?? '');
        $routeHash = (string) ($context['route_hash'] ?? '');
        $packageHash = (string) ($context['package_hash'] ?? '');
        $ttl = max(60, (int) ($context['quote_ttl_seconds'] ?? 7200));
        $validUntil = date('Y-m-d H:i:s', time() + $ttl);
        $billableWeight = $context['billable_weight_kg'] ?? null;
        $extraMeta = is_array($context['meta'] ?? null) ? $context['meta'] : [];

        $tariffs = [];
        $quotes = [];

        foreach ($rawTariffs as $row) {
            if (!is_array($row) || empty($row['tariff_code'])) {
                continue;
            }

            $normalized = $this->normalizeTariff($row);
            $deliveryMode = (int) $normalized['delivery_mode'];

            if (is_string($modeFilter) && $modeFilter !== '' && !$this->modeMatches($modeFilter, $deliveryMode)) {
                continue;
            }

            $tariffs[] = $normalized;

            $price = (int) $normalized['price'];
            $total = MoneyAmount::sumInt([$price, $packaging, $handling, $fragile]);
            $serviceCode = 'cdek_' . (int) $normalized['tariff_code'];

            $snapshot = array_merge($extraMeta, [
                'cdek_tariff_code' => (int) $normalized['tariff_code'],
                'cdek_delivery_mode' => $deliveryMode,
                'tariff_name' => $normalized['tariff_name'],
                'tariff_description' => $normalized['tariff_description'],
                'delivery_sum' => $normalized['delivery_sum_raw'],
                'delivery_sum_audit' => $normalized['delivery_sum_raw'],
                'period_min' => $normalized['period_min'],
                'period_max' => $normalized['period_max'],
                'calendar_min' => $normalized['calendar_min'],
                'calendar_max' => $normalized['calendar_max'],
                'delivery_date_range' => $normalized['delivery_date_range'],
            ]);

            $quotes[] = [
                'service_code' => $serviceCode,
                'service_name' => $normalized['tariff_name'] !== ''
                    ? $normalized['tariff_name']
                    : ('СДЭК #' . $normalized['tariff_code']),
                'tariff_version' => 'cdek-v2',
                'tariff_code' => (int) $normalized['tariff_code'],
                'delivery_mode' => $deliveryMode,
                'base_amount' => $price,
                'packaging_amount' => $packaging,
                'handling_amount' => $handling,
                'extra_services_amount' => $fragile,
                'discount_amount' => 0,
                'total_amount' => $total,
                'currency' => $currency,
                'billable_weight' => $billableWeight,
                'billable_weight_method' => 'cdek_package_kg',
                'calculation_method' => 'cdek_tarifflist_v2',
                'eta_days_min' => $normalized['period_min'] ?? $normalized['calendar_min'],
                'eta_days_max' => $normalized['period_max'] ?? $normalized['calendar_max'],
                'calendar_days_min' => $normalized['calendar_min'],
                'calendar_days_max' => $normalized['calendar_max'],
                'delivery_date_from' => $normalized['delivery_date_range']['min'] ?? null,
                'delivery_date_to' => $normalized['delivery_date_range']['max'] ?? null,
                'valid_until' => $validUntil,
                'request_payload_hash' => $requestHash,
                'route_hash' => $routeHash !== '' ? $routeHash : null,
                'package_hash' => $packageHash !== '' ? $packageHash : null,
                'response_hash' => hash(
                    'sha256',
                    $serviceCode . '|' . $total . '|' . $normalized['delivery_sum_raw']
                ),
                'snapshot_json' => $snapshot,
                // Аудит CDEK:
                'delivery_sum_raw' => $normalized['delivery_sum_raw'],
                'price' => $price,
            ];
        }

        if ($quotes === []) {
            return [
                'ok' => false,
                'tariffs' => $tariffs,
                'quotes' => [],
                'warnings' => $warnings,
                'error' => $this->errors->mapLocal(
                    CdekErrorMapper::INTERNAL_TARIFF,
                    'No tariffs match delivery mode filter'
                ),
            ];
        }

        usort($quotes, static fn(array $a, array $b): int => $a['total_amount'] <=> $b['total_amount']);

        return [
            'ok' => true,
            'tariffs' => $tariffs,
            'quotes' => array_slice($quotes, 0, 8),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   tariff_code: int,
     *   tariff_name: string,
     *   tariff_description: string|null,
     *   delivery_mode: int,
     *   delivery_sum_raw: string,
     *   price: int,
     *   period_min: int|null,
     *   period_max: int|null,
     *   calendar_min: int|null,
     *   calendar_max: int|null,
     *   delivery_date_range: array{min: string|null, max: string|null}|null
     * }
     */
    public function normalizeTariff(array $row): array
    {
        $rawSum = $row['delivery_sum'] ?? 0;
        $price = MoneyAmount::toTengeInt($rawSum);
        $range = null;
        if (!empty($row['delivery_date_range']) && is_array($row['delivery_date_range'])) {
            $range = [
                'min' => isset($row['delivery_date_range']['min']) ? (string) $row['delivery_date_range']['min'] : null,
                'max' => isset($row['delivery_date_range']['max']) ? (string) $row['delivery_date_range']['max'] : null,
            ];
        }

        return [
            'tariff_code' => (int) $row['tariff_code'],
            'tariff_name' => trim((string) ($row['tariff_name'] ?? '')),
            'tariff_description' => isset($row['tariff_description']) ? (string) $row['tariff_description'] : null,
            'delivery_mode' => (int) ($row['delivery_mode'] ?? 0),
            'delivery_sum_raw' => MoneyAmount::toAuditString($rawSum),
            'price' => $price,
            'period_min' => isset($row['period_min']) ? (int) $row['period_min'] : null,
            'period_max' => isset($row['period_max']) ? (int) $row['period_max'] : null,
            'calendar_min' => isset($row['calendar_min']) ? (int) $row['calendar_min'] : null,
            'calendar_max' => isset($row['calendar_max']) ? (int) $row['calendar_max'] : null,
            'delivery_date_range' => $range,
        ];
    }

    /**
     * CDEK delivery_mode:
     * 1 door-door, 2 door-warehouse, 3 warehouse-door, 4 warehouse-warehouse, 6/7 postamat.
     */
    public function modeMatches(string $ourMode, int $cdekMode): bool
    {
        if (in_array($ourMode, ['pvz', 'pickup_point'], true)) {
            return in_array($cdekMode, [2, 4, 6, 7], true);
        }
        return in_array($cdekMode, [1, 3], true);
    }
}
