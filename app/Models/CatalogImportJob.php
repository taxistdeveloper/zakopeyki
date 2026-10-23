<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Business\BusinessPackagePolicy;

class CatalogImportJob extends Model
{
    protected string $table = 'catalog_import_jobs';
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
            "CREATE TABLE IF NOT EXISTS catalog_import_jobs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                source VARCHAR(16) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'queued',
                filename VARCHAR(255) DEFAULT NULL,
                total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                ok_rows INT UNSIGNED NOT NULL DEFAULT 0,
                error_rows INT UNSIGNED NOT NULL DEFAULT 0,
                report_json MEDIUMTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cij_user (user_id),
                INDEX idx_cij_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS catalog_import_keys (
                user_id INT UNSIGNED NOT NULL,
                external_key VARCHAR(128) NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, external_key),
                INDEX idx_cik_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    /**
     * @param array{user_id:int,source:string,filename?:string|null} $data
     */
    public function createJob(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO catalog_import_jobs (user_id, source, status, filename)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $data['user_id'],
            (string) $data['source'],
            BusinessPackagePolicy::IMPORT_QUEUED,
            $data['filename'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM catalog_import_jobs WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{status:string,total_rows?:int,ok_rows?:int,error_rows?:int,report_json?:string|null} $data
     */
    public function updateJob(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE catalog_import_jobs
             SET status = ?, total_rows = ?, ok_rows = ?, error_rows = ?, report_json = ?
             WHERE id = ?'
        );
        $stmt->execute([
            (string) $data['status'],
            (int) ($data['total_rows'] ?? 0),
            (int) ($data['ok_rows'] ?? 0),
            (int) ($data['error_rows'] ?? 0),
            $data['report_json'] ?? null,
            $id,
        ]);
    }

    public function findProductIdByKey(int $userId, string $externalKey): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT product_id FROM catalog_import_keys WHERE user_id = ? AND external_key = ? LIMIT 1'
        );
        $stmt->execute([$userId, $externalKey]);
        $row = $stmt->fetch();

        return $row ? (int) $row['product_id'] : null;
    }

    public function upsertKey(int $userId, string $externalKey, int $productId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO catalog_import_keys (user_id, external_key, product_id)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)'
        );
        $stmt->execute([$userId, $externalKey, $productId]);
    }

    public function countJobsSince(int $userId, string $sinceMysqlDatetime): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM catalog_import_jobs WHERE user_id = ? AND created_at >= ?'
        );
        $stmt->execute([$userId, $sinceMysqlDatetime]);

        return (int) $stmt->fetchColumn();
    }
}
