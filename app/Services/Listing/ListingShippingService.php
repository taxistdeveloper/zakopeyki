<?php

namespace App\Services\Listing;

use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\ProductListingShipping;
use App\Models\User;
use App\Services\Cdek\Client;
use App\Services\Delivery\DeliveryPointValidator;
use App\Services\Delivery\PackagingRecommendationService;

/**
 * Shipping snapshot на объявлении (Point A + параметры отправления).
 *
 * CDEK path (fulfillment delivery|both): серверный gate перед публикацией.
 * Pickup-only: CDEK Point A не требуется.
 * Snapshot не привязан к текущему профилю — смена адреса пользователя не меняет уже сохранённый Point A.
 */
class ListingShippingService
{
    public const PHYSICAL_TYPES = ['used', 'new', 'auction'];
    public const OPTIONAL_TYPES = ['free', 'exchange'];

    /** @var array<string, array{weight: float, l: float, w: float, h: float, pack: string}> */
    public const TYPE_HINTS = [
        'phone' => ['weight' => 0.4, 'l' => 18, 'w' => 10, 'h' => 4, 'pack' => 'S'],
        'laptop' => ['weight' => 2.3, 'l' => 35, 'w' => 25, 'h' => 3, 'pack' => 'M'],
        'shoes' => ['weight' => 0.8, 'l' => 35, 'w' => 25, 'h' => 12, 'pack' => 'M'],
        'clothes' => ['weight' => 0.5, 'l' => 35, 'w' => 25, 'h' => 5, 'pack' => 'M'],
        'tv' => ['weight' => 8.0, 'l' => 120, 'w' => 70, 'h' => 8, 'pack' => 'XL'],
        'furniture' => ['weight' => 15.0, 'l' => 80, 'w' => 60, 'h' => 40, 'pack' => 'XL'],
        'tools' => ['weight' => 3.0, 'l' => 40, 'w' => 30, 'h' => 15, 'pack' => 'L'],
        'other' => ['weight' => 1.0, 'l' => 25, 'w' => 20, 'h' => 10, 'pack' => 'M'],
    ];

    /** @var callable|null fn(string $city, ?string $country): ?array{code:int,city?:string,country_code?:string} */
    private $cityResolver;

    /** @var callable|null fn(): list<array<string,mixed>> */
    private $packagingsProvider;

    /** @var callable|null fn(int $productId): bool */
    private $lockedDeliveryChecker;

    private DeliveryPointValidator $validator;

    /**
     * @param callable|null $cityResolver test double / custom resolver
     * @param callable|null $packagingsProvider
     * @param callable|null $lockedDeliveryChecker
     */
    public function __construct(
        ?DeliveryPointValidator $validator = null,
        ?callable $cityResolver = null,
        ?callable $packagingsProvider = null,
        ?callable $lockedDeliveryChecker = null
    ) {
        $this->validator = $validator ?? new DeliveryPointValidator();
        $this->cityResolver = $cityResolver;
        $this->packagingsProvider = $packagingsProvider;
        $this->lockedDeliveryChecker = $lockedDeliveryChecker;
    }

    public function needsShippingBlock(string $type): bool
    {
        return in_array($type, array_merge(self::PHYSICAL_TYPES, self::OPTIONAL_TYPES), true);
    }

    public function requiresShippingValidation(string $type, string $fulfillmentMode): bool
    {
        if (!in_array($type, self::PHYSICAL_TYPES, true)) {
            return false;
        }
        return in_array($fulfillmentMode, [
            ProductListingShipping::FULFILLMENT_DELIVERY,
            ProductListingShipping::FULFILLMENT_BOTH,
        ], true);
    }

    /** CDEK доставка включена для объявления (не самовывоз-only). */
    public function isCdekEnabled(string $fulfillmentMode): bool
    {
        return in_array($fulfillmentMode, [
            ProductListingShipping::FULFILLMENT_DELIVERY,
            ProductListingShipping::FULFILLMENT_BOTH,
        ], true);
    }

    public function packagings(): array
    {
        if (is_callable($this->packagingsProvider)) {
            $rows = ($this->packagingsProvider)();
            return is_array($rows) ? $rows : [];
        }
        try {
            return (new DeliveryOrder())->packagingsForProvider((new DeliveryOrder())->defaultProviderId());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   error_code?: string,
     *   field?: string,
     *   missing_fields?: list<string>,
     *   data?: array<string, mixed>,
     *   checklist?: array<string, bool>
     * }
     */
    public function validateAndBuild(int $userId, string $productType, array $post, ?array $user = null): array
    {
        if (!$this->needsShippingBlock($productType)) {
            return ['ok' => true, 'data' => ['shipping_ready' => 0, 'cdek_ready' => 0]];
        }

        $fulfillment = in_array($post['fulfillment_mode'] ?? '', [
            ProductListingShipping::FULFILLMENT_DELIVERY,
            ProductListingShipping::FULFILLMENT_PICKUP,
            ProductListingShipping::FULFILLMENT_BOTH,
        ], true) ? $post['fulfillment_mode'] : ProductListingShipping::FULFILLMENT_DELIVERY;

        // Только самовывоз — CDEK Point A не блокирует публикацию.
        if (!$this->requiresShippingValidation($productType, $fulfillment)) {
            return [
                'ok' => true,
                'data' => [
                    'fulfillment_mode' => $fulfillment,
                    'param_mode' => ProductListingShipping::MODE_EXACT,
                    'shipping_ready' => 1,
                    'cdek_ready' => 0,
                    'ship_city' => trim((string) ($post['location'] ?? $post['ship_city'] ?? '')) ?: null,
                    'origin_type' => DeliveryPointValidator::ORIGIN_DOOR,
                    'shipment_point' => null,
                    'cdek_city_code' => null,
                ],
            ];
        }

        $user = $user ?? (new User())->find($userId);
        $useDefault = !empty($post['use_default_ship_from']);
        $paramMode = in_array($post['param_mode'] ?? '', [
            ProductListingShipping::MODE_EXACT,
            ProductListingShipping::MODE_STANDARD,
            ProductListingShipping::MODE_UNKNOWN,
        ], true) ? $post['param_mode'] : ProductListingShipping::MODE_EXACT;

        $ship = $this->resolveShipFrom($user, $post, $useDefault);
        if ($ship['error'] ?? null) {
            return $this->fail(
                (string) $ship['error'],
                (string) ($ship['error_code'] ?? 'ship_from_required'),
                (string) ($ship['field'] ?? 'ship_city'),
                $ship['missing_fields'] ?? ['ship_city', 'ship_contact_name', 'ship_phone']
            );
        }

        // Resolve CDEK city code (seller input or Location API / known codes).
        $country = (string) ($ship['ship_country'] ?? 'KZ');
        $cityCode = $this->validator->nullableIntPublic($post['cdek_city_code'] ?? $ship['cdek_city_code'] ?? null);
        if ($cityCode === null) {
            $resolved = $this->resolveCityCode((string) $ship['ship_city'], $country);
            if ($resolved !== null) {
                $cityCode = (int) $resolved['code'];
            }
        }
        $ship['cdek_city_code'] = $cityCode;

        $originType = (string) ($post['origin_type'] ?? $ship['origin_type'] ?? DeliveryPointValidator::ORIGIN_DOOR);
        if (!in_array($originType, [DeliveryPointValidator::ORIGIN_DOOR, DeliveryPointValidator::ORIGIN_PVZ], true)) {
            $originType = DeliveryPointValidator::ORIGIN_DOOR;
        }
        $ship['origin_type'] = $originType;
        $ship['shipment_point'] = trim((string) ($post['shipment_point'] ?? $ship['shipment_point'] ?? '')) ?: null;
        $ship['ship_latitude'] = $post['ship_latitude'] ?? $ship['ship_latitude'] ?? null;
        $ship['ship_longitude'] = $post['ship_longitude'] ?? $ship['ship_longitude'] ?? null;

        $pointA = $this->validator->validatePointA([
            'name' => $ship['ship_contact_name'],
            'phone' => $ship['ship_phone'],
            'city' => $ship['ship_city'],
            'country' => $ship['ship_country'],
            'region' => $ship['ship_region'],
            'street' => $ship['ship_street'],
            'building' => $ship['ship_building'],
            'apartment' => $ship['ship_apartment'],
            'postal_code' => $ship['ship_postal_code'],
            'origin_type' => $originType,
            'shipment_point' => $ship['shipment_point'],
            'cdek_city_code' => $cityCode,
            'latitude' => $ship['ship_latitude'],
            'longitude' => $ship['ship_longitude'],
        ], true);

        if (!$pointA['ok']) {
            return $this->mapValidatorFail($pointA);
        }

        $packReco = new PackagingRecommendationService();
        $packagings = $this->packagings();
        $packagingId = (int) ($post['packaging_id'] ?? 0);
        $itemWeight = $this->float($post['item_weight'] ?? null);
        $itemL = $this->float($post['item_length'] ?? null);
        $itemW = $this->float($post['item_width'] ?? null);
        $itemH = $this->float($post['item_height'] ?? null);
        $packagingWeight = $this->float($post['packaging_weight'] ?? null) ?? $packReco->defaultPackagingWeightKg();
        $autoPackWeight = !empty($post['auto_packaging_weight']);
        $typeHint = trim((string) ($post['product_type_hint'] ?? '')) ?: null;
        $dimensionsUnknown = false;

        if ($paramMode === ProductListingShipping::MODE_UNKNOWN) {
            $hint = self::TYPE_HINTS[$typeHint ?? 'other'] ?? self::TYPE_HINTS['other'];
            if ($itemWeight === null) {
                $itemWeight = $hint['weight'];
            }
            if ($itemL === null) {
                $itemL = $hint['l'];
                $itemW = $hint['w'];
                $itemH = $hint['h'];
            }
            if ($packagingId <= 0) {
                foreach ($packagings as $pack) {
                    if (($pack['code'] ?? '') === $hint['pack']) {
                        $packagingId = (int) $pack['id'];
                        break;
                    }
                }
            }
            $paramMode = ProductListingShipping::MODE_STANDARD;
            $dimensionsUnknown = true;
        }

        if ($paramMode === ProductListingShipping::MODE_STANDARD) {
            $dimensionsUnknown = true;
            if ($packagingId <= 0) {
                return $this->fail(
                    t('listing_shipping.packaging_required'),
                    'packaging_required',
                    'packaging_id',
                    ['packaging_id']
                );
            }
        }

        $pack = $packagingId > 0 ? (new DeliveryOrder())->packagingById($packagingId) : null;
        $reco = $packReco->recommend($packagings, $itemWeight, $itemL, $itemW, $itemH);
        $recommendedId = $reco['recommended']['id'] ?? null;

        if ($pack) {
            $valid = $packReco->validateSelection($pack, $itemWeight, $itemL, $itemW, $itemH);
            if (!$valid['ok']) {
                return $this->fail(
                    (string) ($valid['error'] ?? t('listing_shipping.packaging_incompatible')),
                    'packaging_incompatible',
                    'packaging_id',
                    ['packaging_id']
                );
            }
        }

        $shipmentCheck = $this->validator->validateShipment([
            'item_weight' => $itemWeight,
            'item_length' => $itemL,
            'item_width' => $itemW,
            'item_height' => $itemH,
            'dimensions_unknown' => $dimensionsUnknown,
            'packaging_id' => $packagingId,
            'package_count' => $post['package_count'] ?? 1,
            'declared_cost' => $post['declared_value'] ?? $post['declared_cost'] ?? $post['price'] ?? null,
            'declared_currency' => $post['declared_currency'] ?? 'KZT',
            'description' => $post['shipment_description'] ?? $post['description'] ?? null,
            'is_fragile' => $post['is_fragile'] ?? 0,
            'is_irregular' => $post['is_irregular'] ?? 0,
        ]);
        if (!$shipmentCheck['ok']) {
            return $this->mapValidatorFail($shipmentCheck);
        }

        if ($autoPackWeight && $pack) {
            $packagingWeight = $packReco->defaultPackagingWeightKg();
        }

        $packageL = $pack ? (float) $pack['length_cm'] : $this->float($post['package_length'] ?? $post['item_length'] ?? null);
        $packageW = $pack ? (float) $pack['width_cm'] : $this->float($post['package_width'] ?? $post['item_width'] ?? null);
        $packageH = $pack ? (float) $pack['height_cm'] : $this->float($post['package_height'] ?? $post['item_height'] ?? null);

        $shipData = $shipmentCheck['data'];
        $gross = ($shipData['item_weight'] ?? $itemWeight ?? 0) + ($dimensionsUnknown ? ($packagingWeight ?? 0) : ($packagingWeight ?? 0));
        if ($gross <= 0 && ($shipData['item_weight'] ?? null) !== null) {
            $gross = (float) $shipData['item_weight'];
        }
        if ($gross <= 0) {
            $gross = 1.0;
        }

        $point = $pointA['data'];
        $data = [
            'fulfillment_mode' => $fulfillment,
            'param_mode' => $paramMode,
            'use_default_ship_from' => $useDefault ? 1 : 0,
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
            'packaging_id' => $packagingId > 0 ? $packagingId : null,
            'recommended_packaging_id' => $recommendedId,
            'packaging_name_snapshot' => $pack['name'] ?? null,
            'product_type_hint' => $typeHint,
            'item_weight' => $shipData['item_weight'] ?? $itemWeight,
            'packaging_weight' => $packagingWeight,
            'gross_weight' => $gross,
            'item_length' => $shipData['item_length'] ?? $itemL,
            'item_width' => $shipData['item_width'] ?? $itemW,
            'item_height' => $shipData['item_height'] ?? $itemH,
            'package_length' => $packageL,
            'package_width' => $packageW,
            'package_height' => $packageH,
            'package_count' => $shipData['package_count'],
            'shipment_description' => $shipData['description'],
            'is_irregular' => !empty($shipData['is_irregular']) ? 1 : 0,
            'irregular_reason' => !empty($shipData['is_irregular'])
                ? (trim((string) ($post['irregular_reason'] ?? 'other')) ?: 'other')
                : null,
            'is_fragile' => !empty($shipData['is_fragile']) ? 1 : 0,
            'declared_value' => $shipData['declared_cost'],
            'declared_currency' => $shipData['declared_currency'],
            'shipping_ready' => 1,
            'cdek_ready' => 1,
        ];

        if (!empty($post['save_default_ship_from']) && $user) {
            // Профиль — только мягкий default; snapshot объявления уже зафиксирован в $data.
            (new User())->saveDefaultShipFrom($userId, [
                'ship_country' => $data['ship_country'],
                'ship_region' => $data['ship_region'],
                'ship_city' => $data['ship_city'],
                'ship_street' => $data['ship_street'],
                'ship_building' => $data['ship_building'],
                'ship_apartment' => $data['ship_apartment'],
                'ship_postal_code' => $data['ship_postal_code'],
                'ship_contact_name' => $data['ship_contact_name'],
                'ship_phone' => $data['ship_phone'],
            ]);
        }

        return [
            'ok' => true,
            'data' => $data,
            'checklist' => $this->publishChecklist($productType, $data),
            'recommendation' => $reco,
        ];
    }

    /**
     * Сохранить shipping snapshot только для объявления текущего продавца.
     *
     * @return array{ok: bool, error?: string, error_code?: string}
     */
    public function saveForOwnedProduct(int $productId, int $userId, array $data): array
    {
        $product = (new Product())->find($productId);
        if (!$product || (int) ($product['user_id'] ?? 0) !== $userId) {
            return ['ok' => false, 'error' => t('listing_shipping.forbidden'), 'error_code' => 'forbidden'];
        }

        if ($this->hasLockedDelivery($productId) && $this->isCdekEnabled((string) ($data['fulfillment_mode'] ?? ''))) {
            $existing = $this->findForProduct($productId);
            if ($existing && $this->shippingFingerprint($existing) !== $this->shippingFingerprint(array_merge($existing, $data))) {
                return [
                    'ok' => false,
                    'error' => t('listing_shipping.locked_by_delivery'),
                    'error_code' => 'shipping_locked',
                    'field' => 'fulfillment_mode',
                ];
            }
        }

        $this->saveForProduct($productId, $data);
        return ['ok' => true];
    }

    public function saveForProduct(int $productId, array $data): void
    {
        (new ProductListingShipping())->upsert($productId, $data);
    }

    public function findForProduct(int $productId): ?array
    {
        return (new ProductListingShipping())->findByProductId($productId);
    }

    /** Есть оплаченная / созданная CDEK-доставка по этому объявлению — Point A нельзя тихо менять. */
    public function hasLockedDelivery(int $productId): bool
    {
        if (is_callable($this->lockedDeliveryChecker)) {
            return (bool) ($this->lockedDeliveryChecker)($productId);
        }
        try {
            return (new DeliveryOrder())->hasLockedDeliveryForProduct($productId);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function buyerSummary(?array $shipping, string $location): array
    {
        if (!$shipping) {
            return [
                'fulfillment_label' => t('product.delivery_kz'),
                'price_note' => t('listing_shipping.buyer_price_after_address'),
                'has_delivery' => true,
            ];
        }

        $mode = $shipping['fulfillment_mode'] ?? 'delivery';
        $labels = [
            'delivery' => t('listing_shipping.fulfillment_delivery'),
            'pickup' => t('listing_shipping.fulfillment_pickup'),
            'both' => t('listing_shipping.fulfillment_both'),
        ];

        $fromCity = $shipping['ship_city'] ?? $location;
        $packName = $shipping['packaging_name_snapshot'] ?? null;
        if (!$packName && !empty($shipping['packaging_id'])) {
            $pack = (new DeliveryOrder())->packagingById((int) $shipping['packaging_id']);
            $packName = $pack['name'] ?? null;
        }

        return [
            'fulfillment_label' => $labels[$mode] ?? $labels['delivery'],
            'from_city' => $fromCity,
            'packaging' => $packName,
            'gross_weight' => $shipping['gross_weight'] ?? null,
            'package_dims' => $this->formatDims($shipping),
            'price_note' => t('listing_shipping.buyer_price_after_address'),
            'price_hint' => t('listing_shipping.buyer_price_from_hint'),
            'has_delivery' => in_array($mode, ['delivery', 'both'], true),
            'has_pickup' => in_array($mode, ['pickup', 'both'], true),
            'cdek_ready' => !empty($shipping['cdek_ready']),
        ];
    }

    /** Prefill delivery order from listing (post-purchase). */
    public function applyToDeliveryOrder(int $productId, int $deliveryOrderId, int $sellerId): void
    {
        $shipping = $this->findForProduct($productId);
        if (!$shipping) {
            return;
        }

        $orders = new DeliveryOrder();
        $orders->upsertSender($deliveryOrderId, [
            'name' => $shipping['ship_contact_name'] ?? '',
            'phone' => $shipping['ship_phone'] ?? '',
            'city' => $shipping['ship_city'] ?? '',
            'region' => $shipping['ship_region'] ?? null,
            'street' => $shipping['ship_street'] ?? null,
            'building' => $shipping['ship_building'] ?? null,
            'apartment' => $shipping['ship_apartment'] ?? null,
            'postal_code' => $shipping['ship_postal_code'] ?? null,
            'country' => $shipping['ship_country'] ?? 'KZ',
            'origin_type' => $shipping['origin_type'] ?? 'door',
            'shipment_point' => $shipping['shipment_point'] ?? null,
            'cdek_city_code' => $shipping['cdek_city_code'] ?? null,
            'latitude' => $shipping['ship_latitude'] ?? null,
            'longitude' => $shipping['ship_longitude'] ?? null,
        ]);

        $orders->updateShipment($deliveryOrderId, [
            'weight_value' => $shipping['gross_weight'],
            'gross_weight' => $shipping['gross_weight'],
            'item_weight' => $shipping['item_weight'],
            'packaging_weight' => $shipping['packaging_weight'],
            'item_length' => $shipping['item_length'],
            'item_width' => $shipping['item_width'],
            'item_height' => $shipping['item_height'],
            'package_length' => $shipping['package_length'],
            'package_width' => $shipping['package_width'],
            'package_height' => $shipping['package_height'],
            'length_value' => $shipping['package_length'],
            'width_value' => $shipping['package_width'],
            'height_value' => $shipping['package_height'],
            'package_count' => $shipping['package_count'] ?? 1,
            'description' => $shipping['shipment_description'] ?? null,
            'packaging_id' => $shipping['packaging_id'],
            'packaging_name_snapshot' => $shipping['packaging_name_snapshot'],
            'recommended_packaging_id' => $shipping['recommended_packaging_id'],
            'dimensions_unknown' => ($shipping['param_mode'] ?? '') === ProductListingShipping::MODE_STANDARD ? 1 : 0,
            'is_fragile' => $shipping['is_fragile'] ?? 0,
            'is_irregular' => $shipping['is_irregular'] ?? 0,
            'irregular_reason' => $shipping['irregular_reason'] ?? null,
            'declared_cost' => $shipping['declared_value'] ?? null,
            'declared_currency' => $shipping['declared_currency'] ?? 'KZT',
            'measurement_status' => 'preliminary',
            'weight_source' => 'seller',
            'dimension_source' => ($shipping['param_mode'] ?? '') === ProductListingShipping::MODE_STANDARD ? 'packaging' : 'seller',
        ]);

        $orders->updateFields($deliveryOrderId, [
            'sender_id' => $sellerId,
            'data_completeness_status' => 'seller_prefilled',
            'shipping_version' => (int) ($shipping['shipping_version'] ?? 1),
            'listing_shipping_version' => (int) ($shipping['shipping_version'] ?? 1),
        ]);
        $orders->logEvent($deliveryOrderId, $sellerId, 'system', 'prefilled_from_listing', null, null, [
            'product_id' => $productId,
            'shipping_version' => (int) ($shipping['shipping_version'] ?? 1),
        ]);
    }

    /** @return array<string, bool> */
    public function publishChecklist(string $type, array $shipping): array
    {
        $cdek = $this->isCdekEnabled((string) ($shipping['fulfillment_mode'] ?? ''));
        return [
            'fulfillment' => !empty($shipping['fulfillment_mode']),
            'ship_from' => !empty($shipping['ship_city']),
            'params' => !empty($shipping['shipping_ready']),
            'weight' => ($shipping['gross_weight'] ?? 0) > 0 || ($shipping['fulfillment_mode'] ?? '') === 'pickup',
            'packaging' => !empty($shipping['packaging_id']) || ($shipping['param_mode'] ?? '') === 'exact',
            'cdek_ready' => !$cdek || !empty($shipping['cdek_ready']),
            'cdek_city' => !$cdek || !empty($shipping['cdek_city_code']),
            'point_a_address' => !$cdek || (
                ($shipping['origin_type'] ?? '') === 'pvz'
                    ? !empty($shipping['shipment_point'])
                    : !empty($shipping['ship_street'])
            ),
        ];
    }

    /**
     * Человекочитаемое сообщение по коду валидатора.
     */
    public function messageForErrorCode(string $code): string
    {
        $map = [
            'sender_name_required' => 'listing_shipping.contact_required',
            'sender_phone_invalid' => 'listing_shipping.phone_invalid',
            'sender_city_required' => 'listing_shipping.city_required',
            'sender_address_required' => 'listing_shipping.address_required',
            'sender_postal_invalid' => 'listing_shipping.postal_invalid',
            'cdek_city_code_required' => 'listing_shipping.cdek_city_required',
            'shipment_point_required' => 'listing_shipping.shipment_point_required',
            'weight_required' => 'listing_shipping.item_weight_required',
            'weight_invalid' => 'listing_shipping.weight_invalid',
            'weight_too_large' => 'listing_shipping.weight_invalid',
            'dimensions_required' => 'listing_shipping.package_dims_required',
            'dimensions_invalid' => 'listing_shipping.dims_invalid',
            'dimensions_too_large' => 'listing_shipping.dims_invalid',
            'packaging_required' => 'listing_shipping.packaging_required',
            'forbidden' => 'listing_shipping.forbidden',
            'shipping_locked' => 'listing_shipping.locked_by_delivery',
        ];
        $key = $map[$code] ?? 'listing_shipping.validation_failed';
        return t($key);
    }

    /** @return array{code: int, city?: string, country_code?: string}|null */
    public function resolveCityCode(string $city, ?string $country = null): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }
        if (is_callable($this->cityResolver)) {
            $r = ($this->cityResolver)($city, $country);
            return is_array($r) && !empty($r['code']) ? $r : null;
        }
        try {
            return (new Client())->findCityCode($city, $country);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{ok:false,error:string,field?:string} $result
     * @return array{ok:false,error:string,error_code:string,field:string,missing_fields:list<string>}
     */
    private function mapValidatorFail(array $result): array
    {
        $code = (string) ($result['error'] ?? 'validation_failed');
        $field = (string) ($result['field'] ?? 'ship_city');
        return $this->fail($this->messageForErrorCode($code), $code, $field, [$field]);
    }

    /**
     * @param list<string> $missing
     * @return array{ok:false,error:string,error_code:string,field:string,missing_fields:list<string>}
     */
    private function fail(string $message, string $code, string $field, array $missing): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'error_code' => $code,
            'field' => $field,
            'missing_fields' => array_values($missing),
        ];
    }

    /** @return array<string, mixed> */
    private function resolveShipFrom(?array $user, array $post, bool $useDefault): array
    {
        if ($useDefault && $user) {
            $def = User::defaultShipFrom($user);
            if (($def['ship_city'] ?? '') !== '') {
                // Snapshot: копируем значения профиля на момент сохранения, не ссылку.
                return array_merge($def, [
                    'origin_type' => $post['origin_type'] ?? DeliveryPointValidator::ORIGIN_DOOR,
                    'shipment_point' => $post['shipment_point'] ?? null,
                    'cdek_city_code' => $post['cdek_city_code'] ?? null,
                    'ship_latitude' => $post['ship_latitude'] ?? null,
                    'ship_longitude' => $post['ship_longitude'] ?? null,
                ]);
            }
        }

        $city = trim((string) ($post['ship_city'] ?? $post['location'] ?? ''));
        $name = trim((string) ($post['ship_contact_name'] ?? ($user['name'] ?? '')));
        $phone = trim((string) ($post['ship_phone'] ?? ($user['phone'] ?? '')));
        $street = trim((string) ($post['ship_street'] ?? ''));

        $missing = [];
        if ($city === '') {
            $missing[] = 'ship_city';
        }
        if ($name === '') {
            $missing[] = 'ship_contact_name';
        }
        if ($phone === '') {
            $missing[] = 'ship_phone';
        }

        if ($missing !== []) {
            return [
                'error' => t('listing_shipping.ship_from_required'),
                'error_code' => 'ship_from_required',
                'field' => $missing[0],
                'missing_fields' => $missing,
            ];
        }

        return [
            'ship_country' => trim((string) ($post['ship_country'] ?? 'KZ')) ?: 'KZ',
            'ship_region' => trim((string) ($post['ship_region'] ?? '')) ?: null,
            'ship_city' => $city,
            'ship_street' => $street !== '' ? $street : null,
            'ship_building' => trim((string) ($post['ship_building'] ?? '')) ?: null,
            'ship_apartment' => trim((string) ($post['ship_apartment'] ?? '')) ?: null,
            'ship_postal_code' => trim((string) ($post['ship_postal_code'] ?? '')) ?: null,
            'ship_contact_name' => $name,
            'ship_phone' => $phone,
            'origin_type' => $post['origin_type'] ?? DeliveryPointValidator::ORIGIN_DOOR,
            'shipment_point' => $post['shipment_point'] ?? null,
            'cdek_city_code' => $post['cdek_city_code'] ?? null,
            'ship_latitude' => $post['ship_latitude'] ?? null,
            'ship_longitude' => $post['ship_longitude'] ?? null,
        ];
    }

    private function shippingFingerprint(array $row): string
    {
        $keys = [
            'fulfillment_mode', 'origin_type', 'shipment_point', 'cdek_city_code',
            'ship_city', 'ship_street', 'ship_building', 'ship_postal_code',
            'ship_contact_name', 'ship_phone',
            'item_weight', 'item_length', 'item_width', 'item_height',
            'package_length', 'package_width', 'package_height',
            'package_count', 'gross_weight', 'declared_value', 'declared_currency',
            'packaging_id', 'cdek_ready',
        ];
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = $k . '=' . (string) ($row[$k] ?? '');
        }
        return hash('sha256', implode('|', $parts));
    }

    private function float(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float) str_replace(',', '.', (string) $v);
        return $f > 0 ? $f : null;
    }

    private function formatDims(array $shipping): ?string
    {
        $l = $shipping['package_length'] ?? null;
        $w = $shipping['package_width'] ?? null;
        $h = $shipping['package_height'] ?? null;
        if ($l === null || $w === null || $h === null) {
            return null;
        }
        return (int) $l . '×' . (int) $w . '×' . (int) $h . ' см';
    }
}
