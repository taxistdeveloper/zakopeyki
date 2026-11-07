<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Models\ContestTracking;
use App\Models\Follow;

/**
 * Серверная проверка условий участия. Клиент никогда не является источником истины.
 *
 * Поддерживаются только условия, которые платформа может достоверно проверить:
 *  - email_verified    — email участника подтверждён;
 *  - account_age_days  — аккаунт создан не позднее чем за N дней до вступления;
 *  - follow_organizer  — подписка на организатора: при вступлении (текущее состояние) и
 *                        непрерывно до закрытия (нет события unfollow в журнале модуля);
 *  - submission        — участник отправил работу (для конкурсов с жюри).
 */
final class ContestConditionVerifier
{
    public const MET = 'met';
    public const NOT_MET = 'not_met';

    public function __construct(
        private ?ContestPolicy $policy = null,
        private ?Follow $follow = null,
        private ?ContestTracking $tracking = null,
    ) {
    }

    /**
     * Нормализованный список условий акции (с учётом обязательного подтверждения email).
     * @param list<array> $conditions
     * @return list<array{key:string, days?:int}>
     */
    public function effectiveConditions(array $conditions): array
    {
        $out = [];
        foreach ($conditions as $c) {
            $key = (string) ($c['key'] ?? '');
            if (!in_array($key, ContestPolicy::CONDITIONS, true) || isset($out[$key])) {
                continue;
            }
            $out[$key] = $key === ContestPolicy::COND_ACCOUNT_AGE
                ? ['key' => $key, 'days' => max(1, min(ContestPolicy::MAX_ACCOUNT_AGE_DAYS, (int) ($c['days'] ?? 1)))]
                : ['key' => $key];
        }
        if ($this->policy()->requireVerifiedEmail() && !isset($out[ContestPolicy::COND_EMAIL_VERIFIED])) {
            $out = [ContestPolicy::COND_EMAIL_VERIFIED => ['key' => ContestPolicy::COND_EMAIL_VERIFIED]] + $out;
        }
        return array_values($out);
    }

    /**
     * Проверка при вступлении.
     * @param list<array> $conditions
     * @return array<string, array{status:string, detail:?string}>
     */
    public function checkAtJoin(array $contest, array $conditions, array $user, ?string $submission, ?int $now = null): array
    {
        $now ??= time();
        $res = [];
        foreach ($this->effectiveConditions($conditions) as $c) {
            $res[$c['key']] = match ($c['key']) {
                ContestPolicy::COND_EMAIL_VERIFIED => $this->r(!empty($user['email_verified_at']), 'email_unverified'),
                ContestPolicy::COND_ACCOUNT_AGE => $this->accountAge($user, (int) $c['days'], $now),
                ContestPolicy::COND_FOLLOW_ORGANIZER => $this->r(
                    $this->follow()->isFollowing((int) $user['id'], (int) $contest['organizer_id']),
                    'not_following'
                ),
                ContestPolicy::COND_SUBMISSION => $this->r(trim((string) $submission) !== '', 'no_submission'),
                default => $this->r(false, 'unknown'),
            };
        }
        return $res;
    }

    /**
     * Повторная проверка при закрытии приёма (на момент ends_at).
     * @param list<array> $conditions
     * @return array<string, array{status:string, detail:?string}>
     */
    public function checkAtClose(array $contest, array $conditions, array $entry, ?array $user): array
    {
        $res = [];
        if ($user === null) {
            return ['account' => $this->r(false, 'account_deleted')];
        }
        if (!empty($user['blocked_at'])) {
            $res['account'] = $this->r(false, 'account_blocked');
        }
        $from = (string) $entry['created_at'];
        $to = (string) ($contest['ends_at'] ?? date('Y-m-d H:i:s'));

        foreach ($this->effectiveConditions($conditions) as $c) {
            $res[$c['key']] = match ($c['key']) {
                ContestPolicy::COND_EMAIL_VERIFIED => $this->r(!empty($user['email_verified_at']), 'email_unverified'),
                // Возраст аккаунта проверен при вступлении и со временем не уменьшается
                ContestPolicy::COND_ACCOUNT_AGE => $this->accountAge($user, (int) $c['days'], strtotime($from) ?: time()),
                ContestPolicy::COND_FOLLOW_ORGANIZER => $this->followContinuous($user, $contest, $from, $to),
                ContestPolicy::COND_SUBMISSION => $this->r(trim((string) ($entry['submission_text'] ?? '')) !== '', 'no_submission'),
                default => $this->r(false, 'unknown'),
            };
        }
        return $res;
    }

    /** @param array<string, array{status:string}> $results */
    public static function allMet(array $results): bool
    {
        foreach ($results as $r) {
            if (($r['status'] ?? '') !== self::MET) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, array{status:string, detail:?string}> $results */
    public static function firstFailure(array $results): ?string
    {
        foreach ($results as $key => $r) {
            if (($r['status'] ?? '') !== self::MET) {
                return (string) ($r['detail'] ?? $key);
            }
        }
        return null;
    }

    private function followContinuous(array $user, array $contest, string $from, string $to): array
    {
        $uid = (int) $user['id'];
        $orgId = (int) $contest['organizer_id'];
        if ($this->tracking()->hasEventBetween(ContestTracking::EVENT_UNFOLLOW, $uid, 'user', $orgId, $from, $to)) {
            return $this->r(false, 'unfollowed_during_contest');
        }
        return $this->r($this->follow()->isFollowing($uid, $orgId), 'not_following');
    }

    private function accountAge(array $user, int $days, int $at): array
    {
        $created = strtotime((string) ($user['created_at'] ?? '')) ?: $at;
        return $this->r(($at - $created) >= $days * 86400, 'account_too_new');
    }

    /** @return array{status:string, detail:?string} */
    private function r(bool $ok, string $failDetail): array
    {
        return ['status' => $ok ? self::MET : self::NOT_MET, 'detail' => $ok ? null : $failDetail];
    }

    private function policy(): ContestPolicy
    {
        return $this->policy ??= new ContestPolicy();
    }

    private function follow(): Follow
    {
        return $this->follow ??= new Follow();
    }

    private function tracking(): ContestTracking
    {
        return $this->tracking ??= new ContestTracking();
    }
}
