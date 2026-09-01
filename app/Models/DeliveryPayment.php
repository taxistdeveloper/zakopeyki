<?php

namespace App\Models;

use App\Core\Model;
use App\Services\Delivery\DeliveryService;

class DeliveryPayment extends Model
{
    protected string $table = 'delivery_payments';
    private static bool $ensured = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
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

        self::$ensured = true;
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_payments WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
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

    public function createPending(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO delivery_payments (
                delivery_order_id, buyer_user_id, pg_order_id, amount, currency,
                acquirer_provider, idempotency_key, meta, status, purpose
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $data['delivery_order_id'],
            (int) $data['buyer_user_id'],
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
    }

    /**
     * @return array{ok: bool, status?: string, error?: string, delivery_order_id?: int}
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
                'delivery_order_id' => (int) $payment['delivery_order_id'],
            ];
        }

        if ($payment['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }

        $expected = (int) $payment['amount'];
        $paid = (int) round((float) $pgAmount);
        if ($paid !== $expected) {
            return ['ok' => false, 'error' => 'amount_mismatch'];
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare('SELECT * FROM delivery_payments WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $payment['id']]);
            $locked = $lock->fetch();
            if (!$locked || $locked['status'] === self::STATUS_PAID) {
                $this->db->commit();
                return [
                    'ok' => true,
                    'status' => self::STATUS_PAID,
                    'delivery_order_id' => (int) $locked['delivery_order_id'],
                ];
            }

            $upd = $this->db->prepare(
                'UPDATE delivery_payments SET status = ?, pg_payment_id = ?, paid_at = NOW() WHERE id = ?'
            );
            $upd->execute([self::STATUS_PAID, $pgPaymentId !== '' ? $pgPaymentId : null, (int) $locked['id']]);

            $this->db->commit();

            (new DeliveryService())->onDeliveryPaid((int) $locked['delivery_order_id'], (int) $locked['amount']);

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

    public static function isDeliveryPgOrderId(string $pgOrderId): bool
    {
        return str_starts_with($pgOrderId, 'zk-del-');
    }
}
