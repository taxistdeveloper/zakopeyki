<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use App\Services\Contest\ContestSchema;

/**
 * Журнал событий платформы (append-only), уникальные просмотры и реферальные переходы акций.
 */
class ContestTracking extends Model
{
    protected string $table = 'contest_platform_events';

    public const EVENT_FOLLOW = 'follow';
    public const EVENT_UNFOLLOW = 'unfollow';

    public function __construct()
    {
        parent::__construct();
        ContestSchema::ensure($this->db);
    }

    // ── Журнал событий ────────────────────────────────────────────────────────

    public function recordEvent(string $type, int $actorId, string $targetType, int $targetId): void
    {
        $this->db->prepare(
            'INSERT INTO contest_platform_events (event_type, actor_id, target_type, target_id, occurred_at)
             VALUES (?, ?, ?, ?, NOW(3))'
        )->execute([$type, $actorId, $targetType, $targetId]);
    }

    /** Есть ли у организатора акции, для которых нужно журналировать действия. */
    public function organizerHasLiveContests(int $organizerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM contests WHERE organizer_id = ? AND status IN ('scheduled','active','suspended') LIMIT 1"
        );
        $stmt->execute([$organizerId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Было ли событие данного типа в интервале [from, to]. */
    public function hasEventBetween(string $type, int $actorId, string $targetType, int $targetId, string $from, string $to): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM contest_platform_events
             WHERE target_type = ? AND target_id = ? AND actor_id = ? AND event_type = ?
               AND occurred_at >= ? AND occurred_at <= ?
             LIMIT 1'
        );
        $stmt->execute([$targetType, $targetId, $actorId, $type, $from, $to]);
        return (bool) $stmt->fetchColumn();
    }

    /** Количество событий по цели в интервале (аналитика: новые подписчики за время акции). */
    public function countEvents(string $type, string $targetType, int $targetId, string $from, string $to): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT actor_id) FROM contest_platform_events
             WHERE target_type = ? AND target_id = ? AND event_type = ? AND occurred_at >= ? AND occurred_at <= ?'
        );
        $stmt->execute([$targetType, $targetId, $type, $from, $to]);
        return (int) $stmt->fetchColumn();
    }

    // ── Просмотры ─────────────────────────────────────────────────────────────

    /** @return bool true, если это новый уникальный посетитель акции */
    public function recordView(int $contestId, string $visitorKey): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $visitorKey)) {
            return false;
        }
        $stmt = $this->db->prepare('INSERT IGNORE INTO contest_views (contest_id, visitor_key) VALUES (?, ?)');
        $stmt->execute([$contestId, $visitorKey]);
        return $stmt->rowCount() === 1;
    }

    public function uniqueViews(int $contestId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM contest_views WHERE contest_id = ?');
        $stmt->execute([$contestId]);
        return (int) $stmt->fetchColumn();
    }

    // ── Реферальные переходы ──────────────────────────────────────────────────

    /** @return int ID записи перехода (существующей или новой) либо 0 */
    public function recordRefVisit(int $contestId, int $refUserId, string $visitorKey): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $visitorKey) || $refUserId <= 0) {
            return 0;
        }
        $this->db->prepare(
            'INSERT IGNORE INTO contest_ref_visits (contest_id, ref_user_id, visitor_key) VALUES (?, ?, ?)'
        )->execute([$contestId, $refUserId, $visitorKey]);
        $stmt = $this->db->prepare(
            'SELECT id FROM contest_ref_visits WHERE contest_id = ? AND ref_user_id = ? AND visitor_key = ? LIMIT 1'
        );
        $stmt->execute([$contestId, $refUserId, $visitorKey]);
        return (int) $stmt->fetchColumn();
    }

    /** Связать регистрацию с переходом (однократно; один пользователь — одна атрибуция). */
    public function linkRegistration(int $visitId, int $newUserId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE contest_ref_visits SET registered_user_id = ?, registered_at = NOW()
                 WHERE id = ? AND registered_user_id IS NULL AND ref_user_id <> ?'
            );
            $stmt->execute([$newUserId, $visitId, $newUserId]);
            return $stmt->rowCount() === 1;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /** @return array{visits:int, registrations:int} */
    public function refTotals(int $contestId, ?int $refUserId = null): array
    {
        $sql = 'SELECT COUNT(*) AS visits, COUNT(registered_user_id) AS registrations FROM contest_ref_visits WHERE contest_id = ?';
        $params = [$contestId];
        if ($refUserId !== null) {
            $sql .= ' AND ref_user_id = ?';
            $params[] = $refUserId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        return ['visits' => (int) ($r['visits'] ?? 0), 'registrations' => (int) ($r['registrations'] ?? 0)];
    }

    /**
     * Регистрации, пришедшие по ссылкам участника, со статусом реферального начисления
     * из СУЩЕСТВУЮЩЕЙ системы (bonus_transactions type=referral) — только чтение.
     * @return list<array{registered_at:string, rewarded:bool, amount:int}>
     */
    public function referralsWithRewardStatus(int $contestId, int $refUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT v.registered_at, bt.amount
             FROM contest_ref_visits v
             LEFT JOIN bonus_transactions bt
               ON bt.user_id = v.ref_user_id AND bt.type = 'referral' AND bt.ref_type = 'user' AND bt.ref_id = v.registered_user_id
             WHERE v.contest_id = ? AND v.ref_user_id = ? AND v.registered_user_id IS NOT NULL
             ORDER BY v.registered_at DESC LIMIT 100"
        );
        try {
            $stmt->execute([$contestId, $refUserId]);
        } catch (\PDOException) {
            return []; // таблица бонусов ещё не создана
        }
        return array_map(static fn (array $r): array => [
            'registered_at' => (string) $r['registered_at'],
            'rewarded' => $r['amount'] !== null,
            'amount' => (int) ($r['amount'] ?? 0),
        ], $stmt->fetchAll());
    }

    /** Новые пользователи, пришедшие по ссылкам акции и затем участвовавшие в ней. */
    public function referredParticipants(int $contestId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM contest_ref_visits v
             JOIN contest_entries e ON e.contest_id = v.contest_id AND e.user_id = v.registered_user_id
             WHERE v.contest_id = ?'
        );
        $stmt->execute([$contestId]);
        return (int) $stmt->fetchColumn();
    }

    /** Топ участников по привлечённым регистрациям (для организатора; без ПДн приглашённых). */
    public function topReferrers(int $contestId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            "SELECT v.ref_user_id, u.name, COUNT(*) AS visits, COUNT(v.registered_user_id) AS registrations
             FROM contest_ref_visits v LEFT JOIN users u ON u.id = v.ref_user_id
             WHERE v.contest_id = ?
             GROUP BY v.ref_user_id, u.name
             ORDER BY registrations DESC, visits DESC LIMIT {$limit}"
        );
        $stmt->execute([$contestId]);
        return $stmt->fetchAll();
    }
}
