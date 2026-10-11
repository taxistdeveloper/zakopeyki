<?php

namespace App\Services\Cdek;

/**
 * География и пункты CDEK для Point B.
 * Локальная таблица cdek_delivery_points не заменяет API: это не источник истины при сохранении.
 *
 * @see docs/cdek-locations-and-delivery-points.md
 */
class CdekLocationService
{
    public const TTL_REGIONS = 86400;
    public const TTL_CITIES = 3600;
    public const TTL_CITY_CODE = 86400;
    public const TTL_POSTAL = 86400;
    public const TTL_POINTS = 900;

    private object $api;
    private CdekDirectoryCache $cache;

    public function __construct(?object $api = null, ?CdekDirectoryCache $cache = null)
    {
        $this->api = $api ?? new CdekApi();
        $this->cache = $cache ?? new CdekDirectoryCache(dirname(__DIR__, 3) . '/storage/cdek-location-cache');
    }

    /**
     * @return array{ok: bool, regions?: list<array>, stale?: bool, error_code?: string, error?: string}
     */
    public function searchRegions(string $countryCode = 'KZ', string $lang = 'rus', string $query = ''): array
    {
        $countryCode = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $countryCode) ?: 'KZ', 0, 2));
        $lang = $this->lang($lang);
        $key = 'regions:' . $countryCode . ':' . $lang;
        $cached = $this->cache->remember($key, self::TTL_REGIONS, function () use ($countryCode, $lang) {
            $res = $this->api->regions([
                'country_codes' => $countryCode,
                'lang' => $lang,
                'size' => 1000,
                'page' => 0,
            ]);
            return $this->wrapList($res, 'regions');
        }, true);
        if (!($cached['ok'] ?? false)) {
            return $this->fail($cached['error_code'] ?? 'cdek_error');
        }
        $rows = $this->publicRegions(is_array($cached['payload']) ? $cached['payload'] : []);
        $q = mb_strtolower(trim($query));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($q): bool {
                return str_contains(mb_strtolower((string) ($row['region'] ?? '')), $q);
            }));
        }
        return ['ok' => true, 'regions' => array_slice($rows, 0, 50), 'stale' => !empty($cached['stale'])];
    }

    /**
     * @return array{ok: bool, cities?: list<array>, error_code?: string, error?: string, stale?: bool}
     */
    public function suggestCities(string $name, string $countryCode = 'KZ'): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 2) {
            return $this->fail('query_too_short');
        }
        $countryCode = strtoupper(substr($countryCode, 0, 2));
        $key = 'suggest:' . $countryCode . ':' . mb_strtolower($name);
        $cached = $this->cache->remember($key, self::TTL_CITIES, function () use ($name, $countryCode) {
            $res = $this->api->suggestCities([
                'name' => $name,
                'country_code' => $countryCode,
            ]);
            return $this->wrapList($res, 'cities');
        }, true);
        if (!($cached['ok'] ?? false)) {
            return $this->fail($cached['error_code'] ?? 'cdek_error');
        }
        $cities = [];
        foreach (array_slice(is_array($cached['payload']) ? $cached['payload'] : [], 0, 15) as $row) {
            if (!is_array($row) || !isset($row['code'])) {
                continue;
            }
            $cities[] = [
                'code' => (int) $row['code'],
                'full_name' => (string) ($row['full_name'] ?? ''),
                'country_code' => (string) ($row['country_code'] ?? $countryCode),
                'city_uuid' => (string) ($row['city_uuid'] ?? ''),
            ];
        }
        return ['ok' => true, 'cities' => $cities, 'stale' => !empty($cached['stale'])];
    }

    /**
     * @return array{ok: bool, city?: array, error_code?: string, error?: string}
     */
    public function confirmCity(int $code, string $countryCode = 'KZ'): array
    {
        if ($code <= 0) {
            return $this->fail('city_code_invalid');
        }
        $countryCode = strtoupper(substr($countryCode, 0, 2));
        $key = 'city:' . $code;
        $cached = $this->cache->remember($key, self::TTL_CITY_CODE, function () use ($code) {
            $res = $this->api->cities(['code' => $code, 'size' => 1]);
            return $this->wrapList($res, 'cities');
        }, false);
        if (!($cached['ok'] ?? false)) {
            return $this->fail($cached['error_code'] ?? 'city_not_found');
        }
        $rows = is_array($cached['payload']) ? $cached['payload'] : [];
        $row = null;
        foreach ($rows as $item) {
            if (is_array($item) && (int) ($item['code'] ?? 0) === $code) {
                $row = $item;
                break;
            }
        }
        if ($row === null) {
            return $this->fail('city_not_found');
        }
        $gotCountry = strtoupper((string) ($row['country_code'] ?? ''));
        if ($gotCountry !== '' && $countryCode !== '' && $gotCountry !== $countryCode) {
            return $this->fail('city_country_mismatch');
        }
        return [
            'ok' => true,
            'city' => [
                'code' => (int) $row['code'],
                'city' => (string) ($row['city'] ?? ''),
                'region' => (string) ($row['region'] ?? ''),
                'region_code' => isset($row['region_code']) ? (int) $row['region_code'] : null,
                'country_code' => $gotCountry !== '' ? $gotCountry : $countryCode,
                'city_uuid' => (string) ($row['city_uuid'] ?? ''),
            ],
        ];
    }

    /**
     * @return array{ok: bool, postal_codes?: list<string>, error_code?: string, error?: string}
     */
    public function postalCodes(int $cityCode): array
    {
        if ($cityCode <= 0) {
            return $this->fail('city_code_invalid');
        }
        $cached = $this->cache->remember('postal:' . $cityCode, self::TTL_POSTAL, function () use ($cityCode) {
            $res = $this->api->postalCodes(['city_code' => $cityCode]);
            if (!$res->ok || $res->httpStatus >= 400) {
                return ['ok' => false, 'error_code' => self::httpError($res)];
            }
            $data = $res->data;
            $codes = [];
            if (is_array($data) && isset($data['postal_codes']) && is_array($data['postal_codes'])) {
                $codes = $data['postal_codes'];
            }
            $out = [];
            foreach ($codes as $code) {
                if (is_scalar($code) && (string) $code !== '') {
                    $out[] = (string) $code;
                }
            }
            return ['ok' => true, 'payload' => $out];
        }, false);
        if (!($cached['ok'] ?? false)) {
            return $this->fail($cached['error_code'] ?? 'postal_unavailable');
        }
        return ['ok' => true, 'postal_codes' => array_values($cached['payload'])];
    }

    /**
     * @return array{ok: bool, city?: array, error_code?: string, error?: string}
     */
    public function cityByCoordinates(float $latitude, float $longitude): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $this->fail('coordinates_invalid');
        }
        $res = $this->api->coordinates([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
        if (!$res->ok || $res->httpStatus >= 400 || !is_array($res->data)) {
            return $this->fail(self::httpError($res));
        }
        $row = isset($res->data['code']) ? $res->data : ($res->data[0] ?? null);
        if (!is_array($row) || empty($row['code'])) {
            return $this->fail('city_not_found');
        }
        return [
            'ok' => true,
            'city' => [
                'code' => (int) $row['code'],
                'city' => (string) ($row['city'] ?? ''),
                'city_uuid' => (string) ($row['city_uuid'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{ok: bool, points?: list<array>, stale?: bool, error_code?: string, error?: string}
     */
    public function searchDeliveryPoints(array $filters, bool $allowStale = true): array
    {
        $query = $this->pointQuery($filters);
        if ($query === null) {
            return $this->fail('city_code_required');
        }
        $key = 'points:' . hash('sha256', json_encode($query));
        $cached = $this->cache->remember($key, self::TTL_POINTS, function () use ($query) {
            $res = $this->api->deliveryPoints($query);
            return $this->wrapList($res, 'points');
        }, $allowStale);
        if (!($cached['ok'] ?? false)) {
            return $this->fail($cached['error_code'] ?? 'cdek_error');
        }
        $points = self::filterOffices(is_array($cached['payload']) ? $cached['payload'] : [], $filters);
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 30)));
        return [
            'ok' => true,
            'points' => array_slice($points, 0, $limit),
            'stale' => !empty($cached['stale']),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{ok: bool, point?: array, error_code?: string, error?: string}
     */
    public function confirmDeliveryPoint(string $code, array $filters): array
    {
        $code = trim($code);
        if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{2,32}$/', $code)) {
            return $this->fail('point_code_invalid');
        }
        $filters['code'] = $code;
        $filters['limit'] = 5;
        $found = $this->searchDeliveryPoints($filters, false);
        if (!($found['ok'] ?? false)) {
            return $this->fail($found['error_code'] ?? 'point_not_found');
        }
        foreach ($found['points'] ?? [] as $point) {
            if (strcasecmp((string) $point['code'], $code) === 0) {
                return ['ok' => true, 'point' => $point];
            }
        }
        return $this->fail('point_not_found');
    }

    /**
     * @param list<array<string, mixed>> $offices
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public static function filterOffices(array $offices, array $filters): array
    {
        $weight = isset($filters['weight_kg']) ? (float) $filters['weight_kg'] : 0.0;
        $length = isset($filters['length_cm']) ? (int) $filters['length_cm'] : 0;
        $width = isset($filters['width_cm']) ? (int) $filters['width_cm'] : 0;
        $height = isset($filters['height_cm']) ? (int) $filters['height_cm'] : 0;
        $cityCode = (int) ($filters['city_code'] ?? 0);
        $out = [];
        foreach ($offices as $office) {
            if (!is_array($office)) {
                continue;
            }
            $public = self::toPublicPoint($office);
            if ($public === null) {
                continue;
            }
            if (empty($public['is_handout']) || !empty($office['take_only'])) {
                continue;
            }
            if ($cityCode > 0 && (int) ($public['city_code'] ?? 0) > 0 && (int) $public['city_code'] !== $cityCode) {
                continue;
            }
            if ($weight > 0 && $public['weight_max'] !== null && (float) $public['weight_max'] > 0 && $weight > (float) $public['weight_max']) {
                continue;
            }
            if ($weight > 0 && $public['weight_min'] !== null && $weight < (float) $public['weight_min']) {
                continue;
            }
            if ($length > 0 && !empty($public['length_max']) && $length > (int) $public['length_max']) {
                continue;
            }
            if ($width > 0 && !empty($public['width_max']) && $width > (int) $public['width_max']) {
                continue;
            }
            if ($height > 0 && !empty($public['height_max']) && $height > (int) $public['height_max']) {
                continue;
            }
            $out[] = $public;
        }
        return $out;
    }

    /** @param array<string, mixed> $office */
    public static function toPublicPoint(array $office): ?array
    {
        $code = trim((string) ($office['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $loc = is_array($office['location'] ?? null) ? $office['location'] : [];
        return [
            'code' => $code,
            'uuid' => (string) ($office['uuid'] ?? ''),
            'type' => (string) ($office['type'] ?? ''),
            'name' => (string) ($loc['address'] ?? $office['name'] ?? $code),
            'address' => (string) ($loc['address_full'] ?? $loc['address'] ?? ''),
            'city' => (string) ($loc['city'] ?? ''),
            'city_code' => isset($loc['city_code']) ? (int) $loc['city_code'] : null,
            'region' => (string) ($loc['region'] ?? ''),
            'region_code' => isset($loc['region_code']) ? (int) $loc['region_code'] : null,
            'country_code' => (string) ($loc['country_code'] ?? ''),
            'postal_code' => (string) ($loc['postal_code'] ?? ''),
            'latitude' => isset($loc['latitude']) ? (float) $loc['latitude'] : null,
            'longitude' => isset($loc['longitude']) ? (float) $loc['longitude'] : null,
            'is_handout' => !empty($office['is_handout']),
            'is_reception' => !empty($office['is_reception']),
            'weight_min' => isset($office['weight_min']) ? (float) $office['weight_min'] : null,
            'weight_max' => isset($office['weight_max']) ? (float) $office['weight_max'] : null,
            'length_max' => isset($office['length_max']) ? (int) $office['length_max'] : null,
            'width_max' => isset($office['width_max']) ? (int) $office['width_max'] : null,
            'height_max' => isset($office['height_max']) ? (int) $office['height_max'] : null,
            'work_time' => (string) ($office['work_time'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, scalar>|null
     */
    private function pointQuery(array $filters): ?array
    {
        $query = [
            'country_code' => strtoupper(substr((string) ($filters['country_code'] ?? 'KZ'), 0, 2)),
            'lang' => $this->lang((string) ($filters['lang'] ?? 'rus')),
            'is_handout' => 'true',
            'take_only' => 'false',
            'type' => in_array(strtoupper((string) ($filters['type'] ?? 'PVZ')), ['PVZ', 'POSTAMAT', 'ALL'], true)
                ? strtoupper((string) ($filters['type'] ?? 'PVZ'))
                : 'PVZ',
        ];
        $cityCode = (int) ($filters['city_code'] ?? 0);
        $code = trim((string) ($filters['code'] ?? ''));
        if ($cityCode <= 0 && $code === '') {
            return null;
        }
        if ($cityCode > 0) {
            $query['city_code'] = $cityCode;
        }
        if ($code !== '') {
            $query['code'] = $code;
        }
        if (!empty($filters['region_code'])) {
            $query['region_code'] = (int) $filters['region_code'];
        }
        if (!empty($filters['postal_code'])) {
            $query['postal_code'] = mb_substr((string) $filters['postal_code'], 0, 16);
        }
        if (!empty($filters['weight_kg']) && (float) $filters['weight_kg'] > 0) {
            $query['weight_max'] = max(1, (int) ceil((float) $filters['weight_kg']));
        }
        foreach (['length' => 'length_cm', 'width' => 'width_cm', 'height' => 'height_cm'] as $api => $src) {
            if (!empty($filters[$src]) && (int) $filters[$src] > 0) {
                $query[$api] = (int) $filters[$src];
            }
        }
        return $query;
    }

    /** @return array{ok: bool, payload?: list<array>, error_code?: string} */
    private function wrapList(CdekApiResponse $res, string $kind): array
    {
        if (!$res->ok || $res->httpStatus >= 400 || $res->httpStatus === 0) {
            return ['ok' => false, 'error_code' => self::httpError($res)];
        }
        $data = $res->data;
        if (!is_array($data)) {
            return ['ok' => true, 'payload' => []];
        }
        if ($kind === 'cities' && isset($data['code']) && !isset($data[0])) {
            return ['ok' => true, 'payload' => [$data]];
        }
        if ($kind === 'regions' && isset($data['region']) && !isset($data[0])) {
            return ['ok' => true, 'payload' => [$data]];
        }
        if ($kind === 'points' && isset($data['code']) && !isset($data[0])) {
            return ['ok' => true, 'payload' => [$data]];
        }
        $list = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $list[] = $row;
            }
        }
        return ['ok' => true, 'payload' => $list];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function publicRegions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'country_code' => (string) ($row['country_code'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'region' => (string) ($row['region'] ?? ''),
                'region_code' => isset($row['region_code']) ? (int) $row['region_code'] : null,
            ];
        }
        return $out;
    }

    private function lang(string $lang): string
    {
        $lang = strtolower($lang);
        return in_array($lang, ['rus', 'eng', 'zho'], true) ? $lang : 'rus';
    }

    public static function httpError(CdekApiResponse $res): string
    {
        $http = $res->httpStatus;
        if ($http === 0) {
            $msg = mb_strtolower((string) $res->message);
            return str_contains($msg, 'timeout') || str_contains($msg, 'timed out') ? 'timeout' : 'network';
        }
        if ($http === 429) {
            return 'rate_limit';
        }
        if ($http >= 500) {
            return 'cdek_unavailable';
        }
        if ($http === 404) {
            return 'not_found';
        }
        return 'cdek_http_' . $http;
    }

    /** @return array{ok: false, error_code: string, error: string} */
    private function fail(string $code): array
    {
        $key = match ($code) {
            'query_too_short' => 'delivery.city_query_short',
            'city_not_found', 'not_found' => 'delivery.city_not_found',
            'city_code_invalid', 'city_code_required' => 'delivery.city_code_required',
            'city_country_mismatch' => 'delivery.city_country_mismatch',
            'point_not_found', 'point_code_invalid' => 'delivery.pvz_not_found',
            'rate_limit' => 'delivery.cdek_rate_limit',
            'timeout' => 'delivery.cdek_timeout',
            'cdek_unavailable', 'network' => 'delivery.cdek_unavailable',
            'coordinates_invalid' => 'delivery.coordinates_invalid',
            default => 'delivery.cdek_directory_error',
        };
        return ['ok' => false, 'error_code' => $code, 'error' => function_exists('t') ? t($key) : $code];
    }
}
