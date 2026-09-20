<?php

namespace App\Services;

use App\Helpers\ProductHelper;
use PDO;

/**
 * Атомарный учёт остатков физических товаров (used/new).
 */
class StockService
{
    public static function decrement(PDO $db, int $productId, int $qty): bool
    {
        $qty = (int) $qty;
        if ($productId <= 0 || $qty < 1) {
            return false;
        }

        $stmt = $db->prepare(
            "UPDATE products
             SET quantity = quantity - ?,
                 status = IF(quantity <= 0, 'out_of_stock', 'active')
             WHERE id = ?
               AND status = 'active'
               AND quantity >= ?"
        );
        $stmt->execute([$qty, $productId, $qty]);

        return $stmt->rowCount() > 0;
    }

    public static function increment(PDO $db, int $productId, int $qty): void
    {
        $qty = (int) $qty;
        if ($productId <= 0 || $qty < 1) {
            return;
        }

        $stmt = $db->prepare(
            "UPDATE products
             SET quantity = quantity + ?,
                 status = IF(
                     status IN ('out_of_stock', 'sold', 'reserved') AND quantity > 0,
                     'active',
                     status
                 )
             WHERE id = ?
               AND status <> 'archived'"
        );
        $stmt->execute([$qty, $productId]);
    }

    /**
     * Вернуть товар на склад по заказу (отмена / возврат / срыв оплаты).
     * Идемпотентно через orders.stock_restored.
     *
     * @param array<string, mixed> $order
     */
    public static function restoreForOrder(PDO $db, array $order): void
    {
        $orderId = (int) ($order['id'] ?? 0);
        $productId = (int) ($order['product_id'] ?? 0);
        $qty = max(1, (int) ($order['quantity'] ?? 1));
        if ($productId <= 0) {
            return;
        }

        if ($orderId > 0) {
            $fresh = $db->prepare('SELECT quantity, stock_held, stock_restored FROM orders WHERE id = ? LIMIT 1');
            $fresh->execute([$orderId]);
            $row = $fresh->fetch() ?: [];
            if ($row) {
                $order = array_merge($order, $row);
                $qty = max(1, (int) ($row['quantity'] ?? $qty));
            }
            $mark = $db->prepare(
                'UPDATE orders SET stock_restored = 1 WHERE id = ? AND COALESCE(stock_restored, 0) = 0'
            );
            $mark->execute([$orderId]);
            if ($mark->rowCount() === 0) {
                return;
            }
        }

        $productStmt = $db->prepare('SELECT id, type, status FROM products WHERE id = ? LIMIT 1');
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch() ?: [];
        if (!$product) {
            return;
        }

        if (ProductHelper::isDigitalListing($product)) {
            return;
        }

        $held = (int) ($order['stock_held'] ?? 0) === 1;
        if ($held && ProductHelper::tracksInventory($product)) {
            self::increment($db, $productId, $qty);
            return;
        }

        $stmt = $db->prepare(
            "UPDATE products
             SET status = 'active'
             WHERE id = ?
               AND status <> 'active'
               AND status <> 'archived'"
        );
        $stmt->execute([$productId]);
    }

    /**
     * Списание или резерв в момент создания заказа.
     *
     * @param array<string, mixed> $product
     */
    public static function applyOnOrder(PDO $db, array $product, int $qty, bool $reserveOnly = false): bool
    {
        if (ProductHelper::isDigitalListing($product)) {
            return true;
        }

        $productId = (int) ($product['id'] ?? 0);
        $qty = max(1, $qty);
        if ($productId <= 0) {
            return false;
        }

        if (ProductHelper::tracksInventory($product)) {
            return self::decrement($db, $productId, $qty);
        }

        $status = $reserveOnly ? 'reserved' : 'sold';
        $stmt = $db->prepare(
            "UPDATE products SET status = ? WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$status, $productId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * После успешной оплаты FreedomPay: unique-лоты reserved → sold.
     *
     * @param array<string, mixed>|null $product
     */
    public static function markPaid(PDO $db, ?array $product): void
    {
        if (!$product || ProductHelper::isDigitalListing($product) || ProductHelper::tracksInventory($product)) {
            return;
        }

        $stmt = $db->prepare(
            "UPDATE products SET status = 'sold' WHERE id = ? AND status IN ('active', 'reserved')"
        );
        $stmt->execute([(int) ($product['id'] ?? 0)]);
    }
}
