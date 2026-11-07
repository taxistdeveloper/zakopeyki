<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Contest\ContestSchema;

/**
 * Результат подведения итогов (однократный), победители и вручение призов.
 */
class ContestResult extends Model
{
    protected string $table = 'contest_result_executions';

    public function __construct()
    {
        parent::__construct();
        ContestSchema::ensure($this->db);
    }

    public function forContest(int $contestId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contest_result_executions WHERE contest_id = ? LIMIT 1');
        $stmt->execute([$contestId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['details'] = json_decode((string) ($row['details_json'] ?? ''), true) ?: [];
        return $row;
    }

    /** @param array<string, mixed> $data */
    public function insertExecution(array $data): int
    {
        $this->db->prepare(
            'INSERT INTO contest_result_executions
                (contest_id, rule_version_id, mechanic, algorithm, eligible_count, eligible_hash, commit_hash, seed,
                 executed_by, executed_at, details_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        )->execute([
            $data['contest_id'], $data['rule_version_id'], $data['mechanic'], $data['algorithm'], $data['eligible_count'],
            $data['eligible_hash'], $data['commit_hash'] ?? null, $data['seed'] ?? null, $data['executed_by'],
            json_encode($data['details'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $w */
    public function insertWinner(array $w): int
    {
        $this->db->prepare(
            'INSERT INTO contest_winners
                (execution_id, contest_id, entry_id, user_id, place, prize_title, jury_note, fulfilment_due_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $w['execution_id'], $w['contest_id'], $w['entry_id'], $w['user_id'], $w['place'], $w['prize_title'],
            $w['jury_note'] ?? null, $w['fulfilment_due_at'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array> победители (публичное имя — только name, без контактов) */
    public function winners(int $contestId): array
    {
        $stmt = $this->db->prepare(
            'SELECT w.*, u.name AS user_name, u.login AS user_login
             FROM contest_winners w LEFT JOIN users u ON u.id = w.user_id
             WHERE w.contest_id = ? ORDER BY w.place ASC'
        );
        $stmt->execute([$contestId]);
        return $stmt->fetchAll();
    }

    public function winner(int $winnerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contest_winners WHERE id = ? LIMIT 1');
        $stmt->execute([$winnerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function setFulfilment(int $winnerId, string $from, string $to, array $extra = []): bool
    {
        $sets = ['fulfilment_status = ?'];
        $params = [$to];
        foreach (['fulfilment_note', 'handed_over_at', 'confirmed_at'] as $col) {
            if (array_key_exists($col, $extra)) {
                $sets[] = $col . ' = ?';
                $params[] = $extra[$col];
            }
        }
        $params[] = $winnerId;
        $params[] = $from;
        $stmt = $this->db->prepare('UPDATE contest_winners SET ' . implode(', ', $sets) . ' WHERE id = ? AND fulfilment_status = ?');
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    /** Все победители акции в финальном состоянии вручения? */
    public function allFulfilled(int $contestId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM contest_winners WHERE contest_id = ? AND fulfilment_status NOT IN ('confirmed','expired')"
        );
        $stmt->execute([$contestId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Победители, у которых истёк срок вручения без подтверждения (для cron). */
    public function overdueFulfilments(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db->query(
            "SELECT * FROM contest_winners
             WHERE fulfilment_status IN ('pending','handed_over') AND fulfilment_due_at < NOW()
             ORDER BY id ASC LIMIT {$limit}"
        )->fetchAll();
    }

    /** Победитель подтвердил получение по истечении срока — handed_over → confirmed авто через 7 дней. */
    public function staleHandedOver(int $days = 7, int $limit = 200): array
    {
        $days = max(1, $days);
        $limit = max(1, min(1000, $limit));
        return $this->db->query(
            "SELECT * FROM contest_winners
             WHERE fulfilment_status = 'handed_over' AND handed_over_at < (NOW() - INTERVAL {$days} DAY)
             ORDER BY id ASC LIMIT {$limit}"
        )->fetchAll();
    }

    /** @return list<array> выигрыши пользователя */
    public function byUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT w.*, c.title, c.slug FROM contest_winners w JOIN contests c ON c.id = w.contest_id
             WHERE w.user_id = ? ORDER BY w.id DESC LIMIT 100'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
