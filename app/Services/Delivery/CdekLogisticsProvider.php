<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Services\Cdek\CdekCalculatorRequestBuilder;
use App\Services\Cdek\CdekCalculatorResponseMapper;
use App\Services\Cdek\CdekErrorMapper;
use App\Services\Cdek\CdekOrderService;
use App\Services\Cdek\CdekQuoteReuseService;
use App\Services\Cdek\Client as CdekClient;

/**
 * Провайдер доставки СДЭК (API v2).
 *
 * Marketplace-адаптер: переводит delivery context ↔ CDEK через
 * CdekCalculatorRequestBuilder / CdekCalculatorResponseMapper.
 * HTTP остаётся в Cdek\Client.
 *
 * @see https://apidoc.cdek.ru/#tag/common/Vvedenie
 * @see openapi_api_v2_integration.json
 */
class CdekLogisticsProvider implements LogisticsProviderInterface
{
    private CdekClient $client;
    /** @var DeliveryOrder|object|null */
    private ?object $orders;
    private CdekCalculatorRequestBuilder $requestBuilder;
    private CdekCalculatorResponseMapper $responseMapper;
    private CdekErrorMapper $errorMapper;
    private CdekOrderService $orderService;

    public function __construct(
        ?CdekClient $client = null,
        ?object $orders = null,
        ?CdekCalculatorRequestBuilder $requestBuilder = null,
        ?CdekCalculatorResponseMapper $responseMapper = null,
        ?CdekErrorMapper $errorMapper = null,
        ?CdekOrderService $orderService = null
    ) {
        $this->client = $client ?? new CdekClient();
        $this->orders = $orders;
        $this->errorMapper = $errorMapper ?? new CdekErrorMapper();
        $this->requestBuilder = $requestBuilder ?? new CdekCalculatorRequestBuilder($this->errorMapper);
        $this->responseMapper = $responseMapper ?? new CdekCalculatorResponseMapper($this->errorMapper);
        $this->orderService = $orderService ?? new CdekOrderService($this->client, null, $this->errorMapper);
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
        $hasShipmentPoint = trim((string) ($sender['shipment_point'] ?? $sender['pvz_code'] ?? '')) !== '';
        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $hasDeliveryPoint = in_array($mode, ['pvz', 'pickup_point'], true)
            && trim((string) ($recipient['pvz_code'] ?? $recipient['delivery_point'] ?? '')) !== '';

        // Города нужны только если origin/destination идут через location, а не point.
        $from = null;
        $to = null;
        if (!$hasShipmentPoint) {
            if ($fromCity === '') {
                $this->logApi($deliveryOrderId, '/calculator/tarifflist', 0, null, 'missing origin city', null, null);
                return [];
            }
            $from = $this->client->findCityCode($fromCity, $sender['country'] ?? null);
            if ($from === null) {
                $this->logApi($deliveryOrderId, '/location/cities', 0, null, 'city not found: ' . $fromCity, null, null);
                return [];
            }
        }
        if (!$hasDeliveryPoint) {
            if ($toCity === '') {
                $this->logApi($deliveryOrderId, '/calculator/tarifflist', 0, null, 'missing destination city', null, null);
                return [];
            }
            $to = $this->client->findCityCode($toCity, $recipient['country'] ?? null);
            if ($to === null) {
                $this->logApi($deliveryOrderId, '/location/cities', 0, null, 'city not found: ' . $toCity, null, null);
                return [];
            }
        }

        $cfg = $this->client->config();
        $built = $this->requestBuilder->buildFromDeliveryContext(
            $sender,
            $recipient,
            $shipment,
            [
                'type' => (int) ($cfg['order_type'] ?? 1),
                'currency' => (int) ($cfg['currency'] ?? 2),
                'lang' => (string) ($cfg['lang'] ?? 'rus'),
            ],
            [
                'from_city_code' => $from['code'] ?? null,
                'to_city_code' => $to['code'] ?? null,
            ]
        );

        if (!$built['ok']) {
            $err = $built['error']['message'] ?? 'calculator request build failed';
            $this->logApi($deliveryOrderId, '/calculator/tarifflist', 0, null, $err, null, null);
            return [];
        }

        $payload = $built['payload'];
        $requestHash = $built['request_hash'];
        $routeHash = (string) ($built['route_hash'] ?? '');
        $packageHash = (string) ($built['package_hash'] ?? '');
        $shippingVersion = (int) ($context['shipping_version'] ?? 0);

        // §21: reuse свежего quote с тем же request_hash + shipping_version.
        if ($deliveryOrderId > 0 && $requestHash !== '' && $shippingVersion > 0 && $this->orders !== null) {
            $cachedRows = $this->orders->findReusableQuotes($deliveryOrderId, $requestHash, $shippingVersion);
            $reuse = (new CdekQuoteReuseService())->tryReuse(
                $cachedRows,
                $requestHash,
                $shippingVersion,
                $routeHash !== '' ? $routeHash : null,
                $packageHash !== '' ? $packageHash : null
            );
            if ($reuse['ok'] && !empty($reuse['quotes'])) {
                $this->logApi(
                    $deliveryOrderId,
                    '/calculator/tarifflist',
                    200,
                    $requestHash,
                    null,
                    'reuse:' . count($reuse['quotes']),
                    'local-reuse',
                    0
                );
                return $reuse['quotes'];
            }
        }

        $res = $this->client->post('/calculator/tarifflist', $payload);
        $this->logApi(
            $deliveryOrderId,
            '/calculator/tarifflist',
            (int) ($res['code'] ?? 0),
            $requestHash,
            $res['ok'] ? null : ($res['error'] ?? 'tarifflist failed'),
            $res['ok'] ? hash('sha256', (string) ($res['body'] ?? '')) : null,
            $res['request_id'] ?? null,
            $res['duration_ms'] ?? null
        );

        if (!$res['ok']) {
            return [];
        }

        $packagingAmount = (int) ($context['packaging_price'] ?? 0);
        $handling = !empty($shipment['is_irregular']) ? 500 : 0;
        $fragile = !empty($shipment['is_fragile']) ? 300 : 0;

        $weightKg = $shipment['billed_gross_weight']
            ?? $shipment['gross_weight']
            ?? $shipment['weight_value']
            ?? null;

        $mapped = $this->responseMapper->map(
            is_array($res['data'] ?? null) ? $res['data'] : null,
            [
                'packaging_amount' => $packagingAmount,
                'handling_amount' => $handling,
                'fragile_amount' => $fragile,
                'currency_label' => (string) ($cfg['currency_label'] ?? 'KZT'),
                'request_hash' => $requestHash,
                'route_hash' => $routeHash,
                'package_hash' => $packageHash,
                'quote_ttl_seconds' => 7200,
                'delivery_mode_filter' => $mode,
                'billable_weight_kg' => $weightKg,
                'meta' => [
                    'from_city_code' => $from['code'] ?? null,
                    'to_city_code' => $to['code'] ?? null,
                    'cdek_request_id' => $res['request_id'] ?? null,
                ],
            ]
        );

        if (!$mapped['ok']) {
            $this->logApi(
                $deliveryOrderId,
                '/calculator/tarifflist',
                (int) ($res['code'] ?? 200),
                $requestHash,
                $mapped['error']['message'] ?? 'mapper failed',
                null,
                $res['request_id'] ?? null
            );
            return [];
        }

        // Unfiltered fallback удалён: нельзя предлагать door-тариф для PVZ (и наоборот).
        return $mapped['quotes'];
    }

    public function createOrder(array $context): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('CDEK is not configured');
        }

        $deliveryOrderId = (int) ($context['delivery_order_id'] ?? 0);
        $sender = is_array($context['sender'] ?? null) ? $context['sender'] : [];
        $recipient = is_array($context['recipient'] ?? null) ? $context['recipient'] : [];

        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $hasDeliveryPoint = in_array($mode, ['pvz', 'pickup_point'], true)
            && trim((string) ($recipient['pvz_code'] ?? $recipient['delivery_point'] ?? '')) !== '';
        $hasShipmentPoint = trim((string) ($sender['shipment_point'] ?? '')) !== '';

        $fromCityCode = null;
        $toCityCode = null;

        if (!$hasShipmentPoint) {
            $fromCity = $this->client->findCityCode((string) ($sender['city'] ?? ''), $sender['country'] ?? null);
            if ($fromCity === null) {
                throw new \RuntimeException('CDEK city resolve failed for sender');
            }
            $fromCityCode = (int) $fromCity['code'];
        }

        if (!$hasDeliveryPoint) {
            $toCity = $this->client->findCityCode((string) ($recipient['city'] ?? ''), $recipient['country'] ?? null);
            if ($toCity === null) {
                throw new \RuntimeException('CDEK city resolve failed for recipient');
            }
            $toCityCode = (int) $toCity['code'];
        }

        $cfg = $this->client->config();
        $result = $this->orderService->create($context, [
            'from_city_code' => $fromCityCode,
            'to_city_code' => $toCityCode,
            'type' => (int) ($cfg['order_type'] ?? 1),
            'existing_uuid' => $context['identifiers']['logistics_order_id'] ?? null,
        ]);

        $this->logApi(
            $deliveryOrderId,
            '/orders',
            $result['ok'] ? 202 : 0,
            $result['payload_hash'] ?? null,
            $result['ok'] ? null : ($result['error'] ?? 'order create failed'),
            null,
            $result['request_id'] ?? null
        );

        if (!$result['ok']) {
            $msg = $result['error_mapped']['message'] ?? ($result['error'] ?? 'CDEK order create failed');
            throw new \RuntimeException($msg);
        }

        return [
            'logistics_order_id' => (string) $result['logistics_order_id'],
            'tracking_number' => $result['tracking_number'] ?? null,
            'already_existed' => !empty($result['already_existed']),
        ];
    }

    public function handleStatusWebhook(array $payload): ?array
    {
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
        // ORDER_STATUS: payload.uuid = event uuid; attributes.uuid = CDEK order uuid.
        $orderUuid = (string) ($attrs['uuid'] ?? $attrs['order_uuid'] ?? '');
        $cdekNumber = (string) ($attrs['cdek_number'] ?? $payload['cdek_number'] ?? '');

        $mapped = $this->mapCdekStatus($statusCode);
        if ($mapped === null && $statusCode === '') {
            return null;
        }

        $orders = $this->orders ?? new DeliveryOrder();
        $deliveryOrderId = 0;

        // Resolve только по im_number / CDEK uuid / tracking — НЕ по payload.delivery_order_id (IDOR).
        if ($ourNumber !== '') {
            $found = $orders->findByOrderNumber($ourNumber);
            if ($found) {
                $deliveryOrderId = (int) $found['id'];
            }
        }
        if ($deliveryOrderId <= 0 && $orderUuid !== '') {
            $found = $orders->findByLogisticsOrderId($orderUuid);
            if ($found) {
                $deliveryOrderId = (int) $found['id'];
            }
        }
        if ($deliveryOrderId <= 0 && $cdekNumber !== '') {
            $found = $orders->findByTrackingNumber($cdekNumber);
            if ($found) {
                $deliveryOrderId = (int) $found['id'];
            }
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

    private function mapCdekStatus(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return match ($code) {
            'ACCEPTED', 'CREATED', 'RECEIVED_AT_SHIPMENT_WAREHOUSE',
            'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'READY_FOR_SHIPMENT_IN_TRANSIT_CITY',
            'PASSED_TO_CARRIER_AT_SENDER_CITY' => 'ACCEPTED',
            'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY', 'SENT_TO_RECIPIENT_CITY',
            'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE', 'ACCEPTED_AT_TRANSIT_WAREHOUSE',
            'TAKEN_BY_COURIER', 'RECEIVED_AT_SENDER_WAREHOUSE',
            'RETURNED_TO_SENDER_CITY' => 'IN_TRANSIT',
            'READY_FOR_PICKUP', 'ACCEPTED_AT_PICK_UP_POINT',
            'POSTOMAT_POSTED', 'POSTOMAT_SEIZED' => 'READY_FOR_PICKUP',
            'DELIVERED', 'POSTOMAT_RECEIVED' => 'DELIVERED',
            'NOT_DELIVERED', 'INVALID', 'REMOVED_FROM_PICKUP_POINT' => 'EXCEPTION',
            default => null,
        };
    }

    private function logApi(
        int $deliveryOrderId,
        string $endpoint,
        int $responseCode,
        ?string $requestHash,
        ?string $error,
        ?string $responseHash = null,
        ?string $requestId = null,
        ?int $durationMs = null
    ): void {
        try {
            $orders = $this->orders ?? new DeliveryOrder();
            $providerId = $orders->providerIdByCode('cdek');
            $msg = $error;
            if ($requestId !== null && $requestId !== '') {
                $suffix = ' rid=' . $requestId;
                if ($durationMs !== null) {
                    $suffix .= ' ' . $durationMs . 'ms';
                }
                $msg = ($msg !== null && $msg !== '' ? $msg . ' |' : '') . $suffix;
            }
            $orders->logApiCall(
                $deliveryOrderId > 0 ? $deliveryOrderId : null,
                $providerId,
                $endpoint,
                $responseCode,
                $requestHash,
                $responseHash,
                $msg
            );
        } catch (\Throwable $e) {
            // logging must not break quotes/orders
        }
    }
}
