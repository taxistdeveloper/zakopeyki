<?php

namespace App\Services\Delivery;

use App\Helpers\ProductHelper;
use App\Models\DeliveryOrder;
use App\Models\DeliveryPayment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\FreedomPay\Client as FreedomPayClient;

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
            default => new StubLogisticsProvider(),
        };
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

        $name = trim((string) ($input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        if ($name === '' || $phone === '' || $city === '') {
            return ['ok' => false, 'error' => t('delivery.sender_required')];
        }

        $senderId = $this->orders->upsertSender($deliveryOrderId, [
            'name' => $name,
            'phone' => $phone,
            'email' => trim((string) ($input['email'] ?? '')) ?: null,
            'region' => trim((string) ($input['region'] ?? '')) ?: null,
            'city' => $city,
            'street' => trim((string) ($input['street'] ?? '')) ?: null,
            'building' => trim((string) ($input['building'] ?? '')) ?: null,
            'apartment' => trim((string) ($input['apartment'] ?? '')) ?: null,
            'postal_code' => trim((string) ($input['postal_code'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ]);

        $dimensionsUnknown = !empty($input['dimensions_unknown']);
        $packagingId = (int) ($input['packaging_id'] ?? 0);
        $packagingName = null;
        $packagingPrice = 0;

        if ($packagingId > 0) {
            $pack = $this->orders->packagingById($packagingId);
            if ($pack) {
                $packagingName = $pack['name'];
                $packagingPrice = (int) $pack['price_amount'];
            }
        }

        $weight = $dimensionsUnknown ? null : $this->positiveFloat($input['weight_value'] ?? null);
        $length = $dimensionsUnknown ? null : $this->positiveFloat($input['length_value'] ?? null);
        $width = $dimensionsUnknown ? null : $this->positiveFloat($input['width_value'] ?? null);
        $height = $dimensionsUnknown ? null : $this->positiveFloat($input['height_value'] ?? null);

        if (!$dimensionsUnknown && ($weight === null || $weight <= 0)) {
            return ['ok' => false, 'error' => t('delivery.weight_required')];
        }
        if ($dimensionsUnknown && $packagingId <= 0) {
            return ['ok' => false, 'error' => t('delivery.packaging_required')];
        }

        $this->orders->updateShipment($deliveryOrderId, [
            'package_count' => max(1, (int) ($input['package_count'] ?? 1)),
            'weight_value' => $weight,
            'length_value' => $length,
            'width_value' => $width,
            'height_value' => $height,
            'weight_source' => $dimensionsUnknown && $packagingId > 0 ? 'packaging' : 'seller',
            'dimension_source' => $dimensionsUnknown && $packagingId > 0 ? 'packaging' : 'seller',
            'measurement_status' => 'preliminary',
            'packaging_id' => $packagingId > 0 ? $packagingId : null,
            'packaging_name_snapshot' => $packagingName,
            'dimensions_unknown' => $dimensionsUnknown,
            'is_fragile' => !empty($input['is_fragile']),
            'special_handling' => trim((string) ($input['special_handling'] ?? '')) ?: null,
        ]);

        $this->orders->updateFields($deliveryOrderId, [
            'sender_id' => $senderId,
            'origin_address_id' => $senderId,
        ]);

        $this->orders->logEvent($deliveryOrderId, $actorId, 'seller', 'sender_saved', null, null, [
            'packaging_price' => $packagingPrice,
        ]);

        $this->syncDataCompleteness($deliveryOrderId);
        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function saveBuyerData(int $deliveryOrderId, int $actorId, array $input): array
    {
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

        $name = trim((string) ($input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $mode = in_array($input['delivery_mode'] ?? '', ['courier', 'pvz'], true)
            ? $input['delivery_mode']
            : 'courier';

        if ($name === '' || $phone === '' || $city === '') {
            return ['ok' => false, 'error' => t('delivery.recipient_required')];
        }

        if ($mode === 'courier' && trim((string) ($input['street'] ?? '')) === '') {
            return ['ok' => false, 'error' => t('delivery.address_required')];
        }
        if ($mode === 'pvz' && trim((string) ($input['pvz_code'] ?? '')) === '') {
            return ['ok' => false, 'error' => t('delivery.pvz_required')];
        }

        $recipientId = $this->orders->upsertRecipient($deliveryOrderId, [
            'name' => $name,
            'phone' => $phone,
            'email' => trim((string) ($input['email'] ?? '')) ?: null,
            'delivery_mode' => $mode,
            'region' => trim((string) ($input['region'] ?? '')) ?: null,
            'city' => $city,
            'street' => trim((string) ($input['street'] ?? '')) ?: null,
            'building' => trim((string) ($input['building'] ?? '')) ?: null,
            'apartment' => trim((string) ($input['apartment'] ?? '')) ?: null,
            'postal_code' => trim((string) ($input['postal_code'] ?? '')) ?: null,
            'pvz_code' => trim((string) ($input['pvz_code'] ?? '')) ?: null,
            'pvz_name' => trim((string) ($input['pvz_name'] ?? '')) ?: null,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
        ]);

        $this->orders->updateFields($deliveryOrderId, [
            'recipient_id' => $recipientId,
            'destination_address_id' => $recipientId,
        ]);
        $this->orders->logEvent($deliveryOrderId, $actorId, 'buyer', 'recipient_saved', null, null);

        $this->syncDataCompleteness($deliveryOrderId);
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
        ];

        $provider = $this->providerFor($row);
        $requestId = 'req-' . $deliveryOrderId . '-' . bin2hex(random_bytes(4));
        $quotes = $provider->getQuotes($context);

        if ($quotes === []) {
            $this->orders->transitionStatus($deliveryOrderId, DeliveryOrder::STATUS_EXCEPTION, null, 'system', 'quote_empty');
            return ['ok' => false, 'error' => t('delivery.quote_failed')];
        }

        $this->orders->saveQuotes(
            $deliveryOrderId,
            (int) $row['logistics_provider_id'],
            $requestId,
            $quotes
        );
        $this->orders->transitionStatus(
            $deliveryOrderId,
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            null,
            'system',
            'quote_received',
            ['count' => count($quotes)]
        );

        return ['ok' => true];
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
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if ((int) $row['buyer_user_id'] !== $actorId) {
            return ['ok' => false, 'error' => t('delivery.forbidden')];
        }
        if ($row['status'] !== DeliveryOrder::STATUS_READY_FOR_PAYMENT) {
            return ['ok' => false, 'error' => t('delivery.bad_status')];
        }

        $quote = $row['selected_quote'];
        if (!$quote || strtotime((string) $quote['valid_until']) < time()) {
            return ['ok' => false, 'error' => t('delivery.quote_expired')];
        }

        $amount = (int) $quote['total_amount'];
        if ($amount <= 0) {
            return ['ok' => false, 'error' => t('delivery.invalid_amount')];
        }

        $pending = (new DeliveryPayment())->findPendingForOrder($deliveryOrderId);
        if ($pending) {
            return ['ok' => false, 'error' => t('delivery.payment_pending')];
        }

        $idempotencyKey = 'del-pay-' . $deliveryOrderId . '-' . (int) $quote['id'];
        $pgOrderId = 'zk-del-' . $deliveryOrderId . '-' . bin2hex(random_bytes(4));

        $fp = new FreedomPayClient();
        if ($method === 'card' && $fp->isConfigured()) {
            $init = $fp->initPayment([
                'order_id' => $pgOrderId,
                'amount' => $amount,
                'description' => t('delivery.payment_description', [
                    'number' => $row['order_number'],
                ]),
                'param1' => (string) $deliveryOrderId,
            ]);
            if (empty($init['redirect_url'])) {
                return ['ok' => false, 'error' => t('delivery.payment_failed')];
            }

            (new DeliveryPayment())->createPending([
                'delivery_order_id' => $deliveryOrderId,
                'buyer_user_id' => $actorId,
                'pg_order_id' => $pgOrderId,
                'amount' => $amount,
                'currency' => $quote['currency'] ?? 'KZT',
                'idempotency_key' => $idempotencyKey,
                'meta' => json_encode(['quote_id' => (int) $quote['id']], JSON_UNESCAPED_UNICODE),
            ]);

            $this->orders->transitionStatus(
                $deliveryOrderId,
                DeliveryOrder::STATUS_PAYMENT_PENDING,
                $actorId,
                'buyer',
                'payment_initiated',
                ['pg_order_id' => $pgOrderId, 'amount' => $amount]
            );
            $this->orders->updateFields($deliveryOrderId, ['payment_status' => 'pending']);

            return ['ok' => true, 'redirect_url' => (string) $init['redirect_url']];
        }

        $allowSim = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);
        if ($method === 'card' && $allowSim) {
            (new DeliveryPayment())->createPending([
                'delivery_order_id' => $deliveryOrderId,
                'buyer_user_id' => $actorId,
                'pg_order_id' => $pgOrderId,
                'amount' => $amount,
                'idempotency_key' => $idempotencyKey,
            ]);
            (new DeliveryPayment())->completeFromGateway($pgOrderId, 'sim-' . time(), (string) $amount);
            return ['ok' => true, 'redirect_url' => ProductHelper::url('/delivery/' . $deliveryOrderId)];
        }

        return ['ok' => false, 'error' => t('wallet.payments_disabled')];
    }

    public function onDeliveryPaid(int $deliveryOrderId, int $amount): void
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->orders->updateFields($deliveryOrderId, [
            'status' => DeliveryOrder::STATUS_PAID,
            'payment_status' => 'paid',
            'paid_amount' => $amount,
            'paid_at' => $now,
        ]);
        $this->orders->logEvent($deliveryOrderId, (int) $row['buyer_user_id'], 'buyer', 'payment_confirmed', null, DeliveryOrder::STATUS_PAID, [
            'amount' => $amount,
        ]);

        $this->createLogisticsOrder($deliveryOrderId);

        (new Notification())->createFor(
            (int) $row['seller_user_id'],
            t('delivery.notify_paid_seller', ['number' => $row['order_number']])
        );
    }

    /** @return array{ok: bool, error?: string} */
    public function createLogisticsOrder(int $deliveryOrderId): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found')];
        }
        if (!empty($row['logistics_order_id'])) {
            return ['ok' => true];
        }
        if (!in_array($row['status'], [DeliveryOrder::STATUS_PAID, DeliveryOrder::STATUS_ORDER_CREATED], true)) {
            return ['ok' => false, 'error' => t('delivery.bad_status')];
        }

        $avr = $this->avrPayload($deliveryOrderId);
        $result = $this->providerFor($row)->createOrder($avr);

        $this->orders->updateFields($deliveryOrderId, [
            'logistics_order_id' => $result['logistics_order_id'],
            'status' => DeliveryOrder::STATUS_ORDER_CREATED,
            'accepted_at' => date('Y-m-d H:i:s'),
        ]);
        $this->orders->logEvent($deliveryOrderId, null, 'system', 'logistics_order_created', DeliveryOrder::STATUS_PAID, DeliveryOrder::STATUS_ORDER_CREATED, $result);

        if (!empty($result['tracking_number'])) {
            $this->orders->addTrackingEvent($deliveryOrderId, [
                'tracking_number' => $result['tracking_number'],
                'carrier_status' => 'created',
                'carrier_message' => t('delivery.tracking_created'),
                'event_at' => date('Y-m-d H:i:s'),
            ]);
            $this->orders->transitionStatus($deliveryOrderId, DeliveryOrder::STATUS_ACCEPTED, null, 'logistics', 'accepted');
        }

        return ['ok' => true];
    }

    /** @return array{ok: bool, error?: string} */
    public function handleLogisticsWebhook(array $payload): array
    {
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

    private function syncDataCompleteness(int $deliveryOrderId): void
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row || !$row['sender'] || !$row['recipient'] || !$row['shipment']) {
            return;
        }

        $shipment = $row['shipment'];
        $hasShipment = !empty($shipment['dimensions_unknown'])
            || ((float) ($shipment['weight_value'] ?? 0) > 0);
        if (!$hasShipment) {
            return;
        }

        $this->orders->updateFields($deliveryOrderId, [
            'data_completeness_status' => 'complete',
        ]);

        if ($row['status'] === DeliveryOrder::STATUS_DATA_COLLECTION) {
            $this->orders->transitionStatus(
                $deliveryOrderId,
                DeliveryOrder::STATUS_DATA_COMPLETE,
                null,
                'system',
                'data_complete'
            );
            $this->requestQuotes($deliveryOrderId);
        }
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
