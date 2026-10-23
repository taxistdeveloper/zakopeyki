<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Business\BusinessPackagePolicy;

class BusinessPackagePayment extends Model
{
    protected string $table = 'business_package_payments';
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
            "CREATE TABLE IF NOT EXISTS business_package_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                package_id INT UNSIGNED NOT NULL,
                subscription_id INT UNSIGNED DEFAULT NULL,
                amount_kzt INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                channel VARCHAR(32) NOT NULL DEFAULT 'wallet',
                idempotency_key VARCHAR(64) NOT NULL,
                meta VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_bpp_idempotency (idempotency_key),
                INDEX idx_bpp_user (user_id),
                INDEX idx_bpp_status (status),
                INDEX idx_bpp_subscription (subscription_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function findByIdempotency(string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM business_package_payments WHERE idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM business_package_payments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{user_id:int,package_id:int,amount_kzt:int,idempotency_key:string,channel?:string,meta?:string|null} $data
     */
    public function createPending(array $data): int|false
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO business_package_payments
                    (user_id, package_id, amount_kzt, status, channel, idempotency_key, meta)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $data['user_id'],
                (int) $data['package_id'],
                (int) $data['amount_kzt'],
                BusinessPackagePolicy::PAYMENT_PENDING,
                (string) ($data['channel'] ?? 'wallet'),
                (string) $data['idempotency_key'],
                $data['meta'] ?? null,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Duplicate idempotency_key
            if (str_contains($e->getMessage(), 'uk_bpp_idempotency') || (int) $e->getCode() === 23000) {
                return false;
            }
            throw $e;
        }
    }

    public function markPaid(int $id, int $subscriptionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE business_package_payments
             SET status = ?, subscription_id = ?
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            BusinessPackagePolicy::PAYMENT_PAID,
            $subscriptionId,
            $id,
            BusinessPackagePolicy::PAYMENT_PENDING,
        ]);
    }

    public function markFailed(int $id, string $meta = ''): void
    {
        $stmt = $this->db->prepare(
            'UPDATE business_package_payments
             SET status = ?, meta = COALESCE(?, meta)
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            BusinessPackagePolicy::PAYMENT_FAILED,
            $meta !== '' ? $meta : null,
            $id,
            BusinessPackagePolicy::PAYMENT_PENDING,
        ]);
    }

    /** @return list<array> */
    public function forUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM business_package_payments
             WHERE user_id = ?
             ORDER BY id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
