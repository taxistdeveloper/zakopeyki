<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Vip\VipPolicy;
use PDOException;

class VipLift extends Model
{
    protected string $table = 'vip_lifts';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    public static function schemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS vip_lifts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vip_order_id INT UNSIGNED NOT NULL,
            slot TINYINT UNSIGNED NOT NULL,
            scheduled_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            executed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vip_lift_slot (vip_order_id, slot),
            INDEX idx_vip_lift_due (status, scheduled_at),
            INDEX idx_vip_lift_order (vip_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(self::schemaSql());
        self::$ensured = true;
    }

    /**
     * Недостающие слоты. Уже записанный слот не сдвигается и не дублируется.
     *
     * @param list<array{slot: int, at: int}> $slots
     */
    public function ensureSlots(int $vipOrderId, array $slots): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO vip_lifts (vip_order_id, slot, scheduled_at, status)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($slots as $slot) {
            $number = (int) ($slot['slot'] ?? 0);
            $at = (int) ($slot['at'] ?? 0);
            if ($number < 1 || $at <= 0) {
                continue;
            }
            try {
                $stmt->execute([
                    $vipOrderId,
                    $number,
                    VipPolicy::sqlDateTime($at),
                    VipPolicy::LIFT_PENDING,
                ]);
            } catch (PDOException $e) {
                if (!VipOrder::isDuplicate($e)) {
                    throw $e;
                }
            }
        }
    }

    public function pendingCount(int $vipOrderId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM vip_lifts WHERE vip_order_id = ? AND status = ?"
        );
        $stmt->execute([$vipOrderId, VipPolicy::LIFT_PENDING]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $orderIds
     * @return array<int, int>
     */
    public function pendingCounts(array $orderIds): array
    {
        $orderIds = array_values(array_filter(array_map('intval', $orderIds)));
        if ($orderIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT vip_order_id, COUNT(*) AS n
             FROM vip_lifts
             WHERE status = ? AND vip_order_id IN ({$marks})
             GROUP BY vip_order_id"
        );
        $stmt->execute(array_merge([VipPolicy::LIFT_PENDING], $orderIds));
        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $map[(int) $row['vip_order_id']] = (int) $row['n'];
        }

        return $map;
    }

    public function lockById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vip_lifts WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<int> */
    public function dueIds(string $now, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            "SELECT l.id
             FROM vip_lifts l
             JOIN vip_orders o ON o.id = l.vip_order_id
             WHERE l.status = ?
               AND l.scheduled_at <= ?
               AND o.status = ?
               AND o.ends_at > ?
             ORDER BY l.scheduled_at ASC, l.id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([
            VipPolicy::LIFT_PENDING,
            $now,
            VipPolicy::STATUS_APPLIED,
            $now,
        ]);
        $ids = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function markStatus(int $id, string $status, ?string $executedAt): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE vip_lifts
             SET status = ?, executed_at = COALESCE(executed_at, ?)
             WHERE id = ? AND status = ?"
        );
        $stmt->execute([
            $status,
            $executedAt,
            $id,
            VipPolicy::LIFT_PENDING,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function skipOutsidePackage(string $now): int
    {
        $stmt = $this->db->prepare(
            "UPDATE vip_lifts l
             JOIN vip_orders o ON o.id = l.vip_order_id
             SET l.status = ?
             WHERE l.status = ?
               AND (
                    o.status = ?
                    OR (o.ends_at IS NOT NULL AND o.ends_at <= ?)
                    OR l.scheduled_at >= o.ends_at
               )"
        );
        $stmt->execute([
            VipPolicy::LIFT_SKIPPED,
            VipPolicy::LIFT_PENDING,
            VipPolicy::STATUS_EXPIRED,
            $now,
        ]);

        return $stmt->rowCount();
    }
}
