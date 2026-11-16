<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Core\Auth;
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Helpers\UploadHelper;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\Follow;
use App\Models\User;

/**
 * Управление акциями: конструктор, версии правил, модерация и жизненный цикл.
 * Все переходы статусов проверяются ContestPolicy::TRANSITIONS и выполняются атомарно.
 */
final class ContestService
{
    public const COVER_DIR = '/public/uploads/contests';
    private const COVER_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const COVER_MAX_BYTES = 5242880;

    private Contest $contests;
    private ContestPolicy $policy;
    private ContestNotifier $notifier;

    public function __construct(?Contest $contests = null, ?ContestPolicy $policy = null, ?ContestNotifier $notifier = null)
    {
        $this->contests = $contests ?? new Contest();
        $this->policy = $policy ?? new ContestPolicy();
        $this->notifier = $notifier ?? new ContestNotifier();
    }

    public function policy(): ContestPolicy
    {
        return $this->policy;
    }

    // ── Роли актёра ───────────────────────────────────────────────────────────

    /** @return list<string> */
    public static function actorRoles(array $contest, ?array $user = null): array
    {
        $user ??= Auth::user();
        if ($user === null) {
            return [];
        }
        $roles = [];
        if ((int) $user['id'] === (int) $contest['organizer_id']) {
            $roles[] = 'organizer';
        }
        $role = (string) ($user['role'] ?? 'user');
        if ($role === 'admin') {
            $roles[] = 'admin';
            $roles[] = 'moderator';
        } elseif (in_array('contests', Auth::normalizePermissions($user['permissions'] ?? null, $role), true)) {
            $roles[] = 'moderator';
        }
        return $roles;
    }

    public static function canManage(array $contest, ?array $user = null): bool
    {
        $roles = self::actorRoles($contest, $user);
        return in_array('organizer', $roles, true) || in_array('moderator', $roles, true);
    }

    // ── Конструктор ───────────────────────────────────────────────────────────

    /**
     * Валидация данных мастера. Возвращает ['data' => ..., 'errors' => [field => message]].
     * @return array{data: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $in, bool $forSubmission = false): array
    {
        $errors = [];
        $s = static fn (string $k, int $max): string => mb_substr(trim(str_replace("\0", '', (string) ($in[$k] ?? ''))), 0, $max);

        $title = $s('title', 160);
        if (mb_strlen($title) < 5) {
            $errors['title'] = t('contests.err.title');
        }
        $summary = $s('summary', 300);
        $description = $s('description', 10000);
        $prizeSummary = $s('prize_summary', 255);
        if (mb_strlen($prizeSummary) < 3) {
            $errors['prize_summary'] = t('contests.err.prize_summary');
        }

        $mechanic = (string) ($in['mechanic'] ?? '');
        if (!in_array($mechanic, ContestPolicy::MECHANICS, true)) {
            $errors['mechanic'] = t('contests.err.mechanic');
        } elseif (!$this->policy->mechanicEnabled($mechanic)) {
            $errors['mechanic'] = t('contests.err.mechanic_disabled');
        }

        $category = $s('category', 120);
        if ($category !== '' && !array_key_exists($category, ProductHelper::PRODUCT_CATEGORY_TREE)) {
            $category = '';
        }
        $region = (string) ($in['region'] ?? 'kz');
        if (!in_array($region, ContestPolicy::REGIONS, true)) {
            $region = 'kz';
        }

        $winners = (int) ($in['winners_count'] ?? 1);
        if ($winners < 1 || $winners > ContestPolicy::MAX_WINNERS) {
            $errors['winners_count'] = t('contests.err.winners', ['max' => ContestPolicy::MAX_WINNERS]);
            $winners = max(1, min(ContestPolicy::MAX_WINNERS, $winners));
        }

        $prizesIn = array_values(array_filter(
            array_map(static fn ($p): string => mb_substr(trim((string) $p), 0, 255), (array) ($in['prizes'] ?? [])),
            static fn (string $p): bool => $p !== ''
        ));
        if ($prizesIn === []) {
            $errors['prizes'] = t('contests.err.prizes');
        }
        $prizes = [];
        for ($place = 1; $place <= $winners; $place++) {
            $prizes[] = ['place' => $place, 'title' => $prizesIn[$place - 1] ?? ($prizesIn[count($prizesIn) - 1] ?? '')];
        }

        $rules = $s('rules_text', 20000);
        if (mb_strlen($rules) < 50) {
            $errors['rules_text'] = t('contests.err.rules');
        }
        $criteria = $s('criteria_text', 5000);
        if ($mechanic === ContestPolicy::MECHANIC_JURY && mb_strlen($criteria) < 10) {
            $errors['criteria_text'] = t('contests.err.criteria');
        }

        $startsTs = $this->parseDate((string) ($in['starts_at'] ?? ''));
        $endsTs = $this->parseDate((string) ($in['ends_at'] ?? ''));
        if ($startsTs === null) {
            $errors['starts_at'] = t('contests.err.date');
        }
        if ($endsTs === null) {
            $errors['ends_at'] = t('contests.err.date');
        }
        if ($startsTs !== null && $endsTs !== null) {
            $dur = $endsTs - $startsTs;
            if ($dur < ContestPolicy::MIN_DURATION_HOURS * 3600) {
                $errors['ends_at'] = t('contests.err.duration_min');
            } elseif ($dur > ContestPolicy::MAX_DURATION_DAYS * 86400) {
                $errors['ends_at'] = t('contests.err.duration_max', ['days' => ContestPolicy::MAX_DURATION_DAYS]);
            }
            if ($forSubmission && $endsTs <= time() + 3600) {
                $errors['ends_at'] = t('contests.err.ends_past');
            }
        }

        $fulfilment = (int) ($in['fulfilment_days'] ?? ContestPolicy::DEFAULT_FULFILMENT_DAYS);
        $fulfilment = max(1, min(ContestPolicy::MAX_FULFILMENT_DAYS, $fulfilment));

        $conditions = [];
        if (!empty($in['cond_follow_organizer'])) {
            $conditions[] = ['key' => ContestPolicy::COND_FOLLOW_ORGANIZER];
        }
        $ageDays = (int) ($in['cond_account_age_days'] ?? 0);
        if ($ageDays > 0) {
            $conditions[] = ['key' => ContestPolicy::COND_ACCOUNT_AGE, 'days' => min(ContestPolicy::MAX_ACCOUNT_AGE_DAYS, $ageDays)];
        }
        if ($mechanic === ContestPolicy::MECHANIC_JURY) {
            $conditions[] = ['key' => ContestPolicy::COND_SUBMISSION];
        }

        if ($forSubmission && empty($in['organizer_terms'])) {
            $errors['organizer_terms'] = t('contests.err.terms');
        }

        return [
            'errors' => $errors,
            'data' => [
                'title' => $title,
                'summary' => $summary,
                'description' => $description,
                'prize_summary' => $prizeSummary,
                'category' => $category !== '' ? $category : null,
                'region' => $region,
                'mechanic' => $mechanic,
                'winners_count' => $winners,
                'prizes' => $prizes,
                'rules_text' => $rules,
                'criteria_text' => $mechanic === ContestPolicy::MECHANIC_JURY ? $criteria : null,
                'conditions' => $conditions,
                'starts_at' => $startsTs !== null ? date('Y-m-d H:i:s', $startsTs) : null,
                'ends_at' => $endsTs !== null ? date('Y-m-d H:i:s', $endsTs) : null,
                'fulfilment_days' => $fulfilment,
            ],
        ];
    }

    /**
     * Создать черновик (идемпотентно по ключу формы).
     * @return array{ok:bool, id?:int, errors?:array<string,string>, error?:string}
     */
    public function createDraft(array $user, array $input, ?array $coverFile, string $idempotencyKey): array
    {
        $deny = $this->policy->organizerDenyReason($user);
        if ($deny !== null) {
            return ['ok' => false, 'error' => $deny];
        }
        $idem = preg_match('/^[a-f0-9]{32}$/', $idempotencyKey) ? $idempotencyKey : null;
        if ($idem !== null && ($existing = $this->contests->findByIdempotencyKey((int) $user['id'], $idem))) {
            return ['ok' => true, 'id' => (int) $existing['id']];
        }

        $v = $this->validate($input);
        if ($v['errors'] !== []) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $d = $v['data'];
        $cover = $this->storeCover($coverFile);
        if ($cover === false) {
            return ['ok' => false, 'errors' => ['cover' => t('contests.err.cover')]];
        }

        $db = $this->contests->db();
        $db->beginTransaction();
        try {
            $id = $this->contests->insert([
                'slug' => $this->uniqueSlug($d['title']),
                'organizer_id' => (int) $user['id'],
                'mechanic' => $d['mechanic'],
                'status' => ContestPolicy::S_DRAFT,
                'title' => $d['title'],
                'summary' => $d['summary'],
                'description' => $d['description'],
                'prize_summary' => $d['prize_summary'],
                'category' => $d['category'],
                'region' => $d['region'],
                'cover_image' => $cover ?: null,
                'winners_count' => $d['winners_count'],
                'starts_at' => $d['starts_at'],
                'ends_at' => $d['ends_at'],
                'fulfilment_days' => $d['fulfilment_days'],
                'idempotency_key' => $idem,
            ]);
            $versionId = $this->contests->addRuleVersion($id, $d, (int) $user['id']);
            $this->contests->updateFields($id, ['current_rule_version_id' => $versionId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062 && $idem !== null
                && ($existing = $this->contests->findByIdempotencyKey((int) $user['id'], $idem))) {
                return ['ok' => true, 'id' => (int) $existing['id']];
            }
            throw $e;
        }

        ActivityLogger::info('contest.create', 'Создан черновик акции', 'contest', $id, ['mechanic' => $d['mechanic']]);
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Изменить черновик/отклонённую акцию. Новая версия правил создаётся при изменении содержимого.
     * @return array{ok:bool, errors?:array<string,string>, error?:string}
     */
    public function updateDraft(array $contest, array $user, array $input, ?array $coverFile): array
    {
        if (!in_array('organizer', self::actorRoles($contest, $user), true) && ($user['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!ContestPolicy::isEditable((string) $contest['status'])) {
            return ['ok' => false, 'error' => 'not_editable'];
        }
        $v = $this->validate($input);
        if ($v['errors'] !== []) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        $d = $v['data'];
        $cover = $this->storeCover($coverFile);
        if ($cover === false) {
            return ['ok' => false, 'errors' => ['cover' => t('contests.err.cover')]];
        }

        $db = $this->contests->db();
        $db->beginTransaction();
        try {
            $locked = $this->contests->lockForUpdate((int) $contest['id']);
            if (!$locked || !ContestPolicy::isEditable((string) $locked['status'])) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'not_editable'];
            }
            $fields = [
                'title' => $d['title'], 'summary' => $d['summary'], 'description' => $d['description'],
                'prize_summary' => $d['prize_summary'], 'category' => $d['category'], 'region' => $d['region'],
                'mechanic' => $d['mechanic'], 'winners_count' => $d['winners_count'], 'starts_at' => $d['starts_at'],
                'ends_at' => $d['ends_at'], 'fulfilment_days' => $d['fulfilment_days'],
            ];
            if ($cover !== '' && $cover !== null) {
                $fields['cover_image'] = $cover;
            }
            $current = $this->contests->currentRules($locked);
            if ($current === null || $this->rulesChanged($current, $d)) {
                $fields['current_rule_version_id'] = $this->contests->addRuleVersion((int) $contest['id'], $d, (int) $user['id']);
            }
            $this->contests->updateFields((int) $contest['id'], $fields);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        ActivityLogger::info('contest.update', 'Изменена акция', 'contest', (int) $contest['id']);
        return ['ok' => true];
    }

    // ── Модерация и статусы ───────────────────────────────────────────────────

    /** @return array{ok:bool, error?:string, errors?:array<string,string>} */
    public function submit(array $contest, array $user, bool $termsAccepted): array
    {
        if (!ContestPolicy::canTransition((string) $contest['status'], ContestPolicy::S_PENDING, self::actorRoles($contest, $user))) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $deny = $this->policy->organizerDenyReason((new User())->find((int) $contest['organizer_id']));
        if ($deny !== null) {
            return ['ok' => false, 'error' => $deny];
        }
        $rules = $this->contests->currentRules($contest);
        if ($rules === null) {
            return ['ok' => false, 'error' => 'no_rules'];
        }
        $check = $this->validate($this->inputFromContest($contest, $rules) + ['organizer_terms' => $termsAccepted ? '1' : ''], true);
        if ($check['errors'] !== []) {
            return ['ok' => false, 'errors' => $check['errors']];
        }
        if (!$this->contests->changeStatus((int) $contest['id'], (string) $contest['status'], ContestPolicy::S_PENDING, [
            'moderation_note' => null,
        ])) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        ActivityLogger::info('contest.submit', 'Акция отправлена на модерацию', 'contest', (int) $contest['id']);
        $this->notifier->notifyModerators('moderation_needed', $contest);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public function approve(array $contest, array $actor, string $note = ''): array
    {
        $now = time();
        $target = strtotime((string) $contest['starts_at']) > $now ? ContestPolicy::S_SCHEDULED : ContestPolicy::S_ACTIVE;
        if (!ContestPolicy::canTransition((string) $contest['status'], $target, self::actorRoles($contest, $actor))) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (in_array('organizer', self::actorRoles($contest, $actor), true) && ($actor['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'error' => 'self_moderation'];
        }
        if (!$this->policy->mechanicEnabled((string) $contest['mechanic'])) {
            return ['ok' => false, 'error' => 'mechanic_disabled'];
        }
        if (strtotime((string) $contest['ends_at']) <= $now) {
            return ['ok' => false, 'error' => 'ends_past'];
        }
        $extra = [
            'moderation_note' => $note !== '' ? mb_substr($note, 0, 500) : null,
            'moderated_by' => (int) $actor['id'],
            'moderated_at' => date('Y-m-d H:i:s'),
            'published_at' => date('Y-m-d H:i:s'),
        ];
        if ($contest['mechanic'] === ContestPolicy::MECHANIC_RANDOM && empty($contest['random_commit'])) {
            $c = RandomDraw::newCommitment();
            $extra['random_seed'] = $c['seed'];
            $extra['random_commit'] = $c['commit'];
        }
        if (!$this->contests->changeStatus((int) $contest['id'], ContestPolicy::S_PENDING, $target, $extra)) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        ActivityLogger::info('contest.approve', 'Акция одобрена', 'contest', (int) $contest['id'], ['status' => $target]);
        $this->notifier->notify((int) $contest['organizer_id'], 'approved', $contest);
        $this->announceToFollowers($contest);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public function reject(array $contest, array $actor, string $note): array
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            return ['ok' => false, 'error' => 'note_required'];
        }
        if (!ContestPolicy::canTransition((string) $contest['status'], ContestPolicy::S_REJECTED, self::actorRoles($contest, $actor))) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!$this->contests->changeStatus((int) $contest['id'], ContestPolicy::S_PENDING, ContestPolicy::S_REJECTED, [
            'moderation_note' => mb_substr($note, 0, 500),
            'moderated_by' => (int) $actor['id'],
            'moderated_at' => date('Y-m-d H:i:s'),
        ])) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        ActivityLogger::info('contest.reject', 'Акция отклонена', 'contest', (int) $contest['id'], ['note' => $note]);
        $this->notifier->notify((int) $contest['organizer_id'], 'rejected', $contest, ['note' => $note]);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public function suspend(array $contest, array $actor, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return ['ok' => false, 'error' => 'note_required'];
        }
        if (!ContestPolicy::canTransition((string) $contest['status'], ContestPolicy::S_SUSPENDED, self::actorRoles($contest, $actor))) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (!$this->contests->changeStatus((int) $contest['id'], (string) $contest['status'], ContestPolicy::S_SUSPENDED, [
            'suspended_reason' => mb_substr($reason, 0, 500),
        ])) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        ActivityLogger::warning('contest.suspend', 'Акция приостановлена', 'contest', (int) $contest['id'], ['reason' => $reason]);
        $this->notifier->notify((int) $contest['organizer_id'], 'suspended', $contest, ['note' => $reason]);
        $this->notifier->notifyMany((new ContestEntry())->participantUserIds((int) $contest['id']), 'suspended_participant', $contest);
        return ['ok' => true];
    }

    /** Возобновление: статус вычисляется по времени. */
    public function resume(array $contest, array $actor): array
    {
        $now = time();
        $target = match (true) {
            strtotime((string) $contest['starts_at']) > $now => ContestPolicy::S_SCHEDULED,
            strtotime((string) $contest['ends_at']) > $now => ContestPolicy::S_ACTIVE,
            default => ContestPolicy::S_CLOSED,
        };
        if ((string) $contest['status'] !== ContestPolicy::S_SUSPENDED
            || !ContestPolicy::canTransition(ContestPolicy::S_SUSPENDED, $target, self::actorRoles($contest, $actor))) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if ($target === ContestPolicy::S_CLOSED) {
            if (!$this->contests->changeStatus((int) $contest['id'], ContestPolicy::S_SUSPENDED, ContestPolicy::S_ACTIVE, ['suspended_reason' => null])) {
                return ['ok' => false, 'error' => 'conflict'];
            }
            $this->close((int) $contest['id']);
        } elseif (!$this->contests->changeStatus((int) $contest['id'], ContestPolicy::S_SUSPENDED, $target, ['suspended_reason' => null])) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        ActivityLogger::info('contest.resume', 'Акция возобновлена', 'contest', (int) $contest['id'], ['status' => $target]);
        $this->notifier->notify((int) $contest['organizer_id'], 'resumed', $contest);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public function cancel(array $contest, array $actor, string $reason): array
    {
        $reason = trim($reason);
        $roles = self::actorRoles($contest, $actor);
        if (!ContestPolicy::canTransition((string) $contest['status'], ContestPolicy::S_CANCELLED, $roles)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        $hadPublic = ContestPolicy::isPublicStatus((string) $contest['status']) || $contest['status'] === ContestPolicy::S_SUSPENDED;
        if ($hadPublic && mb_strlen($reason) < 5) {
            return ['ok' => false, 'error' => 'note_required'];
        }
        if (!$this->contests->changeStatus((int) $contest['id'], (string) $contest['status'], ContestPolicy::S_CANCELLED, [
            'suspended_reason' => $reason !== '' ? mb_substr($reason, 0, 500) : null,
        ])) {
            return ['ok' => false, 'error' => 'conflict'];
        }
        ActivityLogger::warning('contest.cancel', 'Акция отменена', 'contest', (int) $contest['id'], ['reason' => $reason]);
        if ($hadPublic) {
            $this->notifier->notifyMany((new ContestEntry())->participantUserIds((int) $contest['id']), 'cancelled', $contest, ['note' => $reason]);
        }
        if (!in_array('organizer', $roles, true)) {
            $this->notifier->notify((int) $contest['organizer_id'], 'cancelled', $contest, ['note' => $reason]);
        }
        return ['ok' => true];
    }

    /**
     * Перевод по времени: scheduled → active, active → closed (с проверкой условий).
     * Вызывается cron'ом и лениво при открытии страницы. Возвращает актуальную запись.
     */
    public function syncTime(array $contest): array
    {
        $id = (int) $contest['id'];
        $now = time();
        if ($contest['status'] === ContestPolicy::S_SCHEDULED && strtotime((string) $contest['starts_at']) <= $now) {
            if ($this->contests->changeStatus($id, ContestPolicy::S_SCHEDULED, ContestPolicy::S_ACTIVE)) {
                ActivityLogger::info('contest.activate', 'Акция стартовала', 'contest', $id);
            }
            $contest = $this->contests->find($id) ?? $contest;
        }
        if ($contest['status'] === ContestPolicy::S_ACTIVE && strtotime((string) $contest['ends_at']) <= $now) {
            $this->close($id);
            $contest = $this->contests->find($id) ?? $contest;
        }
        return $contest;
    }

    /**
     * Закрытие приёма: окончательная проверка условий каждого участия.
     * Выполняется в транзакции с блокировкой строки акции — повторный вызов безопасен.
     */
    public function close(int $contestId): bool
    {
        $db = $this->contests->db();
        $entries = new ContestEntry();
        $verifier = new ContestConditionVerifier($this->policy);
        $users = new User();
        $changes = [];

        $db->beginTransaction();
        try {
            $locked = $this->contests->lockForUpdate($contestId);
            if (!$locked || $locked['status'] !== ContestPolicy::S_ACTIVE) {
                $db->rollBack();
                return false;
            }
            $rules = $this->contests->currentRules($locked);
            $conditions = $rules['conditions'] ?? [];

            foreach ($entries->activeEntries($contestId) as $entry) {
                $user = $entry['user_id'] !== null ? $users->find((int) $entry['user_id']) : null;
                $res = $verifier->checkAtClose($locked, $conditions, $entry, $user);
                foreach ($res as $key => $r) {
                    $entries->saveCondition((int) $entry['id'], (string) $key, $r['status'], $r['detail']);
                }
                if (ContestConditionVerifier::allMet($res)) {
                    if (trim((string) ($entry['fraud_flags'] ?? '')) !== '' && $entry['status'] === 'pending') {
                        // Условия выполнены, но есть сигналы антифрода — решение за организатором/модератором
                        $changes[] = [(int) ($entry['user_id'] ?? 0), 'entry_review'];
                        continue;
                    }
                    if ($entry['status'] !== 'confirmed') {
                        $entries->setStatus((int) $entry['id'], (string) $entry['status'], 'confirmed', null, null);
                    }
                    $changes[] = [(int) ($entry['user_id'] ?? 0), 'entry_confirmed'];
                } else {
                    $entries->setStatus((int) $entry['id'], (string) $entry['status'], 'disqualified',
                        'conditions:' . (ContestConditionVerifier::firstFailure($res) ?? 'unknown'), null);
                    $changes[] = [(int) ($entry['user_id'] ?? 0), 'entry_disqualified'];
                }
            }

            $this->contests->changeStatus($contestId, ContestPolicy::S_ACTIVE, ContestPolicy::S_CLOSED, [
                'closed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->contests->recountEntries($contestId);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            ActivityLogger::exception($e, 'contest.close_failed');
            throw $e;
        }

        $contest = $this->contests->find($contestId) ?? ['id' => $contestId];
        ActivityLogger::info('contest.close', 'Приём участников закрыт, условия проверены', 'contest', $contestId, [
            'checked' => count($changes),
        ]);
        foreach ($changes as [$uid, $key]) {
            $this->notifier->notify($uid, $key, $contest);
        }
        $this->notifier->notify((int) ($contest['organizer_id'] ?? 0), 'closed_organizer', $contest);
        return true;
    }

    // ── Вспомогательное ───────────────────────────────────────────────────────

    /** Данные текущей версии в формате формы (для редактирования и повторной валидации). */
    public function inputFromContest(array $contest, ?array $rules): array
    {
        $conds = [];
        foreach ((array) ($rules['conditions'] ?? []) as $c) {
            $conds[(string) ($c['key'] ?? '')] = $c;
        }
        return [
            'title' => $contest['title'],
            'summary' => $contest['summary'],
            'description' => $contest['description'],
            'prize_summary' => $contest['prize_summary'],
            'category' => $contest['category'],
            'region' => $contest['region'],
            'mechanic' => $contest['mechanic'],
            'winners_count' => $contest['winners_count'],
            'prizes' => array_map(static fn (array $p): string => (string) ($p['title'] ?? ''), (array) ($rules['prizes'] ?? [])),
            'rules_text' => $rules['rules_text'] ?? '',
            'criteria_text' => $rules['criteria_text'] ?? '',
            'starts_at' => $contest['starts_at'],
            'ends_at' => $contest['ends_at'],
            'fulfilment_days' => $contest['fulfilment_days'],
            'cond_follow_organizer' => isset($conds[ContestPolicy::COND_FOLLOW_ORGANIZER]) ? '1' : '',
            'cond_account_age_days' => (int) ($conds[ContestPolicy::COND_ACCOUNT_AGE]['days'] ?? 0),
        ];
    }

    private function rulesChanged(array $current, array $d): bool
    {
        return $current['mechanic'] !== $d['mechanic']
            || (int) $current['winners_count'] !== (int) $d['winners_count']
            || (string) $current['rules_text'] !== (string) $d['rules_text']
            || (string) ($current['criteria_text'] ?? '') !== (string) ($d['criteria_text'] ?? '')
            || json_encode($current['conditions']) !== json_encode($d['conditions'])
            || json_encode($current['prizes'], JSON_UNESCAPED_UNICODE) !== json_encode($d['prizes'], JSON_UNESCAPED_UNICODE)
            || (string) $current['starts_at'] !== (string) $d['starts_at']
            || (string) $current['ends_at'] !== (string) $d['ends_at'];
    }

    private function announceToFollowers(array $contest): void
    {
        try {
            $ids = (new Follow())->followerIds((int) $contest['organizer_id']);
            $this->notifier->notifyMany(array_slice($ids, 0, 5000), 'new_from_followed', $contest);
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'contest.announce_failed');
        }
    }

    private function parseDate(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace('T', ' ', $raw);
        $ts = strtotime($raw);
        return $ts === false ? null : $ts;
    }

    private function uniqueSlug(string $title): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
            'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ә' => 'a', 'ғ' => 'g', 'қ' => 'k',
            'ң' => 'n', 'ө' => 'o', 'ұ' => 'u', 'ү' => 'u', 'һ' => 'h', 'і' => 'i',
        ];
        $base = strtr(mb_strtolower($title), $map);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        $base = mb_substr($base !== '' ? $base : 'contest', 0, 60);
        do {
            $slug = rtrim($base, '-') . '-' . bin2hex(random_bytes(3));
        } while ($this->contests->slugExists($slug));
        return $slug;
    }

    /** @return string|null|false имя файла, null если файла нет, false при ошибке */
    private function storeCover(?array $file): string|null|false
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $dir = dirname(__DIR__, 3) . self::COVER_DIR;
        $res = UploadHelper::storeStaffFile($file, $dir, self::COVER_EXT, self::COVER_MAX_BYTES);
        return $res['ok'] ? (string) $res['stored'] : false;
    }
}
