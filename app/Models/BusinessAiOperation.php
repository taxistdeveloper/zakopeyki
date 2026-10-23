<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Business\BusinessPackagePolicy;

/**
 * Журнал AI-операций бизнес-пакета (резерв / успех / освобождение квоты).
 */
class BusinessAiOperation extends Model
{
    protected string $table = 'business_ai_operations';
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
            "CREATE TABLE IF NOT EXISTS business_ai_operations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                feature VARCHAR(40) NOT NULL,
                operation_key VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'reserved',
                period_key VARCHAR(16) NOT NULL,
                error_code VARCHAR(64) DEFAULT NULL,
                release_reason VARCHAR(64) DEFAULT NULL,
                result_json MEDIUMTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_bao_operation_key (operation_key),
                INDEX idx_bao_user_feature (user_id, feature),
                INDEX idx_bao_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function findByOperationKey(string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM business_ai_operations WHERE operation_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return int|false id or false on duplicate key
     */
    public function createReserved(int $userId, string $feature, string $operationKey, string $periodKey): int|false
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO business_ai_operations
                    (user_id, feature, operation_key, status, period_key)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $feature,
                $operationKey,
                BusinessPackagePolicy::OP_RESERVED,
                $periodKey,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'uk_bao_operation_key') || (int) $e->getCode() === 23000) {
                return false;
            }
            throw $e;
        }
    }

    public function markSucceeded(int $id, ?array $result = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE business_ai_operations
             SET status = ?, result_json = ?, error_code = NULL, release_reason = NULL
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            BusinessPackagePolicy::OP_SUCCEEDED,
            $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
            $id,
            BusinessPackagePolicy::OP_RESERVED,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markReleased(int $id, string $reason, ?string $errorCode = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE business_ai_operations
             SET status = ?, release_reason = ?, error_code = ?
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            BusinessPackagePolicy::OP_RELEASED,
            $reason,
            $errorCode,
            $id,
            BusinessPackagePolicy::OP_RESERVED,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markFailedNoReserve(int $userId, string $feature, string $operationKey, string $periodKey, string $errorCode): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO business_ai_operations
                (user_id, feature, operation_key, status, period_key, error_code, release_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                error_code = VALUES(error_code),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $userId,
            $feature,
            $operationKey,
            BusinessPackagePolicy::OP_FAILED,
            $periodKey,
            $errorCode,
            null,
        ]);

        $row = $this->findByOperationKey($operationKey);

        return $row ? (int) $row['id'] : 0;
    }
}
