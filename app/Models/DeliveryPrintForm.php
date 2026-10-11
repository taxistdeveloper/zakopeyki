<?php

namespace App\Models;

use App\Core\Model;

/**
 * Печатные формы CDEK (waybill), привязанные к delivery_orders.
 * Не заменяет delivery_documents.avr_data.
 */
class DeliveryPrintForm extends Model
{
    protected string $table = 'delivery_print_forms';
    private static bool $ensured = false;

    public const STATUS_REQUESTED = 'REQUESTED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_READY = 'READY';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_EXPIRED = 'EXPIRED';

    public const SOURCE_API = 'api';
    public const SOURCE_WEBHOOK = 'webhook';

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
            "CREATE TABLE IF NOT EXISTS delivery_print_forms (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                cdek_order_uuid VARCHAR(64) NOT NULL,
                cdek_number VARCHAR(64) DEFAULT NULL,
                form_type VARCHAR(32) NOT NULL,
                print_uuid VARCHAR(64) DEFAULT NULL,
                file_url VARCHAR(512) DEFAULT NULL,
                format VARCHAR(16) NOT NULL DEFAULT 'pdf',
                status VARCHAR(16) NOT NULL DEFAULT 'REQUESTED',
                source VARCHAR(16) NOT NULL DEFAULT 'api',
                error_code VARCHAR(64) DEFAULT NULL,
                error_message VARCHAR(255) DEFAULT NULL,
                storage_path VARCHAR(255) DEFAULT NULL,
                file_size INT UNSIGNED DEFAULT NULL,
                poll_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                requested_at DATETIME DEFAULT NULL,
                received_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_print_order_type (delivery_order_id, form_type),
                UNIQUE KEY uq_print_uuid (print_uuid),
                INDEX idx_print_status (status),
                INDEX idx_print_delivery (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function findForDelivery(int $deliveryOrderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_print_forms WHERE delivery_order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$deliveryOrderId]);
        return $stmt->fetchAll() ?: [];
    }

    public function findByDeliveryAndType(int $deliveryOrderId, string $formType): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_print_forms WHERE delivery_order_id = ? AND form_type = ? LIMIT 1'
        );
        $stmt->execute([$deliveryOrderId, $formType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByPrintUuid(string $printUuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_print_forms WHERE print_uuid = ? LIMIT 1');
        $stmt->execute([$printUuid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, duplicate?: bool}
     */
    public function insertRow(array $data): array
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO delivery_print_forms
                 (delivery_order_id, cdek_order_uuid, cdek_number, form_type, print_uuid, format, status, source, requested_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                (int) $data['delivery_order_id'],
                (string) $data['cdek_order_uuid'],
                $data['cdek_number'] ?? null,
                (string) $data['form_type'],
                $data['print_uuid'] ?? null,
                $data['format'] ?? 'pdf',
                $data['status'] ?? self::STATUS_REQUESTED,
                $data['source'] ?? self::SOURCE_API,
            ]);
            return ['ok' => true, 'id' => (int) $this->db->lastInsertId()];
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'duplicate' => true];
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        $this->db->prepare(
            'UPDATE delivery_print_forms SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($vals);
    }

    /** @return list<array<string, mixed>> */
    public function findPendingPoll(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "SELECT * FROM delivery_print_forms
                WHERE status IN ('REQUESTED', 'PROCESSING')
                  AND poll_attempts < 8
                  AND (updated_at IS NULL OR updated_at <= (NOW() - INTERVAL 3 SECOND))
                ORDER BY updated_at ASC, id ASC
                LIMIT {$limit}";
        return $this->db->query($sql)->fetchAll() ?: [];
    }

    public function getDb(): \PDO
    {
        return $this->db;
    }
}
