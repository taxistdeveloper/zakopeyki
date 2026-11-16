<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Helpers\ActivityLogger;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\SiteVisit;
use App\Services\Security\AuthThrottle;

/**
 * Участие: вступление, выход, ручная проверка организатором/модератором.
 * Допуск, статусы и условия определяются только сервером.
 */
final class ContestEntryService
{
    private Contest $contests;
    private ContestEntry $entries;
    private ContestPolicy $policy;
    private ContestNotifier $notifier;

    public function __construct(
        ?Contest $contests = null,
        ?ContestEntry $entries = null,
        ?ContestPolicy $policy = null,
        ?ContestNotifier $notifier = null,
    ) {
        $this->contests = $contests ?? new Contest();
        $this->entries = $entries ?? new ContestEntry();
        $this->policy = $policy ?? new ContestPolicy();
        $this->notifier = $notifier ?? new ContestNotifier();
    }

    /**
     * @param array{ip?:string, visitor_key?:string, ref_user_id?:int} $ctx
     * @return array{ok:bool, error?:string, conditions?:array, entry_id?:int}
     */
    public function join(array $contest, array $user, ?string $submission, array $ctx = []): array
    {
        $contest = (new ContestService($this->contests, $this->policy, $this->notifier))->syncTime($contest);

        if (!$this->policy->moduleEnabled() || !$this->policy->mechanicEnabled((string) $contest['mechanic'])) {
            return ['ok' => false, 'error' => 'mechanic_disabled'];
        }
        if ($contest['status'] !== ContestPolicy::S_ACTIVE) {
            return ['ok' => false, 'error' => 'not_active'];
        }
        $now = time();
        if (strtotime((string) $contest['starts_at']) > $now || strtotime((string) $contest['ends_at']) <= $now) {
            return ['ok' => false, 'error' => 'not_active'];
        }
        if (!empty($user['blocked_at'])) {
            return ['ok' => false, 'error' => 'blocked'];
        }
        if ((int) $user['id'] === (int) $contest['organizer_id']) {
            return ['ok' => false, 'error' => 'own_contest'];
        }
        if ($this->entries->findForUser((int) $contest['id'], (int) $user['id']) !== null) {
            return ['ok' => false, 'error' => 'already_joined'];
        }
        $throttle = (new AuthThrottle())->hit('contest_join', 'u' . (int) $user['id']);
        if (!$throttle['allowed']) {
            return ['ok' => false, 'error' => 'throttled'];
        }

        $rules = $this->contests->currentRules($contest);
        if ($rules === null) {
            return ['ok' => false, 'error' => 'no_rules'];
        }
        $submission = $submission !== null
            ? mb_substr(trim(str_replace("\0", '', $submission)), 0, ContestPolicy::MAX_SUBMISSION_CHARS)
            : null;
        if ($contest['mechanic'] !== ContestPolicy::MECHANIC_JURY) {
            $submission = null; // работа нужна только в конкурсе с жюри
        }

        $verifier = new ContestConditionVerifier($this->policy);
        $conditions = $verifier->checkAtJoin($contest, (array) $rules['conditions'], $user, $submission, $now);
        if (!ContestConditionVerifier::allMet($conditions)) {
            return ['ok' => false, 'error' => 'conditions', 'conditions' => $conditions];
        }

        $ip = (string) ($ctx['ip'] ?? '');
        $ipHash = $ip !== '' ? hash('sha256', 'contest:' . (int) $contest['id'] . ':' . $ip) : null;
        $visitorKey = preg_match('/^[a-f0-9]{32}$/', (string) ($ctx['visitor_key'] ?? '')) ? (string) $ctx['visitor_key'] : null;
        $refUserId = (int) ($ctx['ref_user_id'] ?? 0);
        if ($refUserId === (int) $user['id'] || $refUserId === (int) $contest['organizer_id']) {
            $refUserId = 0;
        }

        $flags = [];
        if ($visitorKey !== null && $this->countSameDevice((int) $contest['id'], $visitorKey) > 0) {
            $flags[] = 'same_device';
        }
        if ($ipHash !== null && $this->entries->countSameIp((int) $contest['id'], $ipHash) + 1 >= ContestPolicy::FRAUD_SAME_IP_THRESHOLD) {
            $flags[] = 'same_ip';
        }
        if ((time() - (strtotime((string) ($user['created_at'] ?? '')) ?: 0)) < 86400) {
            $flags[] = 'new_account';
        }

        $entryId = $this->entries->insertOnce([
            'contest_id' => (int) $contest['id'],
            'user_id' => (int) $user['id'],
            'rule_version_id' => (int) $rules['id'],
            'status' => 'pending',
            'submission_text' => $submission !== '' ? $submission : null,
            'ref_user_id' => $refUserId > 0 ? $refUserId : null,
            'ip_hash' => $ipHash,
            'visitor_key' => $visitorKey,
            'fraud_flags' => $flags !== [] ? implode(',', $flags) : null,
        ]);
        if ($entryId === null) {
            return ['ok' => false, 'error' => 'already_joined'];
        }

        foreach ($conditions as $key => $r) {
            $this->entries->saveCondition($entryId, (string) $key, $r['status'], $r['detail']);
        }
        if (in_array('same_ip', $flags, true) && $ipHash !== null) {
            $this->entries->flagSameIp((int) $contest['id'], $ipHash, 'same_ip');
        }
        if (in_array('same_device', $flags, true) && $visitorKey !== null) {
            $this->flagSameDevice((int) $contest['id'], $visitorKey);
        }
        $this->contests->recountEntries((int) $contest['id']);

        ActivityLogger::info('contest.join', 'Участие в акции', 'contest', (int) $contest['id'], [
            'entry_id' => $entryId,
            'flags' => $flags,
            'ref_user_id' => $refUserId ?: null,
        ]);
        $this->notifier->notify((int) $user['id'], 'joined', $contest);
        return ['ok' => true, 'entry_id' => $entryId, 'conditions' => $conditions];
    }

    /** @return array{ok:bool, error?:string} */
    public function withdraw(array $contest, array $user): array
    {
        if ($contest['status'] !== ContestPolicy::S_ACTIVE) {
            return ['ok' => false, 'error' => 'not_active'];
        }
        $entry = $this->entries->findForUser((int) $contest['id'], (int) $user['id']);
        if ($entry === null || $entry['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'not_joined'];
        }
        if (!$this->entries->setStatus((int) $entry['id'], 'pending', 'withdrawn', 'by_participant', (int) $user['id'])) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        $this->contests->recountEntries((int) $contest['id']);
        ActivityLogger::info('contest.withdraw', 'Участник вышел из акции', 'contest', (int) $contest['id'], ['entry_id' => (int) $entry['id']]);
        return ['ok' => true];
    }

    /**
     * Ручное решение по участию (организатор или модератор) до подведения итогов.
     * confirm: pending → confirmed (только после закрытия, все условия выполнены);
     * disqualify: pending|confirmed → disqualified (с причиной).
     * @return array{ok:bool, error?:string}
     */
    public function review(array $contest, int $entryId, string $action, string $reason, array $actor): array
    {
        if (!ContestService::canManage($contest, $actor)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!in_array($contest['status'], [ContestPolicy::S_ACTIVE, ContestPolicy::S_CLOSED, ContestPolicy::S_SUSPENDED], true)) {
            return ['ok' => false, 'error' => 'results_locked'];
        }
        $entry = $this->entries->findInContest((int) $contest['id'], $entryId);
        if ($entry === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $reason = trim($reason);

        if ($action === 'confirm') {
            if ($contest['status'] !== ContestPolicy::S_CLOSED || $entry['status'] !== 'pending') {
                return ['ok' => false, 'error' => 'invalid_state'];
            }
            foreach ($this->entries->conditions($entryId) as $c) {
                if ($c['status'] !== ContestConditionVerifier::MET) {
                    return ['ok' => false, 'error' => 'conditions'];
                }
            }
            $ok = $this->entries->setStatus($entryId, 'pending', 'confirmed', $reason !== '' ? 'review:' . $reason : 'review', (int) $actor['id']);
            $notifyKey = 'entry_confirmed';
        } elseif ($action === 'disqualify') {
            if (mb_strlen($reason) < 5) {
                return ['ok' => false, 'error' => 'note_required'];
            }
            if (!in_array($entry['status'], ['pending', 'confirmed'], true)) {
                return ['ok' => false, 'error' => 'invalid_state'];
            }
            $ok = $this->entries->setStatus($entryId, (string) $entry['status'], 'disqualified', 'manual:' . $reason, (int) $actor['id']);
            $notifyKey = 'entry_disqualified_manual';
        } else {
            return ['ok' => false, 'error' => 'invalid_action'];
        }
        if (!$ok) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        $this->contests->recountEntries((int) $contest['id']);
        ActivityLogger::info('contest.entry_' . $action, 'Решение по участию', 'contest', (int) $contest['id'], [
            'entry_id' => $entryId,
            'reason' => $reason,
        ]);
        $this->notifier->notify((int) ($entry['user_id'] ?? 0), $notifyKey, $contest, ['note' => $reason]);
        return ['ok' => true];
    }

    /** Контекст запроса для join (IP и ключ посетителя). */
    public static function requestContext(): array
    {
        return [
            'ip' => AuthThrottle::ip(),
            'visitor_key' => SiteVisit::currentVisitorKey(),
        ];
    }

    private function countSameDevice(int $contestId, string $visitorKey): int
    {
        $stmt = $this->contests->db()->prepare('SELECT COUNT(*) FROM contest_entries WHERE contest_id = ? AND visitor_key = ?');
        $stmt->execute([$contestId, $visitorKey]);
        return (int) $stmt->fetchColumn();
    }

    private function flagSameDevice(int $contestId, string $visitorKey): void
    {
        $stmt = $this->contests->db()->prepare('SELECT id FROM contest_entries WHERE contest_id = ? AND visitor_key = ?');
        $stmt->execute([$contestId, $visitorKey]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->entries->addFraudFlag((int) $id, 'same_device');
        }
    }
}
