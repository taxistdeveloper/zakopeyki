<?php

declare(strict_types=1);

namespace App\Services\Business;

/**
 * Правила бизнес-пакета без доступа к БД.
 *
 * Два независимых флага (settings):
 * - SETTING_SALES — новые покупки / активации / trial / продления через purchase.
 * - SETTING_FEATURES — доступ к преимуществам (импорт, аналитика, AI-контракты, менеджер).
 *
 * Политика при sales=off (утверждено спецификацией VAS-14.2/14.5):
 * не аннулировать активные подписки, не трогать историю оплат, не запускать refunds.
 * Действующие подписчики сохраняют запись подписки; доступ к фичам — по SETTING_FEATURES.
 *
 * Не затрагивает Boost/VIP/Highlight/Ads/Push и прочие VAS (см. UNRELATED_CONTROLS).
 */
final class BusinessPackagePolicy
{
    public const SETTING_SALES = 'business_package_sales_enabled';
    public const SETTING_FEATURES = 'business_package_features_enabled';
    /**
     * Legacy (VAS-15.3): общий флаг. VAS-15.5 — раздельные ключи ниже;
     * используется только как fallback, если per-product ключ ещё не записан.
     */
    public const SETTING_AI_ADDON_SALES = 'business_ai_addon_sales_enabled';

    /** Продажа пакета A (инфографика +500). Не влияет на try-on и на доступ к AI-функции. */
    public const SETTING_AI_INFOGRAPHIC_ADDON_SALES = 'business_ai_infographic_addon_sales_enabled';

    /** Продажа пакета B (примерка +100). Не влияет на инфографику и на доступ к AI-функции. */
    public const SETTING_AI_TRYON_ADDON_SALES = 'business_ai_tryon_addon_sales_enabled';

    public const SLUG_AI_INFOGRAPHIC_ADDON = 'addon-ai-infographic-500';
    public const SLUG_AI_TRYON_ADDON = 'addon-ai-tryon-100';

    /** Соседние контуры, которые эти переключатели не меняют. */
    public const UNRELATED_CONTROLS = [
        'boost_enabled',
        'highlight_enabled',
        'top_fixed_enabled',
        'top_fixed_xl_enabled',
        'vip_enabled',
        'ads_enabled',
        'target_push_enabled',
        'mass_push_enabled',
        'three_lifts_enabled',
        'stub_mode',
    ];

    public const OP_RESERVED = 'reserved';
    public const OP_SUCCEEDED = 'succeeded';
    public const OP_FAILED = 'failed';
    public const OP_RELEASED = 'released';

    /** Действия, запрещённые при sales=off (серверная защита). */
    public const BLOCKED_WHEN_SALES_OFF = [
        'purchase_plan',
        'purchase_addon',
        'grant_trial',
        'extend_subscription',
    ];

    public const FEATURE_LISTINGS = 'listings';
    public const FEATURE_IMPORT_XML = 'import_xml';
    public const FEATURE_IMPORT_CSV = 'import_csv';
    public const FEATURE_IMPORT_API = 'import_api';
    public const FEATURE_AI_INFOGRAPHIC = 'ai_infographic';
    public const FEATURE_AI_SEO = 'ai_seo';
    public const FEATURE_AI_TRYON = 'ai_tryon';
    public const FEATURE_ANALYTICS_EXTENDED = 'analytics_extended';
    public const FEATURE_PERSONAL_MANAGER = 'personal_manager';

    /**
     * Утверждённый каталог доп. AI-пакетов (VAS-15.2). Цена и units только отсюда / seed.
     * @var array<string, array{price_kzt: int, extra_ai_infographic?: int, extra_ai_tryon?: int, feature: string}>
     */
    public const AI_ADDON_CATALOG = [
        self::SLUG_AI_INFOGRAPHIC_ADDON => [
            'price_kzt' => 4900,
            'extra_ai_infographic' => 500,
            'feature' => self::FEATURE_AI_INFOGRAPHIC,
            'sales_setting' => self::SETTING_AI_INFOGRAPHIC_ADDON_SALES,
        ],
        self::SLUG_AI_TRYON_ADDON => [
            'price_kzt' => 7900,
            'extra_ai_tryon' => 100,
            'feature' => self::FEATURE_AI_TRYON,
            'sales_setting' => self::SETTING_AI_TRYON_ADDON_SALES,
        ],
    ];

    /** Преимущества, для которых в репозитории ещё нет исполняемого AI/сервиса. */
    public const UNIMPLEMENTED_GENERATORS = [
        self::FEATURE_AI_INFOGRAPHIC,
        self::FEATURE_AI_SEO,
        self::FEATURE_AI_TRYON,
    ];

    /**
     * Планы, разрешённые к продаже по утверждённой цене VAS-14.2.
     * 6/12 мес. и прочие периоды — только после утверждения скидок/цен (UI показывает «ожидает»).
     */
    public const APPROVED_SALE_PLAN_SLUGS = [
        'business-month',
    ];

    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_CANCELLED = 'cancelled';

    public const IMPORT_QUEUED = 'queued';
    public const IMPORT_RUNNING = 'running';
    public const IMPORT_COMPLETED = 'completed';
    public const IMPORT_FAILED = 'failed';
    public const IMPORT_PARTIAL = 'partial';

    /** Максимальный размер файла импорта (байты) — технический потолок платформы, не коммерческий лимит. */
    public const IMPORT_MAX_BYTES = 5_242_880; // 5 MiB

    public const IMPORT_MAX_ROWS = 5000;

    /** CSV/XML поля, производные от Product::create / формы профиля. */
    public const IMPORT_REQUIRED_FIELDS = ['title', 'description', 'type', 'category', 'location'];

    public const IMPORT_ALLOWED_TYPES = ['used', 'new', 'free', 'exchange', 'service'];

    /**
     * При отсутствии ключа в settings сохраняем текущее поведение площадки (продажа уже работала).
     */
    public static function resolveFlag(mixed $stored, bool $defaultWhenMissing = true): bool
    {
        if ($stored === null) {
            return $defaultWhenMissing;
        }
        if (is_bool($stored)) {
            return $stored;
        }
        if (is_int($stored)) {
            return $stored === 1;
        }
        if (is_string($stored)) {
            $v = strtolower(trim($stored));
            if ($v === '') {
                return $defaultWhenMissing;
            }

            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

    /**
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function salesToggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'business.sales_on' : 'business.sales_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => self::SETTING_SALES,
                'before' => $before,
                'after' => $after,
                'keeps_subscriptions' => true,
                'keeps_history' => true,
                'cancels_paid_periods' => false,
                'refunds' => false,
                'blocks_new_purchases' => !$after,
                'other_settings' => [],
            ],
        ];
    }

    /**
     * Выключение преимуществ не аннулирует оплаченные периоды (политика не утверждена иначе).
     *
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function featuresToggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'business.features_on' : 'business.features_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => self::SETTING_FEATURES,
                'before' => $before,
                'after' => $after,
                'keeps_subscriptions' => true,
                'keeps_history' => true,
                'cancels_paid_periods' => false,
                'refunds' => false,
                'other_settings' => [],
            ],
        ];
    }

    public static function isUnimplementedGenerator(string $feature): bool
    {
        return in_array($feature, self::UNIMPLEMENTED_GENERATORS, true);
    }

    public static function isAiAddonSlug(string $slug): bool
    {
        return isset(self::AI_ADDON_CATALOG[$slug]);
    }

    /**
     * @return array{price_kzt: int, extra_ai_infographic?: int, extra_ai_tryon?: int, feature: string, sales_setting: string}|null
     */
    public static function aiAddonSpec(string $slug): ?array
    {
        return self::AI_ADDON_CATALOG[$slug] ?? null;
    }

    public static function aiAddonSalesSettingKey(string $slug): ?string
    {
        $spec = self::aiAddonSpec($slug);

        return isset($spec['sales_setting']) ? (string) $spec['sales_setting'] : null;
    }

    /** @return list<string> */
    public static function aiAddonSlugs(): array
    {
        return array_keys(self::AI_ADDON_CATALOG);
    }

    /**
     * Сверка seed/DB строки с утверждённым каталогом VAS-15.2.
     *
     * @param array<string, mixed> $package
     * @return array{ok: bool, price_kzt?: int, error_code?: string}
     */
    public static function trustedAiAddon(array $package): array
    {
        $slug = (string) ($package['slug'] ?? '');
        $spec = self::aiAddonSpec($slug);
        if ($spec === null) {
            return ['ok' => false, 'error_code' => 'package_not_approved'];
        }
        if (empty($package['is_active'])) {
            return ['ok' => false, 'error_code' => 'package_inactive'];
        }
        if ((string) ($package['kind'] ?? '') !== 'addon') {
            return ['ok' => false, 'error_code' => 'package_inactive'];
        }
        $dbPrice = (int) ($package['price_kzt'] ?? -1);
        if ($dbPrice !== (int) $spec['price_kzt']) {
            return ['ok' => false, 'error_code' => 'package_misconfigured'];
        }
        $limits = [];
        if (isset($package['limits_json'])) {
            $decoded = json_decode((string) $package['limits_json'], true);
            $limits = is_array($decoded) ? $decoded : [];
        } elseif (isset($package['limits']) && is_array($package['limits'])) {
            $limits = $package['limits'];
        }
        foreach (['extra_ai_infographic', 'extra_ai_tryon'] as $key) {
            if (!isset($spec[$key])) {
                continue;
            }
            if ((int) ($limits[$key] ?? 0) !== (int) $spec[$key]) {
                return ['ok' => false, 'error_code' => 'package_misconfigured'];
            }
        }

        return ['ok' => true, 'price_kzt' => (int) $spec['price_kzt']];
    }

    /**
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function aiAddonSalesToggleAudit(
        int $actorId,
        bool $before,
        bool $after,
        string $slug = '',
        ?string $at = null
    ): array {
        $setting = $slug !== '' ? (self::aiAddonSalesSettingKey($slug) ?? self::SETTING_AI_ADDON_SALES) : self::SETTING_AI_ADDON_SALES;
        $suffix = match ($slug) {
            self::SLUG_AI_INFOGRAPHIC_ADDON => 'infographic',
            self::SLUG_AI_TRYON_ADDON => 'tryon',
            default => 'all',
        };

        return [
            'action' => $after
                ? 'business.ai_addon_sales_on_' . $suffix
                : 'business.ai_addon_sales_off_' . $suffix,
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'setting' => $setting,
                'package_slug' => $slug !== '' ? $slug : null,
                'before' => $before,
                'after' => $after,
                'keeps_subscriptions' => true,
                'keeps_history' => true,
                'keeps_extra_quotas' => true,
                'keeps_usage_history' => true,
                'keeps_ai_operations' => true,
                'keeps_payments' => true,
                'cancels_paid_periods' => false,
                'refunds' => false,
                'annuls_balances' => false,
                'blocks_new_purchases' => !$after,
                'does_not_disable_ai_feature' => true,
                'does_not_disable_business_package' => true,
                'other_settings' => [],
            ],
        ];
    }

    /**
     * Цена и срок только с серверной строки пакета (клиентские amount/days отбрасываются).
     *
     * @param array<string, mixed> $package
     * @param array<string, mixed> $clientInput
     * @return array{ok: bool, price_kzt?: int, duration_days?: int, error_code?: string}
     */
    public static function trustedTariff(array $package, array $clientInput = []): array
    {
        unset($clientInput['amount'], $clientInput['price'], $clientInput['price_kzt'], $clientInput['days'], $clientInput['duration_days'], $clientInput['total']);

        if ($package === [] || empty($package['id'])) {
            return ['ok' => false, 'error_code' => 'package_not_found'];
        }
        if (empty($package['is_active'])) {
            return ['ok' => false, 'error_code' => 'package_inactive'];
        }
        $kind = (string) ($package['kind'] ?? 'plan');
        if (!in_array($kind, ['plan', 'addon'], true)) {
            return ['ok' => false, 'error_code' => 'package_inactive'];
        }

        $slug = (string) ($package['slug'] ?? '');

        // Цена/скидка 6–12 мес. и скрытый 3m не утверждены — сервер отклоняет даже при is_active=1 / прямом API.
        if ($kind === 'plan') {
            if (!in_array($slug, self::APPROVED_SALE_PLAN_SLUGS, true)) {
                return ['ok' => false, 'error_code' => 'package_not_approved'];
            }
        }

        if ($kind === 'addon' && self::isAiAddonSlug($slug)) {
            $ai = self::trustedAiAddon($package);
            if (!$ai['ok']) {
                return $ai;
            }

            return [
                'ok' => true,
                'price_kzt' => (int) $ai['price_kzt'],
                'duration_days' => 0,
            ];
        }

        return [
            'ok' => true,
            'price_kzt' => (int) ($package['price_kzt'] ?? 0),
            'duration_days' => max(0, (int) ($package['duration_days'] ?? 0)),
        ];
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function purchaseBlock(?array $user, bool $salesEnabled, bool $isBusinessVerified): ?string
    {
        if (!$salesEnabled) {
            return 'sales_disabled';
        }
        if ($user === null) {
            return 'user_not_found';
        }
        if (!$isBusinessVerified) {
            return 'business_required';
        }

        return null;
    }
}
