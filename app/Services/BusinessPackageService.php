<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Models\BusinessPackage;
use App\Models\BusinessPackagePayment;
use App\Models\BusinessSubscription;
use App\Models\BusinessUsage;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Business\BusinessAiOperationService;
use App\Services\Business\BusinessPackagePolicy;
use Throwable;

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
    private BusinessPackagePayment $payments;
    private Setting $settings;

    public function __construct(
        ?User $users = null,
        ?BusinessPackage $packages = null,
        ?BusinessSubscription $subscriptions = null,
        ?BusinessUsage $usage = null,
        ?BusinessPackagePayment $payments = null,
        ?Setting $settings = null
    ) {
        $this->users = $users ?? new User();
        $this->packages = $packages ?? new BusinessPackage();
        $this->subscriptions = $subscriptions ?? new BusinessSubscription();
        $this->usage = $usage ?? new BusinessUsage();
        $this->payments = $payments ?? new BusinessPackagePayment();
        $this->settings = $settings ?? new Setting();
        $this->users->ensureBusinessSchema();
        $this->packages->ensureSchema();
        $this->subscriptions->ensureSchema();
        $this->usage->ensureSchema();
        $this->payments->ensureSchema();
    }

    public function isBusinessVerified(array $user): bool
    {
        return ($user['account_type'] ?? '') === 'business'
            && ($user['business_status'] ?? '') === 'verified';
    }

    public function isSalesEnabled(): bool
    {
        try {
            $this->settings->forget(BusinessPackagePolicy::SETTING_SALES);

            return BusinessPackagePolicy::resolveFlag(
                $this->settings->getBool(BusinessPackagePolicy::SETTING_SALES, null),
                true
            );
        } catch (Throwable) {
            return true;
        }
    }

    public function isFeaturesEnabled(): bool
    {
        try {
            $this->settings->forget(BusinessPackagePolicy::SETTING_FEATURES);

            return BusinessPackagePolicy::resolveFlag(
                $this->settings->getBool(BusinessPackagePolicy::SETTING_FEATURES, null),
                true
            );
        } catch (Throwable) {
            return true;
        }
    }

    public function setSalesEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!BusinessPackagePolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning('business.sales_toggle_denied', 'Нет права переключать продажу бизнес-пакета', 'settings', null, [
                'actor_id' => $actorId,
                'role' => $role,
            ]);

            return false;
        }

        try {
            $before = $this->isSalesEnabled();
            if ($before === $enabled) {
                // Повторное сохранение того же состояния — без дубля аудита
                return true;
            }
            $this->settings->setBool(BusinessPackagePolicy::SETTING_SALES, $enabled);
            $audit = BusinessPackagePolicy::salesToggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Продажа бизнес-пакета включена' : 'Продажа бизнес-пакета выключена',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function setFeaturesEnabled(bool $enabled): bool
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!BusinessPackagePolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning('business.features_toggle_denied', 'Нет права переключать преимущества бизнес-пакета', 'settings', null, [
                'actor_id' => $actorId,
                'role' => $role,
            ]);

            return false;
        }

        try {
            $before = $this->isFeaturesEnabled();
            if ($before === $enabled) {
                return true;
            }
            $this->settings->setBool(BusinessPackagePolicy::SETTING_FEATURES, $enabled);
            $audit = BusinessPackagePolicy::featuresToggleAudit($actorId, $before, $enabled);
            ActivityLogger::info(
                $audit['action'],
                $enabled ? 'Преимущества бизнес-пакета включены' : 'Преимущества бизнес-пакета выключены',
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Продажа конкретного AI-аддона (по slug). Default off.
     * Не влияет на assertFeature / использование уже купленных extras.
     */
    public function isAiAddonSalesEnabledForSlug(string $slug): bool
    {
        if (!BusinessPackagePolicy::isAiAddonSlug($slug)) {
            return false;
        }
        try {
            $key = BusinessPackagePolicy::aiAddonSalesSettingKey($slug);
            if ($key === null) {
                return false;
            }
            $this->settings->forget($key);
            $stored = $this->settings->getBool($key, null);
            if ($stored !== null) {
                return BusinessPackagePolicy::resolveFlag($stored, false);
            }
            // Fallback на legacy общий флаг VAS-15.3
            $this->settings->forget(BusinessPackagePolicy::SETTING_AI_ADDON_SALES);

            return BusinessPackagePolicy::resolveFlag(
                $this->settings->getBool(BusinessPackagePolicy::SETTING_AI_ADDON_SALES, null),
                false
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, bool> slug => enabled */
    public function aiAddonSalesFlags(): array
    {
        $out = [];
        foreach (BusinessPackagePolicy::aiAddonSlugs() as $slug) {
            $out[$slug] = $this->isAiAddonSalesEnabledForSlug($slug);
        }

        return $out;
    }

    /** Хотя бы один AI-аддон в продаже (совместимость API/профиля). */
    public function isAiAddonSalesEnabled(): bool
    {
        foreach ($this->aiAddonSalesFlags() as $on) {
            if ($on) {
                return true;
            }
        }

        return false;
    }

    public function setAiAddonSalesEnabledForSlug(string $slug, bool $enabled): bool
    {
        if (!BusinessPackagePolicy::isAiAddonSlug($slug)) {
            return false;
        }
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!BusinessPackagePolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning('business.ai_addon_sales_toggle_denied', 'Нет права переключать продажу AI-аддона', 'settings', null, [
                'actor_id' => $actorId,
                'role' => $role,
                'slug' => $slug,
            ]);

            return false;
        }

        $key = BusinessPackagePolicy::aiAddonSalesSettingKey($slug);
        if ($key === null) {
            return false;
        }

        try {
            $before = $this->isAiAddonSalesEnabledForSlug($slug);
            if ($before === $enabled) {
                return true;
            }
            $this->settings->setBool($key, $enabled);
            $audit = BusinessPackagePolicy::aiAddonSalesToggleAudit($actorId, $before, $enabled, $slug);
            ActivityLogger::info(
                $audit['action'],
                $enabled
                    ? 'Продажа AI-аддона включена: ' . $slug
                    : 'Продажа AI-аддона выключена: ' . $slug,
                'settings',
                null,
                $audit['context']
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @deprecated VAS-15.5: используйте setAiAddonSalesEnabledForSlug. Оставляет оба продукта в одном состоянии.
     */
    public function setAiAddonSalesEnabled(bool $enabled): bool
    {
        $ok = true;
        foreach (BusinessPackagePolicy::aiAddonSlugs() as $slug) {
            if (!$this->setAiAddonSalesEnabledForSlug($slug, $enabled)) {
                $ok = false;
            }
        }

        return $ok;
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

    /**
     * Серверная проверка преимущества пакета.
     *
     * @return array{ok: bool, error?: string, code?: string, subscription?: array, limits?: array<string, int>}
     */
    public function assertFeature(int $userId, string $feature): array
    {
        if (!$this->isFeaturesEnabled()) {
            return [
                'ok' => false,
                'code' => 'features_disabled',
                'error' => t('business.err_features_disabled'),
            ];
        }

        $sub = $this->activeSubscription($userId);
        if ($sub === null) {
            return [
                'ok' => false,
                'code' => 'need_package',
                'error' => t('business.err_need_package'),
            ];
        }

        $limits = $this->effectiveLimits($userId);

        if ($feature === BusinessPackagePolicy::FEATURE_IMPORT_API) {
            if ((int) ($limits['api'] ?? 0) < 1) {
                return [
                    'ok' => false,
                    'code' => 'api_not_included',
                    'error' => t('business.err_api_not_included'),
                ];
            }
        }

        return [
            'ok' => true,
            'subscription' => $sub,
            'limits' => $limits,
        ];
    }

    /**
     * Запуск AI-операции с учётом квоты (резерв/commit/release).
     * Без подключённого генератора — feature_not_implemented, без фиктивного success и без списания.
     *
     * @param callable|null $executor тестовый/будущий генератор
     * @return array{ok: bool, error?: string, code?: string, remaining?: int, operation_id?: int, operation_key?: string, status?: string, idempotent?: bool, result?: array}
     */
    public function invokeGeneratorContract(int $userId, string $feature, ?string $operationKey = null, ?callable $executor = null): array
    {
        return (new BusinessAiOperationService($this))->run($userId, $feature, $operationKey, $executor);
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

    /**
     * VAS-16: бесплатная публикация «Услуги» отключена (нет квоты / waiver).
     * Колонка free_service_listing в пакете сохранена; на fee не влияет.
     */
    public function freeServiceListing(int $userId): bool
    {
        unset($userId);

        return false;
    }

    public function hasPriorityBoost(int $userId): bool
    {
        $sub = $this->activeSubscription($userId);

        return $sub !== null && !empty($sub['priority_boost']);
    }

    /**
     * Лимиты каталога пакета (если утверждены в limits_json).
     * Без активной подписки не вводит искусственный кап числа лотов.
     * Общеплатформенные ограничения (AML, правила публикации и т.д.) не снимаются здесь.
     *
     * @return array{ok: bool, error?: string}
     */
    public function assertCanCreateListing(int $userId): array
    {
        $sub = $this->activeSubscription($userId);
        if (!$sub) {
            return ['ok' => true];
        }

        if (!$this->isFeaturesEnabled()) {
            // Оплаченный период сохраняется; создание лотов через обычный профиль не блокируем
            // отключением «преимуществ» (импорт/AI). Кап каталога пакета при features off не применяем как льготу.
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
     * @return array{ok: bool, error?: string, code?: string, remaining?: int}
     */
    public function consumeQuota(int $userId, string $metric, int $by = 1): array
    {
        $featureMap = [
            self::METRIC_AI_INFOGRAPHIC => BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC,
            self::METRIC_AI_COPY => BusinessPackagePolicy::FEATURE_AI_SEO,
            self::METRIC_AI_OPTIMIZE => BusinessPackagePolicy::FEATURE_AI_SEO,
            self::METRIC_AI_TRYON => BusinessPackagePolicy::FEATURE_AI_TRYON,
        ];
        if (isset($featureMap[$metric])) {
            $gate = $this->assertFeature($userId, $featureMap[$metric]);
            if (!$gate['ok']) {
                return $gate;
            }
        } elseif (!$this->hasActivePackage($userId) || !$this->isFeaturesEnabled()) {
            return ['ok' => false, 'code' => 'need_package', 'error' => t('business.err_need_package')];
        }

        $limits = $this->effectiveLimits($userId);
        if ($limits === []) {
            return ['ok' => false, 'code' => 'need_package', 'error' => t('business.err_need_package')];
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
        if (!$this->usage->tryReserve($userId, $metric, $period, $cap, $by)) {
            $used = $this->usage->get($userId, $metric, $period);

            return ['ok' => false, 'code' => 'quota_exceeded', 'error' => t('business.err_quota', [
                'left' => (string) max(0, $cap - $used),
            ])];
        }
        $new = $this->usage->get($userId, $metric, $period);

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

        return [
            ['key' => 'catalog', 'used' => $catalogUsed, 'cap' => (int) ($limits['catalog'] ?? 0)],
            ['key' => 'listings_per_day', 'used' => $day[self::METRIC_LISTINGS_DAY] ?? (new Product())->countCreatedToday($userId), 'cap' => (int) ($limits['listings_per_day'] ?? 0)],
            ['key' => 'ai_infographic', 'used' => $month[self::METRIC_AI_INFOGRAPHIC] ?? 0, 'cap' => (int) ($limits['ai_infographic'] ?? 0)],
            ['key' => 'ai_copy', 'used' => $month[self::METRIC_AI_COPY] ?? 0, 'cap' => (int) ($limits['ai_copy'] ?? 0)],
            ['key' => 'ai_optimize', 'used' => $month[self::METRIC_AI_OPTIMIZE] ?? 0, 'cap' => (int) ($limits['ai_optimize'] ?? 0)],
            ['key' => 'ai_tryon', 'used' => $month[self::METRIC_AI_TRYON] ?? 0, 'cap' => (int) ($limits['ai_tryon'] ?? 0)],
            ['key' => 'boosts', 'used' => $month[self::METRIC_BOOSTS] ?? 0, 'cap' => (int) ($limits['boosts'] ?? 0)],
            ['key' => 'staff', 'used' => 1, 'cap' => (int) ($limits['staff'] ?? 0)],
        ];
    }

    /**
     * Снимок AI-квот для UI: лимит тарифа, докупленные extras, used/remaining с сервера.
     * Расход единый (base+extra); раздельно показываются только начисленные пулы.
     *
     * @return list<array{
     *   feature: string,
     *   metric: string,
     *   label_key: string,
     *   package_cap: int,
     *   extra: int,
     *   used: int,
     *   remaining: int,
     *   generator_ready: bool
     * }>
     */
    public function aiQuotaSnapshot(int $userId): array
    {
        $sub = $this->activeSubscription($userId);
        if ($sub === null) {
            return [];
        }

        $base = BusinessPackage::decodeLimits($sub['limits_json'] ?? null);
        if ($base === []) {
            $base = (($sub['package_kind'] ?? '') === BusinessPackage::KIND_TRIAL)
                ? BusinessPackage::trialLimits()
                : BusinessPackage::paidLimits();
        }

        $month = $this->usage->forPeriod($userId, date('Y-m'));
        $rows = [
            [
                'feature' => BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC,
                'metric' => self::METRIC_AI_INFOGRAPHIC,
                'label_key' => 'lim_ai_infographic',
                'package_cap' => (int) ($base['ai_infographic'] ?? 0),
                'extra' => (int) ($sub['extra_ai_infographic'] ?? 0),
            ],
            [
                'feature' => BusinessPackagePolicy::FEATURE_AI_TRYON,
                'metric' => self::METRIC_AI_TRYON,
                'label_key' => 'lim_ai_tryon',
                'package_cap' => (int) ($base['ai_tryon'] ?? 0),
                'extra' => (int) ($sub['extra_ai_tryon'] ?? 0),
            ],
        ];

        $out = [];
        foreach ($rows as $row) {
            $used = (int) ($month[$row['metric']] ?? 0);
            $cap = $row['package_cap'] + $row['extra'];
            $out[] = [
                'feature' => $row['feature'],
                'metric' => $row['metric'],
                'label_key' => $row['label_key'],
                'package_cap' => $row['package_cap'],
                'extra' => $row['extra'],
                'used' => $used,
                'remaining' => max(0, $cap - $used),
                'generator_ready' => !BusinessPackagePolicy::isUnimplementedGenerator($row['feature']),
            ];
        }

        return $out;
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

    /** @return list<array> AI-аддоны из активного каталога (цена/units с сервера). */
    public function aiAddons(): array
    {
        $out = [];
        foreach ($this->packages->activeAddons() as $addon) {
            $slug = (string) ($addon['slug'] ?? '');
            if (!BusinessPackagePolicy::isAiAddonSlug($slug)) {
                continue;
            }
            $spec = BusinessPackagePolicy::aiAddonSpec($slug);
            if ($spec === null) {
                continue;
            }
            // Цена и units только из утверждённого каталога (не из клиентского ввода)
            $addon['price_kzt'] = (int) $spec['price_kzt'];
            $addon['units'] = (int) ($spec['extra_ai_infographic'] ?? $spec['extra_ai_tryon'] ?? 0);
            $addon['feature'] = (string) ($spec['feature'] ?? '');
            $out[] = $addon;
        }

        return $out;
    }

    public function findPackage(int $id): ?array
    {
        return $this->packages->find($id);
    }

    /** @return list<array> */
    public function paymentHistory(int $userId, int $limit = 50): array
    {
        return $this->payments->forUser($userId, $limit);
    }

    /**
     * 7 дней Business новым верифицированным продавцам.
     * При sales=off новая активация (включая trial) не выдаётся — без удаления чужих подписок.
     */
    public function grantTrialIfEligible(int $userId): bool
    {
        if (!$this->isSalesEnabled()) {
            ActivityLogger::info('business.trial_skipped_sales_off', 'Trial не выдан: продажа пакета выключена', 'user', $userId);

            return false;
        }
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
     * Покупка/продление с кошелька. Цена и срок только из business_packages.
     * Идемпотентность: уникальный idempotency_key + GET_LOCK по user_id.
     *
     * @return array{ok: bool, error?: string, code?: string, subscription_id?: int, payment_id?: int, idempotent?: bool}
     */
    public function purchase(int $userId, int $packageId, ?string $idempotencyKey = null, array $clientInput = []): array
    {
        $user = $this->users->find($userId);
        if (!$user) {
            return ['ok' => false, 'code' => 'user', 'error' => t('business.err_user')];
        }

        $package = $this->packages->find($packageId);
        if (!$package) {
            return ['ok' => false, 'code' => 'package', 'error' => t('business.err_package')];
        }

        $tariff = BusinessPackagePolicy::trustedTariff($package, $clientInput);
        if (!$tariff['ok']) {
            return ['ok' => false, 'code' => $tariff['error_code'] ?? 'package', 'error' => t('business.err_package')];
        }

        $kind = (string) ($package['kind'] ?? BusinessPackage::KIND_PLAN);
        if ($kind === BusinessPackage::KIND_ADDON) {
            return $this->purchaseAddon($userId, $package, $idempotencyKey);
        }
        if ($kind !== BusinessPackage::KIND_PLAN) {
            return ['ok' => false, 'code' => 'package', 'error' => t('business.err_package')];
        }

        $key = $this->normalizeIdempotencyKey($idempotencyKey, $userId, $packageId);
        $existingPay = $this->payments->findByIdempotency($key);
        if ($existingPay) {
            // Уже подтверждённый платёж — без новой активации (даже если продажи выключены)
            if (($existingPay['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PAID) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'subscription_id' => (int) ($existingPay['subscription_id'] ?? 0),
                    'payment_id' => (int) $existingPay['id'],
                ];
            }
            if (($existingPay['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PENDING) {
                return ['ok' => false, 'code' => 'payment_pending', 'error' => t('business.err_payment_pending')];
            }

            return ['ok' => false, 'code' => 'payment_failed', 'error' => t('business.err_payment_failed')];
        }

        $block = BusinessPackagePolicy::purchaseBlock(
            $user,
            $this->isSalesEnabled(),
            $this->isBusinessVerified($user)
        );
        if ($block === 'sales_disabled') {
            return ['ok' => false, 'code' => 'sales_disabled', 'error' => t('business.err_sales_disabled')];
        }
        if ($block === 'business_required') {
            return ['ok' => false, 'code' => 'business_required', 'error' => t('business.err_package_business_only')];
        }

        $db = Database::connect();
        $lockName = 'bp_purchase_' . $userId;
        $gotLock = false;
        try {
            $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
            $lockStmt->execute([$lockName]);
            $gotLock = (int) $lockStmt->fetchColumn() === 1;
            if (!$gotLock) {
                return ['ok' => false, 'code' => 'busy', 'error' => t('business.err_busy')];
            }

            // Повторная проверка после лока
            $existingPay = $this->payments->findByIdempotency($key);
            if ($existingPay && ($existingPay['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PAID) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'subscription_id' => (int) ($existingPay['subscription_id'] ?? 0),
                    'payment_id' => (int) $existingPay['id'],
                ];
            }

            $price = (int) $tariff['price_kzt'];
            $days = max(1, (int) $tariff['duration_days']);

            $paymentId = $this->payments->createPending([
                'user_id' => $userId,
                'package_id' => $packageId,
                'amount_kzt' => $price,
                'idempotency_key' => $key,
                'channel' => 'wallet',
                'meta' => 'wallet',
            ]);
            if ($paymentId === false) {
                $race = $this->payments->findByIdempotency($key);
                if ($race && ($race['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PAID) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'subscription_id' => (int) ($race['subscription_id'] ?? 0),
                        'payment_id' => (int) $race['id'],
                    ];
                }

                return ['ok' => false, 'code' => 'busy', 'error' => t('business.err_busy')];
            }

            $wallet = new Wallet();
            if ($wallet->balance($userId) < $price) {
                $this->payments->markFailed($paymentId, 'insufficient');

                return ['ok' => false, 'code' => 'balance', 'error' => t('business.err_package_balance', [
                    'amount' => Wallet::formatMoney($price),
                ])];
            }

            $charge = $wallet->chargeBusinessPackage($userId, $price, $packageId);
            if (!$charge['ok']) {
                $this->payments->markFailed($paymentId, 'charge_failed');

                return ['ok' => false, 'code' => 'balance', 'error' => $charge['error'] ?? t('wallet.op_failed')];
            }

            $activated = $this->activatePaidPlan($userId, $package, $price, $days);
            if (!$activated['ok']) {
                $this->payments->markFailed($paymentId, 'activate_failed');

                return $activated;
            }

            $subId = (int) $activated['subscription_id'];
            $this->payments->markPaid($paymentId, $subId);

            (new Notification())->createFor($userId, t('business.notify_package_activated', [
                'name' => (string) $package['name'],
                'until' => date('d.m.Y', strtotime((string) $activated['ends_at'])),
            ]));

            ActivityLogger::info('business.package_purchase', 'Подключён бизнес-пакет', 'business_subscription', $subId, [
                'package_id' => $packageId,
                'price' => $price,
                'payment_id' => $paymentId,
                'idempotency_key' => $key,
            ]);

            return [
                'ok' => true,
                'subscription_id' => $subId,
                'payment_id' => $paymentId,
            ];
        } finally {
            if ($gotLock) {
                $unlock = $db->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$lockName]);
            }
        }
    }

    /**
     * @param array<string, mixed> $package
     * @return array{ok: bool, error?: string, subscription_id?: int, ends_at?: string}
     */
    private function activatePaidPlan(int $userId, array $package, int $price, int $days): array
    {
        $packageId = (int) $package['id'];
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

        return ['ok' => true, 'subscription_id' => $subId, 'ends_at' => $ends];
    }

    /**
     * @param array<string, mixed> $package
     * @return array{ok: bool, error?: string, code?: string, subscription_id?: int, payment_id?: int, idempotent?: bool}
     */
    private function purchaseAddon(int $userId, array $package, ?string $idempotencyKey = null): array
    {
        $existing = $this->activeSubscription($userId);
        if (!$existing) {
            return ['ok' => false, 'code' => 'need_plan', 'error' => t('business.err_addon_need_plan')];
        }

        $packageId = (int) $package['id'];
        $tariff = BusinessPackagePolicy::trustedTariff($package, []);
        if (!$tariff['ok']) {
            return ['ok' => false, 'code' => $tariff['error_code'] ?? 'package', 'error' => t('business.err_package')];
        }

        $key = $this->normalizeIdempotencyKey($idempotencyKey, $userId, $packageId);
        $existingPay = $this->payments->findByIdempotency($key);
        if ($existingPay) {
            if (($existingPay['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PAID) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'subscription_id' => (int) ($existingPay['subscription_id'] ?? $existing['id']),
                    'payment_id' => (int) $existingPay['id'],
                ];
            }
            if (($existingPay['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PENDING) {
                return ['ok' => false, 'code' => 'payment_pending', 'error' => t('business.err_payment_pending')];
            }

            return ['ok' => false, 'code' => 'payment_failed', 'error' => t('business.err_payment_failed')];
        }

        if (!$this->isSalesEnabled()) {
            return ['ok' => false, 'code' => 'sales_disabled', 'error' => t('business.err_sales_disabled')];
        }

        $slug = (string) ($package['slug'] ?? '');
        if (BusinessPackagePolicy::isAiAddonSlug($slug) && !$this->isAiAddonSalesEnabledForSlug($slug)) {
            return ['ok' => false, 'code' => 'ai_addon_sales_disabled', 'error' => t('business.err_ai_addon_sales_disabled')];
        }

        $db = Database::connect();
        $lockName = 'bp_purchase_' . $userId;
        $gotLock = false;
        try {
            $lockStmt = $db->prepare('SELECT GET_LOCK(?, 10)');
            $lockStmt->execute([$lockName]);
            $gotLock = (int) $lockStmt->fetchColumn() === 1;
            if (!$gotLock) {
                return ['ok' => false, 'code' => 'busy', 'error' => t('business.err_busy')];
            }

            $existingPay = $this->payments->findByIdempotency($key);
            if ($existingPay && ($existingPay['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PAID) {
                // Уже оплачено до/после выключения продаж — без повторного начисления
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'subscription_id' => (int) ($existingPay['subscription_id'] ?? $existing['id']),
                    'payment_id' => (int) $existingPay['id'],
                ];
            }

            // Повторная проверка флага внутри lock (админ мог выключить параллельно)
            if (BusinessPackagePolicy::isAiAddonSlug($slug) && !$this->isAiAddonSalesEnabledForSlug($slug)) {
                return ['ok' => false, 'code' => 'ai_addon_sales_disabled', 'error' => t('business.err_ai_addon_sales_disabled')];
            }
            if (!$this->isSalesEnabled()) {
                return ['ok' => false, 'code' => 'sales_disabled', 'error' => t('business.err_sales_disabled')];
            }

            $price = (int) $tariff['price_kzt'];
            $paymentId = $this->payments->createPending([
                'user_id' => $userId,
                'package_id' => $packageId,
                'amount_kzt' => $price,
                'idempotency_key' => $key,
                'channel' => 'wallet',
                'meta' => BusinessPackagePolicy::isAiAddonSlug($slug) ? 'ai_addon:' . $slug : 'addon',
            ]);
            if ($paymentId === false) {
                $race = $this->payments->findByIdempotency($key);
                if ($race && ($race['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PAID) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'subscription_id' => (int) ($race['subscription_id'] ?? $existing['id']),
                        'payment_id' => (int) $race['id'],
                    ];
                }
                if ($race && ($race['status'] ?? '') === BusinessPackagePolicy::PAYMENT_PENDING) {
                    return ['ok' => false, 'code' => 'payment_pending', 'error' => t('business.err_payment_pending')];
                }
                if ($race) {
                    return ['ok' => false, 'code' => 'payment_failed', 'error' => t('business.err_payment_failed')];
                }

                return ['ok' => false, 'code' => 'busy', 'error' => t('business.err_busy')];
            }

            $wallet = new Wallet();
            if ($wallet->balance($userId) < $price) {
                $this->payments->markFailed($paymentId, 'insufficient');

                return ['ok' => false, 'code' => 'balance', 'error' => t('business.err_package_balance', [
                    'amount' => Wallet::formatMoney($price),
                ])];
            }

            $charge = $wallet->chargeBusinessPackage($userId, $price, $packageId);
            if (!$charge['ok']) {
                $this->payments->markFailed($paymentId, 'charge_failed');

                return ['ok' => false, 'code' => 'balance', 'error' => $charge['error'] ?? t('wallet.op_failed')];
            }

            // Units только из серверного каталога для AI-аддонов; иначе из limits_json пакета.
            $limits = BusinessPackage::decodeLimits($package['limits_json'] ?? null);
            if (BusinessPackagePolicy::isAiAddonSlug($slug)) {
                $spec = BusinessPackagePolicy::aiAddonSpec($slug) ?? [];
                $limits['extra_ai_infographic'] = (int) ($spec['extra_ai_infographic'] ?? 0);
                $limits['extra_ai_tryon'] = (int) ($spec['extra_ai_tryon'] ?? 0);
                $limits['extra_catalog'] = 0;
                $limits['extra_staff'] = 0;
            }
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

            $this->payments->markPaid($paymentId, (int) $existing['id']);

            (new Notification())->createFor($userId, t('business.notify_addon', [
                'name' => (string) $package['name'],
            ]));

            ActivityLogger::info('business.addon_purchase', 'Докуплен слот бизнес-пакета', 'business_subscription', (int) $existing['id'], [
                'package_id' => $packageId,
                'price' => $price,
                'payment_id' => $paymentId,
            ]);

            return ['ok' => true, 'subscription_id' => (int) $existing['id'], 'payment_id' => $paymentId];
        } finally {
            if ($gotLock) {
                $unlock = $db->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$lockName]);
            }
        }
    }

    private function normalizeIdempotencyKey(?string $key, int $userId, int $packageId): string
    {
        $key = trim((string) $key);
        if ($key !== '' && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $key)) {
            return $key;
        }

        return sprintf('bp-wallet-%d-%d-%s', $userId, $packageId, bin2hex(random_bytes(8)));
    }
}
