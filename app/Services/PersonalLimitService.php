<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Core\Database;

/**
 * Годовой лимит оборота для персональных аккаунтов (360 МРП).
 */
class PersonalLimitService
{
    public const ACCOUNT_PERSONAL = 'personal';
    public const ACCOUNT_BUSINESS = 'business';

    private User $users;
    private Setting $settings;

    public function __construct(?User $users = null, ?Setting $settings = null)
    {
        $this->users = $users ?? new User();
        $this->settings = $settings ?? new Setting();
        $this->users->ensureBusinessSchema();
    }

    public function mrpKzt(): int
    {
        return max(1, (int) ($this->settings->get('mrp_kzt', '3932') ?? '3932'));
    }

    public function limitMrp(): int
    {
        return max(1, (int) ($this->settings->get('personal_limit_mrp', '360') ?? '360'));
    }

    public function hardLimitKzt(): int
    {
        return $this->mrpKzt() * $this->limitMrp();
    }

    public function warningThresholdKzt(): int
    {
        $configured = (int) ($this->settings->get('personal_warning_kzt', '1100000') ?? '1100000');
        $hard = $this->hardLimitKzt();
        if ($configured <= 0) {
            return (int) floor($hard * 0.8);
        }
        // warning не выше hard limit
        return min($configured, max(1, $hard - 1));
    }

    public function currentYear(): int
    {
        return (int) date('Y');
    }

    /** @return array{ok: bool, error?: string, code?: string, snapshot?: array} */
    public function assertCanPublish(array $user): array
    {
        $snapshot = $this->snapshot($user);
        if ($snapshot['is_business']) {
            return ['ok' => true, 'snapshot' => $snapshot];
        }
        if ($snapshot['blocked']) {
            return [
                'ok' => false,
                'code' => 'limit_reached',
                'error' => t('business.err_limit_reached', [
                    'limit' => number_format($snapshot['hard_limit'], 0, '', ' '),
                    'year' => (string) $snapshot['year'],
                ]),
                'snapshot' => $snapshot,
            ];
        }
        return ['ok' => true, 'snapshot' => $snapshot];
    }

    /**
     * @return array{
     *   account_type: string,
     *   is_business: bool,
     *   year: int,
     *   turnover: int,
     *   hard_limit: int,
     *   warning_at: int,
     *   remaining: int,
     *   percent: float,
     *   blocked: bool,
     *   warning_due: bool,
     *   mrp: int,
     *   limit_mrp: int
     * }
     */
    public function snapshot(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $this->ensureYearReset($userId, $user);
            $fresh = $this->users->find($userId);
            if ($fresh) {
                $user = $fresh;
            }
        }

        $isBusiness = (($user['account_type'] ?? self::ACCOUNT_PERSONAL) === self::ACCOUNT_BUSINESS)
            && (($user['business_status'] ?? '') === 'verified');

        $year = $this->currentYear();
        $turnover = (int) ($user['personal_turnover_kzt'] ?? 0);
        $hard = $this->hardLimitKzt();
        $warningAt = $this->warningThresholdKzt();
        $remaining = max(0, $hard - $turnover);
        $percent = $hard > 0 ? min(100, round($turnover / $hard * 100, 1)) : 0.0;
        $blocked = !$isBusiness && ($turnover >= $hard || !empty($user['limit_blocked_at']));
        $warningDue = !$isBusiness && !$blocked && $turnover >= $warningAt;

        return [
            'account_type' => (string) ($user['account_type'] ?? self::ACCOUNT_PERSONAL),
            'is_business' => $isBusiness,
            'year' => $year,
            'turnover' => $turnover,
            'hard_limit' => $hard,
            'warning_at' => $warningAt,
            'remaining' => $remaining,
            'percent' => $percent,
            'blocked' => $blocked,
            'warning_due' => $warningDue,
            'mrp' => $this->mrpKzt(),
            'limit_mrp' => $this->limitMrp(),
            'business_status' => (string) ($user['business_status'] ?? 'none'),
            'business_name' => (string) ($user['business_name'] ?? ''),
            'business_entity_type' => (string) ($user['business_entity_type'] ?? ''),
        ];
    }

    public function ensureYearReset(int $userId, ?array $user = null): void
    {
        $user = $user ?? $this->users->find($userId);
        if (!$user) {
            return;
        }
        $year = $this->currentYear();
        $stored = (int) ($user['personal_limit_year'] ?? 0);
        if ($stored === $year) {
            return;
        }
        $this->users->resetPersonalLimitYear($userId, $year);
    }

    /** Начислить оборот продавцу после успешной сделки. */
    public function addTurnoverFromOrder(int $sellerId, int $orderId, int $amountKzt): void
    {
        if ($sellerId < 1 || $orderId < 1 || $amountKzt < 1) {
            return;
        }

        $user = $this->users->find($sellerId);
        if (!$user) {
            return;
        }

        $this->ensureYearReset($sellerId, $user);
        $user = $this->users->find($sellerId) ?: $user;

        if (($user['account_type'] ?? '') === self::ACCOUNT_BUSINESS
            && ($user['business_status'] ?? '') === 'verified') {
            return;
        }

        $year = $this->currentYear();
        $db = Database::connect();

        try {
            $ins = $db->prepare(
                'INSERT INTO personal_turnover_ledger (user_id, order_id, amount_kzt, year, meta)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$sellerId, $orderId, $amountKzt, $year, 'escrow_release']);
        } catch (\PDOException $e) {
            // уже учтён (unique order_id)
            return;
        }

        $newTurnover = (int) ($user['personal_turnover_kzt'] ?? 0) + $amountKzt;
        $this->users->setPersonalTurnover($sellerId, $newTurnover, $year);

        $fresh = $this->users->find($sellerId) ?: $user;
        $fresh['personal_turnover_kzt'] = $newTurnover;
        $this->evaluateThresholds($fresh);
    }

    public function evaluateThresholds(array $user): void
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return;
        }
        if (($user['account_type'] ?? '') === self::ACCOUNT_BUSINESS
            && ($user['business_status'] ?? '') === 'verified') {
            return;
        }

        $turnover = (int) ($user['personal_turnover_kzt'] ?? 0);
        $hard = $this->hardLimitKzt();
        $warningAt = $this->warningThresholdKzt();
        $n = new Notification();

        if ($turnover >= $hard) {
            if (empty($user['limit_blocked_at'])) {
                $this->users->setLimitBlocked($userId, true);
                $n->createFor($userId, t('business.notify_limit_blocked', [
                    'limit' => number_format($hard, 0, '', ' '),
                ]));
            }
            return;
        }

        if ($turnover >= $warningAt && empty($user['limit_warning_sent_at'])) {
            $this->users->setLimitWarningSent($userId);
            $n->createFor($userId, t('business.notify_limit_warning', [
                'amount' => number_format($turnover, 0, '', ' '),
                'limit' => number_format($hard, 0, '', ' '),
            ]));
        }
    }

    /** Сброс всех персональных лимитов на новый год (cron). */
    public function resetAllForNewYear(?int $year = null): int
    {
        $year = $year ?? $this->currentYear();
        return $this->users->resetAllPersonalLimitsBeforeYear($year);
    }
}
