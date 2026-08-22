<?php

namespace App\Services;

use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\BusinessPackage;
use App\Models\BusinessSubscription;
use App\Models\BusinessUsage;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;

class BusinessPackageService
{
    public const DEFAULT_MAX_PHOTOS = 3;
    public const PACKAGE_MAX_PHOTOS = 10;

    public const METRIC_LISTINGS_DAY = 'listings_day';
    public const METRIC_AI_INFOGRAPHIC = 'ai_infographic';
    public const METRIC_AI_COPY = 'ai_copy';
    public const METRIC_AI_OPTIMIZE = 'ai_optimize';
    public const METRIC_AI_TRYON = 'ai_tryon';
    public const METRIC_BOOSTS = 'boosts';

    private User $users;
    private BusinessPackage $packages;
    private BusinessSubscription $subscriptions;
    private BusinessUsage $usage;

    public function __construct(
        ?User $users = null,
        ?BusinessPackage $packages = null,
        ?BusinessSubscription $subscriptions = null,
        ?BusinessUsage $usage = null
    ) {
        $this->users = $users ?? new User();
        $this->packages = $packages ?? new BusinessPackage();
        $this->subscriptions = $subscriptions ?? new BusinessSubscription();
        $this->usage = $usage ?? new BusinessUsage();
        $this->users->ensureBusinessSchema();
        $this->packages->ensureSchema();
        $this->subscriptions->ensureSchema();
        $this->usage->ensureSchema();
    }

    public function isBusinessVerified(array $user): bool
    {
        return ($user['account_type'] ?? '') === 'business'
            && ($user['business_status'] ?? '') === 'verified';
    }

    public function activeSubscription(int $userId): ?array
    {
        $this->subscriptions->expireOverdue();
        return $this->subscriptions->activeForUser($userId);
    }

    public function hasActivePackage(int $userId): bool
    {
        return $this->activeSubscription($userId) !== null;
    }

    /** @return array<string, int> */
    public function effectiveLimits(int $userId): array
    {
        $sub = $this->activeSubscription($userId);
        if (!$sub) {
            return [];
        }
        $base = BusinessPackage::decodeLimits($sub['limits_json'] ?? null);
        if ($base === []) {
            $base = (($sub['package_kind'] ?? '') === BusinessPackage::KIND_TRIAL)
                ? BusinessPackage::trialLimits()
                : BusinessPackage::paidLimits();
        }
        $base['catalog'] = (int) ($base['catalog'] ?? 0) + (int) ($sub['extra_catalog'] ?? 0);
        $base['staff'] = (int) ($base['staff'] ?? 0) + (int) ($sub['extra_staff'] ?? 0);
        $base['ai_infographic'] = (int) ($base['ai_infographic'] ?? 0) + (int) ($sub['extra_ai_infographic'] ?? 0);
        $base['ai_tryon'] = (int) ($base['ai_tryon'] ?? 0) + (int) ($sub['extra_ai_tryon'] ?? 0);
        return $base;
    }

    public function maxPhotosForUser(int $userId): int
    {
        if (!$this->hasActivePackage($userId)) {
            return self::DEFAULT_MAX_PHOTOS;
        }
        $sub = $this->activeSubscription($userId);
        $max = (int) ($sub['max_photos'] ?? self::PACKAGE_MAX_PHOTOS);
        return max(self::DEFAULT_MAX_PHOTOS, $max);
    }

    public function freeServiceListing(int $userId): bool
    {
        $sub = $this->activeSubscription($userId);
        return $sub !== null && !empty($sub['free_service_listing']);
    }

    public function hasPriorityBoost(int $userId): bool
    {
        $sub = $this->activeSubscription($userId);
        return $sub !== null && !empty($sub['priority_boost']);
    }

    /** @return array{ok: bool, error?: string} */
    public function assertCanCreateListing(int $userId): array
    {
        $sub = $this->activeSubscription($userId);
        if (!$sub) {
            return ['ok' => true];
        }
        $limits = $this->effectiveLimits($userId);
        $catalogCap = (int) ($limits['catalog'] ?? 0);
        if ($catalogCap > 0) {
            $count = (new Product())->countByUser($userId);
            if ($count >= $catalogCap) {
                return ['ok' => false, 'error' => t('business.err_catalog_cap', [
                    'n' => number_format($catalogCap, 0, '', ' '),
                ])];
            }
        }
        $dayCap = (int) ($limits['listings_per_day'] ?? 0);
        if ($dayCap > 0) {
            $today = (new Product())->countCreatedToday($userId);
            if ($today >= $dayCap) {
                return ['ok' => false, 'error' => t('business.err_listings_day', [
                    'n' => number_format($dayCap, 0, '', ' '),
                ])];
            }
        }
        return ['ok' => true];
    }

    public function consumeListing(int $userId): void
    {
        if (!$this->hasActivePackage($userId)) {
            return;
        }
        $this->usage->increment($userId, self::METRIC_LISTINGS_DAY, date('Y-m-d'));
    }

    /**
     * Списать месячную квоту AI / поднятий.
     * @return array{ok: bool, error?: string, remaining?: int}
     */
    public function consumeQuota(int $userId, string $metric, int $by = 1): array
    {
        $limits = $this->effectiveLimits($userId);
        if ($limits === []) {
            return ['ok' => false, 'error' => t('business.err_need_package')];
        }
        $map = [
            self::METRIC_AI_INFOGRAPHIC => 'ai_infographic',
            self::METRIC_AI_COPY => 'ai_copy',
            self::METRIC_AI_OPTIMIZE => 'ai_optimize',
            self::METRIC_AI_TRYON => 'ai_tryon',
            self::METRIC_BOOSTS => 'boosts',
        ];
        $limitKey = $map[$metric] ?? null;
        if ($limitKey === null) {
            return ['ok' => false, 'error' => t('business.err_generic')];
        }
        $cap = (int) ($limits[$limitKey] ?? 0);
        $period = date('Y-m');
        $used = $this->usage->get($userId, $metric, $period);
        if ($used + $by > $cap) {
            return ['ok' => false, 'error' => t('business.err_quota', [
                'left' => (string) max(0, $cap - $used),
            ])];
        }
        $new = $this->usage->increment($userId, $metric, $period, $by);
        return ['ok' => true, 'remaining' => max(0, $cap - $new)];
    }

    /** @return list<array{metric: string, used: int, cap: int}> */
    public function usageSnapshot(int $userId): array
    {
        $limits = $this->effectiveLimits($userId);
        if ($limits === []) {
            return [];
        }
        $month = $this->usage->forPeriod($userId, date('Y-m'));
        $day = $this->usage->forPeriod($userId, date('Y-m-d'));
        $catalogUsed = (new Product())->countByUser($userId);
        $rows = [
            ['key' => 'catalog', 'used' => $catalogUsed, 'cap' => (int) ($limits['catalog'] ?? 0)],
            ['key' => 'listings_per_day', 'used' => $day[self::METRIC_LISTINGS_DAY] ?? (new Product())->countCreatedToday($userId), 'cap' => (int) ($limits['listings_per_day'] ?? 0)],
            ['key' => 'ai_infographic', 'used' => $month[self::METRIC_AI_INFOGRAPHIC] ?? 0, 'cap' => (int) ($limits['ai_infographic'] ?? 0)],
            ['key' => 'ai_copy', 'used' => $month[self::METRIC_AI_COPY] ?? 0, 'cap' => (int) ($limits['ai_copy'] ?? 0)],
            ['key' => 'ai_optimize', 'used' => $month[self::METRIC_AI_OPTIMIZE] ?? 0, 'cap' => (int) ($limits['ai_optimize'] ?? 0)],
            ['key' => 'ai_tryon', 'used' => $month[self::METRIC_AI_TRYON] ?? 0, 'cap' => (int) ($limits['ai_tryon'] ?? 0)],
            ['key' => 'boosts', 'used' => $month[self::METRIC_BOOSTS] ?? 0, 'cap' => (int) ($limits['boosts'] ?? 0)],
            ['key' => 'staff', 'used' => 1, 'cap' => (int) ($limits['staff'] ?? 0)],
        ];
        return $rows;
    }

    /** @return list<array> */
    public function catalog(): array
    {
        return $this->packages->activePlans();
    }

    /** @return list<array> */
    public function addons(): array
    {
        return $this->packages->activeAddons();
    }

    public function findPackage(int $id): ?array
    {
        return $this->packages->find($id);
    }

    /** 7 дней Business новым верифицированным продавцам. */
    public function grantTrialIfEligible(int $userId): bool
    {
        if ($this->subscriptions->hadAnyForUser($userId)) {
            return false;
        }
        $trial = $this->packages->findBySlug('business-trial');
        if (!$trial) {
            return false;
        }
        $starts = date('Y-m-d H:i:s');
        $ends = date('Y-m-d H:i:s', time() + 7 * 86400);
        $this->subscriptions->createSubscription([
            'user_id' => $userId,
            'package_id' => (int) $trial['id'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'price_paid_kzt' => 0,
            'payment_meta' => 'trial',
        ]);
        (new Notification())->createFor($userId, t('business.notify_trial', [
            'until' => date('d.m.Y', strtotime($ends)),
        ]));
        return true;
    }

    /**
     * @return array{ok: bool, error?: string, subscription_id?: int}
     */
    public function purchase(int $userId, int $packageId): array
    {
        $user = $this->users->find($userId);
        if (!$user) {
            return ['ok' => false, 'error' => t('business.err_user')];
        }
        if (!$this->isBusinessVerified($user)) {
            return ['ok' => false, 'error' => t('business.err_package_business_only')];
        }

        $package = $this->packages->find($packageId);
        if (!$package || empty($package['is_active'])) {
            return ['ok' => false, 'error' => t('business.err_package')];
        }

        $kind = (string) ($package['kind'] ?? BusinessPackage::KIND_PLAN);
        if ($kind === BusinessPackage::KIND_ADDON) {
            return $this->purchaseAddon($userId, $package);
        }
        if ($kind !== BusinessPackage::KIND_PLAN) {
            return ['ok' => false, 'error' => t('business.err_package')];
        }

        $price = (int) $package['price_kzt'];
        $days = max(1, (int) $package['duration_days']);
        $wallet = new Wallet();
        if ($wallet->balance($userId) < $price) {
            return ['ok' => false, 'error' => t('business.err_package_balance', [
                'amount' => Wallet::formatMoney($price),
            ])];
        }

        $existing = $this->activeSubscription($userId);
        $now = time();
        $isTrial = $existing && (($existing['package_kind'] ?? '') === BusinessPackage::KIND_TRIAL
            || ($existing['package_slug'] ?? '') === 'business-trial'
            || ($existing['payment_meta'] ?? '') === 'trial');

        if ($isTrial) {
            $baseStart = $now;
            $starts = date('Y-m-d H:i:s', $now);
        } elseif ($existing && strtotime((string) $existing['ends_at']) > $now) {
            $baseStart = strtotime((string) $existing['ends_at']);
            $starts = (string) $existing['starts_at'];
        } else {
            $baseStart = $now;
            $starts = date('Y-m-d H:i:s', $now);
        }
        $ends = date('Y-m-d H:i:s', $baseStart + ($days * 86400));

        $charge = $wallet->chargeBusinessPackage($userId, $price, $packageId);
        if (!$charge['ok']) {
            return ['ok' => false, 'error' => $charge['error'] ?? t('wallet.op_failed')];
        }

        if ($existing) {
            $this->subscriptions->updateWindow((int) $existing['id'], $starts, $ends);
            $subId = (int) $existing['id'];
            $db = Database::connect();
            $upd = $db->prepare(
                "UPDATE business_subscriptions SET package_id = ?, price_paid_kzt = price_paid_kzt + ?, payment_meta = 'wallet' WHERE id = ?"
            );
            $upd->execute([$packageId, $price, $subId]);
        } else {
            $subId = $this->subscriptions->createSubscription([
                'user_id' => $userId,
                'package_id' => $packageId,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'price_paid_kzt' => $price,
                'payment_meta' => 'wallet',
            ]);
        }

        (new Notification())->createFor($userId, t('business.notify_package_activated', [
            'name' => (string) $package['name'],
            'until' => date('d.m.Y', strtotime($ends)),
        ]));

        ActivityLogger::info('business.package_purchase', 'Подключён бизнес-пакет', 'business_subscription', $subId, [
            'package_id' => $packageId,
            'price' => $price,
        ]);

        return ['ok' => true, 'subscription_id' => $subId];
    }

    /**
     * @param array<string, mixed> $package
     * @return array{ok: bool, error?: string, subscription_id?: int}
     */
    private function purchaseAddon(int $userId, array $package): array
    {
        $existing = $this->activeSubscription($userId);
        if (!$existing) {
            return ['ok' => false, 'error' => t('business.err_addon_need_plan')];
        }

        $price = (int) $package['price_kzt'];
        $wallet = new Wallet();
        if ($wallet->balance($userId) < $price) {
            return ['ok' => false, 'error' => t('business.err_package_balance', [
                'amount' => Wallet::formatMoney($price),
            ])];
        }

        $charge = $wallet->chargeBusinessPackage($userId, $price, (int) $package['id']);
        if (!$charge['ok']) {
            return ['ok' => false, 'error' => $charge['error'] ?? t('wallet.op_failed')];
        }

        $limits = BusinessPackage::decodeLimits($package['limits_json'] ?? null);
        $this->subscriptions->addExtras(
            (int) $existing['id'],
            (int) ($limits['extra_catalog'] ?? 0),
            (int) ($limits['extra_staff'] ?? 0),
            (int) ($limits['extra_ai_infographic'] ?? 0),
            (int) ($limits['extra_ai_tryon'] ?? 0)
        );

        $days = (int) ($package['duration_days'] ?? 0);
        if ($days > 0 && ($package['billing'] ?? '') === BusinessPackage::BILLING_PERIOD) {
            $endTs = strtotime((string) $existing['ends_at']);
            $minEnd = time() + ($days * 86400);
            if ($endTs < $minEnd) {
                $this->subscriptions->updateWindow(
                    (int) $existing['id'],
                    (string) $existing['starts_at'],
                    date('Y-m-d H:i:s', $minEnd)
                );
            }
        }

        (new Notification())->createFor($userId, t('business.notify_addon', [
            'name' => (string) $package['name'],
        ]));

        ActivityLogger::info('business.addon_purchase', 'Докуплен слот бизнес-пакета', 'business_subscription', (int) $existing['id'], [
            'package_id' => (int) $package['id'],
            'price' => $price,
        ]);

        return ['ok' => true, 'subscription_id' => (int) $existing['id']];
    }
}
