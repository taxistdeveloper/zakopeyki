<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Services\Cdek\Client as CdekClient;

/**
 * Провайдер доставки СДЭК (API v2).
 * @see https://apidoc.cdek.ru/#tag/common/Vvedenie
 */
class CdekLogisticsProvider implements LogisticsProviderInterface
{
    private const TARIFF_VERSION = 'cdek-v2';

    private CdekClient $client;
    private ?DeliveryOrder $orders;

    public function __construct(?CdekClient $client = null, ?DeliveryOrder $orders = null)
    {
        $this->client = $client ?? new CdekClient();
        $this->orders = $orders;
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function getQuotes(array $context): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $sender = $context['sender'] ?? [];
        $recipient = $context['recipient'] ?? [];
        $shipment = $context['shipment'] ?? [];
        $deliveryOrderId = (int) ($context['delivery_order_id'] ?? 0);

        $fromCity = trim((string) ($sender['city'] ?? ''));
        $toCity = trim((string) ($recipient['city'] ?? ''));
        if ($fromCity === '' || $toCity === '') {
            $this->logApi($deliveryOrderId, '/calculator/tarifflist', 0, null, 'missing city');
            return [];
        }

        $from = $this->client->findCityCode($fromCity, $sender['country'] ?? null);
        $to = $this->client->findCityCode($toCity, $recipient['country'] ?? null);
        if ($from === null || $to === null) {
            $this->logApi(
                $deliveryOrderId,
                '/location/cities',
                0,
                null,
                'city not found: ' . ($from === null ? $fromCity : $toCity)
            );
            return [];
        }

        $weightKg = (float) ($shipment['billed_gross_weight']
            ?? $shipment['gross_weight']
            ?? $shipment['weight_value']
            ?? 1);
        if ($weightKg <= 0) {
            $weightKg = 1.0;
        }

        $length = (int) max(1, round((float) ($shipment['billed_length'] ?? $shipment['package_length'] ?? $shipment['length_value'] ?? 20)));
        $width = (int) max(1, round((float) ($shipment['billed_width'] ?? $shipment['package_width'] ?? $shipment['width_value'] ?? 20)));
        $height = (int) max(1, round((float) ($shipment['billed_height'] ?? $shipment['package_height'] ?? $shipment['height_value'] ?? 10)));

        $cfg = $this->client->config();
        $payload = [
            'type' => (int) ($cfg['order_type'] ?? 1),
            'currency' => (int) ($cfg['currency'] ?? 2),
            'lang' => (string) ($cfg['lang'] ?? 'rus'),
            'from_location' => ['code' => $from['code']],
            'to_location' => ['code' => $to['code']],
            'packages' => [[
                'weight' => (int) max(1, round($weightKg * 1000)),
                'length' => $length,
                'width' => $width,
                'height' => $height,
            ]],
        ];

        $res = $this->client->post('/calculator/tarifflist', $payload);
        $this->logApi(
            $deliveryOrderId,
            '/calculator/tarifflist',
            (int) ($res['code'] ?? 0),
            hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            $res['ok'] ? null : ($res['error'] ?? 'tarifflist failed'),
            $res['ok'] ? hash('sha256', (string) ($res['body'] ?? '')) : null
        );

        if (!$res['ok']) {
            return [];
        }

        $tariffs = $res['data']['tariff_codes'] ?? [];
        if (!is_array($tariffs) || $tariffs === []) {
            return [];
        }

        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $packagingAmount = (int) ($context['packaging_price'] ?? 0);
        $handling = !empty($shipment['is_irregular']) ? 500 : 0;
        $fragile = !empty($shipment['is_fragile']) ? 300 : 0;
        $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $validUntil = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $currencyLabel = (string) ($cfg['currency_label'] ?? 'KZT');

        $quotes = [];
        foreach ($tariffs as $tariff) {
            if (!is_array($tariff) || empty($tariff['tariff_code'])) {
                continue;
            }
            $deliveryMode = (int) ($tariff['delivery_mode'] ?? 0);
            if (!$this->modeMatches($mode, $deliveryMode)) {
                continue;
            }

            $base = (int) max(0, round((float) ($tariff['delivery_sum'] ?? 0)));
            $total = $base + $packagingAmount + $handling + $fragile;
            $code = 'cdek_' . (int) $tariff['tariff_code'];
            $name = trim((string) ($tariff['tariff_name'] ?? ('СДЭК #' . $tariff['tariff_code'])));
            if ($name === '') {
                $name = 'СДЭК #' . $tariff['tariff_code'];
            }

            $meta = [
                'cdek_tariff_code' => (int) $tariff['tariff_code'],
                'cdek_delivery_mode' => $deliveryMode,
                'from_city_code' => $from['code'],
                'to_city_code' => $to['code'],
                'delivery_sum' => $tariff['delivery_sum'] ?? null,
            ];

            $quotes[] = [
                'service_code' => $code,
                'service_name' => $name,
                'tariff_version' => self::TARIFF_VERSION,
                'base_amount' => $base,
                'packaging_amount' => $packagingAmount,
                'handling_amount' => $handling,
                'extra_services_amount' => $fragile,
                'discount_amount' => 0,
                'total_amount' => $total,
                'currency' => $currencyLabel,
                'billable_weight' => $weightKg,
                'billable_weight_method' => 'cdek_package_kg',
                'calculation_method' => 'cdek_tarifflist_v2',
                'eta_days_min' => isset($tariff['period_min']) ? (int) $tariff['period_min'] : (isset($tariff['calendar_min']) ? (int) $tariff['calendar_min'] : null),
                'eta_days_max' => isset($tariff['period_max']) ? (int) $tariff['period_max'] : (isset($tariff['calendar_max']) ? (int) $tariff['calendar_max'] : null),
                'valid_until' => $validUntil,
                'request_payload_hash' => $requestHash,
                'response_hash' => hash('sha256', $code . $total . json_encode($meta)),
                'snapshot_json' => $meta,
            ];
        }

        // Если фильтр по режиму ничего не дал — вернём топ тарифов без фильтра (тестовая отладка)
        if ($quotes === [] && $tariffs !== []) {
            foreach (array_slice($tariffs, 0, 5) as $tariff) {
                if (!is_array($tariff) || empty($tariff['tariff_code'])) {
                    continue;
                }
                $base = (int) max(0, round((float) ($tariff['delivery_sum'] ?? 0)));
                $total = $base + $packagingAmount + $handling + $fragile;
                $code = 'cdek_' . (int) $tariff['tariff_code'];
                $quotes[] = [
                    'service_code' => $code,
                    'service_name' => (string) ($tariff['tariff_name'] ?? $code),
                    'tariff_version' => self::TARIFF_VERSION,
                    'base_amount' => $base,
                    'packaging_amount' => $packagingAmount,
                    'handling_amount' => $handling,
                    'extra_services_amount' => $fragile,
                    'discount_amount' => 0,
                    'total_amount' => $total,
                    'currency' => $currencyLabel,
                    'billable_weight' => $weightKg,
                    'billable_weight_method' => 'cdek_package_kg',
                    'calculation_method' => 'cdek_tarifflist_v2_unfiltered',
                    'eta_days_min' => isset($tariff['period_min']) ? (int) $tariff['period_min'] : null,
                    'eta_days_max' => isset($tariff['period_max']) ? (int) $tariff['period_max'] : null,
                    'valid_until' => $validUntil,
                    'request_payload_hash' => $requestHash,
                    'response_hash' => hash('sha256', $code . $total),
                    'snapshot_json' => [
                        'cdek_tariff_code' => (int) $tariff['tariff_code'],
                        'cdek_delivery_mode' => (int) ($tariff['delivery_mode'] ?? 0),
                        'from_city_code' => $from['code'],
                        'to_city_code' => $to['code'],
                        'unfiltered' => true,
                    ],
                ];
            }
        }

        usort($quotes, static fn(array $a, array $b): int => $a['total_amount'] <=> $b['total_amount']);

        return array_slice($quotes, 0, 8);
    }

    public function createOrder(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('CDEK is not configured');
        }

        $deliveryOrderId = (int) ($context['delivery_order_id'] ?? 0);
        $sender = $context['sender'] ?? [];
        $recipient = $context['recipient'] ?? [];
        $shipment = $context['shipment'] ?? [];
        $service = $context['service'] ?? [];
        $orderNumber = (string) ($context['order_number'] ?? ('DO-' . $deliveryOrderId));

        $tariffCode = $this->parseTariffCode((string) ($service['service_code'] ?? ''));
        if ($tariffCode === null) {
            throw new \RuntimeException('CDEK tariff_code missing in selected quote');
        }

        $fromCity = $this->client->findCityCode((string) ($sender['city'] ?? ''), $sender['country'] ?? null);
        $toCity = $this->client->findCityCode((string) ($recipient['city'] ?? ''), $recipient['country'] ?? null);
        if ($fromCity === null || $toCity === null) {
            throw new \RuntimeException('CDEK city resolve failed for order create');
        }

        $weightKg = (float) ($shipment['billed_gross_weight'] ?? $shipment['gross_weight'] ?? $shipment['weight_value'] ?? 1);
        if ($weightKg <= 0) {
            $weightKg = 1.0;
        }
        $length = (int) max(1, round((float) ($shipment['billed_length'] ?? $shipment['package_length'] ?? 20)));
        $width = (int) max(1, round((float) ($shipment['billed_width'] ?? $shipment['package_width'] ?? 20)));
        $height = (int) max(1, round((float) ($shipment['billed_height'] ?? $shipment['package_height'] ?? 10)));

        $cfg = $this->client->config();
        $payload = [
            'type' => (int) ($cfg['order_type'] ?? 1),
            'number' => $orderNumber,
            'tariff_code' => $tariffCode,
            'comment' => 'Zakapeiku delivery #' . $deliveryOrderId,
            'sender' => [
                'name' => (string) ($sender['name'] ?? 'Sender'),
                'phones' => [['number' => $this->normalizePhone((string) ($sender['phone'] ?? ''))]],
            ],
            'recipient' => [
                'name' => (string) ($recipient['name'] ?? 'Recipient'),
                'phones' => [['number' => $this->normalizePhone((string) ($recipient['phone'] ?? ''))]],
            ],
            'from_location' => [
                'code' => $fromCity['code'],
                'address' => $this->formatAddress($sender),
            ],
            'to_location' => [
                'code' => $toCity['code'],
                'address' => $this->formatAddress($recipient),
            ],
            'packages' => [[
                'number' => '1',
                'weight' => (int) max(1, round($weightKg * 1000)),
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'items' => [[
                    'name' => mb_substr((string) ($shipment['product_title'] ?? 'Товар'), 0, 255),
                    'ware_key' => 'item-' . $deliveryOrderId,
                    'payment' => ['value' => 0],
                    'cost' => 0,
                    'weight' => (int) max(1, round($weightKg * 1000)),
                    'amount' => 1,
                ]],
            ]],
        ];

        if (!empty($sender['email'])) {
            $payload['sender']['email'] = (string) $sender['email'];
        }
        if (!empty($recipient['email'])) {
            $payload['recipient']['email'] = (string) $recipient['email'];
        }

        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $pvz = trim((string) ($recipient['pvz_code'] ?? ''));
        if (in_array($mode, ['pvz', 'pickup_point'], true) && $pvz !== '') {
            $payload['delivery_point'] = $pvz;
            unset($payload['to_location']['address']);
        }

        $res = $this->client->post('/orders', $payload);
        $this->logApi(
            $deliveryOrderId,
            '/orders',
            (int) ($res['code'] ?? 0),
            hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            $res['ok'] ? null : ($res['error'] ?? 'order create failed'),
            $res['ok'] ? hash('sha256', (string) ($res['body'] ?? '')) : null
        );

        if (!$res['ok']) {
            throw new \RuntimeException($res['error'] ?? 'CDEK order create failed');
        }

        $entity = $res['data']['entity'] ?? [];
        $uuid = (string) ($entity['uuid'] ?? '');
        $cdekNumber = isset($entity['cdek_number']) ? (string) $entity['cdek_number'] : null;

        if ($uuid === '') {
            throw new \RuntimeException('CDEK order response without uuid');
        }

        return [
            'logistics_order_id' => $uuid,
            'tracking_number' => $cdekNumber,
        ];
    }

    public function handleStatusWebhook(array $payload): ?array
    {
        // Формат СДЭК ORDER_STATUS
        $type = (string) ($payload['type'] ?? '');
        $attrs = $payload['attributes'] ?? ($payload['payload'] ?? []);
        if (!is_array($attrs)) {
            $attrs = [];
        }

        if ($type === '' && empty($attrs) && empty($payload['uuid'])) {
            return null;
        }

        $statusCode = (string) ($attrs['code'] ?? $attrs['status_code'] ?? $payload['status'] ?? '');
        $ourNumber = (string) ($attrs['number'] ?? $payload['number'] ?? '');
        $uuid = (string) ($payload['uuid'] ?? $attrs['uuid'] ?? '');
        $cdekNumber = (string) ($attrs['cdek_number'] ?? $payload['cdek_number'] ?? '');

        $mapped = $this->mapCdekStatus($statusCode);
        if ($mapped === null && $statusCode === '') {
            return null;
        }

        $deliveryOrderId = 0;
        if ($ourNumber !== '' && preg_match('/DO-/i', $ourNumber)) {
            $orders = $this->orders ?? new DeliveryOrder();
            $found = $orders->findByOrderNumber($ourNumber);
            if ($found) {
                $deliveryOrderId = (int) $found['id'];
            }
        }
        if ($deliveryOrderId <= 0 && $uuid !== '') {
            $orders = $this->orders ?? new DeliveryOrder();
            $found = $orders->findByLogisticsOrderId($uuid);
            if ($found) {
                $deliveryOrderId = (int) $found['id'];
            }
        }
        if ($deliveryOrderId <= 0 && !empty($payload['delivery_order_id'])) {
            $deliveryOrderId = (int) $payload['delivery_order_id'];
        }

        if ($deliveryOrderId <= 0) {
            return null;
        }

        return [
            'delivery_order_id' => $deliveryOrderId,
            'status' => $mapped ?? $statusCode,
            'tracking_number' => $cdekNumber !== '' ? $cdekNumber : null,
            'message' => $attrs['status_reason_code'] ?? $attrs['city'] ?? null,
            'location' => $attrs['city'] ?? null,
        ];
    }

    private function modeMatches(string $ourMode, int $cdekMode): bool
    {
        // 1 door-door, 2 door-warehouse, 3 warehouse-door, 4 warehouse-warehouse, 6/7 postamat
        if (in_array($ourMode, ['pvz', 'pickup_point'], true)) {
            return in_array($cdekMode, [2, 4, 6, 7], true);
        }
        // courier / default — до двери
        return in_array($cdekMode, [1, 3], true);
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
        $line = implode(', ', $parts);
        return $line !== '' ? $line : (string) ($addr['city'] ?? '');
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($digits === '') {
            return '+70000000000';
        }
        if ($digits[0] !== '+' && !str_starts_with($phone, '+')) {
            return '+' . $digits;
        }
        return str_starts_with($phone, '+') ? ('+' . $digits) : $digits;
    }

    private function mapCdekStatus(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return match ($code) {
            'ACCEPTED', 'CREATED', 'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'READY_FOR_SHIPMENT_IN_SENDER_CITY' => 'ACCEPTED',
            'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY', 'SENT_TO_RECIPIENT_CITY',
            'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE', 'TAKEN_BY_COURIER',
            'RECEIVED_AT_SENDER_WAREHOUSE' => 'IN_TRANSIT',
            'DELIVERED' => 'DELIVERED',
            'NOT_DELIVERED', 'INVALID' => 'EXCEPTION',
            default => $code !== '' ? null : null,
        };
    }

    private function logApi(
        int $deliveryOrderId,
        string $endpoint,
        int $responseCode,
        ?string $requestHash,
        ?string $error,
        ?string $responseHash = null
    ): void {
        try {
            $orders = $this->orders ?? new DeliveryOrder();
            $providerId = $orders->providerIdByCode('cdek');
            $orders->logApiCall(
                $deliveryOrderId > 0 ? $deliveryOrderId : null,
                $providerId,
                $endpoint,
                $responseCode,
                $requestHash,
                $responseHash,
                $error
            );
        } catch (\Throwable $e) {
            // logging must not break quotes/orders
        }
    }
}
