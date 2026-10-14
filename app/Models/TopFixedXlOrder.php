<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use PDOException;

class TopFixedXlOrder extends Model
{
    protected string $table = 'top_fixed_xl_orders';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    public static function schemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS top_fixed_xl_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(32) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            amount INT UNSIGNED NOT NULL,
            category VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
            open_key VARCHAR(32) DEFAULT NULL,
            idempotency_key VARCHAR(64) DEFAULT NULL,
            pg_order_id VARCHAR(64) NOT NULL,
            pg_payment_id VARCHAR(64) DEFAULT NULL,
            redirect_url TEXT DEFAULT NULL,
            failure_reason VARCHAR(255) DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            applied_at DATETIME DEFAULT NULL,
            starts_at DATETIME DEFAULT NULL,
            ends_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_top_fixed_xl_public (public_id),
            UNIQUE KEY uq_top_fixed_xl_pg (pg_order_id),
            UNIQUE KEY uq_top_fixed_xl_open (open_key),
            UNIQUE KEY uq_top_fixed_xl_idem (idempotency_key),
            INDEX idx_top_fixed_xl_product (product_id),
            INDEX idx_top_fixed_xl_user (user_id),
            INDEX idx_top_fixed_xl_status (status),
            INDEX idx_top_fixed_xl_window (status, ends_at),
            INDEX idx_top_fixed_xl_pin (product_id, status, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(self::schemaSql());
        $status = $this->db->query("SHOW TABLE STATUS LIKE 'top_fixed_xl_orders'")->fetch();
        if (($status['Collation'] ?? '') !== 'utf8mb4_unicode_ci') {
            $this->db->exec('ALTER TABLE top_fixed_xl_orders CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }
        $category = $this->db->query("SHOW COLUMNS FROM top_fixed_xl_orders LIKE 'category'")->fetch();
        if (!$category) {
            $this->db->exec('ALTER TABLE top_fixed_xl_orders ADD COLUMN category VARCHAR(255) DEFAULT NULL AFTER amount');
        }
        self::$ensured = true;
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM top_fixed_xl_orders WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM top_fixed_xl_orders WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findOpenByProduct(int $productId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT * FROM top_fixed_xl_orders
                WHERE product_id = ? AND status IN ('pending_payment','paid','apply_failed')
                ORDER BY id DESC LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findActiveByProduct(int $productId, bool $forUpdate, string $now): ?array
    {
        $sql = "SELECT * FROM top_fixed_xl_orders
                WHERE product_id = ? AND status = 'applied' AND starts_at <= ? AND ends_at > ?
                ORDER BY ends_at DESC LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId, $now, $now]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function lockById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM top_fixed_xl_orders WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{
     *   public_id: string,
     *   user_id: int,
     *   product_id: int,
     *   amount: int,
     *   open_key: string,
     *   idempotency_key: ?string,
     *   pg_order_id: string
     * } $data
     */
    public function insertPending(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO top_fixed_xl_orders (
                public_id, user_id, product_id, amount, status, open_key, idempotency_key, pg_order_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['public_id'],
            (int) $data['user_id'],
            (int) $data['product_id'],
            (int) $data['amount'],
            TopFixedXlPolicy::STATUS_PENDING,
            $data['open_key'],
            $data['idempotency_key'],
            $data['pg_order_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function saveRedirect(int $id, string $url, ?string $pgPaymentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE top_fixed_xl_orders
             SET redirect_url = ?, pg_payment_id = COALESCE(?, pg_payment_id)
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            $url,
            $pgPaymentId !== null && $pgPaymentId !== '' ? $pgPaymentId : null,
            $id,
            TopFixedXlPolicy::STATUS_PENDING,
        ]);
    }

    public function saveState(
        int $id,
        string $status,
        ?string $openKey,
        ?string $pgPaymentId,
        ?string $failureReason,
        ?string $paidAt,
        ?string $appliedAt,
        ?string $startsAt,
        ?string $endsAt,
        ?string $category
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE top_fixed_xl_orders
             SET status = ?,
                 open_key = ?,
                 pg_payment_id = COALESCE(?, pg_payment_id),
                 failure_reason = ?,
                 paid_at = COALESCE(paid_at, ?),
                 applied_at = COALESCE(applied_at, ?),
                 starts_at = COALESCE(starts_at, ?),
                 ends_at = COALESCE(ends_at, ?),
                 category = COALESCE(category, ?)
             WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $openKey,
            $pgPaymentId !== null && $pgPaymentId !== '' ? $pgPaymentId : null,
            $failureReason,
            $paidAt,
            $appliedAt,
            $startsAt,
            $endsAt,
            $category !== null && $category !== '' ? $category : null,
            $id,
        ]);
    }

    public function markExpired(string $now): int
    {
        $stmt = $this->db->prepare(
            "UPDATE top_fixed_xl_orders
             SET status = ?
             WHERE status = ? AND ends_at IS NOT NULL AND ends_at <= ?"
        );
        $stmt->execute([TopFixedXlPolicy::STATUS_EXPIRED, TopFixedXlPolicy::STATUS_APPLIED, $now]);

        return $stmt->rowCount();
    }

    /**
     * Действующие окна. Просроченные строки сюда не попадают, даже если статус ещё applied.
     *
     * @return array<int, array{ends_at: string, category: string}>
     */
    public function activePins(string $now): array
    {
        $stmt = $this->db->prepare(
            "SELECT product_id, ends_at, category FROM top_fixed_xl_orders
             WHERE status = 'applied' AND starts_at <= ? AND ends_at > ?"
        );
        $stmt->execute([$now, $now]);
        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $ends = (string) ($row['ends_at'] ?? '');
            $category = trim((string) ($row['category'] ?? ''));
            if ($productId <= 0 || $ends === '' || $category === '') {
                continue;
            }
            if (!isset($map[$productId]) || $ends > $map[$productId]['ends_at']) {
                $map[$productId] = ['ends_at' => $ends, 'category' => $category];
            }
        }

        return $map;
    }

    /** @return list<array<string, mixed>> */
    public function findUnapplied(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT * FROM top_fixed_xl_orders
             WHERE status IN ('paid','apply_failed')
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Последний заказ пользователя по каждому объявлению. Более новый id побеждает.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM top_fixed_xl_orders WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll() ?: [];
        $byProduct = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0 && !isset($byProduct[$productId])) {
                $byProduct[$productId] = $row;
            }
        }

        return $byProduct;
    }

    public static function isDuplicate(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $state === '23000';
    }
}
