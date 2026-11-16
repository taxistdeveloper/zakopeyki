<?php

declare(strict_types=1);

namespace App\Services\MobileApi;

use App\Core\Lang;
use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;

/**
 * JSON-представления для мобильного API. Только форматирование: никаких бизнес-правил
 * (цены, доступность покупки и т.п. берутся из ProductHelper — того же источника, что и web).
 *
 * Деньги — {amount: int (тенге), currency: "KZT"}; даты — ISO 8601 с часовым поясом;
 * медиа — абсолютные URL.
 */
final class Presenter
{
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);

        return $ts ? date(DATE_ATOM, $ts) : null;
    }

    public static function media(?string $relativeUrl): ?string
    {
        if ($relativeUrl === null || $relativeUrl === '') {
            return null;
        }

        return ProductHelper::absoluteUrl($relativeUrl);
    }

    /** @return array{amount: int, currency: string}|null */
    public static function money(array $item): ?array
    {
        $type = (string) ($item['type'] ?? '');
        if (in_array($type, ['free', 'exchange', 'service'], true)) {
            return null;
        }
        if ($type === 'auction') {
            $amount = (int) ($item['calculated_current_price'] ?? ((int) ($item['current_bid'] ?? 0) ?: (int) ($item['price'] ?? 0)));
        } else {
            $amount = (int) ($item['price'] ?? 0);
        }
        if ($amount <= 0) {
            return null;
        }

        return ['amount' => $amount, 'currency' => 'KZT'];
    }

    /**
     * Карточка товара для лент/списков.
     *
     * @param array<string, mixed> $item строка products + seller_* (Product::allActive / Favorite::forUser)
     * @param array<int, true> $favoriteIds
     * @return array<string, mixed>
     */
    public static function card(array $item, array $favoriteIds = []): array
    {
        $id = (int) $item['id'];
        $category = (string) ($item['category'] ?? '');

        return [
            'id' => $id,
            'type' => (string) $item['type'],
            'type_label' => ProductHelper::label((string) $item['type']),
            'title' => (string) $item['title'],
            'price' => self::money($item),
            'price_text' => ProductHelper::formatPrice($item),
            'cover_url' => self::media(ProductHelper::imageUrl($item)),
            'category' => $category !== '' ? $category : null,
            'category_label' => $category !== '' ? self::categoryLabel($category) : null,
            'location' => ($item['location'] ?? '') !== '' ? (string) $item['location'] : null,
            'seller' => [
                'id' => (int) $item['user_id'],
                'name' => self::sellerName($item),
                'is_business' => ProductHelper::sellerIsBusiness($item),
            ],
            'is_favorite' => isset($favoriteIds[$id]),
            'created_at' => self::date($item['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $seller строка users
     * @param array{avg: float, count: int} $rating
     * @return array<string, mixed>
     */
    public static function detail(array $item, array $seller, array $rating, bool $isFavorite): array
    {
        $card = self::card($item + [
            'seller_name' => $seller['name'] ?? '',
            'seller_account_type' => $seller['account_type'] ?? null,
            'seller_business_status' => $seller['business_status'] ?? null,
            'seller_business_name' => $seller['business_name'] ?? null,
            'seller_business_entity_type' => $seller['business_entity_type'] ?? null,
        ], $isFavorite ? [(int) $item['id'] => true] : []);

        $characteristics = [];
        foreach (ProductHelper::parseCharacteristics($item['characteristics'] ?? null) as $row) {
            if (is_array($row)) {
                $characteristics[] = [
                    'name' => (string) ($row['name'] ?? $row[0] ?? ''),
                    'value' => (string) ($row['value'] ?? $row[1] ?? ''),
                ];
            }
        }

        $auction = null;
        if (($item['type'] ?? '') === 'auction') {
            $auction = [
                'kind' => (string) ($item['auction_kind'] ?? 'english'),
                'current_bid' => (int) ($item['current_bid'] ?? 0),
                'bid_step' => (int) ($item['bid_step'] ?? 0),
                'buy_now' => (int) ($item['auction_buy_now'] ?? 0) ?: null,
                'starts_at' => self::date($item['auction_start_at'] ?? null),
                'ends_at' => self::date($item['auction_end_at'] ?? null),
            ];
        }

        $seller = $card['seller'] + [
            'avatar_url' => self::media(AvatarHelper::url($seller)),
            'rating' => ['average' => (float) ($rating['avg'] ?? 0), 'count' => (int) ($rating['count'] ?? 0)],
            'member_since' => self::date($seller['created_at'] ?? null),
        ];

        return array_replace($card, [
            'seller' => $seller,
            'description' => (string) ($item['description'] ?? ''),
            'images' => array_values(array_filter(array_map(
                static fn (string $u): ?string => self::media($u),
                ProductHelper::imageUrls($item)
            ))),
            'characteristics' => $characteristics,
            'exchange_for' => ($item['exchange_for'] ?? '') !== '' ? (string) $item['exchange_for'] : null,
            'quantity_available' => ProductHelper::tracksInventory($item) ? ProductHelper::availableQuantity($item) : null,
            'view_count' => (int) ($item['view_count'] ?? 0),
            'status' => (string) ($item['status'] ?? ''),
            'auction' => $auction,
            'can_buy' => ProductHelper::isPurchasable($item),
            'web_url' => ProductHelper::absoluteUrl('/product/' . (int) $item['id']),
        ]);
    }

    /**
     * Профиль текущего пользователя. ИИН/БИН и прочие чувствительные поля не отдаются.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function me(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'email_verified' => !empty($user['email_verified_at']),
            'phone' => ($user['phone'] ?? '') !== '' ? (string) $user['phone'] : null,
            'avatar_url' => self::media(AvatarHelper::url($user)),
            'bio' => ($user['bio'] ?? '') !== '' ? (string) $user['bio'] : null,
            'account_type' => (string) ($user['account_type'] ?? 'personal'),
            'is_business' => ProductHelper::sellerIsBusiness($user),
            'two_factor_enabled' => !empty($user['two_factor_enabled']),
            'locale' => Lang::current(),
            'created_at' => self::date($user['created_at'] ?? null),
        ];
    }

    private static function sellerName(array $item): string
    {
        if (ProductHelper::sellerIsBusiness($item) && ($item['seller_business_name'] ?? '') !== '') {
            return (string) $item['seller_business_name'];
        }

        return (string) ($item['seller_name'] ?? '');
    }

    private static function categoryLabel(string $category): string
    {
        $parts = array_map('trim', explode(' / ', $category));

        return implode(' / ', array_map([ProductHelper::class, 'categoryLabel'], $parts));
    }
}
