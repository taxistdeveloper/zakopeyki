<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Services\Cdek\CdekCalculatorRequestBuilder;
use App\Services\Cdek\CdekCalculatorResponseMapper;
use App\Services\Cdek\CdekCalculatorService;
use App\Services\Cdek\CdekErrorMapper;
use App\Services\Cdek\CdekOrderService;
use App\Services\Cdek\CdekQuoteReuseService;
use App\Services\Cdek\Client as CdekClient;

/**
 * Провайдер доставки СДЭК (API v2).
 *
 * Расчёт тарифов делегирован в CdekCalculatorService (tarifflist).
 * HTTP остаётся в Cdek\Client / CdekApi.
 *
 * @see docs/cdek-calculator.md
 */
class CdekLogisticsProvider implements LogisticsProviderInterface
{
    private CdekClient $client;
    /** @var DeliveryOrder|object|null */
    private ?object $orders;
    private CdekCalculatorService $calculator;
    private CdekErrorMapper $errorMapper;
    private CdekOrderService $orderService;
    /** @var array<string, mixed>|null last calculate() error for DeliveryService */
    private ?array $lastError = null;

    public function __construct(
        ?CdekClient $client = null,
        ?object $orders = null,
        ?CdekCalculatorService $calculator = null,
        ?CdekErrorMapper $errorMapper = null,
        ?CdekOrderService $orderService = null,
        // BC: старые DI-аргументы requestBuilder/responseMapper игнорируются
        ?CdekCalculatorRequestBuilder $requestBuilder = null,
        ?CdekCalculatorResponseMapper $responseMapper = null
    ) {
        $this->client = $client ?? new CdekClient();
        $this->orders = $orders;
        $this->errorMapper = $errorMapper ?? new CdekErrorMapper();
        $this->calculator = $calculator ?? new CdekCalculatorService($this->client, null, $requestBuilder, $responseMapper, $this->errorMapper);
        $this->orderService = $orderService ?? new CdekOrderService($this->client, null, $this->errorMapper);
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /** @return array<string, mixed>|null */
    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    public function getQuotes(array $context): array
    {
        $this->lastError = null;

        if (!$this->isConfigured()) {
            $this->lastError = [
                'error_code' => 'cdek_not_configured',
                'error' => t('delivery.quote_failed'),
            ];
            return [];
        }

        $deliveryOrderId = (int) ($context['delivery_order_id'] ?? 0);

        $reuseQuotes = $this->tryReuseBeforeCall($context);
        if ($reuseQuotes !== null) {
            return $reuseQuotes;
        }

        $result = $this->calculator->calculate($context);
        if (!$result['ok']) {
            $this->lastError = [
                'error_code' => $result['error_code'] ?? 'quote_failed',
                'error' => $result['error'] ?? t('delivery.quote_failed'),
                'http_status' => $result['http_status'] ?? null,
                'cdek_error' => $result['cdek_error'] ?? null,
            ];
            $this->logApi(
                $deliveryOrderId,
                CdekCalculatorService::ENDPOINT,
                (int) ($result['http_status'] ?? 0),
                $result['request_hash'] ?? null,
                $this->lastError['error'],
                null,
                null
            );
            return [];
        }

        $this->logApi(
            $deliveryOrderId,
            CdekCalculatorService::ENDPOINT,
            (int) ($result['http_status'] ?? 200),
            $result['request_hash'] ?? null,
            null,
            hash('sha256', (string) json_encode($result['quotes'] ?? [])),
            null
        );

        return $result['quotes'] ?? [];
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>|null
     */
    private function tryReuseBeforeCall(array $context): ?array
    {
        $deliveryOrderId = (int) ($context['delivery_order_id'] ?? 0);
        $shippingVersion = (int) ($context['shipping_version'] ?? 0);
        if ($deliveryOrderId <= 0 || $shippingVersion <= 0 || $this->orders === null) {
            return null;
        }

        $sender = is_array($context['sender'] ?? null) ? $context['sender'] : [];
        $recipient = is_array($context['recipient'] ?? null) ? $context['recipient'] : [];
        $shipment = is_array($context['shipment'] ?? null) ? $context['shipment'] : [];
        $cfg = $this->client->config();

        $builder = new CdekCalculatorRequestBuilder($this->errorMapper);
        $fromCode = (int) ($sender['cdek_city_code'] ?? 0) ?: null;
        $toCode = (int) ($recipient['cdek_city_code'] ?? 0) ?: null;
        $built = $builder->buildFromDeliveryContext(
            $sender,
            $recipient,
            $shipment,
            [
                'type' => (int) ($cfg['order_type'] ?? 1),
                'currency' => (int) ($cfg['currency'] ?? 2),
                'lang' => (string) ($cfg['lang'] ?? 'rus'),
            ],
            [
                'from_city_code' => $fromCode,
                'to_city_code' => $toCode,
            ]
        );
        if (!$built['ok']) {
            return null;
        }

        $requestHash = $built['request_hash'];
        $cachedRows = $this->orders->findReusableQuotes($deliveryOrderId, $requestHash, $shippingVersion);
        $reuse = (new CdekQuoteReuseService())->tryReuse(
            $cachedRows,
            $requestHash,
            $shippingVersion,
            $built['route_hash'] ?? null,
            $built['package_hash'] ?? null
        );
        if ($reuse['ok'] && !empty($reuse['quotes'])) {
            $this->logApi(
                $deliveryOrderId,
                CdekCalculatorService::ENDPOINT,
                200,
                $requestHash,
                null,
                'reuse:' . count($reuse['quotes']),
                'local-reuse',
                0
            );
            return $reuse['quotes'];
        }

        return null;
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
            $fromCityCode = (int) ($sender['cdek_city_code'] ?? 0) ?: null;
            if ($fromCityCode === null) {
                $fromCity = $this->client->findCityCode((string) ($sender['city'] ?? ''), $sender['country'] ?? null);
                if ($fromCity === null) {
                    throw new \RuntimeException('CDEK city resolve failed for sender');
                }
                $fromCityCode = (int) $fromCity['code'];
            }
        }

        if (!$hasDeliveryPoint) {
            $toCityCode = (int) ($recipient['cdek_city_code'] ?? 0) ?: null;
            if ($toCityCode === null) {
                $toCity = $this->client->findCityCode((string) ($recipient['city'] ?? ''), $recipient['country'] ?? null);
                if ($toCity === null) {
                    throw new \RuntimeException('CDEK city resolve failed for recipient');
                }
                $toCityCode = (int) $toCity['code'];
            }
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
        $orderUuid = (string) ($attrs['uuid'] ?? $attrs['order_uuid'] ?? '');
        $cdekNumber = (string) ($attrs['cdek_number'] ?? $payload['cdek_number'] ?? '');

        $mapped = $this->mapCdekStatus($statusCode);
        if ($mapped === null && $statusCode === '') {
            return null;
        }

        $orders = $this->orders ?? new DeliveryOrder();
        $deliveryOrderId = 0;

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
