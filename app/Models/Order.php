<?php

namespace App\Models;

use App\Core\Model;
use App\Helpers\ProductHelper;
use App\Services\EscrowService;

class Order extends Model
{
    protected string $table = 'orders';
    private static bool $ensured = false;

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
            "CREATE TABLE IF NOT EXISTS orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id INT UNSIGNED NOT NULL,
                buyer_id INT UNSIGNED NOT NULL,
                seller_id INT UNSIGNED NOT NULL,
                amount INT UNSIGNED NOT NULL,
                payment_method VARCHAR(50) NOT NULL DEFAULT 'card',
                delivery_method VARCHAR(50) NOT NULL DEFAULT 'kazpost',
                status VARCHAR(32) NOT NULL DEFAULT 'escrowed',
                escrow_hold VARCHAR(32) NOT NULL DEFAULT 'holding',
                tracking_number VARCHAR(120) DEFAULT NULL,
                carrier VARCHAR(80) DEFAULT NULL,
                shipped_at DATETIME DEFAULT NULL,
                delivered_at DATETIME DEFAULT NULL,
                inspect_until DATETIME DEFAULT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                released_at DATETIME DEFAULT NULL,
                dispute_reason TEXT DEFAULT NULL,
                dispute_evidence TEXT DEFAULT NULL,
                disputed_at DATETIME DEFAULT NULL,
                arbiter_id INT UNSIGNED DEFAULT NULL,
                arbiter_decision VARCHAR(40) DEFAULT NULL,
                arbiter_at DATETIME DEFAULT NULL,
                return_tracking VARCHAR(120) DEFAULT NULL,
                return_shipped_at DATETIME DEFAULT NULL,
                return_delivered_at DATETIME DEFAULT NULL,
                refunded_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_buyer (buyer_id),
                INDEX idx_seller (seller_id),
                INDEX idx_product (product_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->ensureColumns([
            'delivery_method' => "VARCHAR(50) NOT NULL DEFAULT 'kazpost'",
            'escrow_hold' => "VARCHAR(32) NOT NULL DEFAULT 'holding'",
            'tracking_number' => 'VARCHAR(120) DEFAULT NULL',
            'carrier' => 'VARCHAR(80) DEFAULT NULL',
            'shipped_at' => 'DATETIME DEFAULT NULL',
            'delivered_at' => 'DATETIME DEFAULT NULL',
            'inspect_until' => 'DATETIME DEFAULT NULL',
            'confirmed_at' => 'DATETIME DEFAULT NULL',
            'released_at' => 'DATETIME DEFAULT NULL',
            'dispute_reason' => 'TEXT DEFAULT NULL',
            'dispute_evidence' => 'TEXT DEFAULT NULL',
            'disputed_at' => 'DATETIME DEFAULT NULL',
            'arbiter_id' => 'INT UNSIGNED DEFAULT NULL',
            'arbiter_decision' => 'VARCHAR(40) DEFAULT NULL',
            'arbiter_at' => 'DATETIME DEFAULT NULL',
            'return_tracking' => 'VARCHAR(120) DEFAULT NULL',
            'return_shipped_at' => 'DATETIME DEFAULT NULL',
            'return_delivered_at' => 'DATETIME DEFAULT NULL',
            'refunded_at' => 'DATETIME DEFAULT NULL',
        ]);

        // Старый ENUM paid → escrowed semantics
        try {
            $this->db->exec("ALTER TABLE orders MODIFY status VARCHAR(32) NOT NULL DEFAULT 'escrowed'");
        } catch (\Throwable $e) {
            // already migrated or unsupported
        }

        try {
            $this->db->exec("UPDATE orders SET status = 'completed', escrow_hold = 'released_seller' WHERE status = 'paid'");
        } catch (\Throwable $e) {
            // ignore
        }

        self::$ensured = true;
    }

    /** @param array<string, string> $columns */
    private function ensureColumns(array $columns): void
    {
        $existing = [];
        try {
            $rows = $this->db->query('SHOW COLUMNS FROM orders')->fetchAll();
            foreach ($rows as $row) {
                $existing[strtolower((string) $row['Field'])] = true;
            }
        } catch (\Throwable $e) {
            return;
        }

        foreach ($columns as $name => $definition) {
            if (isset($existing[strtolower($name)])) {
                continue;
            }
            try {
                $this->db->exec("ALTER TABLE orders ADD COLUMN {$name} {$definition}");
            } catch (\Throwable $e) {
                // ignore race / duplicate
            }
        }
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*,
                    p.title AS product_title, p.type AS product_type,
                    p.image AS product_image, p.images AS product_images, p.status AS product_status,
                    buyer.name AS buyer_name, buyer.phone AS buyer_phone,
                    seller.name AS seller_name, seller.phone AS seller_phone
             FROM orders o
             JOIN products p ON p.id = o.product_id
             JOIN users buyer ON buyer.id = o.buyer_id
             JOIN users seller ON seller.id = o.seller_id
             WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*,
                    p.title AS product_title, p.image AS product_image, p.images AS product_images,
                    buyer.name AS buyer_name,
                    seller.name AS seller_name
             FROM orders o
             JOIN products p ON p.id = o.product_id
             JOIN users buyer ON buyer.id = o.buyer_id
             JOIN users seller ON seller.id = o.seller_id
             WHERE o.buyer_id = ? OR o.seller_id = ?
             ORDER BY o.created_at DESC'
        );
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    }

    /** @return list<array> */
    public function findByStatus(string $status): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, p.title AS product_title,
                    buyer.name AS buyer_name, seller.name AS seller_name
             FROM orders o
             JOIN products p ON p.id = o.product_id
             JOIN users buyer ON buyer.id = o.buyer_id
             JOIN users seller ON seller.id = o.seller_id
             WHERE o.status = ?
             ORDER BY o.disputed_at DESC, o.created_at DESC'
        );
        $stmt->execute([$status]);
        return $stmt->fetchAll();
    }

    /** @return list<array> */
    public function findDeliveredPastInspect(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM orders
             WHERE status = 'delivered'
               AND inspect_until IS NOT NULL
               AND inspect_until <= NOW()"
        );
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if (!$fields) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($fields as $col => $val) {
            $sets[] = "{$col} = ?";
            $vals[] = $val;
        }
        $vals[] = $id;
        $sql = 'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($vals);
    }

    /**
     * Оплата → деньги на эскроу (заморозка), товар reserved/sold.
     * Карта через FreedomPay: awaiting_payment + redirect_url.
     * @return array{ok: bool, order_id?: int, redirect_url?: string, error?: string}
     */
    public function createEscrow(int $productId, int $buyerId, string $paymentMethod, string $deliveryMethod): array
    {
        $product = (new Product())->find($productId);
        if (!$product || ($product['status'] ?? '') !== 'active') {
            return ['ok' => false, 'error' => t('checkout.unavailable')];
        }

        if ((int) $product['user_id'] === $buyerId) {
            return ['ok' => false, 'error' => t('checkout.own_product')];
        }

        if (!ProductHelper::isPurchasable($product)) {
            return ['ok' => false, 'error' => t('checkout.not_for_sale')];
        }

        $amount = (int) $product['price'];
        if ($amount <= 0) {
            return ['ok' => false, 'error' => t('checkout.invalid_price')];
        }

        $method = in_array($paymentMethod, ['wallet', 'card', 'kaspi'], true) ? $paymentMethod : 'wallet';
        $delivery = in_array($deliveryMethod, EscrowService::DELIVERY_METHODS, true)
            ? $deliveryMethod
            : 'kazpost';

        $fp = new \App\Services\FreedomPay\Client();
        $useFreedomPay = $method === 'card' && $fp->isConfigured();
        $allowSimulated = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);

        if ($method !== 'wallet' && !$useFreedomPay && !$allowSimulated) {
            return ['ok' => false, 'error' => t('wallet.payments_disabled')];
        }

        if ($useFreedomPay) {
            return $this->createFreedomPayEscrow($product, $buyerId, $amount, $delivery, $fp);
        }

        $wallet = new Wallet();
        if ($method === 'wallet' && $wallet->balance($buyerId) < $amount) {
            return ['ok' => false, 'error' => t('wallet.insufficient_checkout')];
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare('SELECT id, status FROM products WHERE id = ? FOR UPDATE');
            $lock->execute([$productId]);
            $locked = $lock->fetch();
            if (!$locked || $locked['status'] !== 'active') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('checkout.unavailable')];
            }

            $stmt = $this->db->prepare(
                'INSERT INTO orders (
                    product_id, buyer_id, seller_id, amount, payment_method, delivery_method,
                    status, escrow_hold, paid_at
                 ) VALUES (?, ?, ?, ?, ?, ?, \'escrowed\', \'holding\', NOW())'
            );
            $stmt->execute([
                $productId,
                $buyerId,
                (int) $product['user_id'],
                $amount,
                $method,
                $delivery,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            if ($method === 'wallet') {
                $pay = $wallet->holdForEscrow($buyerId, $amount, $orderId);
            } else {
                $pay = $wallet->payExternalToEscrow($buyerId, $amount, $orderId, $method);
            }
            if (!$pay['ok']) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => $pay['error'] ?? t('checkout.payment_failed')];
            }

            $sold = $this->db->prepare("UPDATE products SET status = 'sold' WHERE id = ? AND status = 'active'");
            $sold->execute([$productId]);

            $this->db->commit();

            (new Notification())->createFor(
                (int) $product['user_id'],
                t('escrow.notify_escrowed', [
                    'title' => $product['title'],
                    'amount' => number_format($amount, 0, '', ' '),
                    'id' => $orderId,
                ])
            );

            return ['ok' => true, 'order_id' => $orderId];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('checkout.payment_failed')];
        }
    }

    /**
     * Hosted Checkout: резерв товара → init_payment → редирект на FreedomPay.
     * Эскроу фиксируется в Payment::completeFromGateway по result_url.
     *
     * @param array<string, mixed> $product
     * @return array{ok: bool, order_id?: int, redirect_url?: string, error?: string}
     */
    private function createFreedomPayEscrow(
        array $product,
        int $buyerId,
        int $amount,
        string $delivery,
        \App\Services\FreedomPay\Client $fp
    ): array {
        $productId = (int) $product['id'];
        $buyer = (new User())->find($buyerId);
        $paymentModel = new Payment();
        $pgOrderId = 'zk-' . $buyerId . '-' . bin2hex(random_bytes(6));

        $init = $fp->initPayment([
            'order_id' => $pgOrderId,
            'amount' => $amount,
            'description' => mb_substr((string) ($product['title'] ?? ('Product #' . $productId)), 0, 200),
            'user_id' => (string) $buyerId,
            'user_email' => (string) ($buyer['email'] ?? ''),
            'user_phone' => (string) ($buyer['phone'] ?? ''),
            'user_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'param2' => (string) $productId,
        ]);

        if (!$init['ok'] || empty($init['redirect_url'])) {
            return [
                'ok' => false,
                'error' => $init['error'] ?? t('checkout.payment_failed'),
            ];
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare('SELECT id, status FROM products WHERE id = ? FOR UPDATE');
            $lock->execute([$productId]);
            $locked = $lock->fetch();
            if (!$locked || $locked['status'] !== 'active') {
                $this->db->rollBack();
                return ['ok' => false, 'error' => t('checkout.unavailable')];
            }

            $stmt = $this->db->prepare(
                'INSERT INTO orders (
                    product_id, buyer_id, seller_id, amount, payment_method, delivery_method,
                    status, escrow_hold, paid_at
                 ) VALUES (?, ?, ?, ?, \'card\', ?, \'awaiting_payment\', \'pending\', NULL)'
            );
            $stmt->execute([
                $productId,
                $buyerId,
                (int) $product['user_id'],
                $amount,
                $delivery,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            $paymentId = $paymentModel->createPending([
                'pg_order_id' => $pgOrderId,
                'order_id' => $orderId,
                'product_id' => $productId,
                'buyer_id' => $buyerId,
                'amount' => $amount,
                'delivery_method' => $delivery,
                'payment_method' => 'card',
                'pg_payment_id' => !empty($init['payment_id']) ? (string) $init['payment_id'] : null,
            ]);

            $reserve = $this->db->prepare(
                "UPDATE products SET status = 'reserved' WHERE id = ? AND status = 'active'"
            );
            $reserve->execute([$productId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('checkout.payment_failed')];
        }

        return [
            'ok' => true,
            'order_id' => $orderId,
            'redirect_url' => (string) $init['redirect_url'],
        ];
    }

    /**
     * Оплата всей корзины: отдельный эскроу-заказ на каждый товар.
     *
     * @param list<array<string, mixed>> $products
     * @return array{ok: bool, order_id?: int, order_ids?: list<int>, redirect_url?: string, error?: string}
     */
    public function createEscrowCart(array $products, int $buyerId, string $paymentMethod, string $deliveryMethod): array
    {
        if ($products === []) {
            return ['ok' => false, 'error' => t('checkout.cart_empty')];
        }

        if (count($products) === 1) {
            return $this->createEscrow((int) $products[0]['id'], $buyerId, $paymentMethod, $deliveryMethod);
        }

        $validated = [];
        $total = 0;
        foreach ($products as $product) {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0 || ($product['status'] ?? '') !== 'active') {
                return ['ok' => false, 'error' => t('checkout.unavailable')];
            }
            if ((int) ($product['user_id'] ?? 0) === $buyerId) {
                return ['ok' => false, 'error' => t('checkout.own_product')];
            }
            if (!ProductHelper::isPurchasable($product)) {
                return ['ok' => false, 'error' => t('checkout.not_for_sale')];
            }
            $amount = (int) ($product['price'] ?? 0);
            if ($amount <= 0) {
                return ['ok' => false, 'error' => t('checkout.invalid_price')];
            }
            $validated[] = $product;
            $total += $amount;
        }

        $method = in_array($paymentMethod, ['wallet', 'card', 'kaspi'], true) ? $paymentMethod : 'wallet';
        $delivery = in_array($deliveryMethod, EscrowService::DELIVERY_METHODS, true)
            ? $deliveryMethod
            : 'kazpost';

        $fp = new \App\Services\FreedomPay\Client();
        $useFreedomPay = $method === 'card' && $fp->isConfigured();
        $allowSimulated = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);

        if ($method !== 'wallet' && !$useFreedomPay && !$allowSimulated) {
            return ['ok' => false, 'error' => t('wallet.payments_disabled')];
        }

        if ($useFreedomPay) {
            return $this->createFreedomPayEscrowCart($validated, $buyerId, $total, $delivery, $fp);
        }

        $wallet = new Wallet();
        if ($method === 'wallet' && $wallet->balance($buyerId) < $total) {
            return ['ok' => false, 'error' => t('wallet.insufficient_checkout')];
        }

        try {
            $this->db->beginTransaction();
            $orderIds = [];
            $notify = [];

            foreach ($validated as $product) {
                $productId = (int) $product['id'];
                $amount = (int) $product['price'];

                $lock = $this->db->prepare('SELECT id, status FROM products WHERE id = ? FOR UPDATE');
                $lock->execute([$productId]);
                $locked = $lock->fetch();
                if (!$locked || $locked['status'] !== 'active') {
                    $this->db->rollBack();
                    return ['ok' => false, 'error' => t('checkout.unavailable')];
                }

                $stmt = $this->db->prepare(
                    'INSERT INTO orders (
                        product_id, buyer_id, seller_id, amount, payment_method, delivery_method,
                        status, escrow_hold, paid_at
                     ) VALUES (?, ?, ?, ?, ?, ?, \'escrowed\', \'holding\', NOW())'
                );
                $stmt->execute([
                    $productId,
                    $buyerId,
                    (int) $product['user_id'],
                    $amount,
                    $method,
                    $delivery,
                ]);
                $orderId = (int) $this->db->lastInsertId();
                $orderIds[] = $orderId;

                if ($method === 'wallet') {
                    $pay = $wallet->holdForEscrow($buyerId, $amount, $orderId);
                } else {
                    $pay = $wallet->payExternalToEscrow($buyerId, $amount, $orderId, $method);
                }
                if (!$pay['ok']) {
                    $this->db->rollBack();
                    return ['ok' => false, 'error' => $pay['error'] ?? t('checkout.payment_failed')];
                }

                $sold = $this->db->prepare("UPDATE products SET status = 'sold' WHERE id = ? AND status = 'active'");
                $sold->execute([$productId]);

                $notify[] = [
                    'seller_id' => (int) $product['user_id'],
                    'title' => (string) $product['title'],
                    'amount' => $amount,
                    'order_id' => $orderId,
                ];
            }

            $this->db->commit();

            $n = new Notification();
            foreach ($notify as $row) {
                $n->createFor(
                    $row['seller_id'],
                    t('escrow.notify_escrowed', [
                        'title' => $row['title'],
                        'amount' => number_format($row['amount'], 0, '', ' '),
                        'id' => $row['order_id'],
                    ])
                );
            }

            return [
                'ok' => true,
                'order_id' => $orderIds[0],
                'order_ids' => $orderIds,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('checkout.payment_failed')];
        }
    }

    /**
     * @param list<array<string, mixed>> $products
     * @return array{ok: bool, order_id?: int, order_ids?: list<int>, redirect_url?: string, error?: string}
     */
    private function createFreedomPayEscrowCart(
        array $products,
        int $buyerId,
        int $total,
        string $delivery,
        \App\Services\FreedomPay\Client $fp
    ): array {
        $buyer = (new User())->find($buyerId);
        $paymentModel = new Payment();
        $pgOrderId = 'zk-cart-' . $buyerId . '-' . bin2hex(random_bytes(6));
        $titles = array_map(
            static fn (array $p): string => (string) ($p['title'] ?? ('#' . (int) $p['id'])),
            $products
        );
        $description = mb_substr(implode(', ', $titles), 0, 200);

        $init = $fp->initPayment([
            'order_id' => $pgOrderId,
            'amount' => $total,
            'description' => $description,
            'user_id' => (string) $buyerId,
            'user_email' => (string) ($buyer['email'] ?? ''),
            'user_phone' => (string) ($buyer['phone'] ?? ''),
            'user_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'param2' => 'cart',
        ]);

        if (!$init['ok'] || empty($init['redirect_url'])) {
            return [
                'ok' => false,
                'error' => $init['error'] ?? t('checkout.payment_failed'),
            ];
        }

        try {
            $this->db->beginTransaction();
            $cartItems = [];
            $orderIds = [];
            $firstProductId = (int) $products[0]['id'];

            foreach ($products as $product) {
                $productId = (int) $product['id'];
                $amount = (int) $product['price'];

                $lock = $this->db->prepare('SELECT id, status FROM products WHERE id = ? FOR UPDATE');
                $lock->execute([$productId]);
                $locked = $lock->fetch();
                if (!$locked || $locked['status'] !== 'active') {
                    $this->db->rollBack();
                    return ['ok' => false, 'error' => t('checkout.unavailable')];
                }

                $stmt = $this->db->prepare(
                    'INSERT INTO orders (
                        product_id, buyer_id, seller_id, amount, payment_method, delivery_method,
                        status, escrow_hold, paid_at
                     ) VALUES (?, ?, ?, ?, \'card\', ?, \'awaiting_payment\', \'pending\', NULL)'
                );
                $stmt->execute([
                    $productId,
                    $buyerId,
                    (int) $product['user_id'],
                    $amount,
                    $delivery,
                ]);
                $orderId = (int) $this->db->lastInsertId();
                $orderIds[] = $orderId;
                $cartItems[] = [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'amount' => $amount,
                    'seller_id' => (int) $product['user_id'],
                    'title' => (string) ($product['title'] ?? ''),
                ];

                $reserve = $this->db->prepare(
                    "UPDATE products SET status = 'reserved' WHERE id = ? AND status = 'active'"
                );
                $reserve->execute([$productId]);
            }

            $paymentModel->createPending([
                'pg_order_id' => $pgOrderId,
                'order_id' => $orderIds[0],
                'product_id' => $firstProductId,
                'buyer_id' => $buyerId,
                'amount' => $total,
                'delivery_method' => $delivery,
                'payment_method' => 'card',
                'pg_payment_id' => !empty($init['payment_id']) ? (string) $init['payment_id'] : null,
                'meta' => json_encode(['cart_items' => $cartItems], JSON_UNESCAPED_UNICODE),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => t('checkout.payment_failed')];
        }

        return [
            'ok' => true,
            'order_id' => $orderIds[0],
            'order_ids' => $orderIds,
            'redirect_url' => (string) $init['redirect_url'],
        ];
    }

    /** @deprecated use createEscrow */
    public function createPaid(int $productId, int $buyerId, string $paymentMethod): array
    {
        return $this->createEscrow($productId, $buyerId, $paymentMethod, 'kazpost');
    }
}
