<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Lang;
use App\Helpers\ProductHelper;
use App\Models\Product;

class Cart
{
    private const SESSION_KEY = 'cart';

    /** @return list<int> */
    public static function ids(): array
    {
        $ids = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($ids)) {
            return [];
        }

        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return $clean;
    }

    public static function count(): int
    {
        return count(self::ids());
    }

    public static function has(int $productId): bool
    {
        return in_array($productId, self::ids(), true);
    }

    /** @return array{ok: bool, in_cart: bool, count: int, error?: string} */
    public static function add(int $productId): array
    {
        $product = (new Product())->find($productId);
        if (!$product) {
            return self::result(false, false, Lang::get('cart.error_not_found'));
        }

        if (!ProductHelper::isPurchasable($product)) {
            return self::result(false, false, Lang::get('cart.error_not_purchasable'));
        }

        if (Auth::check() && (int) ($product['user_id'] ?? 0) === (int) Auth::id()) {
            return self::result(false, false, Lang::get('cart.error_own'));
        }

        if (self::has($productId)) {
            return self::result(true, true);
        }

        $ids = self::ids();
        $ids[] = $productId;
        $_SESSION[self::SESSION_KEY] = $ids;

        return self::result(true, true);
    }

    /** @return array{ok: bool, in_cart: bool, count: int, error?: string} */
    public static function remove(int $productId): array
    {
        $ids = array_values(array_filter(
            self::ids(),
            static fn (int $id): bool => $id !== $productId
        ));
        $_SESSION[self::SESSION_KEY] = $ids;

        return self::result(true, false);
    }

    /** @return array{ok: bool, in_cart: bool, count: int, error?: string} */
    public static function toggle(int $productId): array
    {
        if (self::has($productId)) {
            return self::remove($productId);
        }

        return self::add($productId);
    }

    public static function clear(): void
    {
        $_SESSION[self::SESSION_KEY] = [];
    }

    /**
     * Товары корзины (порядок как в сессии). Недоступные позиции удаляются.
     *
     * @return list<array>
     */
    public static function items(): array
    {
        $ids = self::ids();
        if ($ids === []) {
            return [];
        }

        $rows = (new Product())->findWithSellersByIds($ids);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $items = [];
        $kept = [];
        $uid = Auth::check() ? (int) Auth::id() : 0;
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if (!$row || !ProductHelper::isPurchasable($row)) {
                continue;
            }
            if ($uid > 0 && (int) ($row['user_id'] ?? 0) === $uid) {
                continue;
            }
            $items[] = $row;
            $kept[] = $id;
        }

        if ($kept !== $ids) {
            $_SESSION[self::SESSION_KEY] = $kept;
        }

        return $items;
    }

    /** @return array{ok: bool, in_cart: bool, count: int, error?: string} */
    private static function result(bool $ok, bool $inCart, ?string $error = null): array
    {
        $out = [
            'ok' => $ok,
            'in_cart' => $inCart,
            'count' => self::count(),
        ];
        if ($error !== null) {
            $out['error'] = $error;
        }

        return $out;
    }
}
