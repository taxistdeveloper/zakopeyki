<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Contest\ContestSchema;

/**
 * Участие в акции и статусы условий участия.
 */
class ContestEntry extends Model
{
    protected string $table = 'contest_entries';

    public function __construct()
    {
        parent::__construct();
        ContestSchema::ensure($this->db);
    }

    public function findForUser(int $contestId, int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contest_entries WHERE contest_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$contestId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findInContest(int $contestId, int $entryId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contest_entries WHERE id = ? AND contest_id = ? LIMIT 1');
        $stmt->execute([$entryId, $contestId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Вставка участия. Дубликат (UNIQUE contest_id+user_id) → null.
     * @param array<string, mixed> $data
     */
    public function insertOnce(array $data): ?int
    {
        try {
            $this->db->prepare(
                'INSERT INTO contest_entries
                    (contest_id, user_id, rule_version_id, status, submission_text, ref_user_id, ip_hash, visitor_key, fraud_flags)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $data['contest_id'], (int) $data['user_id'], (int) $data['rule_version_id'],
                (string) ($data['status'] ?? 'pending'), $data['submission_text'] ?? null,
                $data['ref_user_id'] ?? null, $data['ip_hash'] ?? null, $data['visitor_key'] ?? null,
                $data['fraud_flags'] ?? null,
            ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
        return (int) $this->db->lastInsertId();
    }

    public function setStatus(int $entryId, string $from, string $to, ?string $reason, ?int $byUserId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE contest_entries SET status = ?, status_reason = ?, status_changed_by = ? WHERE id = ? AND status = ?'
        );
        $stmt->execute([$to, $reason !== null ? mb_substr($reason, 0, 500) : null, $byUserId, $entryId, $from]);
        return $stmt->rowCount() === 1;
    }

    public function countSameIp(int $contestId, string $ipHash): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM contest_entries WHERE contest_id = ? AND ip_hash = ?');
        $stmt->execute([$contestId, $ipHash]);
        return (int) $stmt->fetchColumn();
    }

    public function addFraudFlag(int $entryId, string $flag): void
    {
        $this->db->prepare(
            "UPDATE contest_entries
             SET fraud_flags = TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(fraud_flags, ''), ?))
             WHERE id = ? AND (fraud_flags IS NULL OR FIND_IN_SET(?, fraud_flags) = 0)"
        )->execute([$flag, $entryId, $flag]);
    }

    /** Пометить все участия с данным IP-хэшем в акции (после превышения порога). */
    public function flagSameIp(int $contestId, string $ipHash, string $flag): void
    {
        $stmt = $this->db->prepare('SELECT id FROM contest_entries WHERE contest_id = ? AND ip_hash = ?');
        $stmt->execute([$contestId, $ipHash]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->addFraudFlag((int) $id, $flag);
        }
    }

    /**
     * Список участий для организатора/модератора (без email и IP — минимизация ПДн).
     * @return list<array>
     */
    public function listForContest(int $contestId, string $status = '', int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $sql = 'SELECT e.id, e.user_id, e.status, e.submission_text, e.fraud_flags, e.status_reason, e.created_at,
                       e.ref_user_id, u.name AS user_name, u.login AS user_login, u.created_at AS user_created_at
                FROM contest_entries e LEFT JOIN users u ON u.id = e.user_id
                WHERE e.contest_id = ?';
        $params = [$contestId];
        if (in_array($status, ['pending', 'confirmed', 'disqualified', 'withdrawn'], true)) {
            $sql .= ' AND e.status = ?';
            $params[] = $status;
        }
        $sql .= " ORDER BY e.id ASC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array> активные (pending/confirmed) участия — для проверки условий при закрытии */
    public function activeEntries(int $contestId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM contest_entries WHERE contest_id = ? AND status IN ('pending','confirmed') ORDER BY id ASC"
        );
        $stmt->execute([$contestId]);
        return $stmt->fetchAll();
    }

    /** @return list<int> ID допущенных участий в детерминированном порядке */
    public function confirmedIds(int $contestId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM contest_entries WHERE contest_id = ? AND status = 'confirmed' AND user_id IS NOT NULL ORDER BY id ASC"
        );
        $stmt->execute([$contestId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> user_id участников (для уведомлений) */
    public function participantUserIds(int $contestId, array $statuses = ['pending', 'confirmed']): array
    {
        $statuses = array_values(array_intersect($statuses, ['pending', 'confirmed', 'disqualified', 'withdrawn']));
        if ($statuses === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare(
            "SELECT user_id FROM contest_entries WHERE contest_id = ? AND user_id IS NOT NULL AND status IN ({$in})"
        );
        $stmt->execute([$contestId, ...$statuses]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string, int> */
    public function statusCounts(int $contestId): array
    {
        $stmt = $this->db->prepare('SELECT status, COUNT(*) AS c FROM contest_entries WHERE contest_id = ? GROUP BY status');
        $stmt->execute([$contestId]);
        $out = ['pending' => 0, 'confirmed' => 0, 'disqualified' => 0, 'withdrawn' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /** @return list<array> участия пользователя (для «Мои участия») */
    public function byUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, c.title, c.slug, c.status AS contest_status, c.ends_at, c.mechanic
             FROM contest_entries e JOIN contests c ON c.id = e.contest_id
             WHERE e.user_id = ? ORDER BY e.id DESC LIMIT 200'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    // ── Условия ───────────────────────────────────────────────────────────────

    public function saveCondition(int $entryId, string $key, string $status, ?string $detail = null): void
    {
        $this->db->prepare(
            'INSERT INTO contest_entry_conditions (entry_id, condition_key, status, detail, checked_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), detail = VALUES(detail), checked_at = VALUES(checked_at)'
        )->execute([$entryId, $key, $status, $detail !== null ? mb_substr($detail, 0, 255) : null]);
    }

    /** @return array<string, array{status:string, detail:?string, checked_at:string}> */
    public function conditions(int $entryId): array
    {
        $stmt = $this->db->prepare('SELECT condition_key, status, detail, checked_at FROM contest_entry_conditions WHERE entry_id = ?');
        $stmt->execute([$entryId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['condition_key']] = [
                'status' => (string) $r['status'],
                'detail' => $r['detail'] !== null ? (string) $r['detail'] : null,
                'checked_at' => (string) $r['checked_at'],
            ];
        }
        return $out;
    }
}
