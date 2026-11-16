<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\TryOn\TryOnJobStatus;

/**
 * Задания виртуальной примерки (таблица одновременно служит очередью воркера).
 * Схема: database/migrate_tryon.sql, docs/tryon/03-database.md.
 */
class TryOnJob extends Model
{
    protected string $table = 'tryon_jobs';
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

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrate_tryon.sql');
        // ALTER products выполняется отдельно и идемпотентно.
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
            $stmt = trim(preg_replace('/^--.*$/m', '', $stmt) ?? '');
            if ($stmt === '' || str_starts_with($stmt, 'ALTER TABLE products')) {
                continue;
            }
            $this->db->exec($stmt);
        }

        $col = $this->db->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'tryon_type'"
        )->fetchColumn();
        if (!$col) {
            $this->db->exec('ALTER TABLE products ADD COLUMN tryon_type VARCHAR(16) DEFAULT NULL AFTER category');
        }

        self::$ensured = true;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO tryon_jobs (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->db->prepare($sql)->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tryon_jobs WHERE public_id = ? LIMIT 1');
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByIdempotency(int $userId, string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tryon_jobs WHERE user_id = ? AND idempotency_key = ? LIMIT 1');
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Успешная примерка того же фото и того же товара, ещё не удалённая. */
    public function findReusable(int $userId, int $productId, int $garmentIndex, string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT j.* FROM tryon_jobs j
             JOIN tryon_assets a ON a.job_id = j.id AND a.kind = 'person'
             WHERE j.user_id = ? AND j.product_id = ? AND j.garment_image_index = ?
               AND j.status IN ('queued','processing','succeeded') AND j.deleted_at IS NULL
               AND a.sha256 = ? AND a.deleted_at IS NULL
             ORDER BY j.id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $productId, $garmentIndex, $sha256]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function countActiveForUser(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tryon_jobs WHERE user_id = ? AND status IN ('queued','processing')");
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Списывающие лимит задания: всё, кроме завершившихся ошибкой. */
    public function countChargeableForUserSince(int $userId, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM tryon_jobs WHERE user_id = ? AND created_at >= ? AND error_code IS NULL'
        );
        $stmt->execute([$userId, $since]);

        return (int) $stmt->fetchColumn();
    }

    /** Расход (факт, иначе оценка; неудачные — 0) с даты, в микро-USD. */
    public function sumSpendSince(string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(COALESCE(cost_actual_micros,
                    CASE WHEN error_code IS NULL THEN cost_estimate_micros ELSE 0 END)), 0)
             FROM tryon_jobs WHERE created_at >= ? AND is_test = 0'
        );
        $stmt->execute([$since]);

        return (int) $stmt->fetchColumn();
    }

    public function countByStatus(string $status): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tryon_jobs WHERE status = ?');
        $stmt->execute([$status]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Атомарно захватить до $limit заданий в статусе $status, готовых к обработке.
     * @return list<array<string, mixed>>
     */
    public function claim(string $status, int $limit, string $workerId, int $lockTtlSec, string $now): array
    {
        if ($limit <= 0) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT id FROM tryon_jobs
             WHERE status = ? AND (next_poll_at IS NULL OR next_poll_at <= ?)
               AND (locked_until IS NULL OR locked_until < ?)
             ORDER BY id ASC LIMIT ' . (int) $limit
        );
        $stmt->execute([$status, $now, $now]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $lockUntil = date('Y-m-d H:i:s', strtotime($now) + $lockTtlSec);
        $upd = $this->db->prepare(
            'UPDATE tryon_jobs SET locked_until = ?, locked_by = ?
             WHERE id = ? AND status = ? AND (locked_until IS NULL OR locked_until < ?)'
        );
        $claimed = [];
        foreach ($ids as $id) {
            $upd->execute([$lockUntil, $workerId, $id, $status, $now]);
            if ($upd->rowCount() === 1) {
                $row = $this->find($id);
                if ($row) {
                    $claimed[] = $row;
                }
            }
        }

        return $claimed;
    }

    /**
     * Переход статуса с проверкой исходного состояния (оптимистичная блокировка).
     * @param list<string> $from
     * @param array<string, mixed> $fields
     */
    public function transition(int $id, array $from, string $to, array $fields = []): bool
    {
        if (!TryOnJobStatus::canTransitionAny($from, $to)) {
            throw new \LogicException('tryon: invalid transition to ' . $to);
        }
        $fields['status'] = $to;
        $set = implode(', ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($fields)));
        $in = implode(',', array_fill(0, count($from), '?'));
        $stmt = $this->db->prepare("UPDATE tryon_jobs SET {$set} WHERE id = ? AND status IN ({$in})");
        $stmt->execute([...array_values($fields), $id, ...$from]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($fields)));
        $this->db->prepare("UPDATE tryon_jobs SET {$set} WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId, ?int $productId, int $limit): array
    {
        $sql = 'SELECT * FROM tryon_jobs WHERE user_id = ? AND deleted_at IS NULL';
        $params = [$userId];
        if ($productId !== null) {
            $sql .= ' AND product_id = ?';
            $params[] = $productId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(50, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listStale(string $status, string $olderThan, string $column, int $limit = 50): array
    {
        $column = in_array($column, ['created_at', 'submitted_at'], true) ? $column : 'created_at';
        $stmt = $this->db->prepare(
            "SELECT * FROM tryon_jobs WHERE status = ? AND {$column} < ? ORDER BY id ASC LIMIT " . (int) $limit
        );
        $stmt->execute([$status, $olderThan]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listExpired(string $now, int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM tryon_jobs
             WHERE expires_at IS NOT NULL AND expires_at < ? AND status IN ('succeeded','failed')
             ORDER BY id ASC LIMIT " . (int) $limit
        );
        $stmt->execute([$now]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listAllForUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tryon_jobs WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }
}
