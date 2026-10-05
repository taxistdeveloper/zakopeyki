<?php

namespace App\Models;

use App\Core\Model;
use App\Services\Delivery\DeliveryPaymentService;

class DeliveryPayment extends Model
{
    protected string $table = 'delivery_payments';
    private static bool $ensured = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PURPOSE = 'DELIVERY_SERVICE';

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
            "CREATE TABLE IF NOT EXISTS delivery_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                buyer_user_id INT UNSIGNED NOT NULL,
                quote_id INT UNSIGNED DEFAULT NULL,
                pg_order_id VARCHAR(64) NOT NULL,
                pg_payment_id VARCHAR(64) DEFAULT NULL,
                acquirer_provider VARCHAR(32) NOT NULL DEFAULT 'freedompay',
                external_payment_id VARCHAR(64) DEFAULT NULL,
                amount INT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'KZT',
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                purpose VARCHAR(32) NOT NULL DEFAULT 'DELIVERY_SERVICE',
                idempotency_key VARCHAR(64) NOT NULL,
                failure_reason VARCHAR(255) DEFAULT NULL,
                webhook_payload_hash VARCHAR(64) DEFAULT NULL,
                meta TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_del_pg_order (pg_order_id),
                UNIQUE KEY uq_del_idempotency (idempotency_key),
                INDEX idx_del_payment_order (delivery_order_id),
                INDEX idx_del_payment_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->ensureDeliveryPaymentColumns();

        self::$ensured = true;
    }

    private function ensureDeliveryPaymentColumns(): void
    {
        $columns = [
            'quote_id' => 'INT UNSIGNED DEFAULT NULL',
            'webhook_payload_hash' => 'VARCHAR(64) DEFAULT NULL',
            'external_payment_id' => 'VARCHAR(64) DEFAULT NULL',
            'failure_reason' => 'VARCHAR(255) DEFAULT NULL',
        ];
        $existing = [];
        try {
            $rows = $this->db->query('SHOW COLUMNS FROM delivery_payments')->fetchAll();
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
                $this->db->exec("ALTER TABLE delivery_payments ADD COLUMN {$name} {$definition}");
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_payments WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_payments WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findPendingForOrder(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM delivery_payments
             WHERE delivery_order_id = ? AND status = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$deliveryOrderId, self::STATUS_PENDING]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findLatestForOrder(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_payments WHERE delivery_order_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$deliveryOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createPending(array $data): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO delivery_payments (
                    delivery_order_id, buyer_user_id, quote_id, pg_order_id, amount, currency,
                    acquirer_provider, idempotency_key, meta, status, purpose
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $data['delivery_order_id'],
                (int) $data['buyer_user_id'],
                isset($data['quote_id']) ? (int) $data['quote_id'] : null,
                $data['pg_order_id'],
                (int) $data['amount'],
                $data['currency'] ?? 'KZT',
                $data['acquirer_provider'] ?? 'freedompay',
                $data['idempotency_key'],
                $data['meta'] ?? null,
                self::STATUS_PENDING,
                self::PURPOSE,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Unique idempotency_key / pg_order_id — return existing (race-safe).
            $existing = $this->findByIdempotencyKey((string) $data['idempotency_key']);
            if ($existing) {
                return (int) $existing['id'];
            }
            $byPg = $this->findByPgOrderId((string) $data['pg_order_id']);
            if ($byPg) {
                return (int) $byPg['id'];
            }
            throw $e;
        }
    }

    public function findPaidForDeliveryOrder(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_payments
             WHERE delivery_order_id = ? AND status = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$deliveryOrderId, self::STATUS_PAID]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Подтверждение оплаты только после серверной проверки callback.
     * paidAmount >= expected; currency match; idempotent PAID.
     *
     * @return array{ok: bool, status?: string, error?: string, delivery_order_id?: int}
     */
    public function completeFromGateway(
        string $pgOrderId,
        string $pgPaymentId,
        string $pgAmount,
        ?string $pgCurrency = null,
        ?string $webhookPayloadHash = null
    ): array {
        $payment = $this->findByPgOrderId($pgOrderId);
        if (!$payment) {
            return ['ok' => false, 'error' => 'payment_not_found'];
        }

        if ($payment['status'] === self::STATUS_PAID) {
            return [
                'ok' => true,
                'status' => self::STATUS_PAID,
                'delivery_order_id' => (int) $payment['delivery_order_id'],
            ];
        }

        if (in_array($payment['status'], [self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED], true)) {
            return ['ok' => false, 'error' => 'payment_refunded'];
        }

        if ($payment['status'] !== self::STATUS_PENDING && $payment['status'] !== self::STATUS_AUTHORIZED) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }

        $expected = (int) $payment['amount'];
        $paid = (int) round((float) $pgAmount);
        // 100% rule: paid must be >= required (INT tenge).
        if ($paid < $expected) {
            return ['ok' => false, 'error' => 'amount_mismatch'];
        }

        $expectedCurrency = strtoupper((string) ($payment['currency'] ?? 'KZT'));
        if ($pgCurrency !== null && $pgCurrency !== '') {
            if (strtoupper(trim($pgCurrency)) !== $expectedCurrency) {
                return ['ok' => false, 'error' => 'currency_mismatch'];
            }
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare('SELECT * FROM delivery_payments WHERE id = ? FOR UPDATE');
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
                    'delivery_order_id' => (int) $locked['delivery_order_id'],
                ];
            }

            // Same webhook hash → idempotent no-op if already processed fields set.
            if ($webhookPayloadHash !== null && $webhookPayloadHash !== ''
                && ($locked['webhook_payload_hash'] ?? '') === $webhookPayloadHash
                && $locked['status'] === self::STATUS_PAID
            ) {
                $this->db->commit();
                return [
                    'ok' => true,
                    'status' => self::STATUS_PAID,
                    'delivery_order_id' => (int) $locked['delivery_order_id'],
                ];
            }

            $upd = $this->db->prepare(
                'UPDATE delivery_payments
                 SET status = ?, pg_payment_id = ?, paid_at = NOW(),
                     webhook_payload_hash = COALESCE(?, webhook_payload_hash)
                 WHERE id = ?'
            );
            $upd->execute([
                self::STATUS_PAID,
                $pgPaymentId !== '' ? $pgPaymentId : null,
                $webhookPayloadHash,
                (int) $locked['id'],
            ]);

            $this->db->commit();

            // Confirm delivery PAID without CDEK create (Phase 7 barrier).
            (new DeliveryPaymentService(null, $this))->onPaymentConfirmed(
                (int) $locked['delivery_order_id'],
                (int) $locked['amount'],
                (int) $locked['id']
            );

            return [
                'ok' => true,
                'status' => self::STATUS_PAID,
                'delivery_order_id' => (int) $locked['delivery_order_id'],
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['ok' => false, 'error' => 'complete_failed'];
        }
    }

    /**
     * @return array{ok: bool, delivery_order_id?: int, error?: string}
     */
    public function failFromGateway(string $pgOrderId, ?string $pgPaymentId = null): array
    {
        $payment = $this->findByPgOrderId($pgOrderId);
        if (!$payment) {
            return ['ok' => false, 'error' => 'payment_not_found'];
        }

        if ($payment['status'] === self::STATUS_PAID) {
            return ['ok' => true, 'delivery_order_id' => (int) $payment['delivery_order_id']];
        }

        $this->db->prepare(
            'UPDATE delivery_payments SET status = ?, pg_payment_id = COALESCE(?, pg_payment_id) WHERE id = ?'
        )->execute([self::STATUS_FAILED, $pgPaymentId, (int) $payment['id']]);

        $delivery = new DeliveryOrder();
        $delivery->transitionStatus(
            (int) $payment['delivery_order_id'],
            DeliveryOrder::STATUS_READY_FOR_PAYMENT,
            null,
            'system',
            'payment_failed'
        );
        $delivery->updateFields((int) $payment['delivery_order_id'], [
            'payment_status' => 'failed',
        ]);

        return ['ok' => true, 'delivery_order_id' => (int) $payment['delivery_order_id']];
    }

    /**
     * Refund до CDEK registration: нельзя считать PAID.
     *
     * @return array{ok: bool, error?: string}
     */
    public function markRefunded(int $paymentId, string $reason = 'refunded'): array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_payments WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            return ['ok' => false, 'error' => 'payment_not_found'];
        }

        $this->db->prepare(
            'UPDATE delivery_payments SET status = ?, failure_reason = ? WHERE id = ?'
        )->execute([self::STATUS_REFUNDED, $reason, $paymentId]);

        $delivery = new DeliveryOrder();
        $row = $delivery->find((int) $payment['delivery_order_id']);
        $uuid = (string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? '');
        if ($uuid !== '') {
            // Refund after CDEK create — GAP lifecycle; only flag payment.
            $delivery->updateFields((int) $payment['delivery_order_id'], [
                'payment_status' => 'refunded',
            ]);
            return ['ok' => true, 'gap' => 'refund_after_cdek_create'];
        }

        $delivery->updateFields((int) $payment['delivery_order_id'], [
            'payment_status' => 'refunded',
            'status' => DeliveryOrder::STATUS_READY_FOR_PAYMENT,
        ]);
        $delivery->logEvent(
            (int) $payment['delivery_order_id'],
            null,
            'system',
            'payment_refunded',
            null,
            null,
            ['payment_id' => $paymentId]
        );

        return ['ok' => true];
    }

    public static function isDeliveryPgOrderId(string $pgOrderId): bool
    {
        return str_starts_with($pgOrderId, 'zk-del-');
    }
}
