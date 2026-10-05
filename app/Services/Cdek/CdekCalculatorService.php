<?php

namespace App\Services\Cdek;

use App\Services\Delivery\DeliveryPointValidator;

/**
 * Серверный расчёт стоимости CDEK (Phase 6).
 *
 * Endpoint: POST /v2/calculator/tarifflist (см. docs/cdek-calculator.md).
 * Frontend НЕ передаёт CalculatorRequestDto и НЕ передаёт сумму.
 *
 * @see CdekCalculatorRequestBuilder
 * @see CdekCalculatorResponseMapper
 */
class CdekCalculatorService
{
    public const ENDPOINT = '/calculator/tarifflist';
    /** Внутренний TTL quote: CDEK OpenAPI не задаёт срок действия результата. */
    public const DEFAULT_QUOTE_TTL_SECONDS = 7200;

    private Client $client;
    private CdekApi $api;
    private CdekCalculatorRequestBuilder $requestBuilder;
    private CdekCalculatorResponseMapper $responseMapper;
    private CdekErrorMapper $errors;
    private DeliveryPointValidator $validator;

    public function __construct(
        ?Client $client = null,
        ?CdekApi $api = null,
        ?CdekCalculatorRequestBuilder $requestBuilder = null,
        ?CdekCalculatorResponseMapper $responseMapper = null,
        ?CdekErrorMapper $errors = null,
        ?DeliveryPointValidator $validator = null
    ) {
        $this->client = $client ?? new Client();
        $this->api = $api ?? new CdekApi($this->client);
        $this->errors = $errors ?? new CdekErrorMapper();
        $this->requestBuilder = $requestBuilder ?? new CdekCalculatorRequestBuilder($this->errors);
        $this->responseMapper = $responseMapper ?? new CdekCalculatorResponseMapper($this->errors);
        $this->validator = $validator ?? new DeliveryPointValidator();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Предпроверки до вызова CDEK (без HTTP).
     *
     * @param array<string, mixed> $row delivery_orders findWithDetails
     * @return array{ok: true}|array{ok: false, error_code: string, error: string, field?: string}
     */
    public function validateReady(array $row): array
    {
        if (empty($row['id'])) {
            return $this->failLocal('delivery_not_found', 'delivery.not_found');
        }

        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId <= 0 && empty($row['order_id'])) {
            return $this->failLocal('product_missing', 'delivery.data_incomplete', 'product_id');
        }

        $sender = is_array($row['sender'] ?? null) ? $row['sender'] : null;
        if ($sender === null || $sender === []) {
            return $this->failLocal('point_a_missing', 'delivery.missing_sender', 'sender');
        }

        $recipient = is_array($row['recipient'] ?? null) ? $row['recipient'] : null;
        if ($recipient === null || $recipient === []) {
            return $this->failLocal('point_b_missing', 'delivery.missing_recipient', 'recipient');
        }

        $shipment = is_array($row['shipment'] ?? null) ? $row['shipment'] : null;
        if ($shipment === null || $shipment === []) {
            return $this->failLocal('shipment_missing', 'delivery.missing_shipment', 'shipment');
        }

        $weight = (float) ($shipment['billed_gross_weight']
            ?? $shipment['gross_weight']
            ?? $shipment['weight_value']
            ?? $shipment['item_weight']
            ?? 0);
        $hasPackaging = !empty($shipment['packaging_id']) && !empty($shipment['dimensions_unknown']);
        if ($weight <= 0 && !$hasPackaging) {
            return $this->failLocal('weight_missing', 'delivery.weight_required', 'weight');
        }

        $hasDims = ($shipment['billed_length'] ?? $shipment['package_length'] ?? $shipment['length_value'] ?? null) !== null
            || ($shipment['billed_width'] ?? $shipment['package_width'] ?? $shipment['width_value'] ?? null) !== null
            || ($shipment['billed_height'] ?? $shipment['package_height'] ?? $shipment['height_value'] ?? null) !== null
            || $hasPackaging;
        if (!$hasDims && $weight > 0) {
            // Габариты optional в OpenAPI CalcPackageRequestDto, но бизнес требует для точного тарифа.
            // Не блокируем жёстко если есть вес — CDEK может считать по весу. GAP: dims preferred.
        }

        $mode = (string) ($recipient['delivery_mode'] ?? '');
        if (!in_array($mode, [
            DeliveryPointValidator::MODE_COURIER,
            DeliveryPointValidator::MODE_PVZ,
            'pickup_point',
        ], true)) {
            return $this->failLocal('delivery_mode_invalid', 'delivery.quote_failed', 'delivery_mode');
        }

        $pointB = $this->validator->validatePointB($recipient, true);
        if (!$pointB['ok']) {
            return $this->failLocal(
                (string) ($pointB['error'] ?? 'point_b_invalid'),
                'delivery.recipient_required',
                (string) ($pointB['field'] ?? 'recipient')
            );
        }

        $originType = (string) ($sender['origin_type'] ?? 'door');
        $shipmentPoint = trim((string) ($sender['shipment_point'] ?? $sender['pvz_code'] ?? ''));
        $fromCode = (int) ($sender['cdek_city_code'] ?? 0);
        if ($originType === 'pvz' || $shipmentPoint !== '') {
            if ($shipmentPoint === '') {
                return $this->failLocal('point_a_pvz_missing', 'delivery.pvz_required', 'shipment_point');
            }
        } elseif ($fromCode <= 0 && trim((string) ($sender['city'] ?? '')) === '') {
            return $this->failLocal('point_a_city_missing', 'delivery.sender_required', 'city');
        }

        if (in_array($mode, [DeliveryPointValidator::MODE_PVZ, 'pickup_point'], true)) {
            $pvz = trim((string) ($recipient['pvz_code'] ?? $recipient['delivery_point'] ?? ''));
            if ($pvz === '') {
                return $this->failLocal('pvz_required', 'delivery.pvz_required', 'pvz_code');
            }
        } else {
            $toCode = (int) ($recipient['cdek_city_code'] ?? 0);
            if ($toCode <= 0 && trim((string) ($recipient['city'] ?? '')) === '') {
                return $this->failLocal('point_b_city_missing', 'delivery.recipient_required', 'city');
            }
        }

        return ['ok' => true];
    }

    /**
     * Расчёт по delivery context (без сохранения quote).
     *
     * @param array{
     *   sender: array<string, mixed>,
     *   recipient: array<string, mixed>,
     *   shipment: array<string, mixed>,
     *   packaging_price?: int,
     *   shipping_version?: int,
     *   delivery_order_id?: int,
     *   quote_ttl_seconds?: int
     * } $context
     * @return array{
     *   ok: bool,
     *   quotes?: list<array<string, mixed>>,
     *   request_hash?: string,
     *   route_hash?: string,
     *   package_hash?: string,
     *   payload?: array<string, mixed>,
     *   error?: string,
     *   error_code?: string,
     *   http_status?: int|null,
     *   cdek_error?: array|null
     * }
     */
    public function calculate(array $context): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'error_code' => 'cdek_not_configured',
                'error' => t('delivery.quote_failed'),
                'http_status' => null,
            ];
        }

        $sender = is_array($context['sender'] ?? null) ? $context['sender'] : [];
        $recipient = is_array($context['recipient'] ?? null) ? $context['recipient'] : [];
        $shipment = is_array($context['shipment'] ?? null) ? $context['shipment'] : [];

        $ready = $this->validateReady([
            'id' => (int) ($context['delivery_order_id'] ?? 1),
            'product_id' => (int) ($context['product_id'] ?? 1),
            'order_id' => (int) ($context['order_id'] ?? 1),
            'sender' => $sender,
            'recipient' => $recipient,
            'shipment' => $shipment,
        ]);
        if (!$ready['ok']) {
            return $ready;
        }

        $resolved = $this->resolveCityCodes($sender, $recipient);
        if (!$resolved['ok']) {
            return $resolved;
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
                'from_city_code' => $resolved['from_city_code'],
                'to_city_code' => $resolved['to_city_code'],
            ]
        );

        if (!$built['ok']) {
            return [
                'ok' => false,
                'error_code' => (string) ($built['error']['internal_code'] ?? 'build_failed'),
                'error' => $this->userMessage((string) ($built['error']['internal_code'] ?? ''), (string) ($built['error']['message'] ?? '')),
                'cdek_error' => $built['error'] ?? null,
                'http_status' => null,
            ];
        }

        $payload = $built['payload'];
        $apiRes = $this->api->calculateTariffList($payload);
        $http = $apiRes->httpStatus;

        if (!$apiRes->ok) {
            $mapped = $apiRes->errorMapped ?? $this->errors->map(
                is_array($apiRes->data) ? $apiRes->data : null,
                $http,
                $apiRes->message
            );
            $internal = (string) ($mapped['internal_code'] ?? CdekErrorMapper::INTERNAL_UNKNOWN);
            return [
                'ok' => false,
                'error_code' => $internal,
                'error' => $this->userMessage($internal, (string) ($mapped['message'] ?? $apiRes->message ?? '')),
                'http_status' => $http,
                'cdek_error' => $mapped,
                'request_hash' => $built['request_hash'],
                'payload' => $payload,
            ];
        }

        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $packaging = (int) ($context['packaging_price'] ?? 0);
        $handling = !empty($shipment['is_irregular']) ? 500 : 0;
        $fragile = !empty($shipment['is_fragile']) ? 300 : 0;
        $ttl = max(60, (int) ($context['quote_ttl_seconds'] ?? ($cfg['quote_ttl_seconds'] ?? self::DEFAULT_QUOTE_TTL_SECONDS)));

        $mapped = $this->responseMapper->map(
            is_array($apiRes->data) ? $apiRes->data : null,
            [
                'packaging_amount' => $packaging,
                'handling_amount' => $handling,
                'fragile_amount' => $fragile,
                'currency_label' => (string) ($cfg['currency_label'] ?? 'KZT'),
                'request_hash' => $built['request_hash'],
                'route_hash' => $built['route_hash'] ?? '',
                'package_hash' => $built['package_hash'] ?? '',
                'quote_ttl_seconds' => $ttl,
                'delivery_mode_filter' => $mode,
                'billable_weight_kg' => $shipment['billed_gross_weight']
                    ?? $shipment['gross_weight']
                    ?? $shipment['weight_value']
                    ?? null,
                'meta' => [
                    'from_city_code' => $resolved['from_city_code'],
                    'to_city_code' => $resolved['to_city_code'],
                    'cdek_request_id' => $apiRes->requestId,
                    'calculator_endpoint' => self::ENDPOINT,
                    'calculated_at' => date('c'),
                ],
            ]
        );

        if (!$mapped['ok']) {
            $internal = (string) ($mapped['error']['internal_code'] ?? CdekErrorMapper::INTERNAL_TARIFF);
            return [
                'ok' => false,
                'error_code' => $internal,
                'error' => $this->userMessage($internal, (string) ($mapped['error']['message'] ?? '')),
                'http_status' => $http,
                'cdek_error' => $mapped['error'] ?? null,
                'request_hash' => $built['request_hash'],
                'payload' => $payload,
            ];
        }

        $quotes = [];
        foreach ($mapped['quotes'] as $q) {
            $cdekSum = (int) ($q['base_amount'] ?? $q['price'] ?? 0);
            $total = (int) ($q['total_amount'] ?? 0);
            $q['cdek_delivery_sum'] = $cdekSum;
            $q['delivery_amount_to_pay'] = $total; // 100% к оплате покупателем (следующий этап)
            $q['quote_status'] = 'active';
            $quotes[] = $q;
        }

        return [
            'ok' => true,
            'quotes' => $quotes,
            'request_hash' => $built['request_hash'],
            'route_hash' => $built['route_hash'] ?? null,
            'package_hash' => $built['package_hash'] ?? null,
            'payload' => $payload,
            'http_status' => $http,
            'warnings' => $mapped['warnings'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $sender
     * @param array<string, mixed> $recipient
     * @return array{ok: true, from_city_code: int|null, to_city_code: int|null}|array{ok: false, error_code: string, error: string}
     */
    private function resolveCityCodes(array $sender, array $recipient): array
    {
        $hasShipmentPoint = trim((string) ($sender['shipment_point'] ?? $sender['pvz_code'] ?? '')) !== '';
        $mode = (string) ($recipient['delivery_mode'] ?? 'courier');
        $hasDeliveryPoint = in_array($mode, ['pvz', 'pickup_point'], true)
            && trim((string) ($recipient['pvz_code'] ?? $recipient['delivery_point'] ?? '')) !== '';

        $fromCode = null;
        $toCode = null;

        if (!$hasShipmentPoint) {
            $fromCode = (int) ($sender['cdek_city_code'] ?? 0) ?: null;
            if ($fromCode === null) {
                $city = trim((string) ($sender['city'] ?? ''));
                if ($city === '') {
                    return $this->failLocal('point_a_city_missing', 'delivery.sender_required', 'city');
                }
                $found = $this->client->findCityCode($city, $sender['country'] ?? null);
                if ($found === null) {
                    return [
                        'ok' => false,
                        'error_code' => CdekErrorMapper::INTERNAL_CITY_NOT_FOUND,
                        'error' => t('listing_shipping.cdek_city_not_found'),
                    ];
                }
                $fromCode = (int) $found['code'];
            }
        }

        if (!$hasDeliveryPoint) {
            $toCode = (int) ($recipient['cdek_city_code'] ?? 0) ?: null;
            if ($toCode === null) {
                $city = trim((string) ($recipient['city'] ?? ''));
                if ($city === '') {
                    return $this->failLocal('point_b_city_missing', 'delivery.recipient_required', 'city');
                }
                $found = $this->client->findCityCode($city, $recipient['country'] ?? null);
                if ($found === null) {
                    return [
                        'ok' => false,
                        'error_code' => CdekErrorMapper::INTERNAL_CITY_NOT_FOUND,
                        'error' => t('listing_shipping.cdek_city_not_found'),
                    ];
                }
                $toCode = (int) $found['code'];
            }
        }

        return [
            'ok' => true,
            'from_city_code' => $fromCode,
            'to_city_code' => $toCode,
        ];
    }

    /**
     * @return array{ok: false, error_code: string, error: string, field?: string}
     */
    private function failLocal(string $code, string $langKey, ?string $field = null): array
    {
        $out = [
            'ok' => false,
            'error_code' => $code,
            'error' => t($langKey),
        ];
        if ($field !== null) {
            $out['field'] = $field;
        }
        return $out;
    }

    private function userMessage(string $internalCode, string $fallback): string
    {
        $map = [
            CdekErrorMapper::INTERNAL_RATE_LIMIT => 'delivery.quote_rate_limited',
            CdekErrorMapper::INTERNAL_TIMEOUT => 'delivery.quote_timeout',
            CdekErrorMapper::INTERNAL_NETWORK => 'delivery.quote_network_error',
            CdekErrorMapper::INTERNAL_SERVER => 'delivery.quote_server_error',
            CdekErrorMapper::INTERNAL_VALIDATION => 'delivery.quote_validation_error',
            CdekErrorMapper::INTERNAL_TARIFF => 'delivery.quote_route_unavailable',
            CdekErrorMapper::INTERNAL_ORIGIN => 'delivery.quote_origin_invalid',
            CdekErrorMapper::INTERNAL_DESTINATION => 'delivery.quote_destination_invalid',
            CdekErrorMapper::INTERNAL_PACKAGE => 'delivery.quote_package_invalid',
            CdekErrorMapper::INTERNAL_CITY_NOT_FOUND => 'listing_shipping.cdek_city_not_found',
            CdekErrorMapper::INTERNAL_POINT_NOT_FOUND => 'delivery.pvz_not_found',
            'point_a_missing' => 'delivery.missing_sender',
            'point_b_missing' => 'delivery.missing_recipient',
            'shipment_missing' => 'delivery.missing_shipment',
            'weight_missing' => 'delivery.weight_required',
            'pvz_required' => 'delivery.pvz_required',
        ];
        if (isset($map[$internalCode])) {
            return t($map[$internalCode]);
        }
        return $fallback !== '' ? t('delivery.quote_failed') : t('delivery.quote_failed');
    }
}
