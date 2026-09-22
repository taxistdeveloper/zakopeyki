<?php

namespace App\Services\Cdek;

/**
 * Собирает тело POST /v2/orders по OpenAPI OrderCreateRequestDto.
 *
 * ВАЖНО: это НЕ calculator payload.
 * Order DTO включает contacts, packages.items, developer_key и т.д.
 *
 * Origin/destination:
 * - shipment_point XOR from_location
 * - delivery_point XOR to_location
 *
 * RequestFromLocationDto / RequestToLocationDto требуют address, когда location используется.
 *
 * @see openapi_api_v2_integration.json OrderCreateRequestDto, PackageRequestDto, ItemRequestDto
 */
class CdekOrderPayloadBuilder
{
    /** Whitelist RequestFromLocationDto / RequestToLocationDto (без deprecated kladr_*). */
    private const LOCATION_FIELDS = [
        'code',
        'city_uuid',
        'city',
        'fias_guid',
        'country_code',
        'country',
        'region',
        'region_code',
        'fias_region_guid',
        'sub_region',
        'longitude',
        'latitude',
        'time_zone',
        'payment_limit',
        'address',
        'postal_code',
    ];

    private const CONTACT_FIELDS = [
        'company',
        'name',
        'contragent_type',
        'passport_series',
        'passport_number',
        'passport_date_of_issue',
        'passport_organization',
        'tin',
        'passport_date_of_birth',
        'email',
        'phones',
    ];

    private CdekErrorMapper $errors;

    public function __construct(?CdekErrorMapper $errors = null)
    {
        $this->errors = $errors ?? new CdekErrorMapper();
    }

    /**
     * @param array<string, mixed> $avr delivery AVR context (DeliveryService::avrPayload)
     * @param array{
     *   from_city_code?: int|null,
     *   to_city_code?: int|null,
     *   type?: int,
     *   developer_key?: string|null,
     *   declared_cost?: int|null
     * } $options
     * @return array{ok: true, payload: array<string, mixed>, payload_hash: string, developer_key: string}|array{ok: false, error: array}
     */
    public function buildFromAvr(array $avr, array $options = []): array
    {
        try {
            $sender = is_array($avr['sender'] ?? null) ? $avr['sender'] : [];
            $recipient = is_array($avr['recipient'] ?? null) ? $avr['recipient'] : [];
            $shipment = is_array($avr['shipment'] ?? null) ? $avr['shipment'] : [];
            $service = is_array($avr['service'] ?? null) ? $avr['service'] : [];

            $deliveryOrderId = (int) ($avr['delivery_order_id'] ?? $avr['identifiers']['delivery_order_id'] ?? 0);
            $orderNumber = (string) ($avr['order_number'] ?? ('DO-' . $deliveryOrderId));
            if (mb_strlen($orderNumber) > 40) {
                $orderNumber = mb_substr($orderNumber, 0, 40);
            }

            $tariffCode = $this->parseTariffCode((string) ($service['service_code'] ?? ''));
            if ($tariffCode === null) {
                throw new \InvalidArgumentException('tariff_code is required');
            }

            $developerKey = trim((string) ($options['developer_key'] ?? ''));
            if ($developerKey === '') {
                // Стабильный ключ на delivery order — помогает дедупу на стороне CDEK / у нас.
                $developerKey = 'zk-del-' . $deliveryOrderId;
            }

            $payload = [
                'type' => (int) ($options['type'] ?? 1),
                'number' => $orderNumber,
                'tariff_code' => $tariffCode,
                'comment' => mb_substr('Zakapeiku delivery #' . $deliveryOrderId, 0, 255),
                'developer_key' => mb_substr($developerKey, 0, 255),
                'sender' => $this->buildContact($sender, 'sender'),
                'recipient' => $this->buildContact($recipient, 'recipient'),
                'packages' => [
                    $this->buildPackage($shipment, $deliveryOrderId, $options['declared_cost'] ?? null),
                ],
            ];

            $this->applyOrigin($payload, $sender, (int) ($options['from_city_code'] ?? 0));
            $this->applyDestination($payload, $recipient, (int) ($options['to_city_code'] ?? 0));

            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => true,
                'payload' => $payload,
                'payload_hash' => $hash,
                'developer_key' => $developerKey,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'ok' => false,
                'error' => $this->errors->mapLocal(
                    $this->guessCode($e->getMessage()),
                    $e->getMessage()
                ),
            ];
        }
    }

    /**
     * @param array<string, mixed> $person
     * @return array<string, mixed>
     */
    private function buildContact(array $person, string $role): array
    {
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException($role . '.name is required');
        }

        $phone = $this->normalizePhone((string) ($person['phone'] ?? ''));
        if ($phone === null) {
            throw new \InvalidArgumentException($role . '.phone is required and must be valid');
        }

        $contact = [
            'name' => mb_substr($name, 0, 255),
            'phones' => [['number' => $phone]],
        ];

        $email = trim((string) ($person['email'] ?? ''));
        if ($email !== '') {
            $contact['email'] = mb_substr($email, 0, 255);
        }

        $company = trim((string) ($person['company'] ?? ''));
        if ($company !== '') {
            $contact['company'] = mb_substr($company, 0, 255);
        }

        // Whitelist — не тащим произвольные поля person в CDEK.
        return array_intersect_key($contact, array_flip(self::CONTACT_FIELDS));
    }

    /**
     * @param array<string, mixed> $shipment
     * @return array<string, mixed>
     */
    private function buildPackage(array $shipment, int $deliveryOrderId, ?int $declaredCost): array
    {
        $weight = $this->resolveWeightGrams($shipment);
        $pkg = [
            'number' => '1',
            'weight' => $weight,
        ];

        foreach (['length' => ['billed_length', 'package_length', 'length_value'],
                  'width' => ['billed_width', 'package_width', 'width_value'],
                  'height' => ['billed_height', 'package_height', 'height_value']] as $dim => $keys) {
            $v = null;
            foreach ($keys as $k) {
                if (isset($shipment[$k]) && $shipment[$k] !== '' && $shipment[$k] !== null) {
                    $v = (int) round((float) $shipment[$k]);
                    break;
                }
            }
            if ($v !== null && $v > 0) {
                $pkg[$dim] = $v;
            }
        }

        // ItemRequestDto: amount, cost, name, payment, ware_key, weight — required.
        // Доставка уже оплачена на платформе → payment.value = 0 (без наложенного платежа).
        $cost = $declaredCost !== null ? max(0, $declaredCost) : 0;
        if ($cost === 0 && isset($shipment['declared_cost'])) {
            $cost = max(0, MoneyAmount::toTengeInt($shipment['declared_cost']));
        }

        $pkg['items'] = [[
            'name' => mb_substr((string) ($shipment['product_title'] ?? 'Товар'), 0, 255),
            'ware_key' => mb_substr('item-' . max(1, $deliveryOrderId), 0, 50),
            'payment' => ['value' => 0],
            'cost' => $cost,
            'weight' => $weight,
            'amount' => 1,
        ]];

        return $pkg;
    }

    /** @param array<string, mixed> $payload */
    private function applyOrigin(array &$payload, array $sender, int $fromCityCode): void
    {
        $point = trim((string) ($sender['shipment_point'] ?? ''));
        // Не путать recipient PVZ с seller ship-from: seller.pvz_code только если явно shipment_point-семантика.
        if ($point === '' && !empty($sender['use_pvz_as_shipment_point'])) {
            $point = trim((string) ($sender['pvz_code'] ?? ''));
        }

        if ($point !== '') {
            $payload['shipment_point'] = mb_substr($point, 0, 255);
            return;
        }

        if ($fromCityCode <= 0) {
            throw new \InvalidArgumentException('from_location.code is required when shipment_point is empty');
        }

        $address = $this->formatAddress($sender);
        if ($address === '') {
            throw new \InvalidArgumentException('from_location.address is required');
        }

        $loc = [
            'code' => $fromCityCode,
            'address' => mb_substr($address, 0, 255),
        ];
        $city = trim((string) ($sender['city'] ?? ''));
        if ($city !== '') {
            $loc['city'] = mb_substr($city, 0, 255);
        }
        $country = trim((string) ($sender['country'] ?? $sender['country_code'] ?? ''));
        if ($country !== '') {
            $loc['country_code'] = mb_substr(strtoupper($country), 0, 2);
        }

        $payload['from_location'] = $this->sanitizeLocation($loc);
    }

    /** @param array<string, mixed> $payload */
    private function applyDestination(array &$payload, array $recipient, int $toCityCode): void
    {
        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $point = '';
        if (in_array($mode, ['pvz', 'pickup_point'], true)) {
            $point = trim((string) ($recipient['pvz_code'] ?? $recipient['delivery_point'] ?? ''));
        }

        if ($point !== '') {
            $payload['delivery_point'] = mb_substr($point, 0, 255);
            // XOR: не передаём to_location вместе с delivery_point.
            return;
        }

        if ($toCityCode <= 0) {
            throw new \InvalidArgumentException('to_location.code is required when delivery_point is empty');
        }

        $address = $this->formatAddress($recipient);
        if ($address === '') {
            throw new \InvalidArgumentException('to_location.address is required');
        }

        $loc = [
            'code' => $toCityCode,
            'address' => mb_substr($address, 0, 255),
        ];
        $city = trim((string) ($recipient['city'] ?? ''));
        if ($city !== '') {
            $loc['city'] = mb_substr($city, 0, 255);
        }
        $country = trim((string) ($recipient['country'] ?? $recipient['country_code'] ?? ''));
        if ($country !== '') {
            $loc['country_code'] = mb_substr(strtoupper($country), 0, 2);
        }

        $payload['to_location'] = $this->sanitizeLocation($loc);
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
                'code', 'region_code' => (int) $value,
                'longitude', 'latitude', 'payment_limit' => (float) $value,
                'country_code' => mb_substr(strtoupper((string) $value), 0, 2),
                default => is_string($value) ? mb_substr($value, 0, 255) : $value,
            };
        }
        if (empty($out['address'])) {
            throw new \InvalidArgumentException('location.address is required by OpenAPI');
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
            throw new \InvalidArgumentException('package.weight is required');
        }

        $kgStr = is_string($kg) ? str_replace(',', '.', trim($kg)) : number_format((float) $kg, 3, '.', '');
        if (!preg_match('/^\d+(\.\d+)?$/', $kgStr)) {
            throw new \InvalidArgumentException('invalid package.weight');
        }
        [$intPart, $frac] = array_pad(explode('.', $kgStr, 2), 2, '0');
        $frac = str_pad(substr($frac, 0, 3), 3, '0');
        $grams = ((int) $intPart) * 1000 + (int) $frac;
        if ($grams <= 0) {
            throw new \InvalidArgumentException('package.weight must be > 0');
        }
        return $grams;
    }

    private function parseTariffCode(string $serviceCode): ?int
    {
        if (preg_match('/^cdek_(\d+)$/i', $serviceCode, $m)) {
            return (int) $m[1];
        }
        if (ctype_digit($serviceCode)) {
            return (int) $serviceCode;
        }
        return null;
    }

    /** @param array<string, mixed> $addr */
    private function formatAddress(array $addr): string
    {
        $parts = array_filter([
            trim((string) ($addr['street'] ?? '')),
            trim((string) ($addr['building'] ?? '')),
            trim((string) ($addr['apartment'] ?? '')),
        ], static fn($v) => $v !== '');
        return implode(', ', $parts);
    }

    /**
     * Нормализация телефона. Placeholder +7000… запрещён.
     */
    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($digits) < 10) {
            return null;
        }
        // Отсекаем тестовый placeholder из старой реализации.
        if ($digits === '70000000000' || $digits === '0000000000') {
            return null;
        }
        if (str_starts_with($phone, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '8') && strlen($digits) === 11) {
            return '+7' . substr($digits, 1);
        }
        if (str_starts_with($digits, '7') && strlen($digits) === 11) {
            return '+' . $digits;
        }
        return '+' . $digits;
    }

    private function guessCode(string $message): string
    {
        $m = mb_strtolower($message);
        if (str_contains($m, 'from_location') || str_contains($m, 'shipment_point') || str_contains($m, 'sender')) {
            return CdekErrorMapper::INTERNAL_ORIGIN;
        }
        if (str_contains($m, 'to_location') || str_contains($m, 'delivery_point') || str_contains($m, 'recipient')) {
            return CdekErrorMapper::INTERNAL_DESTINATION;
        }
        if (str_contains($m, 'package') || str_contains($m, 'weight') || str_contains($m, 'tariff')) {
            return CdekErrorMapper::INTERNAL_PACKAGE;
        }
        return CdekErrorMapper::INTERNAL_VALIDATION;
    }
}
