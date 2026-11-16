<?php

declare(strict_types=1);

namespace App\Services\TryOn;

use App\Core\Database;
use App\Models\TryOnJob;

/**
 * Лимиты пользователя и системы, месячный бюджет. Агрегаты считаются по tryon_jobs
 * (отдельная таблица usage не нужна — docs/tryon/03-database.md §2).
 */
final class TryOnUsageService
{
    /** @var callable */
    private $clock;

    public function __construct(
        private readonly TryOnSettings $settings,
        private readonly TryOnJob $jobs,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{daily_left:int,monthly_left:int,active_jobs:int,max_active_jobs:int} */
    public function remaining(int $userId): array
    {
        $now = ($this->clock)();
        $daily = $this->jobs->countChargeableForUserSince($userId, date('Y-m-d 00:00:00', $now));
        $monthly = $this->jobs->countChargeableForUserSince($userId, date('Y-m-01 00:00:00', $now));

        return [
            'daily_left' => max(0, $this->settings->int('tryon_user_daily_limit') - $daily),
            'monthly_left' => max(0, $this->settings->int('tryon_user_monthly_limit') - $monthly),
            'active_jobs' => $this->jobs->countActiveForUser($userId),
            'max_active_jobs' => max(1, $this->settings->int('tryon_user_max_active')),
        ];
    }

    /** @throws TryOnException */
    public function assertUserCanCreate(int $userId): void
    {
        $r = $this->remaining($userId);
        $now = ($this->clock)();
        if ($r['active_jobs'] >= $r['max_active_jobs']) {
            throw new TryOnException('active_job_exists', 429, ['retry_after' => 10]);
        }
        if ($r['daily_left'] <= 0) {
            throw new TryOnException('daily_limit', 429, ['retry_after' => strtotime('tomorrow', $now) - $now]);
        }
        if ($r['monthly_left'] <= 0) {
            throw new TryOnException('monthly_limit', 429, ['retry_after' => strtotime('first day of next month 00:00', $now) - $now]);
        }
    }

    public function monthSpendMicros(): int
    {
        return $this->jobs->sumSpendSince(date('Y-m-01 00:00:00', ($this->clock)()));
    }

    /**
     * Выполнить $fn под глобальной блокировкой бюджета, если резерв $estimateMicros помещается в бюджет.
     * @template T
     * @param callable():T $fn
     * @return T
     * @throws TryOnException budget_exhausted | provider_unavailable
     */
    public function withBudgetReservation(int $estimateMicros, callable $fn): mixed
    {
        $db = Database::connect();
        $lock = $db->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute(['tryon_budget']);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new TryOnException('provider_unavailable', 503, ['retry_after' => 5]);
        }
        try {
            if ($estimateMicros > 0 && $this->monthSpendMicros() + $estimateMicros > $this->settings->monthlyBudgetMicros()) {
                throw new TryOnException('budget_exhausted', 503);
            }

            return $fn();
        } finally {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute(['tryon_budget']);
        }
    }
}
