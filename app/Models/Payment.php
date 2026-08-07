<?php

namespace App\Models;

use App\Core\Model;

class Payment extends Model
{
    protected string $table = 'payments';
    private static bool $ensured = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pg_order_id VARCHAR(64) NOT NULL,
                pg_payment_id VARCHAR(64) DEFAULT NULL,
                order_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                buyer_id INT UNSIGNED NOT NULL,
                amount INT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                delivery_method VARCHAR(50) NOT NULL DEFAULT 'kazpost',
                payment_method VARCHAR(50) NOT NULL DEFAULT 'card',
                meta TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_pg_order (pg_order_id),
                INDEX idx_order (order_id),
                INDEX idx_buyer (buyer_id),
                INDEX idx_status (status),
                INDEX idx_pg_payment (pg_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$ensured = true;
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payments WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByOrderId(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findPendingByProductBuyer(int $productId, int $buyerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payments
             WHERE product_id = ? AND buyer_id = ? AND status = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$productId, $buyerId, self::STATUS_PENDING]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array{
     *   pg_order_id: string,
     *   order_id: int,
     *   product_id: int,
     *   buyer_id: int,
     *   amount: int,
     *   delivery_method?: string,
     *   payment_method?: string,
     *   pg_payment_id?: string|null,
     *   meta?: string|null,
     * } $data
     */
    public function createPending(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO payments (
                pg_order_id, pg_payment_id, order_id, product_id, buyer_id,
                amount, status, delivery_method, payment_method, meta
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['pg_order_id'],
            $data['pg_payment_id'] ?? null,
            (int) $data['order_id'],
            (int) $data['product_id'],
            (int) $data['buyer_id'],
            (int) $data['amount'],
            self::STATUS_PENDING,
            $data['delivery_method'] ?? 'kazpost',
            $data['payment_method'] ?? 'card',
            $data['meta'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function setPgPaymentId(int $id, string $pgPaymentId): void
    {
        $stmt = $this->db->prepare('UPDATE payments SET pg_payment_id = ? WHERE id = ?');
        $stmt->execute([$pgPaymentId, $id]);
    }

    /**
     * Finalize successful gateway payment → escrow hold + product sold.
     * Idempotent for repeated result_url calls.
     * Supports cart batches via payments.meta.cart_items.
     *
     * @return array{ok: bool, status?: string, error?: string, order_id?: int}
     */
    public function completeFromGateway(string $pgOrderId, string $pgPaymentId, string $pgAmount): array
    {
        $payment = $this->findByPgOrderId($pgOrderId);
        if (!$payment) {
            return ['ok' => false, 'error' => 'payment_not_found'];
        }

        if ($payment['status'] === self::STATUS_PAID) {
            return [
                'ok' => true,
                'status' => self::STATUS_PAID,
                'order_id' => (int) $payment['order_id'],
            ];
        }

        if ($payment['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'error' => 'invalid_status', 'status' => $payment['status']];
        }

        $amountExpected = (int) $payment['amount'];
        $amountPaid = (int) round((float) $pgAmount);
        if ($amountPaid !== $amountExpected) {
            return ['ok' => false, 'error' => 'amount_mismatch'];
        }

        $cartItems = $this->cartItemsFromMeta($payment['meta'] ?? null);
        $orderModel = new Order();

        if ($cartItems === []) {
            $order = $orderModel->find((int) $payment['order_id']);
            if (!$order || ($order['status'] ?? '') !== 'awaiting_payment') {
                return ['ok' => false, 'error' => 'order_invalid'];
            }
            $cartItems = [[
                'order_id' => (int) $payment['order_id'],
                'product_id' => (int) $payment['product_id'],
                'amount' => (int) $payment['amount'],
                'seller_id' => (int) ($order['seller_id'] ?? 0),
                'title' => '',
            ]];
        } else {
            foreach ($cartItems as $item) {
                $order = $orderModel->find((int) $item['order_id']);
                if (!$order || ($order['status'] ?? '') !== 'awaiting_payment') {
                    return ['ok' => false, 'error' => 'order_invalid'];
                }
            }
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $payment['id']]);
            $locked = $lock->fetch();
            if (!$locked) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'payment_not_found'];
            }
            if ($locked['status'] === self::STATUS_PAID) {
                $this->db->commit();
                return [
                    'ok' => true,
                    'status' => self::STATUS_PAID,
                    'order_id' => (int) $locked['order_id'],
                ];
            }

            $wallet = new Wallet();
            foreach ($cartItems as $item) {
                $pay = $wallet->payExternalToEscrow(
                    (int) $locked['buyer_id'],
                    (int) $item['amount'],
                    (int) $item['order_id'],
                    'freedompay'
                );
                if (!$pay['ok']) {
                    $this->db->rollBack();
                    return ['ok' => false, 'error' => $pay['error'] ?? 'wallet_failed'];
                }

                $orderModel->updateFields((int) $item['order_id'], [
                    'status' => 'escrowed',
                    'escrow_hold' => 'holding',
                    'paid_at' => date('Y-m-d H:i:s'),
                ]);

                $sold = $this->db->prepare(
                    "UPDATE products SET status = 'sold' WHERE id = ? AND status IN ('active', 'reserved')"
                );
                $sold->execute([(int) $item['product_id']]);
            }

            $updPay = $this->db->prepare(
                "UPDATE payments SET status = ?, pg_payment_id = ?, paid_at = NOW() WHERE id = ?"
            );
            $updPay->execute([
                self::STATUS_PAID,
                $pgPaymentId !== '' ? $pgPaymentId : $locked['pg_payment_id'],
                (int) $locked['id'],
            ]);

            $this->db->commit();

            $n = new Notification();
            foreach ($cartItems as $item) {
                $product = (new Product())->find((int) $item['product_id']);
                $sellerId = (int) ($item['seller_id'] ?? 0);
                if ($sellerId <= 0 && $product) {
                    $sellerId = (int) $product['user_id'];
                }
                if ($sellerId > 0) {
                    $n->createFor(
                        $sellerId,
                        t('escrow.notify_escrowed', [
                            'title' => $product['title'] ?? ($item['title'] ?? ''),
                            'amount' => number_format((int) $item['amount'], 0, '', ' '),
                            'id' => (int) $item['order_id'],
                        ])
                    );
                }
            }

            return [
                'ok' => true,
                'status' => self::STATUS_PAID,
                'order_id' => (int) $locked['order_id'],
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => 'complete_failed'];
        }
    }

    /**
     * Mark payment failed and release reserved product / cancel order.
     * @return array{ok: bool, order_id?: int, error?: string}
     */
    public function failFromGateway(string $pgOrderId, ?string $pgPaymentId = null): array
    {
        $payment = $this->findByPgOrderId($pgOrderId);
        if (!$payment) {
            return ['ok' => false, 'error' => 'payment_not_found'];
        }

        if ($payment['status'] === self::STATUS_PAID) {
            return ['ok' => true, 'order_id' => (int) $payment['order_id']];
        }

        if ($payment['status'] === self::STATUS_FAILED || $payment['status'] === self::STATUS_CANCELLED) {
            return ['ok' => true, 'order_id' => (int) $payment['order_id']];
        }

        $cartItems = $this->cartItemsFromMeta($payment['meta'] ?? null);
        if ($cartItems === []) {
            $cartItems = [[
                'order_id' => (int) $payment['order_id'],
                'product_id' => (int) $payment['product_id'],
            ]];
        }

        try {
            $this->db->beginTransaction();

            $upd = $this->db->prepare(
                "UPDATE payments SET status = ?, pg_payment_id = COALESCE(?, pg_payment_id) WHERE id = ? AND status = ?"
            );
            $upd->execute([
                self::STATUS_FAILED,
                $pgPaymentId,
                (int) $payment['id'],
                self::STATUS_PENDING,
            ]);

            $orderModel = new Order();
            foreach ($cartItems as $item) {
                $orderModel->updateFields((int) $item['order_id'], [
                    'status' => 'cancelled',
                    'escrow_hold' => 'none',
                ]);

                $release = $this->db->prepare(
                    "UPDATE products SET status = 'active' WHERE id = ? AND status = 'reserved'"
                );
                $release->execute([(int) $item['product_id']]);
            }

            $this->db->commit();
            return ['ok' => true, 'order_id' => (int) $payment['order_id']];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => 'fail_failed'];
        }
    }

    /**
     * @return list<array{order_id: int, product_id: int, amount?: int, seller_id?: int, title?: string}>
     */
    private function cartItemsFromMeta(mixed $meta): array
    {
        if (!is_string($meta) || $meta === '') {
            return [];
        }
        $decoded = json_decode($meta, true);
        if (!is_array($decoded) || empty($decoded['cart_items']) || !is_array($decoded['cart_items'])) {
            return [];
        }

        $items = [];
        foreach ($decoded['cart_items'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['order_id'] ?? 0);
            $productId = (int) ($row['product_id'] ?? 0);
            if ($orderId <= 0 || $productId <= 0) {
                continue;
            }
            $items[] = [
                'order_id' => $orderId,
                'product_id' => $productId,
                'amount' => (int) ($row['amount'] ?? 0),
                'seller_id' => (int) ($row['seller_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
            ];
        }

        return $items;
    }
}
