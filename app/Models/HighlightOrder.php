<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Highlight\HighlightPolicy;
use PDOException;

class HighlightOrder extends Model
{
    protected string $table = 'highlight_orders';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    public static function schemaSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS highlight_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(32) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            vip_order_id INT UNSIGNED DEFAULT NULL,
            amount INT UNSIGNED NOT NULL,
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
            UNIQUE KEY uq_highlight_public (public_id),
            UNIQUE KEY uq_highlight_pg (pg_order_id),
            UNIQUE KEY uq_highlight_open (open_key),
            UNIQUE KEY uq_highlight_idem (idempotency_key),
            UNIQUE KEY uq_highlight_vip (vip_order_id),
            INDEX idx_highlight_product (product_id),
            INDEX idx_highlight_user (user_id),
            INDEX idx_highlight_status (status),
            INDEX idx_highlight_window (status, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(self::schemaSql());
        $vip = $this->db->query("SHOW COLUMNS FROM highlight_orders LIKE 'vip_order_id'")->fetch();
        if (!$vip) {
            $this->db->exec('ALTER TABLE highlight_orders ADD COLUMN vip_order_id INT UNSIGNED DEFAULT NULL AFTER product_id');
            $this->db->exec('ALTER TABLE highlight_orders ADD UNIQUE KEY uq_highlight_vip (vip_order_id)');
        }
        self::$ensured = true;
    }

    public function findByPgOrderId(string $pgOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM highlight_orders WHERE pg_order_id = ? LIMIT 1');
        $stmt->execute([$pgOrderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM highlight_orders WHERE idempotency_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findOpenByProduct(int $productId, bool $forUpdate = false): ?array
    {
        $sql = "SELECT * FROM highlight_orders
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
        $sql = "SELECT * FROM highlight_orders
                WHERE product_id = ? AND vip_order_id IS NULL AND status = 'applied' AND starts_at <= ? AND ends_at > ?
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
        $stmt = $this->db->prepare('SELECT * FROM highlight_orders WHERE id = ? LIMIT 1 FOR UPDATE');
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
            'INSERT INTO highlight_orders (
                public_id, user_id, product_id, amount, status, open_key, idempotency_key, pg_order_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['public_id'],
            (int) $data['user_id'],
            (int) $data['product_id'],
            (int) $data['amount'],
            HighlightPolicy::STATUS_PENDING,
            $data['open_key'],
            $data['idempotency_key'],
            $data['pg_order_id'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function saveRedirect(int $id, string $url, ?string $pgPaymentId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE highlight_orders
             SET redirect_url = ?, pg_payment_id = COALESCE(?, pg_payment_id)
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([
            $url,
            $pgPaymentId !== null && $pgPaymentId !== '' ? $pgPaymentId : null,
            $id,
            HighlightPolicy::STATUS_PENDING,
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
        ?string $endsAt
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE highlight_orders
             SET status = ?,
                 open_key = ?,
                 pg_payment_id = COALESCE(?, pg_payment_id),
                 failure_reason = ?,
                 paid_at = COALESCE(paid_at, ?),
                 applied_at = COALESCE(applied_at, ?),
                 starts_at = COALESCE(starts_at, ?),
                 ends_at = COALESCE(ends_at, ?)
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
            $id,
        ]);
    }

    public function markExpired(string $now): int
    {
        $stmt = $this->db->prepare(
            "UPDATE highlight_orders
             SET status = ?
             WHERE status = ? AND ends_at IS NOT NULL AND ends_at <= ?"
        );
        $stmt->execute([HighlightPolicy::STATUS_EXPIRED, HighlightPolicy::STATUS_APPLIED, $now]);

        return $stmt->rowCount();
    }

    /**
     * Действующие окна. Просроченные строки сюда не попадают, даже если статус ещё applied.
     *
     * @return array<int, string> product_id => ends_at
     */
    public function activeEnds(string $now): array
    {
        $stmt = $this->db->prepare(
            "SELECT product_id, ends_at FROM highlight_orders
             WHERE status = 'applied' AND starts_at <= ? AND ends_at > ?"
        );
        $stmt->execute([$now, $now]);
        $map = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $ends = (string) ($row['ends_at'] ?? '');
            if ($productId <= 0 || $ends === '') {
                continue;
            }
            if (!isset($map[$productId]) || $ends > $map[$productId]) {
                $map[$productId] = $ends;
            }
        }

        return $map;
    }

    /** @return list<array<string, mixed>> */
    public function findUnapplied(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT * FROM highlight_orders
             WHERE vip_order_id IS NULL AND status IN ('paid','apply_failed')
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
            'SELECT * FROM highlight_orders WHERE user_id = ? AND vip_order_id IS NULL ORDER BY id DESC'
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

    public function findByVipOrderId(int $vipOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM highlight_orders WHERE vip_order_id = ? LIMIT 1');
        $stmt->execute([$vipOrderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Эффект VIP. Цена всегда 0. Чужие строки выделения не обновляются.
     */
    public function insertVipGrant(int $vipOrderId, int $userId, int $productId, string $startsAt, string $endsAt): int
    {
        $existing = $this->findByVipOrderId($vipOrderId);
        if ($existing) {
            return (int) $existing['id'];
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO highlight_orders (
                public_id, user_id, product_id, vip_order_id, amount, status,
                open_key, pg_order_id, paid_at, applied_at, starts_at, ends_at
             ) VALUES (?, ?, ?, ?, 0, ?, NULL, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            bin2hex(random_bytes(16)),
            $userId,
            $productId,
            $vipOrderId,
            HighlightPolicy::STATUS_APPLIED,
            'zk-vipcomp-hl-' . $vipOrderId,
            $now,
            $now,
            $startsAt,
            $endsAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public static function isDuplicate(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $state === '23000';
    }
}
