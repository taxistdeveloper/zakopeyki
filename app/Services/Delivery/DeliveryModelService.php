<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Models\DeliveryPayment;
use App\Models\ProductListingShipping;

/**
 * Domain API для модели доставки CDEK (без frontend-зависимостей).
 *
 * Важно (Phase 2):
 * - НЕ создаёт заказ в CDEK API (см. order_create_enabled / markCreatePending).
 * - Seller = Point A / sender; Buyer = Point B / recipient + заказчик доставки.
 * - Quote snapshot отделён от Delivery Order.
 */
class DeliveryModelService
{
    private DeliveryOrder $orders;
    private DeliveryPointValidator $validator;
    private DeliveryStatusMachine $fsm;
    private DeliveryService $delivery;

    public function __construct(
        ?DeliveryOrder $orders = null,
        ?DeliveryPointValidator $validator = null,
        ?DeliveryStatusMachine $fsm = null,
        ?DeliveryService $delivery = null
    ) {
        $this->orders = $orders ?? new DeliveryOrder();
        $this->validator = $validator ?? new DeliveryPointValidator();
        $this->fsm = $fsm ?? new DeliveryStatusMachine();
        $this->delivery = $delivery ?? new DeliveryService($this->orders);
    }

    /**
     * Сохранить Point A на объявлении (product_listing_shipping).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, field?: string, data?: array<string, mixed>}
     */
    public function saveListingPointA(int $productId, int $sellerUserId, array $input, bool $requireCdekCodes = false): array
    {
        $product = (new \App\Models\Product())->find($productId);
        if (!$product || (int) ($product['user_id'] ?? 0) !== $sellerUserId) {
            return ['ok' => false, 'error' => 'forbidden', 'field' => 'product_id'];
        }

        $validated = $this->validator->validatePointA($input, $requireCdekCodes);
        if (!$validated['ok']) {
            return $validated;
        }
        $shipment = $this->validator->validateShipment($input);
        if (!$shipment['ok']) {
            return $shipment;
        }

        $point = $validated['data'];
        $ship = $shipment['data'];
        $fulfillment = in_array($input['fulfillment_mode'] ?? '', [
            ProductListingShipping::FULFILLMENT_DELIVERY,
            ProductListingShipping::FULFILLMENT_PICKUP,
            ProductListingShipping::FULFILLMENT_BOTH,
        ], true) ? $input['fulfillment_mode'] : ProductListingShipping::FULFILLMENT_DELIVERY;

        $cdekReady = $fulfillment !== ProductListingShipping::FULFILLMENT_PICKUP ? 1 : 0;

        $listing = new ProductListingShipping();
        $payload = [
            'fulfillment_mode' => $fulfillment,
            'param_mode' => $input['param_mode'] ?? ProductListingShipping::MODE_EXACT,
            'use_default_ship_from' => !empty($input['use_default_ship_from']) ? 1 : 0,
            'ship_country' => $point['country'],
            'ship_region' => $point['region'],
            'ship_city' => $point['city'],
            'ship_street' => $point['street'],
            'ship_building' => $point['building'],
            'ship_apartment' => $point['apartment'],
            'ship_postal_code' => $point['postal_code'],
            'ship_contact_name' => $point['name'],
            'ship_phone' => $point['phone'],
            'origin_type' => $point['origin_type'],
            'shipment_point' => $point['shipment_point'],
            'cdek_city_code' => $point['cdek_city_code'],
            'ship_latitude' => $point['latitude'],
            'ship_longitude' => $point['longitude'],
            'item_weight' => $ship['item_weight'],
            'item_length' => $ship['item_length'],
            'item_width' => $ship['item_width'],
            'item_height' => $ship['item_height'],
            'packaging_id' => $ship['packaging_id'],
            'is_fragile' => $ship['is_fragile'] ? 1 : 0,
            'is_irregular' => $ship['is_irregular'] ? 1 : 0,
            'declared_value' => $ship['declared_cost'],
            'declared_currency' => $ship['declared_currency'],
            'package_count' => $ship['package_count'],
            'shipment_description' => $ship['description'],
            'shipping_ready' => 1,
            'cdek_ready' => $cdekReady,
        ];
        $listing->upsert($productId, $payload);

        return [
            'ok' => true,
            'data' => [
                'product_id' => $productId,
                'seller_user_id' => $sellerUserId,
                'cdek_ready' => $cdekReady,
                'point_a' => DeliveryPii::redactPayload($point),
            ],
        ];
    }

    /**
     * Point A на delivery_order (продавец = отправитель).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, field?: string}
     */
    public function savePointA(int $deliveryOrderId, int $actorId, array $input): array
    {
        $validated = $this->validator->validatePointA($input, false);
        if (!$validated['ok']) {
            return $validated;
        }

        return $this->delivery->saveSellerData($deliveryOrderId, $actorId, array_merge($input, $validated['data']));
    }

    /**
     * Point B (покупатель = получатель / заказчик доставки).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, field?: string}
     */
    public function savePointB(int $deliveryOrderId, int $actorId, array $input): array
    {
        $validated = $this->validator->validatePointB($input, false);
        if (!$validated['ok']) {
            return $validated;
        }

        return $this->delivery->saveBuyerData($deliveryOrderId, $actorId, array_merge($input, $validated['data']));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, field?: string}
     */
    public function saveShipment(int $deliveryOrderId, int $actorId, array $input): array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ((int) $row['seller_user_id'] !== $actorId) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        $validated = $this->validator->validateShipment($input);
        if (!$validated['ok']) {
            return $validated;
        }

        $data = $validated['data'];
        $this->orders->updateShipment($deliveryOrderId, array_merge($input, [
            'package_count' => $data['package_count'],
            'item_weight' => $data['item_weight'],
            'item_length' => $data['item_length'],
            'item_width' => $data['item_width'],
            'item_height' => $data['item_height'],
            'gross_weight' => $data['item_weight'],
            'package_length' => $data['item_length'],
            'package_width' => $data['item_width'],
            'package_height' => $data['item_height'],
            'billed_gross_weight' => $data['item_weight'],
            'billed_length' => $data['item_length'],
            'billed_width' => $data['item_width'],
            'billed_height' => $data['item_height'],
            'dimensions_unknown' => $data['dimensions_unknown'],
            'packaging_id' => $data['packaging_id'],
            'is_fragile' => $data['is_fragile'],
            'is_irregular' => $data['is_irregular'],
            'declared_cost' => $data['declared_cost'],
            'declared_currency' => $data['declared_currency'],
            'description' => $data['description'],
            'weight_source' => 'seller',
            'dimension_source' => 'seller',
            'measurement_status' => 'preliminary',
        ]));
        $this->orders->bumpShippingVersion($deliveryOrderId);

        return ['ok' => true];
    }

    /**
     * Создать/обновить внутренний delivery order для P2P покупки (idempotent).
     *
     * @param array<string, mixed> $p2pOrder
     * @return array{ok: bool, delivery_order_id?: int, error?: string, created?: bool}
     */
    public function ensureDeliveryOrderForPurchase(array $p2pOrder): array
    {
        $orderId = (int) ($p2pOrder['id'] ?? 0);
        if ($orderId <= 0) {
            return ['ok' => false, 'error' => 'invalid_order'];
        }
        $existing = $this->orders->findByP2pOrderId($orderId);
        if ($existing) {
            return [
                'ok' => true,
                'delivery_order_id' => (int) $existing['id'],
                'created' => false,
            ];
        }
        $id = $this->orders->createForP2pOrder($p2pOrder);
        return ['ok' => true, 'delivery_order_id' => $id, 'created' => true];
    }

    /**
     * Привязать подтверждённый delivery payment к delivery order.
     */
    public function bindPayment(int $deliveryOrderId, int $paymentId, int $amount): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $from = (string) ($row['status'] ?? '');
        $assert = $this->fsm->assertTransition($from, DeliveryOrder::STATUS_PAID);
        if (!$assert['ok'] && $from !== DeliveryOrder::STATUS_PAID) {
            // allow already paid idempotent bind
            if ($from !== DeliveryOrder::STATUS_PAID
                && $from !== DeliveryOrder::STATUS_CDEK_ORDER_PENDING
                && $from !== DeliveryOrder::STATUS_ORDER_CREATED
            ) {
                return $assert;
            }
        }

        $idempotencyKey = $this->orders->buildCreateIdempotencyKey($deliveryOrderId, $paymentId);

        $this->orders->updateFields($deliveryOrderId, [
            'payment_id' => $paymentId,
            'payment_status' => 'paid',
            'paid_amount' => $amount,
            'paid_at' => $row['paid_at'] ?? date('Y-m-d H:i:s'),
            'create_idempotency_key' => $idempotencyKey,
            'status' => $from === DeliveryOrder::STATUS_PAID
                || $from === DeliveryOrder::STATUS_CDEK_ORDER_PENDING
                || $from === DeliveryOrder::STATUS_ORDER_CREATED
                ? $from
                : DeliveryOrder::STATUS_PAID,
        ]);

        return ['ok' => true, 'create_idempotency_key' => $idempotencyKey];
    }

    /**
     * Пометить готовность к созданию CDEK-заказа БЕЗ вызова API (Phase 2 gate).
     *
     * @return array{ok: bool, error?: string, deferred?: bool, already?: bool}
     */
    public function markCreatePending(int $deliveryOrderId): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $uuid = (string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? '');
        if ($uuid !== '') {
            return ['ok' => true, 'already' => true, 'deferred' => false];
        }

        if (!$this->fsm->isCreateAllowed(
            (string) $row['status'],
            (string) ($row['cdek_api_status'] ?? DeliveryStatusMachine::API_NONE),
            $uuid !== '' ? $uuid : null
        )) {
            return ['ok' => false, 'error' => 'create_not_allowed'];
        }

        $from = (string) $row['status'];
        if ($from === DeliveryOrder::STATUS_PAID) {
            $assert = $this->fsm->assertTransition($from, DeliveryOrder::STATUS_CDEK_ORDER_PENDING);
            if (!$assert['ok']) {
                return $assert;
            }
        }

        $key = (string) ($row['create_idempotency_key'] ?? '');
        if ($key === '') {
            $paymentId = (int) ($row['payment_id'] ?? 0);
            $key = $this->orders->buildCreateIdempotencyKey($deliveryOrderId, $paymentId > 0 ? $paymentId : null);
        }

        $this->orders->updateFields($deliveryOrderId, [
            'status' => DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            'cdek_api_status' => DeliveryStatusMachine::API_PENDING,
            'create_idempotency_key' => $key,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);
        $this->orders->logEvent(
            $deliveryOrderId,
            null,
            'system',
            'cdek_create_deferred',
            $from,
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryPii::redactPayload(['reason' => 'order_create_disabled'])
        );

        return ['ok' => true, 'deferred' => true, 'already' => false];
    }

    /**
     * Сохранить идентификаторы CDEK (после будущего create / reconcile).
     * Идемпотентно: повтор с тем же uuid не создаёт дубль.
     *
     * @return array{ok: bool, error?: string, duplicate?: bool}
     */
    public function saveCdekIdentifiers(
        int $deliveryOrderId,
        ?string $uuid,
        ?string $cdekNumber = null,
        ?string $requestUuid = null,
        ?string $apiStatus = null
    ): array {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        $uuid = $uuid !== null ? trim($uuid) : '';
        $existingUuid = trim((string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? ''));

        if ($uuid !== '' && $existingUuid !== '' && $existingUuid !== $uuid) {
            return ['ok' => false, 'error' => 'cdek_uuid_conflict', 'duplicate' => true];
        }

        if ($uuid === '' && $existingUuid !== '') {
            $uuid = $existingUuid;
        }

        $fields = [
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
        if ($uuid !== '') {
            $fields['cdek_uuid'] = $uuid;
            $fields['logistics_order_id'] = $uuid;
        }
        if ($cdekNumber !== null && trim($cdekNumber) !== '') {
            $fields['cdek_number'] = trim($cdekNumber);
        }
        if ($requestUuid !== null && trim($requestUuid) !== '') {
            $fields['cdek_request_uuid'] = trim($requestUuid);
        }
        if ($apiStatus !== null && $apiStatus !== '') {
            $fields['cdek_api_status'] = $apiStatus;
        } elseif ($uuid !== '') {
            $fields['cdek_api_status'] = DeliveryStatusMachine::API_CREATED;
        }

        // Unique constraint protection: if another row already has this uuid
        if ($uuid !== '') {
            $other = $this->orders->findByCdekUuid($uuid);
            if ($other && (int) $other['id'] !== $deliveryOrderId) {
                return ['ok' => false, 'error' => 'cdek_uuid_taken', 'duplicate' => true];
            }
        }

        $this->orders->updateFields($deliveryOrderId, $fields);
        return ['ok' => true, 'duplicate' => $existingUuid !== '' && $existingUuid === $uuid];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function transitionStatus(int $deliveryOrderId, string $toStatus, ?int $actorId = null, string $role = 'system'): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $from = (string) ($row['status'] ?? '');
        $assert = $this->fsm->assertTransition($from, $toStatus);
        if (!$assert['ok']) {
            return $assert;
        }
        $this->orders->transitionStatus($deliveryOrderId, $toStatus, $actorId, $role, 'status_change');
        return ['ok' => true];
    }

    public function getSelectedQuote(int $deliveryOrderId): ?array
    {
        $row = $this->orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return null;
        }
        return is_array($row['selected_quote'] ?? null) ? $row['selected_quote'] : null;
    }

    public function statusMachine(): DeliveryStatusMachine
    {
        return $this->fsm;
    }

    public function validator(): DeliveryPointValidator
    {
        return $this->validator;
    }

    public static function isOrderCreateEnabled(): bool
    {
        $cfg = [];
        $path = dirname(__DIR__, 3) . '/config/cdek.php';
        if (is_file($path)) {
            $cfg = require $path;
        } else {
            $example = dirname(__DIR__, 3) . '/config/cdek.php.example';
            if (is_file($example)) {
                $cfg = require $example;
            }
        }
        $env = getenv('CDEK_ORDER_CREATE_ENABLED');
        if ($env !== false && $env !== '') {
            return (int) $env === 1;
        }
        return !empty($cfg['order_create_enabled']);
    }
}
