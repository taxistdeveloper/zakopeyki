<?php

namespace App\Services\Delivery;

/**
 * Серверная валидация Point A / Point B / shipment (без доверия к frontend).
 */
final class DeliveryPointValidator
{
    public const ORIGIN_DOOR = 'door';
    public const ORIGIN_PVZ = 'pvz';

    public const MODE_COURIER = 'courier';
    public const MODE_PVZ = 'pvz';

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, field?: string}
     */
    public function validatePointA(array $input, bool $requireCdekCodes = false): array
    {
        $name = trim((string) ($input['name'] ?? $input['ship_contact_name'] ?? ''));
        $phone = $this->normalizePhone((string) ($input['phone'] ?? $input['ship_phone'] ?? ''));
        $city = trim((string) ($input['city'] ?? $input['ship_city'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? $input['ship_country'] ?? 'KZ'))) ?: 'KZ';
        $originType = (string) ($input['origin_type'] ?? self::ORIGIN_DOOR);
        if (!in_array($originType, [self::ORIGIN_DOOR, self::ORIGIN_PVZ], true)) {
            $originType = self::ORIGIN_DOOR;
        }

        if ($name === '') {
            return ['ok' => false, 'error' => 'sender_name_required', 'field' => 'name'];
        }
        if (mb_strlen($name) > 160) {
            return ['ok' => false, 'error' => 'sender_name_too_long', 'field' => 'name'];
        }
        if (!$this->isValidPhone($phone)) {
            return ['ok' => false, 'error' => 'sender_phone_invalid', 'field' => 'phone'];
        }
        if ($city === '') {
            return ['ok' => false, 'error' => 'sender_city_required', 'field' => 'city'];
        }

        $shipmentPoint = trim((string) ($input['shipment_point'] ?? '')) ?: null;
        $street = trim((string) ($input['street'] ?? $input['ship_street'] ?? '')) ?: null;
        $building = trim((string) ($input['building'] ?? $input['ship_building'] ?? '')) ?: null;

        if ($originType === self::ORIGIN_PVZ) {
            if ($shipmentPoint === null || $shipmentPoint === '') {
                return ['ok' => false, 'error' => 'shipment_point_required', 'field' => 'shipment_point'];
            }
        } elseif ($requireCdekCodes) {
            if ($street === null || $street === '') {
                return ['ok' => false, 'error' => 'sender_address_required', 'field' => 'street'];
            }
        }

        $cityCode = $this->nullableInt($input['cdek_city_code'] ?? null);
        if ($requireCdekCodes && $cityCode === null) {
            // City code нужен и для door, и для PVZ (калькулятор from_location / фильтр ПВЗ).
            return ['ok' => false, 'error' => 'cdek_city_code_required', 'field' => 'cdek_city_code'];
        }

        $postalCode = trim((string) ($input['postal_code'] ?? $input['ship_postal_code'] ?? '')) ?: null;
        if ($postalCode !== null && !$this->isValidPostalCode($postalCode, $country)) {
            return ['ok' => false, 'error' => 'sender_postal_invalid', 'field' => 'postal_code'];
        }

        $email = trim((string) ($input['email'] ?? '')) ?: null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'sender_email_invalid', 'field' => 'email'];
        }

        return [
            'ok' => true,
            'data' => [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'company' => trim((string) ($input['company'] ?? '')) ?: null,
                'country' => $country,
                'region' => trim((string) ($input['region'] ?? $input['ship_region'] ?? '')) ?: null,
                'city' => $city,
                'street' => $street,
                'building' => $building,
                'apartment' => trim((string) ($input['apartment'] ?? $input['ship_apartment'] ?? '')) ?: null,
                'postal_code' => $postalCode,
                'origin_type' => $originType,
                'shipment_point' => $originType === self::ORIGIN_PVZ ? $shipmentPoint : null,
                'cdek_city_code' => $cityCode,
                'latitude' => $this->nullableFloat($input['latitude'] ?? $input['ship_latitude'] ?? null),
                'longitude' => $this->nullableFloat($input['longitude'] ?? $input['ship_longitude'] ?? null),
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, field?: string}
     */
    public function validatePointB(array $input, bool $requireCdekCodes = false): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $phone = $this->normalizePhone((string) ($input['phone'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $country = strtoupper(trim((string) ($input['country'] ?? 'KZ'))) ?: 'KZ';
        $mode = (string) ($input['delivery_mode'] ?? self::MODE_COURIER);
        if (!in_array($mode, [self::MODE_COURIER, self::MODE_PVZ, 'pickup_point'], true)) {
            $mode = self::MODE_COURIER;
        }
        if ($mode === 'pickup_point') {
            $mode = self::MODE_PVZ;
        }

        if ($name === '') {
            return ['ok' => false, 'error' => 'recipient_name_required', 'field' => 'name'];
        }
        if (!$this->isValidPhone($phone)) {
            return ['ok' => false, 'error' => 'recipient_phone_invalid', 'field' => 'phone'];
        }
        if ($city === '') {
            return ['ok' => false, 'error' => 'recipient_city_required', 'field' => 'city'];
        }

        $street = trim((string) ($input['street'] ?? '')) ?: null;
        $pvzCode = trim((string) ($input['pvz_code'] ?? $input['delivery_point'] ?? '')) ?: null;

        if ($mode === self::MODE_COURIER) {
            if ($street === null || $street === '') {
                return ['ok' => false, 'error' => 'recipient_address_required', 'field' => 'street'];
            }
        } else {
            if ($pvzCode === null || $pvzCode === '') {
                return ['ok' => false, 'error' => 'pvz_required', 'field' => 'pvz_code'];
            }
        }

        $cityCode = $this->nullableInt($input['cdek_city_code'] ?? null);
        if ($requireCdekCodes && $cityCode === null && $mode === self::MODE_COURIER) {
            return ['ok' => false, 'error' => 'cdek_city_code_required', 'field' => 'cdek_city_code'];
        }

        $postalCode = trim((string) ($input['postal_code'] ?? '')) ?: null;
        if ($postalCode !== null && !$this->isValidPostalCode($postalCode, $country)) {
            return ['ok' => false, 'error' => 'recipient_postal_invalid', 'field' => 'postal_code'];
        }

        $email = trim((string) ($input['email'] ?? '')) ?: null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'recipient_email_invalid', 'field' => 'email'];
        }

        return [
            'ok' => true,
            'data' => [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'delivery_mode' => $mode,
                'country' => $country,
                'region' => trim((string) ($input['region'] ?? '')) ?: null,
                'city' => $city,
                'street' => $mode === self::MODE_COURIER ? $street : null,
                'building' => $mode === self::MODE_COURIER
                    ? (trim((string) ($input['building'] ?? '')) ?: null)
                    : null,
                'apartment' => $mode === self::MODE_COURIER
                    ? (trim((string) ($input['apartment'] ?? '')) ?: null)
                    : null,
                'postal_code' => $postalCode,
                'pvz_code' => $mode === self::MODE_PVZ ? $pvzCode : null,
                'pvz_name' => $mode === self::MODE_PVZ
                    ? (trim((string) ($input['pvz_name'] ?? '')) ?: null)
                    : null,
                'delivery_point' => $mode === self::MODE_PVZ ? $pvzCode : null,
                'cdek_city_code' => $cityCode,
                'latitude' => $this->nullableFloat($input['latitude'] ?? null),
                'longitude' => $this->nullableFloat($input['longitude'] ?? null),
                'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string, field?: string}
     */
    public function validateShipment(array $input): array
    {
        $packageCount = max(1, (int) ($input['package_count'] ?? 1));
        if ($packageCount > 20) {
            return ['ok' => false, 'error' => 'package_count_invalid', 'field' => 'package_count'];
        }

        $dimensionsUnknown = !empty($input['dimensions_unknown']);
        $rawWeight = $input['item_weight'] ?? $input['gross_weight'] ?? $input['weight_value'] ?? null;
        $rawLength = $input['item_length'] ?? $input['package_length'] ?? $input['length_value'] ?? null;
        $rawWidth = $input['item_width'] ?? $input['package_width'] ?? $input['width_value'] ?? null;
        $rawHeight = $input['item_height'] ?? $input['package_height'] ?? $input['height_value'] ?? null;
        $weight = $this->positiveFloat($rawWeight);
        $length = $this->positiveFloat($rawLength);
        $width = $this->positiveFloat($rawWidth);
        $height = $this->positiveFloat($rawHeight);
        $packagingId = (int) ($input['packaging_id'] ?? 0);

        if (!$dimensionsUnknown) {
            if ($this->isProvided($rawWeight) && $weight === null) {
                return ['ok' => false, 'error' => 'weight_invalid', 'field' => 'item_weight'];
            }
            if ($weight === null || $weight <= 0) {
                return ['ok' => false, 'error' => 'weight_required', 'field' => 'item_weight'];
            }
            if ($weight > 1000) {
                return ['ok' => false, 'error' => 'weight_too_large', 'field' => 'item_weight'];
            }
            if (
                ($this->isProvided($rawLength) && $length === null)
                || ($this->isProvided($rawWidth) && $width === null)
                || ($this->isProvided($rawHeight) && $height === null)
            ) {
                return ['ok' => false, 'error' => 'dimensions_invalid', 'field' => 'item_length'];
            }
            if ($length === null || $width === null || $height === null) {
                return ['ok' => false, 'error' => 'dimensions_required', 'field' => 'item_length'];
            }
            foreach (['length' => $length, 'width' => $width, 'height' => $height] as $field => $val) {
                if ($val > 500) {
                    return ['ok' => false, 'error' => 'dimensions_too_large', 'field' => 'item_' . $field];
                }
            }
        } elseif ($packagingId <= 0) {
            return ['ok' => false, 'error' => 'packaging_required', 'field' => 'packaging_id'];
        }

        $declaredCost = isset($input['declared_cost']) ? (int) $input['declared_cost'] : null;
        if ($declaredCost !== null && $declaredCost < 0) {
            return ['ok' => false, 'error' => 'declared_cost_invalid', 'field' => 'declared_cost'];
        }

        return [
            'ok' => true,
            'data' => [
                'package_count' => $packageCount,
                'item_weight' => $weight,
                'item_length' => $length,
                'item_width' => $width,
                'item_height' => $height,
                'dimensions_unknown' => $dimensionsUnknown,
                'packaging_id' => $packagingId > 0 ? $packagingId : null,
                'declared_cost' => $declaredCost,
                'declared_currency' => strtoupper(trim((string) ($input['declared_currency'] ?? 'KZT'))) ?: 'KZT',
                'description' => trim((string) ($input['description'] ?? '')) ?: null,
                'is_fragile' => !empty($input['is_fragile']),
                'is_irregular' => !empty($input['is_irregular']),
            ],
        ];
    }

    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? $phone;
        return $phone;
    }

    public function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $len = strlen($digits);
        return $len >= 10 && $len <= 15;
    }

    public function isValidPostalCode(string $postal, string $country = 'KZ'): bool
    {
        $postal = trim($postal);
        if ($postal === '') {
            return false;
        }
        $country = strtoupper($country);
        if ($country === 'KZ' || $country === 'RU') {
            return (bool) preg_match('/^\d{6}$/', $postal);
        }
        return (bool) preg_match('/^[A-Za-z0-9\-\s]{3,12}$/', $postal);
    }

    public function nullableIntPublic(mixed $value): ?int
    {
        return $this->nullableInt($value);
    }

    private function isProvided(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $i = (int) $value;
        return $i > 0 ? $i : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
