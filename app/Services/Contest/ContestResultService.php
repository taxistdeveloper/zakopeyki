<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Helpers\ActivityLogger;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\ContestResult;

/**
 * Подведение итогов (однократно, в транзакции с блокировкой) и вручение призов.
 */
final class ContestResultService
{
    private Contest $contests;
    private ContestEntry $entries;
    private ContestResult $results;
    private ContestPolicy $policy;
    private ContestNotifier $notifier;

    public function __construct(
        ?Contest $contests = null,
        ?ContestEntry $entries = null,
        ?ContestResult $results = null,
        ?ContestPolicy $policy = null,
        ?ContestNotifier $notifier = null,
    ) {
        $this->contests = $contests ?? new Contest();
        $this->entries = $entries ?? new ContestEntry();
        $this->results = $results ?? new ContestResult();
        $this->policy = $policy ?? new ContestPolicy();
        $this->notifier = $notifier ?? new ContestNotifier();
    }

    /**
     * @param array<int, int> $juryPicks place => entry_id (только для jury)
     * @param array<int, string> $juryNotes place => обоснование
     * @return array{ok:bool, error?:string}
     */
    public function execute(array $contest, array $actor, array $juryPicks = [], array $juryNotes = []): array
    {
        $roles = ContestService::actorRoles($contest, $actor);
        if (!ContestPolicy::canTransition((string) $contest['status'], ContestPolicy::S_RESULTS, $roles)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!$this->policy->mechanicEnabled((string) $contest['mechanic'])) {
            return ['ok' => false, 'error' => 'mechanic_disabled'];
        }

        $db = $this->contests->db();
        $winnerRows = [];
        $db->beginTransaction();
        try {
            $locked = $this->contests->lockForUpdate((int) $contest['id']);
            if (!$locked || $locked['status'] !== ContestPolicy::S_CLOSED) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'invalid_state'];
            }
            if ($this->results->forContest((int) $locked['id']) !== null) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'already_executed'];
            }
            $counts = $this->entries->statusCounts((int) $locked['id']);
            if ($counts['pending'] > 0) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'pending_review'];
            }
            $eligible = $this->entries->confirmedIds((int) $locked['id']);
            if ($eligible === []) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'no_eligible'];
            }
            $rules = $this->contests->currentRules($locked);
            if ($rules === null) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'no_rules'];
            }
            $places = min((int) $rules['winners_count'], count($eligible));

            if ($locked['mechanic'] === ContestPolicy::MECHANIC_RANDOM) {
                $seed = (string) ($locked['random_seed'] ?? '');
                if ($seed === '' || RandomDraw::commitFor($seed) !== (string) $locked['random_commit']) {
                    $db->rollBack();
                    ActivityLogger::error('contest.random_commit_mismatch', 'Seed не соответствует commit', 'contest', (int) $locked['id']);
                    return ['ok' => false, 'error' => 'commit_mismatch'];
                }
                $picked = RandomDraw::pick($seed, $eligible, $places);
                $algorithm = RandomDraw::ALGORITHM;
                $notes = [];
            } else {
                $picked = $this->validateJuryPicks($juryPicks, $eligible, $places);
                if ($picked === null) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => 'invalid_picks'];
                }
                $algorithm = 'jury-manual-v1';
                $notes = $juryNotes;
            }

            $execId = $this->results->insertExecution([
                'contest_id' => (int) $locked['id'],
                'rule_version_id' => (int) $rules['id'],
                'mechanic' => (string) $locked['mechanic'],
                'algorithm' => $algorithm,
                'eligible_count' => count($eligible),
                'eligible_hash' => RandomDraw::listHash($eligible),
                'commit_hash' => $locked['random_commit'] ?? null,
                'seed' => $locked['mechanic'] === ContestPolicy::MECHANIC_RANDOM ? $locked['random_seed'] : null,
                'executed_by' => (int) $actor['id'],
                'details' => ['eligible_ids' => $eligible, 'rule_hash' => $rules['content_hash']],
            ]);

            $due = date('Y-m-d H:i:s', time() + max(1, (int) $locked['fulfilment_days']) * 86400);
            $prizes = [];
            foreach ((array) $rules['prizes'] as $p) {
                $prizes[(int) ($p['place'] ?? 0)] = (string) ($p['title'] ?? '');
            }
            foreach (array_values($picked) as $i => $entryId) {
                $place = $i + 1;
                $entry = $this->entries->findInContest((int) $locked['id'], (int) $entryId);
                $prize = $prizes[$place] ?? ($prizes[1] ?? (string) $locked['prize_summary']);
                $this->results->insertWinner([
                    'execution_id' => $execId,
                    'contest_id' => (int) $locked['id'],
                    'entry_id' => (int) $entryId,
                    'user_id' => $entry['user_id'] ?? null,
                    'place' => $place,
                    'prize_title' => $prize,
                    'jury_note' => isset($notes[$place]) ? mb_substr(trim((string) $notes[$place]), 0, 500) : null,
                    'fulfilment_due_at' => $due,
                ]);
                $winnerRows[] = ['user_id' => (int) ($entry['user_id'] ?? 0), 'prize' => $prize];
            }

            $this->contests->changeStatus((int) $locked['id'], ContestPolicy::S_CLOSED, ContestPolicy::S_RESULTS, [
                'results_at' => date('Y-m-d H:i:s'),
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'error' => 'already_executed'];
            }
            ActivityLogger::exception($e, 'contest.results_failed');
            throw $e;
        }

        ActivityLogger::info('contest.results', 'Итоги подведены', 'contest', (int) $contest['id'], [
            'mechanic' => $contest['mechanic'],
            'winners' => count($winnerRows),
        ]);

        $winnerIds = array_column($winnerRows, 'user_id');
        $others = array_diff($this->entries->participantUserIds((int) $contest['id'], ['confirmed', 'disqualified']), $winnerIds);
        $this->notifier->notifyMany($others, 'results', $contest);
        foreach ($winnerRows as $w) {
            $this->notifier->notify($w['user_id'], 'winner', $contest, ['prize' => $w['prize']]);
            $this->notifier->emailWinner($w['user_id'], $contest, $w['prize']);
        }
        $this->notifier->notify((int) $contest['organizer_id'], 'results_organizer', $contest);
        return ['ok' => true];
    }

    // ── Вручение призов ──────────────────────────────────────────────────────

    /** Организатор отмечает, что приз передан. */
    public function markHandedOver(array $contest, int $winnerId, string $note, array $actor): array
    {
        $roles = ContestService::actorRoles($contest, $actor);
        if (!in_array('organizer', $roles, true) && !in_array('admin', $roles, true)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $w = $this->winnerOf($contest, $winnerId);
        if ($w === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if (!in_array($contest['status'], [ContestPolicy::S_RESULTS, ContestPolicy::S_DISPUTED], true)) {
            return ['ok' => false, 'error' => 'invalid_state'];
        }
        if (!$this->results->setFulfilment($winnerId, 'pending', 'handed_over', [
            'fulfilment_note' => mb_substr(trim($note), 0, 500) ?: null,
            'handed_over_at' => date('Y-m-d H:i:s'),
        ])) {
            return ['ok' => false, 'error' => 'invalid_state'];
        }
        ActivityLogger::info('contest.prize_handed', 'Организатор отметил вручение приза', 'contest', (int) $contest['id'], ['winner_id' => $winnerId]);
        $this->notifier->notify((int) ($w['user_id'] ?? 0), 'prize_handed', $contest);
        return ['ok' => true];
    }

    /** Победитель подтверждает получение. */
    public function confirmReceived(array $contest, int $winnerId, array $user): array
    {
        $w = $this->winnerOf($contest, $winnerId);
        if ($w === null || (int) ($w['user_id'] ?? 0) !== (int) $user['id']) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $ok = $this->results->setFulfilment($winnerId, 'handed_over', 'confirmed', ['confirmed_at' => date('Y-m-d H:i:s')])
            || $this->results->setFulfilment($winnerId, 'pending', 'confirmed', ['confirmed_at' => date('Y-m-d H:i:s')]);
        if (!$ok) {
            return ['ok' => false, 'error' => 'invalid_state'];
        }
        ActivityLogger::info('contest.prize_confirmed', 'Победитель подтвердил получение приза', 'contest', (int) $contest['id'], ['winner_id' => $winnerId]);
        $this->notifier->notify((int) $contest['organizer_id'], 'prize_confirmed', $contest);
        $this->tryComplete((int) $contest['id']);
        return ['ok' => true];
    }

    /** Победитель сообщает, что приз не получен → спор. */
    public function disputePrize(array $contest, int $winnerId, string $note, array $user): array
    {
        $w = $this->winnerOf($contest, $winnerId);
        if ($w === null || (int) ($w['user_id'] ?? 0) !== (int) $user['id']) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (mb_strlen(trim($note)) < 5) {
            return ['ok' => false, 'error' => 'note_required'];
        }
        $from = (string) $w['fulfilment_status'];
        if (!in_array($from, ['pending', 'handed_over', 'expired'], true)
            || !$this->results->setFulfilment($winnerId, $from, 'disputed', ['fulfilment_note' => mb_substr(trim($note), 0, 500)])) {
            return ['ok' => false, 'error' => 'invalid_state'];
        }
        if (in_array($contest['status'], [ContestPolicy::S_RESULTS, ContestPolicy::S_COMPLETED], true)) {
            $this->contests->changeStatus((int) $contest['id'], (string) $contest['status'], ContestPolicy::S_DISPUTED);
        }
        ActivityLogger::warning('contest.prize_dispute', 'Победитель открыл спор о вручении', 'contest', (int) $contest['id'], [
            'winner_id' => $winnerId,
            'note' => $note,
        ]);
        $this->notifier->notifyModerators('dispute_opened', $contest);
        $this->notifier->notify((int) $contest['organizer_id'], 'dispute_opened', $contest);
        return ['ok' => true];
    }

    /** Модератор закрывает спор: приз вручён (confirmed) или не вручён (expired). */
    public function resolveDispute(array $contest, int $winnerId, string $outcome, string $note, array $actor): array
    {
        if (!in_array('moderator', ContestService::actorRoles($contest, $actor), true)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!in_array($outcome, ['confirmed', 'expired'], true) || mb_strlen(trim($note)) < 5) {
            return ['ok' => false, 'error' => 'note_required'];
        }
        $w = $this->winnerOf($contest, $winnerId);
        if ($w === null || $w['fulfilment_status'] !== 'disputed') {
            return ['ok' => false, 'error' => 'invalid_state'];
        }
        $this->results->setFulfilment($winnerId, 'disputed', $outcome, [
            'fulfilment_note' => mb_substr('moderator: ' . trim($note), 0, 500),
            'confirmed_at' => $outcome === 'confirmed' ? date('Y-m-d H:i:s') : null,
        ]);
        if ($contest['status'] === ContestPolicy::S_DISPUTED) {
            $this->contests->changeStatus((int) $contest['id'], ContestPolicy::S_DISPUTED, ContestPolicy::S_RESULTS);
        }
        ActivityLogger::warning('contest.dispute_resolved', 'Спор о вручении закрыт модератором', 'contest', (int) $contest['id'], [
            'winner_id' => $winnerId,
            'outcome' => $outcome,
            'note' => $note,
        ]);
        $this->notifier->notify((int) ($w['user_id'] ?? 0), 'dispute_resolved', $contest, ['note' => $note]);
        $this->notifier->notify((int) $contest['organizer_id'], 'dispute_resolved', $contest, ['note' => $note]);
        $this->tryComplete((int) $contest['id']);
        return ['ok' => true];
    }

    /** results_published → completed, когда все призы в финальном статусе. */
    public function tryComplete(int $contestId): bool
    {
        $c = $this->contests->find($contestId);
        if (!$c || $c['status'] !== ContestPolicy::S_RESULTS || !$this->results->allFulfilled($contestId)) {
            return false;
        }
        if ($this->contests->changeStatus($contestId, ContestPolicy::S_RESULTS, ContestPolicy::S_COMPLETED, [
            'completed_at' => date('Y-m-d H:i:s'),
        ])) {
            ActivityLogger::info('contest.complete', 'Акция завершена', 'contest', $contestId);
            return true;
        }
        return false;
    }

    /** Cron: просроченные вручения → expired (+ модераторам), «передан» без ответа 7 дней → confirmed. */
    public function processFulfilmentDeadlines(): array
    {
        $expired = 0;
        $autoConfirmed = 0;
        foreach ($this->results->overdueFulfilments() as $w) {
            if ($w['fulfilment_status'] === 'pending'
                && $this->results->setFulfilment((int) $w['id'], 'pending', 'expired', ['fulfilment_note' => 'deadline_passed'])) {
                $expired++;
                $c = $this->contests->find((int) $w['contest_id']);
                if ($c) {
                    $this->notifier->notifyModerators('prize_overdue', $c);
                    $this->notifier->notify((int) ($w['user_id'] ?? 0), 'prize_overdue_winner', $c);
                }
                $this->tryComplete((int) $w['contest_id']);
            }
        }
        foreach ($this->results->staleHandedOver(7) as $w) {
            if ($this->results->setFulfilment((int) $w['id'], 'handed_over', 'confirmed', [
                'confirmed_at' => date('Y-m-d H:i:s'),
                'fulfilment_note' => 'auto_confirmed_after_7_days',
            ])) {
                $autoConfirmed++;
                $this->tryComplete((int) $w['contest_id']);
            }
        }
        return ['expired' => $expired, 'auto_confirmed' => $autoConfirmed];
    }

    private function winnerOf(array $contest, int $winnerId): ?array
    {
        $w = $this->results->winner($winnerId);
        return ($w && (int) $w['contest_id'] === (int) $contest['id']) ? $w : null;
    }

    /**
     * @param array<int|string, int|string> $picks
     * @param list<int> $eligible
     * @return list<int>|null
     */
    private function validateJuryPicks(array $picks, array $eligible, int $places): ?array
    {
        $out = [];
        for ($place = 1; $place <= $places; $place++) {
            $id = (int) ($picks[$place] ?? 0);
            if ($id <= 0 || !in_array($id, $eligible, true) || in_array($id, $out, true)) {
                return null;
            }
            $out[] = $id;
        }
        return $out;
    }
}
