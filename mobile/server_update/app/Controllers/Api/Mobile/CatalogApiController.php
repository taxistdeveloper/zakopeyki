<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Core\Lang;
use App\Helpers\ProductHelper;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\EscrowService;
use App\Services\Listing\ListingShippingService;
use App\Services\MobileApi\Presenter;

/**
 * Публичный каталог для мобильного приложения: конфиг, категории, лента/поиск, карточка, избранное.
 * Порядок выдачи (платное продвижение, VIP, TopFixed) — тот же, что на сайте (Product::allActive).
 */
final class CatalogApiController extends BaseMobileController
{
    private const FEED_TYPES = ['new', 'used', 'auction', 'service', 'exchange', 'free', 'gig'];
    private const PAGE_MAX = 50;

    public function config(): void
    {
        $types = [];
        foreach (self::FEED_TYPES as $t) {
            $types[] = ['code' => $t, 'label' => ProductHelper::label($t)];
        }
        $this->ok([
            'api_version' => 1,
            'min_supported_app_version' => '1.0.0',
            'locales' => array_values($GLOBALS['appConfig']['locales'] ?? ['ru', 'kk']),
            'locale' => Lang::current(),
            'currency' => 'KZT',
            'timezone' => (string) ($GLOBALS['appConfig']['timezone'] ?? 'Asia/Almaty'),
            'listing_types' => $types,
            // Справочно для UI; расчёт сумм всегда выполняет сервер
            'fees' => [
                'safe_deal_buyer_percent' => EscrowService::ARBITRATION_FEE_PERCENT,
                'seller_commission_percent' => EscrowService::SELLER_COMMISSION_PERCENT,
                'inspection_hours' => EscrowService::INSPECT_HOURS,
            ],
            'site_open' => !self::siteClosed(),
            'site_opens_at' => self::siteClosed() ? Presenter::date($GLOBALS['appConfig']['stub_opens_at'] ?? null) : null,
            'web_base_url' => ProductHelper::absoluteUrl('/'),
            'legal' => [
                'offer_url' => ProductHelper::absoluteUrl('/offer'),
                'privacy_url' => ProductHelper::absoluteUrl('/privacy'),
                'data_policy_url' => ProductHelper::absoluteUrl('/data-policy'),
            ],
        ]);
    }

    public function categories(): void
    {
        $tree = [];
        foreach (ProductHelper::PRODUCT_CATEGORY_TREE as $parent => $children) {
            $tree[] = [
                'value' => $parent,
                'label' => ProductHelper::categoryLabel($parent),
                'children' => array_map(static fn (string $c): array => [
                    'value' => ProductHelper::formatCategory($parent, $c),
                    'label' => ProductHelper::categoryLabel($c),
                ], $children),
            ];
        }
        $this->ok([
            'types_with_category' => ProductHelper::PRODUCT_TYPES_WITH_CATEGORY,
            'categories' => $tree,
        ]);
    }

    /**
     * GET /products?type=&category=&q=&cursor=&limit=
     * cursor — непрозрачная строка из meta.next_cursor.
     */
    public function feed(): void
    {
        $this->guardSiteOpen();
        $type = $this->queryString('type', 20);
        if ($type !== '' && !in_array($type, self::FEED_TYPES, true)) {
            $this->fail('validation_failed', 422, null, ['type' => self::message('invalid_value')]);
        }
        $q = $this->queryString('q', 100);
        if ($q !== '' && mb_strlen($q) < 2) {
            $q = '';
        }
        $category = $this->queryString('category', 160);
        $limit = $this->queryInt('limit', 20, 1, self::PAGE_MAX);
        $offset = $this->decodeCursor($this->queryString('cursor', 40));

        $rows = (new Product())->allActive(
            $type !== '' ? $type : null,
            $q !== '' ? $q : null,
            $category !== '' ? $category : null,
            $limit + 1,
            $offset,
            false
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $favorites = $this->favoriteIds();
        $items = array_map(static fn (array $r): array => Presenter::card($r, $favorites), $rows);

        $this->ok(['items' => $items], [
            'next_cursor' => $hasMore ? $this->encodeCursor($offset + $limit) : null,
            'limit' => $limit,
        ]);
    }

    public function show(string $id): void
    {
        $this->guardSiteOpen();
        $productId = (int) $id;
        $products = new Product();
        $item = $products->find($productId);
        if (!$item || ($item['type'] ?? '') === 'course') {
            $this->fail('not_found', 404);
        }
        $seller = (new User())->find((int) $item['user_id']) ?? [];
        $rating = (new Review())->statsFor((int) $item['user_id']);

        $uid = $this->optionalUserId();
        $isFavorite = $uid !== null && (new Favorite())->isFavorite($uid, $productId);

        if ($uid === null || $uid !== (int) $item['user_id']) {
            try {
                $products->recordView($productId, (int) $item['user_id']);
            } catch (\Throwable) {
                // просмотр не критичен
            }
        }

        $detail = Presenter::detail($item, $seller, $rating, $isFavorite);
        $detail['delivery'] = $this->delivery($item);
        $this->ok(['product' => $detail]);
    }

    /**
     * Блок «Доставка» как на странице товара сайта (app/Views/products/show.php).
     *
     * @return array{title: string, hint: string, details: list<string>}|null
     */
    private function delivery(array $item): ?array
    {
        if (ProductHelper::isDigitalListing($item)) {
            return null;
        }
        $location = (string) ($item['location'] ?? '');
        $ship = null;
        try {
            $svc = new ListingShippingService();
            $ship = $svc->buyerSummary($svc->findForProduct((int) $item['id']), $location);
        } catch (\Throwable) {
            $ship = null;
        }

        $title = t('product.delivery_kz');
        $hint = t('listing_shipping.buyer_price_after_address');
        $details = [];
        if (!empty($ship)) {
            if (!empty($ship['has_delivery'])) {
                $title = t('listing_shipping.buyer_delivery_title');
                $hint = (string) ($ship['price_note'] ?? t('listing_shipping.buyer_price_after_address'));
                if (!empty($ship['from_city'])) {
                    $details[] = t('listing_shipping.buyer_from_city', ['city' => $ship['from_city']]);
                }
                if (!empty($ship['packaging'])) {
                    $details[] = t('listing_shipping.buyer_packaging', ['name' => $ship['packaging']]);
                }
                $dims = !empty($ship['package_dims']) ? (string) $ship['package_dims'] : '';
                if (!empty($ship['gross_weight'])) {
                    $weight = t('listing_shipping.buyer_weight', [
                        'weight' => rtrim(rtrim(number_format((float) $ship['gross_weight'], 2, '.', ''), '0'), '.'),
                    ]);
                    $dims = $dims !== '' ? $dims . ' · ' . $weight : $weight;
                }
                if ($dims !== '') {
                    $details[] = $dims;
                }
            } elseif (!empty($ship['has_pickup'])) {
                $city = $location !== '' ? $location : (string) ($ship['from_city'] ?? '');
                $title = t('listing_shipping.fulfillment_pickup');
                $hint = $city !== '' ? t('product.delivery_city_hint', ['city' => $city]) : t('listing_shipping.fulfillment_pickup');
            }
            if (!empty($ship['has_pickup']) && !empty($ship['has_delivery']) && $location !== '') {
                $details[] = t('product.delivery_city_hint', ['city' => $location]);
            }
        } elseif ($location !== '') {
            $title = t('product.delivery_city');
            $hint = t('product.delivery_city_hint', ['city' => $location]);
            $details[] = t('product.delivery_kz') . ' — ' . t('listing_shipping.buyer_price_after_address');
        }
        $details[] = t('product.delivery_escrow');

        return ['title' => $title, 'hint' => $hint, 'details' => $details];
    }

    public function favorites(): void
    {
        $this->guardSiteOpen();
        $uid = $this->requireUserId();
        $rows = (new Favorite())->forUser($uid);
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int) $r['id']] = true;
        }
        $this->ok(['items' => array_map(static fn (array $r): array => Presenter::card($r, $ids), $rows)]);
    }

    public function toggleFavorite(string $id): void
    {
        $this->guardSiteOpen();
        $uid = $this->requireUserId();
        $product = (new Product())->find((int) $id);
        if (!$product || ($product['type'] ?? '') === 'course') {
            $this->fail('not_found', 404);
        }
        $res = (new Favorite())->toggle($uid, (int) $id);
        if (empty($res['ok'])) {
            $this->fail('not_found', 404);
        }
        $this->ok(['product_id' => (int) $id, 'is_favorite' => (bool) $res['favorited']]);
    }

    /** @return array<int, true> */
    private function favoriteIds(): array
    {
        $uid = $this->optionalUserId();
        if ($uid === null) {
            return [];
        }

        return array_fill_keys((new Favorite())->idsForUser($uid), true);
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:' . $offset), '+/', '-_'), '=');
    }

    private function decodeCursor(string $cursor): int
    {
        if ($cursor === '') {
            return 0;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($raw) || !preg_match('/^o:(\d{1,7})$/', $raw, $m)) {
            $this->fail('invalid_cursor', 422);
        }

        return (int) $m[1];
    }
}
