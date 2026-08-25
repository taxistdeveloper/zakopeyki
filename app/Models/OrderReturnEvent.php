<?php

namespace App\Models;

use App\Core\Model;

class OrderReturnEvent extends Model
{
    protected string $table = 'order_return_events';
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
            "CREATE TABLE IF NOT EXISTS order_return_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id INT UNSIGNED NOT NULL,
                actor_id INT UNSIGNED DEFAULT NULL,
                actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
                event_type VARCHAR(40) NOT NULL,
                payload TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order_return_events_order (order_id),
                INDEX idx_order_return_events_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function add(
        int $orderId,
        string $eventType,
        string $actorRole = 'system',
        ?int $actorId = null,
        array $payload = []
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO order_return_events (order_id, actor_id, actor_role, event_type, payload)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $actorId,
            $actorRole,
            $eventType,
            $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM order_return_events WHERE order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$orderId]);
        return $stmt->fetchAll() ?: [];
    }
}
