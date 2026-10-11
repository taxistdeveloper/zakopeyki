<?php

namespace App\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductListingShipping;
use App\Services\Cdek\CdekLocationService;
use App\Services\Cdek\CdekDeliveryPointsSyncService;
use App\Services\Listing\ListingShippingService;

/**
 * Point B покупателя (получатель + ПВЗ/дверь) для CDEK.
 *
 * Checkout собирает snapshot → orders.buyer_delivery_json →
 * после bootstrap delivery_order применяется в delivery_recipients.
 *
 * Quote calc / payment / CDEK create — следующие этапы (auto_quote=false при apply с checkout).
 */
class BuyerPointBService
{
    private DeliveryPointValidator $validator;
    private ListingShippingService $listingShipping;
    /** @var object{validateCode?: callable}|CdekDeliveryPointsSyncService */
    private object $points;
    private DeliveryService $delivery;
    private ?CdekLocationService $locations;

    /**
     * @param object|null $points CdekDeliveryPointsSyncService or stub with validateCode()
     */
    public function __construct(
        ?DeliveryPointValidator $validator = null,
        ?ListingShippingService $listingShipping = null,
        ?object $points = null,
        ?DeliveryService $delivery = null,
        ?CdekLocationService $locations = null
    ) {
        $this->validator = $validator ?? new DeliveryPointValidator();
        $this->listingShipping = $listingShipping ?? new ListingShippingService();
        $this->points = $points ?? new CdekDeliveryPointsSyncService();
        $this->delivery = $delivery ?? new DeliveryService();
        $this->locations = $locations;
    }

    /**
     * Доступные способы доставки для checkout по listing.
     *
     * @return list<string>
     */
    public function availableDeliveryMethods(int $productId, bool $digital = false): array
    {
        if ($digital) {
            return ['digital'];
        }

        $shipping = $this->listingShipping->findForProduct($productId);
        $mode = (string) ($shipping['fulfillment_mode'] ?? ProductListingShipping::FULFILLMENT_DELIVERY);
        $cdekReady = !empty($shipping['cdek_ready']) && $this->listingShipping->isCdekEnabled($mode);

        $methods = [];
        if (in_array($mode, [ProductListingShipping::FULFILLMENT_PICKUP, ProductListingShipping::FULFILLMENT_BOTH], true)) {
            $methods[] = 'pickup';
        }
        if ($cdekReady) {
            $methods[] = 'cdek';
        }
        if (in_array($mode, [ProductListingShipping::FULFILLMENT_DELIVERY, ProductListingShipping::FULFILLMENT_BOTH], true)) {
            // Legacy non-CDEK options remain available unless pickup-only.
            foreach (['kazpost', 'courier', 'other'] as $m) {
                $methods[] = $m;
            }
        }
        if ($methods === []) {
            $methods = ['kazpost', 'courier', 'other'];
        }
        return array_values(array_unique($methods));
    }

    public function isCdekSelectable(int $productId): bool
    {
        return in_array('cdek', $this->availableDeliveryMethods($productId), true);
    }

    /**
     * Валидация Point B на checkout (до создания delivery_order).
     *
     * @param array<string, mixed> $input
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   error_code?: string,
     *   field?: string,
     *   missing_fields?: list<string>,
     *   snapshot?: array<string, mixed>
     * }
     */
    public function validateForCheckout(int $productId, int $buyerId, string $deliveryMethod, array $input): array
    {
        if ($deliveryMethod !== 'cdek') {
            return ['ok' => true, 'snapshot' => null];
        }

        $product = (new Product())->find($productId);
        if (!$product) {
            return $this->fail('product_not_found', 'product_id', ['product_id']);
        }
        if ((int) ($product['user_id'] ?? 0) === $buyerId) {
            return $this->fail('own_product', 'product_id', ['product_id']);
        }

        $allowed = $this->availableDeliveryMethods($productId);
        if (!in_array('cdek', $allowed, true)) {
            return $this->fail('cdek_not_available', 'delivery_method', ['delivery_method']);
        }

        $shipping = $this->listingShipping->findForProduct($productId);
        if (!$shipping || empty($shipping['cdek_ready'])) {
            return $this->fail('point_a_missing', 'delivery_method', ['point_a']);
        }

        $weight = (float) ($shipping['gross_weight'] ?? $shipping['item_weight'] ?? 0);
        $hasDims = ($shipping['package_length'] ?? null) !== null
            || ($shipping['item_length'] ?? null) !== null
            || !empty($shipping['packaging_id']);
        if ($weight <= 0 || !$hasDims) {
            return $this->fail('shipment_incomplete', 'item_weight', ['shipment']);
        }

        $city = trim((string) ($input['city'] ?? $input['recipient_city'] ?? ''));
        $cityCode = $this->validator->nullableIntPublic($input['cdek_city_code'] ?? null);

        $normalized = [
            'name' => $input['name'] ?? $input['recipient_name'] ?? '',
            'phone' => $input['phone'] ?? $input['recipient_phone'] ?? '',
            'email' => $input['email'] ?? $input['recipient_email'] ?? null,
            'delivery_mode' => $input['delivery_mode'] ?? $input['cdek_delivery_mode'] ?? 'pvz',
            'country' => $input['country'] ?? $input['recipient_country'] ?? 'KZ',
            'region' => $input['region'] ?? $input['recipient_region'] ?? null,
            'city' => $city,
            'street' => $input['street'] ?? $input['recipient_street'] ?? null,
            'building' => $input['building'] ?? $input['recipient_building'] ?? null,
            'apartment' => $input['apartment'] ?? $input['recipient_apartment'] ?? null,
            'postal_code' => $input['postal_code'] ?? $input['recipient_postal_code'] ?? null,
            'pvz_code' => $input['pvz_code'] ?? $input['delivery_point'] ?? null,
            'pvz_name' => $input['pvz_name'] ?? null,
            'delivery_point' => $input['delivery_point'] ?? $input['pvz_code'] ?? null,
            'cdek_city_code' => $cityCode,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
            'notes' => $input['notes'] ?? $input['recipient_notes'] ?? null,
        ];

        $pointB = $this->validator->validatePointB($normalized, true);
        if (!$pointB['ok']) {
            $code = (string) ($pointB['error'] ?? 'validation_failed');
            $field = (string) ($pointB['field'] ?? 'city');
            return $this->fail($code, $field, [$field], $this->messageFor($code));
        }

        $data = $pointB['data'];
        $mode = (string) $data['delivery_mode'];
        $locations = $this->locations ?? new CdekLocationService();
        $country = (string) ($data['country'] ?? 'KZ');

        if (empty($data['cdek_city_code'])) {
            $suggested = $locations->suggestCities((string) $data['city'], $country);
            if (!($suggested['ok'] ?? false) || ($suggested['cities'] ?? []) === []) {
                return $this->fail('cdek_city_code_required', 'city', ['city'], $suggested['error'] ?? null);
            }
            if (count($suggested['cities']) !== 1) {
                return $this->fail('city_ambiguous', 'city', ['cdek_city_code']);
            }
            $data['cdek_city_code'] = (int) $suggested['cities'][0]['code'];
        }

        $cityOk = $locations->confirmCity((int) $data['cdek_city_code'], $country);
        if (!($cityOk['ok'] ?? false)) {
            return $this->fail('cdek_city_code_required', 'cdek_city_code', ['cdek_city_code'], $cityOk['error'] ?? null);
        }
        $data['cdek_city_code'] = (int) $cityOk['city']['code'];
        if (!empty($cityOk['city']['city'])) {
            $data['city'] = (string) $cityOk['city']['city'];
        }
        if (!empty($cityOk['city']['region'])) {
            $data['region'] = (string) $cityOk['city']['region'];
        }
        $data['country'] = (string) ($cityOk['city']['country_code'] ?? $country);

        $shipmentFilters = [
            'city_code' => (int) $data['cdek_city_code'],
            'country_code' => $data['country'],
            'weight_kg' => $weight > 0 ? $weight : null,
            'length_cm' => (int) round((float) ($shipping['package_length'] ?? $shipping['item_length'] ?? 0)),
            'width_cm' => (int) round((float) ($shipping['package_width'] ?? $shipping['item_width'] ?? 0)),
            'height_cm' => (int) round((float) ($shipping['package_height'] ?? $shipping['item_height'] ?? 0)),
            'type' => 'ALL',
        ];

        if ($mode === DeliveryPointValidator::MODE_PVZ) {
            $pvzCode = (string) ($data['pvz_code'] ?? '');
            $validated = $locations->confirmDeliveryPoint($pvzCode, $shipmentFilters);
            if (!($validated['ok'] ?? false)) {
                return $this->fail('pvz_invalid', 'pvz_code', ['pvz_code'], (string) ($validated['error'] ?? t('delivery.pvz_not_found')));
            }
            $point = $validated['point'];
            $data['pvz_code'] = (string) $point['code'];
            $data['delivery_point'] = (string) $point['code'];
            $data['pvz_name'] = (string) ($point['name'] ?? $point['address'] ?? $point['code']);
            if (!empty($point['city'])) {
                $data['city'] = (string) $point['city'];
            }
            if (!empty($point['city_code'])) {
                $data['cdek_city_code'] = (int) $point['city_code'];
            }
            $data['latitude'] = $point['latitude'] ?? null;
            $data['longitude'] = $point['longitude'] ?? null;
            $data['postal_code'] = $point['postal_code'] ?: ($data['postal_code'] ?? null);
            $data['point_snapshot'] = $point;
        } else {
            $postal = (string) ($data['postal_code'] ?? '');
            if ($postal !== '' && !$this->validator->isValidPostalCode($postal, (string) $data['country'])) {
                return $this->fail('recipient_postal_invalid', 'postal_code', ['postal_code']);
            }
            if ($postal !== '') {
                $codes = $locations->postalCodes((int) $data['cdek_city_code']);
                if (($codes['ok'] ?? false) && ($codes['postal_codes'] ?? []) !== [] && !in_array($postal, $codes['postal_codes'], true)) {
                    return $this->fail('recipient_postal_invalid', 'postal_code', ['postal_code']);
                }
                $data['postal_verified'] = (bool) ($codes['ok'] ?? false);
            }
            $data['address_verified'] = false;
        }

        $snapshot = [
            'version' => 1,
            'delivery_method' => 'cdek',
            'captured_at' => date('c'),
            'product_id' => $productId,
            'buyer_user_id' => $buyerId,
            'listing_shipping_version' => (int) ($shipping['shipping_version'] ?? 1),
            'point_b' => $data,
        ];

        return ['ok' => true, 'snapshot' => $snapshot];
    }

    /**
     * Применить snapshot к delivery_order (после bootstrap).
     * auto_quote=false — Phase 5 не запускает расчёт.
     *
     * @param array<string, mixed> $snapshot
     * @return array{ok: bool, error?: string}
     */
    public function applySnapshotToDeliveryOrder(int $deliveryOrderId, int $buyerId, array $snapshot, bool $autoQuote = false): array
    {
        $pointB = is_array($snapshot['point_b'] ?? null) ? $snapshot['point_b'] : $snapshot;
        if ($pointB === []) {
            return ['ok' => false, 'error' => 'empty_snapshot'];
        }

        return $this->delivery->saveBuyerData($deliveryOrderId, $buyerId, $pointB, [
            'auto_quote' => $autoQuote,
        ]);
    }

    /**
     * @return array{ok: bool, error?: string, recipient?: array<string, mixed>|null}
     */
    public function getPointB(int $deliveryOrderId, int $actorId): array
    {
        $orders = new DeliveryOrder();
        $row = $orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $uid = $actorId;
        $isBuyer = (int) ($row['buyer_user_id'] ?? 0) === $uid;
        $isSeller = (int) ($row['seller_user_id'] ?? 0) === $uid;
        if (!$isBuyer && !$isSeller) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        return ['ok' => true, 'recipient' => $row['recipient'] ?? null];
    }

    /**
     * Обновить Point B на существующем delivery_order (покупатель).
     * Изменение инвалидирует quotes (внутри saveBuyerData).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error?: string, error_code?: string, field?: string}
     */
    public function updatePointB(int $deliveryOrderId, int $buyerId, array $input, bool $autoQuote = false): array
    {
        $orders = new DeliveryOrder();
        $row = $orders->findWithDetails($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found', 'error_code' => 'not_found'];
        }
        if ((int) ($row['buyer_user_id'] ?? 0) !== $buyerId) {
            return ['ok' => false, 'error' => 'forbidden', 'error_code' => 'forbidden'];
        }

        // После оплаты доставки / создания CDEK — ограничить (GAP: полная матрица позже).
        $status = (string) ($row['status'] ?? '');
        $locked = [
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
            DeliveryOrder::STATUS_ORDER_CREATED,
            DeliveryOrder::STATUS_ACCEPTED,
            DeliveryOrder::STATUS_SHIPMENT_RECEIVED,
            DeliveryOrder::STATUS_IN_TRANSIT,
            DeliveryOrder::STATUS_DELIVERED,
        ];
        if (in_array($status, $locked, true) || !empty($row['cdek_uuid'])) {
            return [
                'ok' => false,
                'error' => t('delivery.point_b_locked'),
                'error_code' => 'point_b_locked',
                'field' => 'delivery_mode',
            ];
        }

        // Re-validate PVZ / city via same path as checkout when possible.
        $productId = (int) ($row['product_id'] ?? 0);
        if ($productId > 0) {
            $check = $this->validateForCheckout($productId, $buyerId, 'cdek', $input);
            if (!$check['ok']) {
                return $check;
            }
            $pointB = $check['snapshot']['point_b'] ?? $input;
            return $this->delivery->saveBuyerData($deliveryOrderId, $buyerId, $pointB, [
                'auto_quote' => $autoQuote,
            ]);
        }

        return $this->delivery->saveBuyerData($deliveryOrderId, $buyerId, $input, [
            'auto_quote' => $autoQuote,
        ]);
    }

    public function messageFor(string $code): string
    {
        $map = [
            'product_not_found' => 'checkout.unavailable',
            'own_product' => 'checkout.own_product',
            'cdek_not_available' => 'checkout.cdek_not_available',
            'point_a_missing' => 'checkout.cdek_point_a_missing',
            'shipment_incomplete' => 'checkout.cdek_shipment_incomplete',
            'recipient_name_required' => 'delivery.recipient_required',
            'recipient_phone_invalid' => 'delivery.recipient_required',
            'recipient_city_required' => 'delivery.recipient_required',
            'recipient_address_required' => 'delivery.address_required',
            'pvz_required' => 'delivery.pvz_required',
            'pvz_invalid' => 'delivery.pvz_not_found',
            'cdek_city_code_required' => 'listing_shipping.cdek_city_required',
            'recipient_postal_invalid' => 'listing_shipping.postal_invalid',
            'point_b_locked' => 'delivery.point_b_locked',
            'forbidden' => 'delivery.forbidden',
        ];
        $key = $map[$code] ?? 'checkout.cdek_point_b_invalid';
        return t($key);
    }

    /**
     * @param list<string> $missing
     * @return array{ok:false,error:string,error_code:string,field:string,missing_fields:list<string>}
     */
    private function fail(string $code, string $field, array $missing, ?string $message = null): array
    {
        return [
            'ok' => false,
            'error' => $message ?? $this->messageFor($code),
            'error_code' => $code,
            'field' => $field,
            'missing_fields' => array_values($missing),
        ];
    }
}
