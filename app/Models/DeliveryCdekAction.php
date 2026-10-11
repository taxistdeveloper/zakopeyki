<?php

namespace App\Models;

use App\Core\Model;

/**
 * Внутренний запрос на операцию CDEK. Статусы строки — не статусы CDEK и не возврат товара.
 */
class DeliveryCdekAction extends Model
{
    protected string $table = 'delivery_cdek_actions';
    private static bool $ensured = false;

    public const REQUESTED = 'REQUESTED';
    public const VALIDATING = 'VALIDATING';
    public const SUBMITTED = 'SUBMITTED';
    public const CONFIRMED = 'CONFIRMED';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';
    public const UNKNOWN_RESULT = 'UNKNOWN_RESULT';

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
            "CREATE TABLE IF NOT EXISTS delivery_cdek_actions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                operation_type VARCHAR(32) NOT NULL,
                initiator_user_id INT UNSIGNED DEFAULT NULL,
                initiator_role VARCHAR(16) DEFAULT NULL,
                reason VARCHAR(200) DEFAULT NULL,
                state VARCHAR(32) NOT NULL,
                cdek_order_uuid VARCHAR(64) DEFAULT NULL,
                idempotency_key CHAR(64) NOT NULL,
                cdek_request_uuid VARCHAR(64) DEFAULT NULL,
                related_uuid VARCHAR(64) DEFAULT NULL,
                response_state VARCHAR(32) DEFAULT NULL,
                error_code VARCHAR(64) DEFAULT NULL,
                error_message VARCHAR(200) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cdek_action_key (idempotency_key),
                INDEX idx_cdek_action_delivery (delivery_order_id, operation_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$ensured = true;
    }

    public function findByKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_cdek_actions WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_cdek_actions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function forDelivery(int $deliveryOrderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_cdek_actions WHERE delivery_order_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$deliveryOrderId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO delivery_cdek_actions
                (delivery_order_id, operation_type, initiator_user_id, initiator_role, reason, state,
                 cdek_order_uuid, idempotency_key, cdek_request_uuid, related_uuid, response_state,
                 error_code, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $row['delivery_order_id'],
            (string) $row['operation_type'],
            $row['initiator_user_id'] ?? null,
            $row['initiator_role'] ?? null,
            $row['reason'] ?? null,
            (string) $row['state'],
            $row['cdek_order_uuid'] ?? null,
            (string) $row['idempotency_key'],
            $row['cdek_request_uuid'] ?? null,
            $row['related_uuid'] ?? null,
            $row['response_state'] ?? null,
            $row['error_code'] ?? null,
            $row['error_message'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateState(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($fields as $key => $value) {
            $sets[] = $key . ' = ?';
            $vals[] = $value;
        }
        $vals[] = $id;
        $this->db->prepare(
            'UPDATE delivery_cdek_actions SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($vals);
    }
}
