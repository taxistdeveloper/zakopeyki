<?php

namespace App\Services\Listing;

use App\Helpers\ProductHelper;
use App\Models\DeliveryOrder;
use App\Models\ProductListingShipping;
use App\Models\User;
use App\Services\Delivery\PackagingRecommendationService;

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

    public function packagings(): array
    {
        return (new DeliveryOrder())->packagingsForProvider((new DeliveryOrder())->defaultProviderId());
    }

    /**
     * @return array{ok: bool, error?: string, data?: array<string, mixed>, checklist?: array<string, bool>}
     */
    public function validateAndBuild(int $userId, string $productType, array $post, ?array $user = null): array
    {
        if (!$this->needsShippingBlock($productType)) {
            return ['ok' => true, 'data' => ['shipping_ready' => 0]];
        }

        $fulfillment = in_array($post['fulfillment_mode'] ?? '', [
            ProductListingShipping::FULFILLMENT_DELIVERY,
            ProductListingShipping::FULFILLMENT_PICKUP,
            ProductListingShipping::FULFILLMENT_BOTH,
        ], true) ? $post['fulfillment_mode'] : ProductListingShipping::FULFILLMENT_DELIVERY;

        if (!$this->requiresShippingValidation($productType, $fulfillment)) {
            return [
                'ok' => true,
                'data' => [
                    'fulfillment_mode' => $fulfillment,
                    'param_mode' => ProductListingShipping::MODE_EXACT,
                    'shipping_ready' => 1,
                    'ship_city' => trim((string) ($post['location'] ?? $post['ship_city'] ?? '')) ?: null,
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
            return ['ok' => false, 'error' => $ship['error']];
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
        }

        if ($paramMode === ProductListingShipping::MODE_STANDARD && $packagingId <= 0) {
            return ['ok' => false, 'error' => t('listing_shipping.packaging_required')];
        }

        $pack = $packagingId > 0 ? (new DeliveryOrder())->packagingById($packagingId) : null;
        $reco = $packReco->recommend($packagings, $itemWeight, $itemL, $itemW, $itemH);
        $recommendedId = $reco['recommended']['id'] ?? null;

        if ($pack) {
            $valid = $packReco->validateSelection($pack, $itemWeight, $itemL, $itemW, $itemH);
            if (!$valid['ok']) {
                return ['ok' => false, 'error' => $valid['error'] ?? t('listing_shipping.packaging_incompatible')];
            }
        }

        if ($paramMode === ProductListingShipping::MODE_EXACT) {
            if ($itemWeight === null || $itemWeight <= 0) {
                return ['ok' => false, 'error' => t('listing_shipping.item_weight_required')];
            }
            if ($itemL === null || $itemW === null || $itemH === null) {
                return ['ok' => false, 'error' => t('listing_shipping.package_dims_required')];
            }
        }

        $packageL = $pack ? (float) $pack['length_cm'] : $this->float($post['package_length'] ?? $post['item_length'] ?? null);
        $packageW = $pack ? (float) $pack['width_cm'] : $this->float($post['package_width'] ?? $post['item_width'] ?? null);
        $packageH = $pack ? (float) $pack['height_cm'] : $this->float($post['package_height'] ?? $post['item_height'] ?? null);

        if ($autoPackWeight && $pack) {
            $packagingWeight = $packReco->defaultPackagingWeightKg();
        }

        $gross = ($itemWeight ?? 0) + $packagingWeight;
        if ($gross <= 0) {
            $gross = 1.0;
        }

        $data = array_merge($ship, [
            'fulfillment_mode' => $fulfillment,
            'param_mode' => $paramMode,
            'use_default_ship_from' => $useDefault ? 1 : 0,
            'packaging_id' => $packagingId > 0 ? $packagingId : null,
            'recommended_packaging_id' => $recommendedId,
            'packaging_name_snapshot' => $pack['name'] ?? null,
            'product_type_hint' => $typeHint,
            'item_weight' => $itemWeight,
            'packaging_weight' => $packagingWeight,
            'gross_weight' => $gross,
            'item_length' => $itemL,
            'item_width' => $itemW,
            'item_height' => $itemH,
            'package_length' => $packageL,
            'package_width' => $packageW,
            'package_height' => $packageH,
            'is_irregular' => !empty($post['is_irregular']) ? 1 : 0,
            'irregular_reason' => !empty($post['is_irregular']) ? (trim((string) ($post['irregular_reason'] ?? 'other')) ?: 'other') : null,
            'is_fragile' => !empty($post['is_fragile']) ? 1 : 0,
            'shipping_ready' => 1,
        ]);

        if (!empty($post['save_default_ship_from']) && $user) {
            (new User())->saveDefaultShipFrom($userId, $ship);
        }

        return [
            'ok' => true,
            'data' => $data,
            'checklist' => $this->publishChecklist($productType, $data),
            'recommendation' => $reco,
        ];
    }

    public function saveForProduct(int $productId, array $data): void
    {
        (new ProductListingShipping())->upsert($productId, $data);
    }

    public function findForProduct(int $productId): ?array
    {
        return (new ProductListingShipping())->findByProductId($productId);
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
            'packaging_id' => $shipping['packaging_id'],
            'packaging_name_snapshot' => $shipping['packaging_name_snapshot'],
            'recommended_packaging_id' => $shipping['recommended_packaging_id'],
            'dimensions_unknown' => ($shipping['param_mode'] ?? '') === ProductListingShipping::MODE_STANDARD ? 1 : 0,
            'is_fragile' => $shipping['is_fragile'] ?? 0,
            'is_irregular' => $shipping['is_irregular'] ?? 0,
            'irregular_reason' => $shipping['irregular_reason'] ?? null,
            'measurement_status' => 'preliminary',
            'weight_source' => 'seller',
            'dimension_source' => ($shipping['param_mode'] ?? '') === ProductListingShipping::MODE_STANDARD ? 'packaging' : 'seller',
        ]);

        $orders->updateFields($deliveryOrderId, [
            'sender_id' => $sellerId,
            'data_completeness_status' => 'seller_prefilled',
        ]);
        $orders->logEvent($deliveryOrderId, $sellerId, 'system', 'prefilled_from_listing', null, null, [
            'product_id' => $productId,
        ]);
    }

    /** @return array<string, bool> */
    public function publishChecklist(string $type, array $shipping): array
    {
        return [
            'fulfillment' => !empty($shipping['fulfillment_mode']),
            'ship_from' => !empty($shipping['ship_city']),
            'params' => !empty($shipping['shipping_ready']),
            'weight' => ($shipping['gross_weight'] ?? 0) > 0 || ($shipping['fulfillment_mode'] ?? '') === 'pickup',
            'packaging' => !empty($shipping['packaging_id']) || ($shipping['param_mode'] ?? '') === 'exact',
        ];
    }

    /** @return array<string, mixed> */
    private function resolveShipFrom(?array $user, array $post, bool $useDefault): array
    {
        if ($useDefault && $user) {
            $def = User::defaultShipFrom($user);
            if (($def['ship_city'] ?? '') !== '') {
                return $def;
            }
        }

        $city = trim((string) ($post['ship_city'] ?? $post['location'] ?? ''));
        $name = trim((string) ($post['ship_contact_name'] ?? ($user['name'] ?? '')));
        $phone = trim((string) ($post['ship_phone'] ?? ($user['phone'] ?? '')));

        if ($city === '' || $name === '' || $phone === '') {
            return ['error' => t('listing_shipping.ship_from_required')];
        }

        return [
            'ship_country' => trim((string) ($post['ship_country'] ?? 'KZ')) ?: 'KZ',
            'ship_region' => trim((string) ($post['ship_region'] ?? '')) ?: null,
            'ship_city' => $city,
            'ship_street' => trim((string) ($post['ship_street'] ?? '')) ?: null,
            'ship_building' => trim((string) ($post['ship_building'] ?? '')) ?: null,
            'ship_apartment' => trim((string) ($post['ship_apartment'] ?? '')) ?: null,
            'ship_postal_code' => trim((string) ($post['ship_postal_code'] ?? '')) ?: null,
            'ship_contact_name' => $name,
            'ship_phone' => $phone,
        ];
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
