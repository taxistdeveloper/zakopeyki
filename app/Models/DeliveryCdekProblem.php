<?php

namespace App\Models;

use App\Core\Model;

/**
 * Проблема доставки CDEK. Это не возврат товара и не операция refusal/clientReturn.
 */
class DeliveryCdekProblem extends Model
{
    protected string $table = 'delivery_cdek_problems';
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
            "CREATE TABLE IF NOT EXISTS delivery_cdek_problems (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                source VARCHAR(16) NOT NULL,
                problem_code VARCHAR(64) DEFAULT NULL,
                occurred_at DATETIME DEFAULT NULL,
                event_hash CHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cdek_problem_hash (event_hash),
                INDEX idx_cdek_problem_delivery (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$ensured = true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{inserted: bool, id: int}
     */
    public function insertIgnore(array $row): array
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO delivery_cdek_problems
                (delivery_order_id, source, problem_code, occurred_at, event_hash)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $row['delivery_order_id'],
            (string) $row['source'],
            $row['problem_code'] ?? null,
            $row['occurred_at'] ?? null,
            (string) $row['event_hash'],
        ]);
        if ($stmt->rowCount() > 0) {
            return ['inserted' => true, 'id' => (int) $this->db->lastInsertId()];
        }
        $existing = $this->findByHash((string) $row['event_hash']);
        return ['inserted' => false, 'id' => (int) ($existing['id'] ?? 0)];
    }

    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_cdek_problems WHERE event_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function forDelivery(int $deliveryOrderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_cdek_problems WHERE delivery_order_id = ? ORDER BY occurred_at DESC, id DESC'
        );
        $stmt->execute([$deliveryOrderId]);
        return $stmt->fetchAll() ?: [];
    }
}
