<?php

namespace App\Services\Cdek;

/**
 * Собирает тело POST /v2/calculator/tarifflist строго по OpenAPI:
 * CalculatorTariffListRequestDto + CalcPackageRequestDto + CalculatorLocationDto.
 *
 * Ограничения CDEK (бизнес + OpenAPI поля):
 * - origin: shipment_point XOR from_location (оба нельзя; оба пустые нельзя);
 * - destination: delivery_point XOR to_location;
 * - packages[].weight обязателен (граммы, int);
 * - length/width/height — только если заданы;
 * - items в calculator DTO НЕТ — не передаём.
 *
 * Вход — внутренний shipping snapshot + destination; выход — чистый CDEK JSON.
 *
 * @see openapi_api_v2_integration.json CalculatorTariffListRequestDto
 */
class CdekCalculatorRequestBuilder
{
    /** Поля CalculatorLocationDto из OpenAPI — whitelist. */
    private const LOCATION_FIELDS = [
        'code',
        'postal_code',
        'country_code',
        'city',
        'address',
        'contragent_type',
        'longitude',
        'latitude',
    ];

    private CdekErrorMapper $errors;

    public function __construct(?CdekErrorMapper $errors = null)
    {
        $this->errors = $errors ?? new CdekErrorMapper();
    }

    /**
     * @param array{
     *   shipment_point?: string|null,
     *   from_location?: array<string, mixed>|null
     * } $origin
     * @param array{
     *   delivery_point?: string|null,
     *   to_location?: array<string, mixed>|null
     * } $destination
     * @param list<array{weight: int|float|string, length?: int|float|string|null, width?: int|float|string|null, height?: int|float|string|null}> $packages
     * @param array{type?: int, currency?: int, lang?: string, date?: string, additional_order_types?: list<int>} $options
     * @return array{ok: true, payload: array<string, mixed>, request_hash: string, route_hash: string, package_hash: string}|array{ok: false, error: array}
     */
    public function build(array $origin, array $destination, array $packages, array $options = []): array
    {
        try {
            $payload = [];

            if (isset($options['date']) && is_string($options['date']) && $options['date'] !== '') {
                $payload['date'] = $options['date'];
            }
            if (array_key_exists('type', $options) && $options['type'] !== null && $options['type'] !== '') {
                $payload['type'] = (int) $options['type'];
            }
            if (!empty($options['additional_order_types']) && is_array($options['additional_order_types'])) {
                $payload['additional_order_types'] = array_values(array_unique(array_map('intval', $options['additional_order_types'])));
            }
            if (array_key_exists('currency', $options) && $options['currency'] !== null && $options['currency'] !== '') {
                $payload['currency'] = (int) $options['currency'];
            }
            if (isset($options['lang']) && is_string($options['lang']) && $options['lang'] !== '') {
                $payload['lang'] = mb_substr($options['lang'], 0, 3);
            }

            $this->applyOrigin($payload, $origin);
            $this->applyDestination($payload, $destination);
            $payload['packages'] = $this->buildPackages($packages);

            $hashes = self::hashesForPayload($payload);

            return [
                'ok' => true,
                'payload' => $payload,
                'request_hash' => $hashes['request_hash'],
                'route_hash' => $hashes['route_hash'],
                'package_hash' => $hashes['package_hash'],
            ];
        } catch (\InvalidArgumentException $e) {
            $code = $this->guessLocalCode($e->getMessage());
            return [
                'ok' => false,
                'error' => $this->errors->mapLocal($code, $e->getMessage()),
            ];
        }
    }

    /**
     * Канонические хеши для idempotent quote reuse (§21).
     * request_hash = весь calculator payload;
     * route_hash = origin+destination;
     * package_hash = packages[].
     *
     * @param array<string, mixed> $payload
     * @return array{request_hash: string, route_hash: string, package_hash: string}
     */
    public static function hashesForPayload(array $payload): array
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $route = [
            'shipment_point' => $payload['shipment_point'] ?? null,
            'from_location' => $payload['from_location'] ?? null,
            'delivery_point' => $payload['delivery_point'] ?? null,
            'to_location' => $payload['to_location'] ?? null,
        ];
        $packages = $payload['packages'] ?? [];

        return [
            'request_hash' => hash('sha256', (string) json_encode($payload, $flags)),
            'route_hash' => hash('sha256', (string) json_encode($route, $flags)),
            'package_hash' => hash('sha256', (string) json_encode($packages, $flags)),
        ];
    }

    /**
     * Удобный вход из delivery context (sender/recipient/shipment), как в текущем DeliveryService.
     *
     * @param array<string, mixed> $sender
     * @param array<string, mixed> $recipient
     * @param array<string, mixed> $shipment
     * @param array{type?: int, currency?: int, lang?: string} $options
     * @param array{from_city_code?: int|null, to_city_code?: int|null} $resolvedCities
     * @return array{ok: true, payload: array<string, mixed>, request_hash: string}|array{ok: false, error: array}
     */
    public function buildFromDeliveryContext(
        array $sender,
        array $recipient,
        array $shipment,
        array $options,
        array $resolvedCities = []
    ): array {
        $shipmentPoint = trim((string) ($sender['shipment_point'] ?? $sender['pvz_code'] ?? ''));
        $deliveryPoint = '';
        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        if (in_array($mode, ['pvz', 'pickup_point'], true)) {
            $deliveryPoint = trim((string) ($recipient['pvz_code'] ?? $recipient['delivery_point'] ?? ''));
        }

        $origin = [];
        if ($shipmentPoint !== '') {
            $origin['shipment_point'] = $shipmentPoint;
        } else {
            $fromCode = (int) ($resolvedCities['from_city_code'] ?? $sender['cdek_city_code'] ?? 0);
            if ($fromCode <= 0) {
                return [
                    'ok' => false,
                    'error' => $this->errors->mapLocal(
                        CdekErrorMapper::INTERNAL_ORIGIN,
                        'from_location.code is required when shipment_point is empty'
                    ),
                ];
            }
            $from = ['code' => $fromCode];
            $country = trim((string) ($sender['country'] ?? $sender['country_code'] ?? ''));
            if ($country !== '') {
                $from['country_code'] = mb_substr(strtoupper($country), 0, 2);
            }
            $city = trim((string) ($sender['city'] ?? ''));
            if ($city !== '') {
                $from['city'] = mb_substr($city, 0, 255);
            }
            $origin['from_location'] = $from;
        }

        $destination = [];
        if ($deliveryPoint !== '') {
            $destination['delivery_point'] = $deliveryPoint;
        } else {
            $toCode = (int) ($resolvedCities['to_city_code'] ?? $recipient['cdek_city_code'] ?? 0);
            if ($toCode <= 0) {
                return [
                    'ok' => false,
                    'error' => $this->errors->mapLocal(
                        CdekErrorMapper::INTERNAL_DESTINATION,
                        'to_location.code is required when delivery_point is empty'
                    ),
                ];
            }
            $to = ['code' => $toCode];
            $country = trim((string) ($recipient['country'] ?? $recipient['country_code'] ?? ''));
            if ($country !== '') {
                $to['country_code'] = mb_substr(strtoupper($country), 0, 2);
            }
            $city = trim((string) ($recipient['city'] ?? ''));
            if ($city !== '') {
                $to['city'] = mb_substr($city, 0, 255);
            }
            $address = $this->formatAddressLine($recipient);
            if ($address !== '') {
                $to['address'] = mb_substr($address, 0, 255);
            }
            $destination['to_location'] = $to;
        }

        $weightGrams = $this->resolveWeightGrams($shipment);
        $pkg = ['weight' => $weightGrams];
        $length = $this->optionalPositiveInt(
            $shipment['billed_length'] ?? $shipment['package_length'] ?? $shipment['length_value'] ?? null
        );
        $width = $this->optionalPositiveInt(
            $shipment['billed_width'] ?? $shipment['package_width'] ?? $shipment['width_value'] ?? null
        );
        $height = $this->optionalPositiveInt(
            $shipment['billed_height'] ?? $shipment['package_height'] ?? $shipment['height_value'] ?? null
        );
        if ($length !== null) {
            $pkg['length'] = $length;
        }
        if ($width !== null) {
            $pkg['width'] = $width;
        }
        if ($height !== null) {
            $pkg['height'] = $height;
        }

        return $this->build($origin, $destination, [$pkg], $options);
    }

    /** @param array<string, mixed> $payload */
    private function applyOrigin(array &$payload, array $origin): void
    {
        $point = trim((string) ($origin['shipment_point'] ?? ''));
        $location = $origin['from_location'] ?? null;
        $hasLocation = is_array($location) && $location !== [];

        if ($point !== '' && $hasLocation) {
            throw new \InvalidArgumentException('shipment_point XOR from_location: both provided');
        }
        if ($point === '' && !$hasLocation) {
            throw new \InvalidArgumentException('shipment_point XOR from_location: neither provided');
        }

        if ($point !== '') {
            $payload['shipment_point'] = mb_substr($point, 0, 255);
            return;
        }

        $payload['from_location'] = $this->sanitizeLocation($location);
    }

    /** @param array<string, mixed> $payload */
    private function applyDestination(array &$payload, array $destination): void
    {
        $point = trim((string) ($destination['delivery_point'] ?? ''));
        $location = $destination['to_location'] ?? null;
        $hasLocation = is_array($location) && $location !== [];

        if ($point !== '' && $hasLocation) {
            throw new \InvalidArgumentException('delivery_point XOR to_location: both provided');
        }
        if ($point === '' && !$hasLocation) {
            throw new \InvalidArgumentException('delivery_point XOR to_location: neither provided');
        }

        if ($point !== '') {
            $payload['delivery_point'] = mb_substr($point, 0, 255);
            return;
        }

        $payload['to_location'] = $this->sanitizeLocation($location);
    }

    /**
     * @param array<string, mixed> $location
     * @return array<string, mixed>
     */
    private function sanitizeLocation(array $location): array
    {
        $out = [];
        foreach (self::LOCATION_FIELDS as $field) {
            if (!array_key_exists($field, $location) || $location[$field] === null || $location[$field] === '') {
                continue;
            }
            $value = $location[$field];
            $out[$field] = match ($field) {
                'code' => (int) $value,
                'longitude', 'latitude' => (float) $value, // OpenAPI: number/double
                'country_code' => mb_substr(strtoupper((string) $value), 0, 2),
                'postal_code', 'city', 'address' => mb_substr((string) $value, 0, 255),
                'contragent_type' => mb_substr((string) $value, 0, 20),
                default => $value,
            };
        }

        if ($out === []) {
            throw new \InvalidArgumentException('CalculatorLocationDto must contain at least one field');
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<array<string, int>>
     */
    private function buildPackages(array $packages): array
    {
        if ($packages === []) {
            throw new \InvalidArgumentException('packages must not be empty');
        }

        $out = [];
        foreach ($packages as $i => $pkg) {
            if (!is_array($pkg)) {
                throw new \InvalidArgumentException('packages[' . $i . '] must be object');
            }
            if (!array_key_exists('weight', $pkg) || $pkg['weight'] === null || $pkg['weight'] === '') {
                throw new \InvalidArgumentException('packages[' . $i . '].weight is required');
            }
            $weight = (int) $pkg['weight'];
            if ($weight <= 0) {
                throw new \InvalidArgumentException('packages[' . $i . '].weight must be > 0 (grams)');
            }

            $row = ['weight' => $weight];
            foreach (['length', 'width', 'height'] as $dim) {
                if (!array_key_exists($dim, $pkg) || $pkg[$dim] === null || $pkg[$dim] === '') {
                    continue;
                }
                $v = (int) $pkg[$dim];
                if ($v > 0) {
                    $row[$dim] = $v;
                }
            }
            // Явно не копируем items и любые прочие поля.
            $out[] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $shipment */
    private function resolveWeightGrams(array $shipment): int
    {
        if (isset($shipment['weight_grams']) && (int) $shipment['weight_grams'] > 0) {
            return (int) $shipment['weight_grams'];
        }

        $kg = $shipment['billed_gross_weight']
            ?? $shipment['gross_weight']
            ?? $shipment['weight_value']
            ?? null;

        if ($kg === null || $kg === '') {
            throw new \InvalidArgumentException('package weight is required');
        }

        // кг → граммы через строку, без float*1000 в бизнес-цепочке денег (вес — не деньги, но единообразие).
        $kgStr = is_string($kg) ? str_replace(',', '.', trim($kg)) : number_format((float) $kg, 3, '.', '');
        if (!preg_match('/^\d+(\.\d+)?$/', $kgStr)) {
            throw new \InvalidArgumentException('invalid package weight');
        }
        [$intPart, $frac] = array_pad(explode('.', $kgStr, 2), 2, '0');
        $frac = str_pad(substr($frac, 0, 3), 3, '0');
        $grams = ((int) $intPart) * 1000 + (int) $frac;
        if ($grams <= 0) {
            throw new \InvalidArgumentException('package weight must be > 0');
        }
        return $grams;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) round((float) $value);
        return $n > 0 ? $n : null;
    }

    /** @param array<string, mixed> $addr */
    private function formatAddressLine(array $addr): string
    {
        $parts = array_filter([
            trim((string) ($addr['street'] ?? '')),
            trim((string) ($addr['building'] ?? '')),
            trim((string) ($addr['apartment'] ?? '')),
        ], static fn($v) => $v !== '');
        return implode(', ', $parts);
    }

    private function guessLocalCode(string $message): string
    {
        $m = mb_strtolower($message);
        if (str_contains($m, 'shipment_point') || str_contains($m, 'from_location') || str_contains($m, 'origin')) {
            return CdekErrorMapper::INTERNAL_ORIGIN;
        }
        if (str_contains($m, 'delivery_point') || str_contains($m, 'to_location') || str_contains($m, 'destination')) {
            return CdekErrorMapper::INTERNAL_DESTINATION;
        }
        if (str_contains($m, 'package') || str_contains($m, 'weight')) {
            return CdekErrorMapper::INTERNAL_PACKAGE;
        }
        return CdekErrorMapper::INTERNAL_VALIDATION;
    }
}
