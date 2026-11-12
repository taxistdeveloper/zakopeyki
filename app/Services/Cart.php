<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Lang;
use App\Helpers\ProductHelper;
use App\Models\Product;

class Cart
{
    private const SESSION_KEY = 'cart';

    /** Явный владелец корзины (мобильное API — Bearer-пользователь без web-сессии). */
    private static ?int $userOverride = null;
    private static bool $tableEnsured = false;

    /**
     * Корзина конкретного пользователя (мобильное API). Для web по умолчанию — Auth::id().
     * Корзина вошедшего пользователя хранится в БД (user_cart_items) и общая для сайта и приложения;
     * корзина гостя — в сессии и переносится в БД при входе.
     */
    public static function forUser(?int $userId): void
    {
        self::$userOverride = $userId !== null && $userId > 0 ? $userId : null;
    }

    private static function userId(): int
    {
        if (self::$userOverride !== null) {
            return self::$userOverride;
        }

        return Auth::check() ? (int) Auth::id() : 0;
    }

    /** @return array<int, int> productId => qty */
    public static function map(): array
    {
        $uid = self::userId();
        if ($uid > 0) {
            $map = self::dbMap($uid);
            // Гостевая корзина сайта → в корзину аккаунта (один раз после входа)
            $guest = self::sessionMap();
            if ($guest !== [] && self::$userOverride === null) {
                foreach ($guest as $id => $qty) {
                    $map[$id] = max($map[$id] ?? 0, $qty);
                }
                $_SESSION[self::SESSION_KEY] = [];
                self::dbSave($uid, $map);
            }

            return $map;
        }

        return self::sessionMap();
    }

    /** @return array<int, int> */
    private static function sessionMap(): array
    {
        $raw = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        $isList = array_is_list($raw);
        foreach ($raw as $key => $val) {
            if ($isList) {
                $id = (int) $val;
                $qty = 1;
            } else {
                $id = (int) $key;
                $qty = (int) $val;
            }
            if ($id > 0) {
                $out[$id] = max(1, $qty);
            }
        }

        return $out;
    }

    /** @param array<int, int> $map */
    private static function save(array $map): void
    {
        $clean = [];
        foreach ($map as $id => $qty) {
            $id = (int) $id;
            $qty = (int) $qty;
            if ($id > 0 && $qty > 0) {
                $clean[$id] = $qty;
            }
        }
        $uid = self::userId();
        if ($uid > 0) {
            self::dbSave($uid, $clean);

            return;
        }
        $_SESSION[self::SESSION_KEY] = $clean;
    }

    /** @return array<int, int> */
    private static function dbMap(int $userId): array
    {
        self::ensureTable();
        $stmt = Database::connect()->prepare('SELECT product_id, qty FROM user_cart_items WHERE user_id = ? ORDER BY added_at, product_id');
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['product_id']] = max(1, (int) $row['qty']);
        }

        return $out;
    }

    /** @param array<int, int> $map */
    private static function dbSave(int $userId, array $map): void
    {
        self::ensureTable();
        $db = Database::connect();
        $current = self::dbMap($userId);
        $del = $db->prepare('DELETE FROM user_cart_items WHERE user_id = ? AND product_id = ?');
        foreach (array_diff_key($current, $map) as $id => $_) {
            $del->execute([$userId, $id]);
        }
        $up = $db->prepare(
            'INSERT INTO user_cart_items (user_id, product_id, qty) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
        );
        foreach ($map as $id => $qty) {
            if (($current[$id] ?? null) !== $qty) {
                $up->execute([$userId, $id, $qty]);
            }
        }
    }

    private static function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }
        Database::connect()->exec(
            'CREATE TABLE IF NOT EXISTS user_cart_items (
                user_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                qty INT UNSIGNED NOT NULL DEFAULT 1,
                added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, product_id),
                KEY idx_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$tableEnsured = true;
    }

    /** @return list<int> */
    public static function ids(): array
    {
        return array_keys(self::map());
    }

    public static function count(): int
    {
        return array_sum(self::map());
    }

    public static function qty(int $productId): int
    {
        return self::map()[$productId] ?? 0;
    }

    public static function has(int $productId): bool
    {
        return isset(self::map()[$productId]);
    }

    /** @return array{ok: bool, in_cart: bool, count: int, qty?: int, available?: int, notice?: string, error?: string} */
    public static function add(int $productId, int $qty = 1): array
    {
        $product = (new Product())->find($productId);
        if (!$product) {
            return self::result(false, false, Lang::get('cart.error_not_found'));
        }

        if (!ProductHelper::isPurchasable($product)) {
            return self::result(false, false, Lang::get('cart.error_not_purchasable'));
        }

        if (self::userId() > 0 && (int) ($product['user_id'] ?? 0) === self::userId()) {
            return self::result(false, false, Lang::get('cart.error_own'));
        }

        $available = ProductHelper::availableQuantity($product);
        $qty = max(1, $qty);
        $notice = null;
        if ($qty > $available) {
            $qty = $available;
            $notice = t('product.qty_only', ['n' => $available]);
        }
        if ($qty < 1) {
            return self::result(false, false, Lang::get('cart.error_not_purchasable'));
        }

        $map = self::map();
        $map[$productId] = $qty;
        self::save($map);

        $out = self::result(true, true);
        $out['qty'] = $qty;
        $out['available'] = $available;
        if ($notice !== null) {
            $out['notice'] = $notice;
        }

        return $out;
    }

    /** @return array{ok: bool, in_cart: bool, count: int, qty?: int, available?: int, line_total?: int, total?: int, notice?: string, error?: string} */
    public static function setQty(int $productId, int $qty): array
    {
        if ($qty <= 0) {
            return self::remove($productId);
        }

        $result = self::add($productId, $qty);
        if ($result['ok']) {
            $items = self::items();
            $total = 0;
            $lineTotal = 0;
            foreach ($items as $item) {
                $line = (int) ($item['line_total'] ?? 0);
                $total += $line;
                if ((int) ($item['id'] ?? 0) === $productId) {
                    $lineTotal = $line;
                }
            }
            $result['line_total'] = $lineTotal;
            $result['total'] = $total;
        }

        return $result;
    }

    /** @return array{ok: bool, in_cart: bool, count: int, error?: string} */
    public static function remove(int $productId): array
    {
        $map = self::map();
        unset($map[$productId]);
        self::save($map);

        return self::result(true, false);
    }

    /** @return array{ok: bool, in_cart: bool, count: int, error?: string} */
    public static function toggle(int $productId): array
    {
        if (self::has($productId)) {
            return self::remove($productId);
        }

        return self::add($productId, 1);
    }

    public static function clear(): void
    {
        $uid = self::userId();
        if ($uid > 0) {
            self::dbSave($uid, []);
        }
        $_SESSION[self::SESSION_KEY] = [];
    }

    /**
     * Товары корзины (порядок как в сессии). Недоступные позиции удаляются.
     *
     * @return list<array>
     */
    public static function items(): array
    {
        $map = self::map();
        if ($map === []) {
            return [];
        }

        $ids = array_keys($map);
        $rows = (new Product())->findWithSellersByIds($ids);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $items = [];
        $kept = [];
        $uid = self::userId();
        foreach ($map as $id => $qty) {
            $row = $byId[$id] ?? null;
            if (!$row || !ProductHelper::isPurchasable($row)) {
                continue;
            }
            if ($uid > 0 && (int) ($row['user_id'] ?? 0) === $uid) {
                continue;
            }
            $available = ProductHelper::availableQuantity($row);
            $qty = max(1, min((int) $qty, $available));
            $row['cart_qty'] = $qty;
            $row['line_total'] = ProductHelper::lineAmount($row, $qty);
            $items[] = $row;
            $kept[$id] = $qty;
        }

        if ($kept !== $map) {
            self::save($kept);
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
