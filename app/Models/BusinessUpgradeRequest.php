<?php

namespace App\Models;

use App\Core\Model;

class BusinessUpgradeRequest extends Model
{
    protected string $table = 'business_upgrade_requests';
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
            "CREATE TABLE IF NOT EXISTS business_upgrade_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                entity_type ENUM('ip','too') NOT NULL,
                business_name VARCHAR(255) NOT NULL,
                bin VARCHAR(12) NOT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                address VARCHAR(500) DEFAULT NULL,
                doc_files TEXT DEFAULT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                admin_note VARCHAR(500) DEFAULT NULL,
                reviewed_by INT UNSIGNED DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_bur_user (user_id),
                INDEX idx_bur_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function latestPending(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM business_upgrade_requests WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM business_upgrade_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listByStatus(string $status = 'pending', int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($status === 'all') {
            $stmt = $this->db->query(
                "SELECT r.*, u.name AS user_name, u.email AS user_email,
                        u.account_type AS user_account_type, u.business_status AS user_business_status
                 FROM business_upgrade_requests r
                 LEFT JOIN users u ON u.id = r.user_id
                 ORDER BY FIELD(r.status,'pending','approved','rejected'), r.id DESC
                 LIMIT {$limit}"
            );
            return $stmt->fetchAll() ?: [];
        }

        $stmt = $this->db->prepare(
            "SELECT r.*, u.name AS user_name, u.email AS user_email,
                    u.account_type AS user_account_type, u.business_status AS user_business_status
             FROM business_upgrade_requests r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.status = ?
             ORDER BY r.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$status]);
        return $stmt->fetchAll() ?: [];
    }

    public function countPending(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM business_upgrade_requests WHERE status = 'pending'"
        )->fetchColumn();
    }

    /** @param array{user_id:int,entity_type:string,business_name:string,bin:string,phone?:string,address?:string,doc_files:list<string>} $data */
    public function createRequest(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO business_upgrade_requests
                (user_id, entity_type, business_name, bin, phone, address, doc_files, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\')'
        );
        $stmt->execute([
            (int) $data['user_id'],
            $data['entity_type'],
            $data['business_name'],
            $data['bin'],
            $data['phone'] ?? null,
            $data['address'] ?? null,
            json_encode(array_values($data['doc_files'] ?? []), JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function markReviewed(int $id, string $status, int $adminId, ?string $note = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE business_upgrade_requests
             SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $note, $adminId, $id]);
    }

    /** @return list<string> */
    public static function decodeDocs(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $decoded)));
    }
}
