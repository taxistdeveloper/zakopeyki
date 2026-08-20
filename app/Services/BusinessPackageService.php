<?php

namespace App\Services;

use App\Helpers\ActivityLogger;
use App\Models\BusinessPackage;
use App\Models\BusinessSubscription;
use App\Models\Notification;
use App\Models\User;
use App\Models\Wallet;

class BusinessPackageService
{
    public const DEFAULT_MAX_PHOTOS = 3;
    public const PACKAGE_MAX_PHOTOS = 10;

    private User $users;
    private BusinessPackage $packages;
    private BusinessSubscription $subscriptions;

    public function __construct(
        ?User $users = null,
        ?BusinessPackage $packages = null,
        ?BusinessSubscription $subscriptions = null
    ) {
        $this->users = $users ?? new User();
        $this->packages = $packages ?? new BusinessPackage();
        $this->subscriptions = $subscriptions ?? new BusinessSubscription();
        $this->users->ensureBusinessSchema();
        $this->packages->ensureSchema();
        $this->subscriptions->ensureSchema();
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

    public function maxPhotosForUser(int $userId): int
    {
        $sub = $this->activeSubscription($userId);
        if (!$sub) {
            return self::DEFAULT_MAX_PHOTOS;
        }
        $pkg = $this->packages->find((int) $sub['package_id']);
        $max = (int) ($pkg['max_photos'] ?? self::PACKAGE_MAX_PHOTOS);
        return max(self::DEFAULT_MAX_PHOTOS, $max);
    }

    public function freeServiceListing(int $userId): bool
    {
        $sub = $this->activeSubscription($userId);
        if (!$sub) {
            return false;
        }
        $pkg = $this->packages->find((int) $sub['package_id']);
        return !empty($pkg['free_service_listing']);
    }

    public function hasPriorityBoost(int $userId): bool
    {
        $sub = $this->activeSubscription($userId);
        if (!$sub) {
            return false;
        }
        $pkg = $this->packages->find((int) $sub['package_id']);
        return !empty($pkg['priority_boost']);
    }

    /** @return list<array> */
    public function catalog(): array
    {
        return $this->packages->activeAll();
    }

    public function findPackage(int $id): ?array
    {
        return $this->packages->find($id);
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
        $baseStart = $now;
        if ($existing && strtotime((string) $existing['ends_at']) > $now) {
            $baseStart = strtotime((string) $existing['ends_at']);
        }
        $starts = date('Y-m-d H:i:s', $existing && strtotime((string) $existing['ends_at']) > $now
            ? strtotime((string) $existing['starts_at'])
            : $now);
        $ends = date('Y-m-d H:i:s', $baseStart + ($days * 86400));

        $charge = $wallet->chargeBusinessPackage($userId, $price, $packageId);
        if (!$charge['ok']) {
            return ['ok' => false, 'error' => $charge['error'] ?? t('wallet.op_failed')];
        }

        if ($existing) {
            $this->subscriptions->updateWindow((int) $existing['id'], $starts, $ends);
            $subId = (int) $existing['id'];
            // update package_id / price on renew
            $db = \App\Core\Database::connect();
            $upd = $db->prepare(
                'UPDATE business_subscriptions SET package_id = ?, price_paid_kzt = price_paid_kzt + ?, payment_meta = ? WHERE id = ?'
            );
            $upd->execute([$packageId, $price, 'wallet', $subId]);
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
}
