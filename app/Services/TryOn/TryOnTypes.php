<?php

declare(strict_types=1);

namespace App\Services\TryOn;

/**
 * Типы примерки товара (products.tryon_type). NULL у товара = примерка не поддерживается.
 */
final class TryOnTypes
{
    public const UPPER = 'upper';
    public const LOWER = 'lower';
    public const DRESS = 'dress';
    public const SHOES = 'shoes';
    public const GLASSES = 'glasses';
    public const HAT = 'hat';
    public const BAG = 'bag';
    public const JEWELRY = 'jewelry';
    public const WATCH = 'watch';

    public const ALL = [
        self::UPPER, self::LOWER, self::DRESS, self::SHOES, self::GLASSES,
        self::HAT, self::BAG, self::JEWELRY, self::WATCH,
    ];

    /** Разделы каталога, где продавец может указать тип примерки. */
    public const CATEGORY_PARENTS = ['Одежда, обувь и аксессуары', 'Детские товары'];

    public static function isValid(?string $type): bool
    {
        return $type !== null && in_array($type, self::ALL, true);
    }

    /** @return list<string> */
    public static function parseList(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $t) {
            $t = trim($t);
            if (self::isValid($t) && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Значение для сохранения из формы объявления.
     * Тип допустим только для товаров с категорией из разделов одежды/обуви/аксессуаров.
     * $posted === null — поле не пришло (скрыто) → сохраняем прежнее значение.
     */
    public static function normalizeForListing(?string $posted, string $productType, string $category, ?string $existing): ?string
    {
        if (!in_array($productType, ['used', 'new'], true)) {
            return null;
        }
        $parent = trim(explode(' / ', $category, 2)[0]);
        if (!in_array($parent, self::CATEGORY_PARENTS, true)) {
            return null;
        }
        if ($posted === null) {
            return self::isValid($existing) ? $existing : null;
        }
        $posted = trim($posted);

        return self::isValid($posted) ? $posted : null;
    }
}
