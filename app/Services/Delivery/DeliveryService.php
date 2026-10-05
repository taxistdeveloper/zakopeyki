<?php

namespace App\Services\Delivery;

use App\Helpers\ProductHelper;
use App\Models\DeliveryOrder;
use App\Models\DeliveryPayment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cdek\CdekDeliveryPointsSyncService;

class DeliveryService
{
    private DeliveryOrder $orders;

    public function __construct(?DeliveryOrder $orders = null)
    {
        $this->orders = $orders ?? new DeliveryOrder();
    }

    public static function bootstrapForPaidOrder(int $orderId): void
    {
        $orderModel = new Order();
        $order = $orderModel->find($orderId);
        if (!$order) {
            return;
        }

        if (($order['deal_mode'] ?? 'escrow') === 'direct') {
            return;
        }
        if (($order['status'] ?? '') !== 'escrowed') {
            return;
        }

        $product = (new Product())->find((int) $order['product_id']);
        if (!$product || ProductHelper::isDigitalListing($product)) {
            return;
        }
        if (($order['delivery_method'] ?? '') === 'digital') {
            return;
        }

        $service = new self();
        $deliveryOrderId = $service->orders->createForP2pOrder($order, (string) ($product['title'] ?? ''));

        (new \App\Services\Listing\ListingShippingService())->applyToDeliveryOrder(
            (int) $order['product_id'],
            $deliveryOrderId,
            (int) $order['seller_id']
        );

        // Phase 5: apply buyer Point B snapshot from checkout (no auto quote).
        $snapshotJson = (string) ($order['buyer_delivery_json'] ?? '');
        if ($snapshotJson !== '' && ($order['delivery_method'] ?? '') === 'cdek') {
            $decoded = json_decode($snapshotJson, true);
            if (is_array($decoded)) {
                (new BuyerPointBService(null, null, null, $service))->applySnapshotToDeliveryOrder(
                    $deliveryOrderId,
                    (int) $order['buyer_id'],
                    $decoded,
                    false
                );
            }
        }

        $buyerId = (int) $order['buyer_id'];
        $sellerId = (int) $order['seller_id'];
        $n = new Notification();
        $n->createFor($sellerId, t('delivery.notify_seller_fill', ['id' => $deliveryOrderId]));
        $n->createFor($buyerId, t('delivery.notify_buyer_fill', ['id' => $deliveryOrderId]));
    }

    public function providerFor(array $deliveryOrder): LogisticsProviderInterface
    {
        $code = $deliveryOrder['logistics_code'] ?? 'stub';
        return match ($code) {
            'cdek' => new CdekLogisticsProvider(null, $this->orders),
            default => new StubLogisticsProvider(),
        };
    }

    /**
     * Явный расчёт доставки покупателем (Phase 6).
     * Frontend передаёт только delivery_order id; сумма считается на сервере.
     *
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   error_code?: string,
     *   quotes?: list<array<string, mixed>>,
     *   active_quote_ids?: list<int>,
     *   reused?: bool
     * }
     */
    public function calculateQuotesForBuyer(int $deliveryOrderId, int $actorId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
        }
        if ((int) $row['buyer_user_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('delivery.forbidden'), 'error_code' => 'forbidden'];
        }

        // Ignore any client-supplied delivery amount (security).
        unset($_POST['total_amount'], $_POST['delivery_amount'], $_POST['deliveryAmountToPay'], $_POST['amount']);

        $calc = new \App\Services\Cdek\CdekCalculatorService();
        $ready = $calc->validateReady($row);
        if (!$ready['ok']) {
            return [
                'ok' => false,
                'error' => $ready['error'] ?? t('delivery.data_incomplete'),
                'error_code' => $ready['error_code'] ?? 'data_incomplete',
                'field' => $ready['field'] ?? null,
            ];
        }

        // Mark complete before quote request.
        if ($this->shipmentComplete($row['shipment'] ?? null) && $row['sender'] && $row['recipient']) {
            $this->orders->updateFields($deliveryOrderId, [
                'data_completeness_status' => 'complete',
            ]);
            if (($row['status'] ?? '') === DeliveryOrder::STATUS_DATA_COLLECTION) {
                $this->orders->transitionStatus(
                    $deliveryOrderId,
                    DeliveryOrder::STATUS_DATA_COMPLETE,
                    $actorId,
                    'buyer',
                    'data_complete_before_calc'
                );
            }
        }

        $result = $this->requestQuotes($deliveryOrderId);
        if (!$result['ok']) {
            return $result;
        }

        $quotes = $this->orders->quotesFor($deliveryOrderId);
        $public = [];
        foreach ($quotes as $q) {
            $total = (int) ($q['total_amount'] ?? 0);
            $public[] = [
                'id' => (int) $q['id'],
                'service_name' => $q['service_name'] ?? null,
                'tariff_code' => isset($q['tariff_code']) ? (int) $q['tariff_code'] : null,
                'delivery_mode' => $q['cdek_delivery_mode'] ?? null,
                'cdek_delivery_sum' => (int) ($q['cdek_delivery_sum'] ?? $q['base_amount'] ?? 0),
                'delivery_amount_to_pay' => $total,
                'total_amount' => $total,
                'currency' => $q['currency'] ?? 'KZT',
                'eta_days_min' => $q['eta_days_min'] ?? null,
                'eta_days_max' => $q['eta_days_max'] ?? null,
                'valid_until' => $q['valid_until'] ?? null,
                'quote_status' => $q['quote_status'] ?? 'active',
                'is_selected' => !empty($q['is_selected']),
            ];
        }

        return [
            'ok' => true,
            'reused' => !empty($result['reused']),
            'quotes' => $public,
            'active_quote_ids' => array_map(static fn(array $q): int => (int) $q['id'], $public),
        ];
    }

    /** @return array{ok: bool, error?: string} */
    public function saveSellerData(int $deliveryOrderId, int $actorId, array $input): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if ((int) $row['seller_user_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('delivery.forbidden')];
        }
        if (!$this->canEditData($row['status'])) {
            return ['ok' => false, 'error' => t('delivery.bad_status')];
        }

        $validator = new DeliveryPointValidator();
        $pointA = $validator->validatePointA([
            'name' => $input['name'] ?? '',
            'phone' => $input['phone'] ?? '',
            'email' => $input['email'] ?? null,
            'company' => $input['company'] ?? null,
            'country' => $input['country'] ?? 'KZ',
            'region' => $input['region'] ?? null,
            'city' => $input['city'] ?? '',
            'street' => $input['street'] ?? null,
            'building' => $input['building'] ?? null,
            'apartment' => $input['apartment'] ?? null,
            'postal_code' => $input['postal_code'] ?? null,
            'origin_type' => $input['origin_type'] ?? 'door',
            'shipment_point' => $input['shipment_point'] ?? null,
            'cdek_city_code' => $input['cdek_city_code'] ?? null,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
            'notes' => $input['notes'] ?? null,
        ], false);
        if (!$pointA['ok']) {
            return ['ok' => false, 'error' => t('delivery.sender_required')];
        }

        $name = $pointA['data']['name'];
        $phone = $pointA['data']['phone'];
        $city = $pointA['data']['city'];

        $senderId = $this->orders->upsertSender($deliveryOrderId, $pointA['data']);

        $dimensionsUnknown = !empty($input['dimensions_unknown']);
        $packagingId = (int) ($input['packaging_id'] ?? 0);
        $packReco = new PackagingRecommendationService();
        $packagings = $this->orders->packagingsForProvider((int) $row['logistics_provider_id']);

        $itemWeight = $this->positiveFloat($input['item_weight'] ?? null);
        $itemL = $this->positiveFloat($input['item_length'] ?? null);
        $itemW = $this->positiveFloat($input['item_width'] ?? null);
        $itemH = $this->positiveFloat($input['item_height'] ?? null);

        // Обратная совместимость: weight_value / length_value как gross/упаковка
        $legacyGross = $this->positiveFloat($input['weight_value'] ?? null);
        if ($itemWeight === null && $legacyGross !== null && !$dimensionsUnknown) {
            $itemWeight = max(0.01, $legacyGross - $packReco->defaultPackagingWeightKg());
        }
        if ($itemL === null && !$dimensionsUnknown) {
            $itemL = $this->positiveFloat($input['length_value'] ?? null);
            $itemW = $this->positiveFloat($input['width_value'] ?? null);
            $itemH = $this->positiveFloat($input['height_value'] ?? null);
        }

        $packagingWeight = $this->positiveFloat($input['packaging_weight'] ?? null)
            ?? $packReco->defaultPackagingWeightKg();

        $recommendation = $packReco->recommend($packagings, $itemWeight, $itemL, $itemW, $itemH);
        $recommendedId = $recommendation['recommended']['id'] ?? null;

        $pack = null;
        $packagingName = null;
        $packagingPrice = 0;
        if ($packagingId > 0) {
            $pack = $this->orders->packagingById($packagingId);
            if (!$pack) {
                return ['ok' => false, 'error' => t('delivery.packaging_required')];
            }
            $validPack = $packReco->validateSelection($pack, $itemWeight, $itemL, $itemW, $itemH);
            if (!$validPack['ok']) {
                return ['ok' => false, 'error' => $validPack['error'] ?? t('delivery.packaging_incompatible')];
            }
            $packagingName = $pack['name'];
            $packagingPrice = (int) $pack['price_amount'];
        }

        if (!$dimensionsUnknown && ($itemWeight === null || $itemWeight <= 0)) {
            return ['ok' => false, 'error' => t('delivery.weight_required')];
        }
        if ($dimensionsUnknown && $packagingId <= 0) {
            return ['ok' => false, 'error' => t('delivery.packaging_required')];
        }

        $packageL = $pack ? (float) ($pack['length_cm'] ?? 0) : $itemL;
        $packageW = $pack ? (float) ($pack['width_cm'] ?? 0) : $itemW;
        $packageH = $pack ? (float) ($pack['height_cm'] ?? 0) : $itemH;

        if (!$dimensionsUnknown && ($packageL === null || $packageW === null || $packageH === null)) {
            return ['ok' => false, 'error' => t('delivery.dimensions_required')];
        }

        $grossWeight = ($itemWeight ?? 0) + $packagingWeight;
        if ($dimensionsUnknown && $itemWeight === null) {
            $grossWeight = $packagingWeight > 0 ? $packagingWeight : 1.0;
        }

        $billedL = $packageL !== null ? $packReco->billedDimension((float) $packageL) : null;
        $billedW = $packageW !== null ? $packReco->billedDimension((float) $packageW) : null;
        $billedH = $packageH !== null ? $packReco->billedDimension((float) $packageH) : null;
        $billedGross = $packReco->billedDimension($grossWeight);

        $isIrregular = !empty($input['is_irregular']);
        $irregularReason = $isIrregular ? (trim((string) ($input['irregular_reason'] ?? 'other')) ?: 'other') : null;

        if ($this->shouldInvalidateQuotes($row)) {
            $this->orders->invalidateQuotes($deliveryOrderId, 'shipment_changed');
            $this->resetOrderAfterQuoteInvalidation($deliveryOrderId);
        }

        $this->orders->updateShipment($deliveryOrderId, [
            'package_count' => max(1, (int) ($input['package_count'] ?? 1)),
            'item_weight' => $itemWeight,
            'packaging_weight' => $packagingWeight,
            'gross_weight' => $grossWeight,
            'item_length' => $itemL,
            'item_width' => $itemW,
            'item_height' => $itemH,
            'package_length' => $packageL,
            'package_width' => $packageW,
            'package_height' => $packageH,
            'billed_length' => $billedL,
            'billed_width' => $billedW,
            'billed_height' => $billedH,
            'billed_gross_weight' => $billedGross,
            'weight_source' => $dimensionsUnknown && $packagingId > 0 ? 'packaging' : 'seller',
            'dimension_source' => $dimensionsUnknown && $packagingId > 0 ? 'packaging' : 'seller',
            'measurement_status' => 'preliminary',
            'packaging_id' => $packagingId > 0 ? $packagingId : null,
            'packaging_name_snapshot' => $packagingName,
            'recommended_packaging_id' => $recommendedId,
            'dimensions_unknown' => $dimensionsUnknown,
            'is_fragile' => !empty($input['is_fragile']),
            'is_irregular' => $isIrregular,
            'irregular_reason' => $irregularReason,
            'special_handling' => trim((string) ($input['special_handling'] ?? '')) ?: null,
        ]);

        $this->orders->updateFields($deliveryOrderId, [
            'sender_id' => $senderId,
            'origin_address_id' => $senderId,
        ]);

        // Любое сохранение seller shipping → shipping_version++ (старые quotes невалидны по version).
        $newVersion = $this->orders->bumpShippingVersion($deliveryOrderId);

        $this->orders->logEvent($deliveryOrderId, $actorId, 'seller', 'sender_saved', null, null, [
            'packaging_price' => $packagingPrice,
            'recommended_packaging_id' => $recommendedId,
            'gross_weight' => $grossWeight,
            'shipping_version' => $newVersion,
        ]);

        $this->syncDataCompleteness($deliveryOrderId);
        return ['ok' => true];
    }

    /**
     * Что ещё нужно до расчёта тарифа.
     * @param array<string, mixed> $row findWithDetails
     * @return list<string> ключи: sender|shipment|recipient
     */
    public function missingForQuotes(array $row): array
    {
        $missing = [];
        if (empty($row['sender'])) {
            $missing[] = 'sender';
        }
        if (!$this->shipmentComplete($row['shipment'] ?? null)) {
            $missing[] = 'shipment';
        }
        if (empty($row['recipient'])) {
            $missing[] = 'recipient';
        }
        return $missing;
    }

    /** @return array{ok: bool, error?: string} */
    public function saveBuyerData(int $deliveryOrderId, int $actorId, array $input, array $options = []): array
    {
        $autoQuote = array_key_exists('auto_quote', $options) ? (bool) $options['auto_quote'] : true;

        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if ((int) $row['buyer_user_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('delivery.forbidden')];
        }
        if (!$this->canEditData($row['status'])) {
            return ['ok' => false, 'error' => t('delivery.bad_status')];
        }

        $validator = new DeliveryPointValidator();
        $pointB = $validator->validatePointB([
            'name' => $input['name'] ?? '',
            'phone' => $input['phone'] ?? '',
            'email' => $input['email'] ?? null,
            'delivery_mode' => $input['delivery_mode'] ?? 'courier',
            'country' => $input['country'] ?? 'KZ',
            'region' => $input['region'] ?? null,
            'city' => $input['city'] ?? '',
            'street' => $input['street'] ?? null,
            'building' => $input['building'] ?? null,
            'apartment' => $input['apartment'] ?? null,
            'postal_code' => $input['postal_code'] ?? null,
            'pvz_code' => $input['pvz_code'] ?? null,
            'pvz_name' => $input['pvz_name'] ?? null,
            'delivery_point' => $input['delivery_point'] ?? null,
            'cdek_city_code' => $input['cdek_city_code'] ?? null,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
            'notes' => $input['notes'] ?? null,
        ], !empty($options['require_cdek_codes']));
        if (!$pointB['ok']) {
            $err = $pointB['error'] ?? '';
            if ($err === 'recipient_address_required') {
                return ['ok' => false, 'error' => t('delivery.address_required')];
            }
            if ($err === 'pvz_required') {
                return ['ok' => false, 'error' => t('delivery.pvz_required')];
            }
            return ['ok' => false, 'error' => t('delivery.recipient_required')];
        }

        $name = $pointB['data']['name'];
        $phone = $pointB['data']['phone'];
        $city = $pointB['data']['city'];
        $mode = $pointB['data']['delivery_mode'];

        $pvzCode = (string) ($pointB['data']['pvz_code'] ?? '');
        $pvzName = $pointB['data']['pvz_name'] ?? null;
        if ($mode === 'pvz') {
            if ($pvzCode === '') {
                return ['ok' => false, 'error' => t('delivery.pvz_required')];
            }

            $weightKg = null;
            if (!empty($row['shipment']['billed_gross_weight'])) {
                $weightKg = (float) $row['shipment']['billed_gross_weight'];
            } elseif (!empty($row['shipment']['gross_weight'])) {
                $weightKg = (float) $row['shipment']['gross_weight'];
            }

            $dir = new CdekDeliveryPointsSyncService();
            $validated = $dir->validateCode($pvzCode, $city, $weightKg);
            if (!$validated['ok']) {
                return ['ok' => false, 'error' => $validated['error'] ?? t('delivery.pvz_not_found')];
            }

            $point = $validated['point'];
            // Канонические значения из справочника — не доверяем frontend name/city для PVZ.
            $pvzCode = (string) $point['code'];
            $pvzName = (string) ($point['name'] ?? $point['address'] ?? $pvzCode);
            if (!empty($point['city'])) {
                $city = (string) $point['city'];
            }
            $pointB['data']['pvz_code'] = $pvzCode;
            $pointB['data']['delivery_point'] = $pvzCode;
            $pointB['data']['pvz_name'] = $pvzName;
            $pointB['data']['city'] = $city;
            if (!empty($point['city_code'])) {
                $pointB['data']['cdek_city_code'] = (int) $point['city_code'];
            }
        }

        $oldFingerprint = $this->recipientFingerprint($row['recipient'] ?? null);
        $newData = $pointB['data'];

        if ($this->shouldInvalidateQuotes($row) && $oldFingerprint !== null) {
            $newFingerprint = $this->recipientFingerprint($newData);
            if ($oldFingerprint !== $newFingerprint) {
                $this->orders->invalidateQuotes($deliveryOrderId, 'address_changed');
                $this->resetOrderAfterQuoteInvalidation($deliveryOrderId);
            }
        }

        $recipientId = $this->orders->upsertRecipient($deliveryOrderId, $newData);

        $this->orders->updateFields($deliveryOrderId, [
            'recipient_id' => $recipientId,
            'destination_address_id' => $recipientId,
        ]);
        $this->orders->logEvent($deliveryOrderId, $actorId, 'buyer', 'recipient_saved', null, null);

        $this->syncDataCompleteness($deliveryOrderId, $autoQuote);
        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function requestQuotes(int $deliveryOrderId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if (($row['data_completeness_status'] ?? '') !== 'complete') {
            return ['ok' => false, 'error' => t('delivery.data_incomplete')];
        }

        $this->orders->transitionStatus(
            $deliveryOrderId,
            DeliveryOrder::STATUS_QUOTE_REQUESTED,
            null,
            'system',
            'quote_requested'
        );

        $packagingPrice = 0;
        if (!empty($row['shipment']['packaging_id'])) {
            $pack = $this->orders->packagingById((int) $row['shipment']['packaging_id']);
            $packagingPrice = (int) ($pack['price_amount'] ?? 0);
        }

        $context = [
            'delivery_order_id' => $deliveryOrderId,
            'sender' => $row['sender'],
            'recipient' => $row['recipient'],
            'shipment' => $row['shipment'],
            'packaging_price' => $packagingPrice,
            'shipping_version' => (int) ($row['shipping_version'] ?? 1),
        ];
        $snapshot = $this->buildQuoteSnapshot($row, $context);

        $provider = $this->providerFor($row);
        $requestId = 'req-' . $deliveryOrderId . '-' . bin2hex(random_bytes(4));
        $quotes = $provider->getQuotes($context);

        if ($quotes === []) {
            $providerError = null;
            if ($provider instanceof CdekLogisticsProvider) {
                $providerError = $provider->getLastError();
            }
            $this->orders->transitionStatus($deliveryOrderId, DeliveryOrder::STATUS_EXCEPTION, null, 'system', 'quote_empty', [
                'error_code' => $providerError['error_code'] ?? null,
            ]);
            // Reset to DATA_COMPLETE so buyer can retry calculate.
            $this->orders->updateFields($deliveryOrderId, [
                'status' => DeliveryOrder::STATUS_DATA_COMPLETE,
                'data_completeness_status' => 'complete',
            ]);
            return [
                'ok' => false,
                'error' => $providerError['error'] ?? t('delivery.quote_failed'),
                'error_code' => $providerError['error_code'] ?? 'quote_failed',
                'http_status' => $providerError['http_status'] ?? null,
            ];
        }

        $reused = !empty($quotes[0]['reused']);

        if (!$reused) {
            foreach ($quotes as &$q) {
                // Не затираем CDEK meta из provider — мержим с AVR snapshot.
                $providerSnap = is_array($q['snapshot_json'] ?? null) ? $q['snapshot_json'] : [];
                $q['snapshot_json'] = array_merge($providerSnap, $snapshot);
                $q['shipping_version'] = (int) ($row['shipping_version'] ?? 1);
            }
            unset($q);

            $this->orders->saveQuotes(
                $deliveryOrderId,
                (int) $row['logistics_provider_id'],
                $requestId,
                $quotes
            );
        }

        $this->orders->transitionStatus(
            $deliveryOrderId,
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            null,
            'system',
            $reused ? 'quote_reused' : 'quote_received',
            [
                'count' => count($quotes),
                'reused' => $reused,
                'request_hash' => $quotes[0]['request_payload_hash'] ?? null,
            ]
        );

        return ['ok' => true, 'reused' => $reused];
    }

    /** @return array{ok: bool, error?: string} */
    public function selectQuote(int $deliveryOrderId, int $actorId, int $quoteId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if ((int) $row['buyer_user_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('delivery.forbidden')];
        }
        if (!in_array($row['status'], [
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
        ], true)) {
            return ['ok' => false, 'error' => t('delivery.bad_status')];
        }

        $quote = $this->orders->selectQuote($deliveryOrderId, $quoteId);
        if (!$quote) {
            return ['ok' => false, 'error' => t('delivery.quote_not_found')];
        }

        if (strtotime((string) $quote['valid_until']) < time()) {
            return ['ok' => false, 'error' => t('delivery.quote_expired')];
        }

        $this->buildAvrPackage($deliveryOrderId);
        $this->orders->transitionStatus(
            $deliveryOrderId,
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
            $actorId,
            'buyer',
            'quote_selected',
            ['quote_id' => $quoteId, 'total' => (int) $quote['total_amount']]
        );
        $this->orders->updateFields($deliveryOrderId, [
            'data_completeness_status' => 'avr_ready',
        ]);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, redirect_url?: string, error?: string}
     */
    public function initiatePayment(int $deliveryOrderId, int $actorId, string $method = 'card'): array
    {
        // Phase 7: amount/currency never from frontend; gateway-agnostic via DeliveryPaymentService.
        unset($_POST['amount'], $_POST['total_amount'], $_POST['currency'], $_POST['delivery_amount']);
        return (new DeliveryPaymentService($this->orders))->createPaymentIntent($deliveryOrderId, $actorId, [
            'payment_method' => $method,
        ]);
    }

    /**
     * Confirmed delivery payment (server-side only). Does NOT create CDEK order (Phase 7).
     */
    public function onDeliveryPaid(int $deliveryOrderId, int $amount): void
    {
        (new DeliveryPaymentService($this->orders))->onPaymentConfirmed($deliveryOrderId, $amount);
    }

    /**
     * Gate for Phase 8 CDEK registration.
     *
     * @return array{ok: bool, reason?: string, checks?: array<string, bool>}
     */
    public function canCreateCdekOrder(int $deliveryOrderId): array
    {
        return (new DeliveryPaymentService($this->orders))->canCreateCdekOrder($deliveryOrderId);
    }

    /** @return array{ok: bool, error?: string, deferred?: bool} */
    public function createLogisticsOrder(int $deliveryOrderId): array
    {
        $gate = $this->canCreateCdekOrder($deliveryOrderId);
        if (!($gate['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => t('delivery.cdek_not_ready'),
                'reason' => $gate['reason'] ?? 'can_create_false',
                'checks' => $gate['checks'] ?? [],
            ];
        }

        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }

        $existingUuid = (string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? '');
        if ($existingUuid !== '') {
            return ['ok' => true];
        }

        $fsm = new DeliveryStatusMachine();
        if (!$fsm->isCreateAllowed(
            (string) $row['status'],
            (string) ($row['cdek_api_status'] ?? DeliveryStatusMachine::API_NONE),
            null
        )) {
            return ['ok' => false, 'error' => t('delivery.bad_status')];
        }

        // Phase 2/7 gate: реальные POST /v2/orders для CDEK отключены до Phase 8.
        $providerCode = (string) ($row['logistics_code'] ?? 'stub');
        if ($providerCode === 'cdek' && !DeliveryModelService::isOrderCreateEnabled()) {
            $model = new DeliveryModelService($this->orders, null, $fsm, $this);
            return $model->markCreatePending($deliveryOrderId);
        }

        // Защита от race: два payment-callback не должны параллельно дергать create.
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if (!empty($row['logistics_order_id']) || !empty($row['cdek_uuid'])) {
            return ['ok' => true];
        }

        $avr = $this->avrPayload($deliveryOrderId);
        try {
            $result = $this->providerFor($row)->createOrder($avr);
        } catch (\Throwable $e) {
            $this->orders->updateFields($deliveryOrderId, [
                'status' => DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
                'cdek_api_status' => DeliveryStatusMachine::API_FAILED,
                'last_error_code' => 'create_failed',
                'last_error_message' => mb_substr($e->getMessage(), 0, 255),
            ]);
            $this->orders->logEvent(
                $deliveryOrderId,
                null,
                'system',
                'logistics_create_failed',
                DeliveryOrder::STATUS_PAID,
                DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
                DeliveryPii::redactPayload(['error' => $e->getMessage()])
            );
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $uuid = (string) ($result['logistics_order_id'] ?? '');
        $tracking = (string) ($result['tracking_number'] ?? '');
        $this->orders->updateFields($deliveryOrderId, [
            'logistics_order_id' => $uuid !== '' ? $uuid : null,
            'cdek_uuid' => $uuid !== '' ? $uuid : null,
            'cdek_number' => $tracking !== '' ? $tracking : null,
            'cdek_api_status' => DeliveryStatusMachine::API_CREATED,
            'status' => DeliveryOrder::STATUS_ORDER_CREATED,
            'accepted_at' => date('Y-m-d H:i:s'),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        $this->orders->logEvent(
            $deliveryOrderId,
            null,
            'system',
            'logistics_order_created',
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_ORDER_CREATED,
            DeliveryPii::redactPayload($result)
        );

        if ($tracking !== '') {
            $this->orders->addTrackingEvent($deliveryOrderId, [
                'tracking_number' => $tracking,
                'carrier_status' => 'created',
                'carrier_message' => t('delivery.tracking_created'),
                'event_at' => date('Y-m-d H:i:s'),
            ]);
            $this->orders->transitionStatus($deliveryOrderId, DeliveryOrder::STATUS_ACCEPTED, null, 'logistics', 'accepted');
        }

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @return array{ok: bool, error?: string, duplicate?: bool, delivery_order_id?: int, status?: string|null}
     */
    public function handleLogisticsWebhook(
        array $payload,
        array $headers = [],
        array $query = [],
        string $rawBody = ''
    ): array {
        $hint = (string) ($payload['provider'] ?? '');
        if ($hint === '' && (!empty($payload['type']) || !empty($payload['uuid']))) {
            $hint = 'cdek';
        }

        if ($hint === 'cdek') {
            $cdek = new \App\Services\Cdek\CdekWebhookService(null, null, $this->orders);
            $auth = $cdek->authorize($headers, $query);
            if (!$auth['ok']) {
                return ['ok' => false, 'error' => $auth['error'] ?? 'unauthorized'];
            }
            return $cdek->handle($payload, $rawBody);
        }

        $provider = new StubLogisticsProvider();
        $parsed = $provider->handleStatusWebhook($payload);
        if (!$parsed) {
            return ['ok' => false, 'error' => 'invalid_payload'];
        }

        $deliveryOrderId = (int) $parsed['delivery_order_id'];
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }

        $statusMap = [
            'ACCEPTED' => DeliveryOrder::STATUS_ACCEPTED,
            'SHIPMENT_RECEIVED' => DeliveryOrder::STATUS_SHIPMENT_RECEIVED,
            'IN_TRANSIT' => DeliveryOrder::STATUS_IN_TRANSIT,
            'DELIVERED' => DeliveryOrder::STATUS_DELIVERED,
            'EXCEPTION' => DeliveryOrder::STATUS_EXCEPTION,
        ];
        $newStatus = $statusMap[$parsed['status']] ?? null;

        if (!empty($parsed['tracking_number']) || !empty($parsed['message'])) {
            $this->orders->addTrackingEvent($deliveryOrderId, [
                'tracking_number' => $parsed['tracking_number'] ?? null,
                'carrier_status' => $parsed['status'] ?? null,
                'carrier_message' => $parsed['message'] ?? null,
                'location' => $parsed['location'] ?? null,
                'event_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if ($newStatus) {
            $this->orders->transitionStatus($deliveryOrderId, $newStatus, null, 'logistics', 'webhook_status');
            if ($newStatus === DeliveryOrder::STATUS_DELIVERED) {
                $this->orders->updateFields($deliveryOrderId, ['delivered_at' => date('Y-m-d H:i:s')]);
            }
        }

        return ['ok' => true];
    }

    private function syncDataCompleteness(int $deliveryOrderId, bool $autoQuote = true): void
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row || !$row['sender'] || !$row['recipient'] || !$row['shipment']) {
            return;
        }

        $shipment = $row['shipment'];
        if (!$this->shipmentComplete($shipment)) {
            return;
        }

        $this->orders->updateFields($deliveryOrderId, [
            'data_completeness_status' => 'complete',
        ]);

        if (in_array($row['status'], [
            DeliveryOrder::STATUS_DATA_COLLECTION,
            DeliveryOrder::STATUS_DATA_COMPLETE,
        ], true)) {
            if ($row['status'] === DeliveryOrder::STATUS_DATA_COLLECTION) {
                $this->orders->transitionStatus(
                    $deliveryOrderId,
                    DeliveryOrder::STATUS_DATA_COMPLETE,
                    null,
                    'system',
                    'data_complete'
                );
            }
        // Phase 5/6: checkout-applied Point B must not auto-trigger quote calc.
            // Buyer explicitly calls POST /delivery/{id}/quotes/calculate.
            if ($autoQuote) {
                $this->requestQuotes($deliveryOrderId);
            }
        }
    }

    /** @param array<string, mixed>|null $shipment */
    private function shipmentComplete(?array $shipment): bool
    {
        if (!$shipment) {
            return false;
        }
        if (!empty($shipment['dimensions_unknown']) && !empty($shipment['packaging_id'])) {
            return true;
        }
        $gross = (float) ($shipment['gross_weight'] ?? $shipment['weight_value'] ?? 0);
        return $gross > 0;
    }

    private function shouldInvalidateQuotes(array $row): bool
    {
        return in_array($row['status'] ?? '', [
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
            DeliveryOrder::STATUS_PAYMENT_PENDING,
        ], true);
    }

    private function resetOrderAfterQuoteInvalidation(int $deliveryOrderId): void
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return;
        }

        $status = DeliveryOrder::STATUS_DATA_COLLECTION;
        $completeness = 'pending';
        if ($row['sender'] && $row['recipient'] && $this->shipmentComplete($row['shipment'])) {
            $status = DeliveryOrder::STATUS_DATA_COMPLETE;
            $completeness = 'complete';
        }

        $this->orders->updateFields($deliveryOrderId, [
            'status' => $status,
            'data_completeness_status' => $completeness,
        ]);
    }

    /** @param array<string, mixed>|null $recipient */
    private function recipientFingerprint(?array $recipient): ?string
    {
        if (!$recipient) {
            return null;
        }
        $parts = [
            $recipient['delivery_mode'] ?? '',
            $recipient['city'] ?? '',
            $recipient['street'] ?? '',
            $recipient['building'] ?? '',
            $recipient['apartment'] ?? '',
            $recipient['postal_code'] ?? '',
            $recipient['pvz_code'] ?? '',
            $recipient['delivery_point'] ?? '',
            (string) ($recipient['cdek_city_code'] ?? ''),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /** @param array<string, mixed> $row */
    private function buildQuoteSnapshot(array $row, array $context): array
    {
        return [
            'delivery_order_id' => (int) $row['id'],
            'order_number' => $row['order_number'],
            'order_id' => (int) ($row['order_id'] ?? 0),
            'product_id' => (int) ($row['product_id'] ?? 0),
            'shipping_version' => (int) ($row['shipping_version'] ?? 1),
            'listing_shipping_version' => (int) ($row['listing_shipping_version'] ?? 1),
            'point_a' => $row['sender'],
            'point_b' => $row['recipient'],
            'origin' => $row['sender'],
            'destination' => $row['recipient'],
            'shipment' => $row['shipment'],
            'weight' => $row['shipment']['billed_gross_weight']
                ?? $row['shipment']['gross_weight']
                ?? $row['shipment']['weight_value']
                ?? null,
            'dimensions' => [
                'length' => $row['shipment']['billed_length'] ?? $row['shipment']['package_length'] ?? null,
                'width' => $row['shipment']['billed_width'] ?? $row['shipment']['package_width'] ?? null,
                'height' => $row['shipment']['billed_height'] ?? $row['shipment']['package_height'] ?? null,
            ],
            'delivery_mode' => $row['recipient']['delivery_mode'] ?? null,
            'route' => [
                'from_city' => $row['sender']['city'] ?? null,
                'to_city' => $row['recipient']['city'] ?? null,
                'from_cdek_city_code' => $row['sender']['cdek_city_code'] ?? null,
                'to_cdek_city_code' => $row['recipient']['cdek_city_code'] ?? null,
                'shipment_point' => $row['sender']['shipment_point'] ?? null,
                'delivery_point' => $row['recipient']['delivery_point'] ?? $row['recipient']['pvz_code'] ?? null,
            ],
            'requested_at' => date('c'),
            'calculator_endpoint' => \App\Services\Cdek\CdekCalculatorService::ENDPOINT,
            'context_hash' => hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function buildAvrPackage(int $deliveryOrderId): void
    {
        $payload = $this->avrPayload($deliveryOrderId);
        $this->orders->saveAvrDocument($deliveryOrderId, $payload);
    }

    private function avrPayload(int $deliveryOrderId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        $quote = $row['selected_quote'] ?? null;
        $buyer = (new User())->find((int) $row['buyer_user_id']);
        $seller = (new User())->find((int) $row['seller_user_id']);

        return [
            'delivery_order_id' => $deliveryOrderId,
            'order_number' => $row['order_number'],
            'p2p_transaction_id' => (int) $row['order_id'],
            'customer' => [
                'user_id' => (int) $row['buyer_user_id'],
                'name' => $row['recipient']['name'] ?? ($buyer['name'] ?? ''),
                'phone' => $row['recipient']['phone'] ?? ($buyer['phone'] ?? ''),
            ],
            'sender' => $row['sender'],
            'recipient' => $row['recipient'],
            'shipment' => $row['shipment'],
            'service' => $quote ? [
                'service_code' => $quote['service_code'],
                'service_name' => $quote['service_name'],
                'logistics_provider' => $row['logistics_name'],
            ] : null,
            'finance' => $quote ? [
                'base_amount' => (int) $quote['base_amount'],
                'packaging_amount' => (int) $quote['packaging_amount'],
                'extra_services_amount' => (int) $quote['extra_services_amount'],
                'discount_amount' => (int) $quote['discount_amount'],
                'total_amount' => (int) $quote['total_amount'],
                'currency' => $quote['currency'],
            ] : null,
            'identifiers' => [
                'delivery_order_id' => $deliveryOrderId,
                'p2p_transaction_id' => (int) $row['order_id'],
                'logistics_order_id' => $row['logistics_order_id'],
            ],
            'seller' => [
                'user_id' => (int) $row['seller_user_id'],
                'name' => $seller['name'] ?? '',
            ],
            'timestamps' => [
                'created_at' => $row['created_at'],
                'status' => $row['status'],
            ],
        ];
    }

    private function canEditData(string $status): bool
    {
        return in_array($status, [
            DeliveryOrder::STATUS_DATA_COLLECTION,
            DeliveryOrder::STATUS_DATA_COMPLETE,
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
        ], true);
    }

    private function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }
}
