<?php

namespace App\Services\Cdek;

use App\Models\CdekDeliveryPoint;

/**
 * Синхронизация GET /v2/deliverypoints → локальный справочник cdek_delivery_points.
 *
 * Принцип:
 *   CDEK API → our directory → frontend
 * Frontend не доверяется: код ПВЗ проверяется по is_active в БД.
 *
 * @see openapi_api_v2_integration.json OfficeDto
 */
class CdekDeliveryPointsSyncService
{
    private Client $client;
    /** @var object CdekDeliveryPoint-like */
    private object $points;
    private CdekErrorMapper $errors;

    public function __construct(
        ?Client $client = null,
        ?object $points = null,
        ?CdekErrorMapper $errors = null
    ) {
        $this->client = $client ?? new Client();
        $this->points = $points ?? new CdekDeliveryPoint();
        $this->errors = $errors ?? new CdekErrorMapper();
    }

    /**
     * Полный sync по стране (по умолчанию KZ).
     * type=ALL чтобы тянуть PVZ и POSTAMAT.
     *
     * @return array{ok: bool, upserted?: int, deactivated?: int, fetched?: int, error?: string, request_id?: string}
     */
    public function syncCountry(string $countryCode = 'KZ', string $type = 'ALL'): array
    {
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'error' => 'CDEK is not configured'];
        }

        $countryCode = strtoupper(mb_substr(trim($countryCode), 0, 2));
        $type = strtoupper($type);
        if (!in_array($type, ['PVZ', 'POSTAMAT', 'ALL'], true)) {
            $type = 'ALL';
        }

        $query = [
            'country_code' => $countryCode,
            'type' => $type,
            'is_handout' => 'true',
        ];

        $res = $this->client->get('/deliverypoints', $query);
        if (!$res['ok']) {
            $mapped = $res['error_mapped'] ?? $this->errors->map(
                is_array($res['data'] ?? null) ? $res['data'] : null,
                (int) ($res['code'] ?? 0),
                $res['error'] ?? null
            );
            return [
                'ok' => false,
                'error' => $mapped['message'] ?? ($res['error'] ?? 'deliverypoints failed'),
                'request_id' => $res['request_id'] ?? null,
            ];
        }

        $list = $this->extractList($res['data'] ?? null);
        $syncedAt = date('Y-m-d H:i:s');
        $upserted = 0;

        foreach ($list as $office) {
            if (!is_array($office)) {
                continue;
            }
            $normalized = $this->normalizeOffice($office, $countryCode);
            if ($normalized === null) {
                continue;
            }
            $this->points->upsertNormalized($normalized, $syncedAt);
            $upserted++;
        }

        $deactivated = $this->points->deactivateStale($countryCode, $syncedAt);

        return [
            'ok' => true,
            'fetched' => count($list),
            'upserted' => $upserted,
            'deactivated' => $deactivated,
            'request_id' => $res['request_id'] ?? null,
            'synced_at' => $syncedAt,
        ];
    }

    /**
     * Sync по конкретному city_code (быстрее для точечного обновления).
     *
     * @return array{ok: bool, upserted?: int, fetched?: int, error?: string}
     */
    public function syncCity(int $cityCode, string $countryCode = 'KZ'): array
    {
        if ($cityCode <= 0) {
            return ['ok' => false, 'error' => 'city_code required'];
        }
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'error' => 'CDEK is not configured'];
        }

        $res = $this->client->get('/deliverypoints', [
            'city_code' => $cityCode,
            'country_code' => strtoupper($countryCode),
            'type' => 'ALL',
            'is_handout' => 'true',
        ]);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'deliverypoints failed'];
        }

        $list = $this->extractList($res['data'] ?? null);
        $syncedAt = date('Y-m-d H:i:s');
        $upserted = 0;
        foreach ($list as $office) {
            if (!is_array($office)) {
                continue;
            }
            $normalized = $this->normalizeOffice($office, strtoupper($countryCode));
            if ($normalized === null) {
                continue;
            }
            $this->points->upsertNormalized($normalized, $syncedAt);
            $upserted++;
        }

        return [
            'ok' => true,
            'fetched' => count($list),
            'upserted' => $upserted,
            'request_id' => $res['request_id'] ?? null,
        ];
    }

    /**
     * Проверка кода ПВЗ против локального справочника.
     *
     * @return array{ok: true, point: array}|array{ok: false, error: string, internal_code: string}
     */
    public function validateCode(string $code, ?string $expectedCity = null, ?float $packageWeightKg = null): array
    {
        $code = trim($code);
        if ($code === '') {
            return [
                'ok' => false,
                'error' => t('delivery.pvz_required'),
                'internal_code' => CdekErrorMapper::INTERNAL_POINT_NOT_FOUND,
            ];
        }

        $point = $this->points->findByCode($code, true);
        if (!$point) {
            return [
                'ok' => false,
                'error' => t('delivery.pvz_not_found'),
                'internal_code' => CdekErrorMapper::INTERNAL_POINT_NOT_FOUND,
            ];
        }

        if (empty($point['is_handout'])) {
            return [
                'ok' => false,
                'error' => t('delivery.pvz_no_handout'),
                'internal_code' => CdekErrorMapper::INTERNAL_POINT_NOT_FOUND,
            ];
        }

        if ($expectedCity !== null && trim($expectedCity) !== '') {
            $pointCity = mb_strtolower(trim((string) ($point['city'] ?? '')));
            $want = mb_strtolower(trim($expectedCity));
            if ($pointCity !== '' && $want !== '' && !str_contains($pointCity, $want) && !str_contains($want, $pointCity)) {
                return [
                    'ok' => false,
                    'error' => t('delivery.pvz_city_mismatch'),
                    'internal_code' => CdekErrorMapper::INTERNAL_POINT_NOT_FOUND,
                ];
            }
        }

        if ($packageWeightKg !== null && $packageWeightKg > 0 && isset($point['weight_max']) && $point['weight_max'] !== null && $point['weight_max'] !== '') {
            $max = (float) $point['weight_max'];
            if ($max > 0 && $packageWeightKg > $max) {
                return [
                    'ok' => false,
                    'error' => t('delivery.pvz_weight_exceeded'),
                    'internal_code' => CdekErrorMapper::INTERNAL_PACKAGE,
                ];
            }
        }

        return ['ok' => true, 'point' => $point];
    }

    /**
     * @param mixed $data
     * @return list<array<string, mixed>>
     */
    private function extractList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        // CDEK обычно отдаёт массив OfficeDto на верхнем уровне.
        if ($data === []) {
            return [];
        }
        if (isset($data['code'])) {
            return [$data];
        }
        $out = [];
        foreach ($data as $row) {
            if (is_array($row) && !empty($row['code'])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $office
     * @return array<string, mixed>|null
     */
    public function normalizeOffice(array $office, string $fallbackCountry = 'KZ'): ?array
    {
        $code = trim((string) ($office['code'] ?? ''));
        if ($code === '') {
            return null;
        }

        $loc = is_array($office['location'] ?? null) ? $office['location'] : [];
        $address = (string) ($loc['address'] ?? '');
        $addressFull = (string) ($loc['address_full'] ?? $address);
        $city = (string) ($loc['city'] ?? '');
        $name = $address !== '' ? $address : ($city !== '' ? ('CDEK ' . $city) : $code);

        $type = strtoupper((string) ($office['type'] ?? 'PVZ'));
        if ($type === '') {
            $type = 'PVZ';
        }

        $hashSource = [
            'code' => $code,
            'type' => $type,
            'loc' => $loc,
            'work_time' => $office['work_time'] ?? null,
            'flags' => [
                $office['is_handout'] ?? null,
                $office['is_reception'] ?? null,
                $office['take_only'] ?? null,
            ],
        ];

        return [
            'code' => $code,
            'uuid' => isset($office['uuid']) ? (string) $office['uuid'] : null,
            'type' => $type,
            'name' => mb_substr($name, 0, 255),
            'address' => $address !== '' ? mb_substr($address, 0, 255) : null,
            'address_full' => $addressFull !== '' ? mb_substr($addressFull, 0, 255) : null,
            'city' => $city !== '' ? mb_substr($city, 0, 120) : null,
            'city_code' => isset($loc['city_code']) ? (int) $loc['city_code'] : null,
            'region' => isset($loc['region']) ? mb_substr((string) $loc['region'], 0, 120) : null,
            'region_code' => isset($loc['region_code']) ? (int) $loc['region_code'] : null,
            'country_code' => strtoupper(mb_substr((string) ($loc['country_code'] ?? $fallbackCountry), 0, 2)),
            'postal_code' => isset($loc['postal_code']) ? (string) $loc['postal_code'] : null,
            'longitude' => isset($loc['longitude']) ? $loc['longitude'] : null,
            'latitude' => isset($loc['latitude']) ? $loc['latitude'] : null,
            'work_time' => isset($office['work_time']) ? mb_substr((string) $office['work_time'], 0, 255) : null,
            'is_handout' => !empty($office['is_handout']),
            'is_reception' => !empty($office['is_reception']),
            'take_only' => !empty($office['take_only']),
            'have_cashless' => !empty($office['have_cashless']),
            'have_cash' => !empty($office['have_cash']),
            'allowed_cod' => !empty($office['allowed_cod']),
            'weight_min' => $office['weight_min'] ?? null,
            'weight_max' => $office['weight_max'] ?? null,
            'raw_hash' => hash('sha256', json_encode($hashSource, JSON_UNESCAPED_UNICODE)),
        ];
    }
}
