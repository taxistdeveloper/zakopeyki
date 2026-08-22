<?php

namespace App\Models;

use App\Core\Model;

class BusinessSubscription extends Model
{
    protected string $table = 'business_subscriptions';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS business_subscriptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                package_id INT UNSIGNED NOT NULL,
                status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                price_paid_kzt INT UNSIGNED NOT NULL DEFAULT 0,
                payment_meta VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_bs_user (user_id),
                INDEX idx_bs_status_ends (status, ends_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureColumn('extra_catalog', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_meta');
        $this->ensureColumn('extra_staff', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_catalog');
        $this->ensureColumn('extra_ai_infographic', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_staff');
        $this->ensureColumn('extra_ai_tryon', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER extra_ai_infographic');

        self::$ensured = true;
    }

    private function ensureColumn(string $column, string $definition): void
    {
        try {
            $this->db->exec("ALTER TABLE business_subscriptions ADD COLUMN `{$column}` {$definition}");
        } catch (\PDOException) {
            // exists
        }
    }

    public function activeForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, p.name AS package_name, p.slug AS package_slug, p.max_photos, p.free_service_listing, p.priority_boost,
                    p.kind AS package_kind, p.limits_json
             FROM business_subscriptions s
             LEFT JOIN business_packages p ON p.id = s.package_id
             WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW()
             ORDER BY s.ends_at DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function expireOverdue(): int
    {
        return (int) $this->db->exec(
            "UPDATE business_subscriptions SET status = 'expired'
             WHERE status = 'active' AND ends_at <= NOW()"
        );
    }

    /** @param array{user_id:int,package_id:int,starts_at:string,ends_at:string,price_paid_kzt:int,payment_meta?:string} $data */
    public function createSubscription(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO business_subscriptions
                (user_id, package_id, status, starts_at, ends_at, price_paid_kzt, payment_meta)
             VALUES (?, ?, 'active', ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int) $data['user_id'],
            (int) $data['package_id'],
            $data['starts_at'],
            $data['ends_at'],
            (int) $data['price_paid_kzt'],
            $data['payment_meta'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function markCancelled(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE business_subscriptions SET status = 'cancelled' WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    public function updateWindow(int $id, string $startsAt, string $endsAt): void
    {
        $stmt = $this->db->prepare(
            "UPDATE business_subscriptions SET starts_at = ?, ends_at = ?, status = 'active' WHERE id = ?"
        );
        $stmt->execute([$startsAt, $endsAt, $id]);
    }

    public function addExtras(int $id, int $catalog = 0, int $staff = 0, int $aiInfographic = 0, int $aiTryon = 0): void
    {
        $stmt = $this->db->prepare(
            'UPDATE business_subscriptions SET
                extra_catalog = extra_catalog + ?,
                extra_staff = extra_staff + ?,
                extra_ai_infographic = extra_ai_infographic + ?,
                extra_ai_tryon = extra_ai_tryon + ?
             WHERE id = ?'
        );
        $stmt->execute([$catalog, $staff, $aiInfographic, $aiTryon, $id]);
    }

    public function hadAnyForUser(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM business_subscriptions WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return (bool) $stmt->fetch();
    }
}
