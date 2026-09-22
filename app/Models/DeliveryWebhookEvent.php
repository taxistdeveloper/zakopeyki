<?php

namespace App\Models;

use App\Core\Model;

/**
 * Идемпотентный лог входящих webhook-событий логистики.
 */
class DeliveryWebhookEvent extends Model
{
    protected string $table = 'delivery_webhook_events';
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
            "CREATE TABLE IF NOT EXISTS delivery_webhook_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(32) NOT NULL DEFAULT 'cdek',
                event_type VARCHAR(64) DEFAULT NULL,
                event_uuid VARCHAR(64) DEFAULT NULL,
                cdek_order_uuid VARCHAR(64) DEFAULT NULL,
                status_code VARCHAR(64) DEFAULT NULL,
                event_hash CHAR(64) NOT NULL,
                delivery_order_id INT UNSIGNED DEFAULT NULL,
                payload_hash CHAR(64) DEFAULT NULL,
                process_result VARCHAR(32) NOT NULL DEFAULT 'received',
                error_message VARCHAR(255) DEFAULT NULL,
                processed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_del_webhook_hash (event_hash),
                INDEX idx_del_webhook_order (delivery_order_id),
                INDEX idx_del_webhook_cdek (cdek_order_uuid),
                INDEX idx_del_webhook_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    /**
     * Попытка зарегистрировать событие. false = дубликат (уже есть).
     *
     * @param array{
     *   provider?: string,
     *   event_type?: string|null,
     *   event_uuid?: string|null,
     *   cdek_order_uuid?: string|null,
     *   status_code?: string|null,
     *   event_hash: string,
     *   payload_hash?: string|null,
     *   delivery_order_id?: int|null
     * } $data
     * @return array{inserted: bool, id?: int}
     */
    public function tryInsert(array $data): array
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO delivery_webhook_events
                 (provider, event_type, event_uuid, cdek_order_uuid, status_code, event_hash,
                  delivery_order_id, payload_hash, process_result)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['provider'] ?? 'cdek',
                $data['event_type'] ?? null,
                $data['event_uuid'] ?? null,
                $data['cdek_order_uuid'] ?? null,
                $data['status_code'] ?? null,
                $data['event_hash'],
                $data['delivery_order_id'] ?? null,
                $data['payload_hash'] ?? null,
                'received',
            ]);
            return ['inserted' => true, 'id' => (int) $this->db->lastInsertId()];
        } catch (\PDOException $e) {
            // Duplicate unique event_hash
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['inserted' => false];
            }
            throw $e;
        }
    }

    public function markProcessed(int $id, string $result, ?int $deliveryOrderId = null, ?string $error = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE delivery_webhook_events
             SET process_result = ?,
                 delivery_order_id = COALESCE(?, delivery_order_id),
                 error_message = ?,
                 processed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $result,
            $deliveryOrderId,
            $error !== null ? mb_substr($error, 0, 255) : null,
            $id,
        ]);
    }

    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_webhook_events WHERE event_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
