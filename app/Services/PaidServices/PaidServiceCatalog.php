<?php

declare(strict_types=1);

namespace App\Services\PaidServices;

use App\Services\Ads\AdPolicy;
use App\Services\Boost\BoostPolicy;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\Highlight\HighlightPolicy;
use App\Services\Listing\ServiceListingFeePolicy;
use App\Services\MassPush\MassPushPolicy;
use App\Services\TargetPush\TargetPushPolicy;
use App\Services\ThreeLifts\ThreeLiftsPolicy;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\Vip\VipPolicy;

/**
 * VAS-17: единый реестр платных услуг поверх существующих Policy.
 * Цены — только чтение из серверных констант/утверждённого каталога. Не второй источник истины для оплаты.
 */
final class PaidServiceCatalog
{
    public const STATUS_FULL = 'full';
    public const STATUS_FLAG_ONLY = 'flag_only';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_SALES_BLOCKED = 'sales_blocked';

    public const BILLING_ONE_SHOT = 'one_shot';
    public const BILLING_TIMED_WINDOW = 'timed_window';
    public const BILLING_CPM = 'cpm_thousands';
    public const BILLING_EXCLUSIVE_WEEKS = 'exclusive_weeks';
    public const BILLING_PUSH_SEND = 'push_send';
    public const BILLING_SUBSCRIPTION = 'subscription_month';
    public const BILLING_ADDON_QUOTA = 'addon_quota';
    public const BILLING_LISTING_FEE = 'listing_per_item';
    public const BILLING_NONE = 'none';

    /**
     * Стабильные коды управляемых записей (toggleability определяется полем can_toggle).
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'boost',
                'vas_id' => 'VAS-01',
                'title' => 'Boost',
                'category' => 'promotion',
                'billing_model' => self::BILLING_ONE_SHOT,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => BoostPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => BoostPolicy::SETTING_KEY,
                'toggle_handler' => 'boost',
                'notes' => 'Разовое поднятие объявления.',
            ],
            [
                'code' => 'three_lifts',
                'vas_id' => 'VAS-02',
                'title' => '3 Поднятия',
                'category' => 'promotion',
                'billing_model' => self::BILLING_ONE_SHOT,
                'implementation_status' => self::STATUS_FLAG_ONLY,
                'can_toggle' => true,
                'price_kzt' => ThreeLiftsPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => ThreeLiftsPolicy::SETTING_KEY,
                'toggle_handler' => 'three_lifts',
                'notes' => 'В коде только feature flag; заказы и слоты не создаются.',
            ],
            [
                'code' => 'highlight',
                'vas_id' => 'VAS-03',
                'title' => 'Highlight',
                'category' => 'promotion',
                'billing_model' => self::BILLING_TIMED_WINDOW,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => HighlightPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => HighlightPolicy::DURATION_DAYS,
                'setting_key' => HighlightPolicy::SETTING_KEY,
                'toggle_handler' => 'highlight',
                'notes' => null,
            ],
            [
                'code' => 'top_fixed',
                'vas_id' => 'VAS-04',
                'title' => 'Top Fixed',
                'category' => 'promotion',
                'billing_model' => self::BILLING_TIMED_WINDOW,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => TopFixedPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => TopFixedPolicy::DURATION_DAYS,
                'setting_key' => TopFixedPolicy::SETTING_KEY,
                'toggle_handler' => 'top_fixed',
                'notes' => null,
            ],
            [
                'code' => 'top_fixed_xl',
                'vas_id' => 'VAS-05',
                'title' => 'Top Fixed XL',
                'category' => 'promotion',
                'billing_model' => self::BILLING_TIMED_WINDOW,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => TopFixedXlPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => TopFixedXlPolicy::DURATION_DAYS,
                'setting_key' => TopFixedXlPolicy::SETTING_KEY,
                'toggle_handler' => 'top_fixed_xl',
                'notes' => null,
            ],
            [
                'code' => 'vip',
                'vas_id' => 'VAS-06',
                'title' => 'VIP «Всё включено»',
                'category' => 'promotion',
                'billing_model' => self::BILLING_TIMED_WINDOW,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => VipPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => VipPolicy::DURATION_DAYS,
                'setting_key' => VipPolicy::SETTING_KEY,
                'toggle_handler' => 'vip',
                'notes' => 'Пакет: Top Fixed + Highlight + 2 lift; компоненты с amount 0. Отдельные флаги HL/TF — для standalone.',
            ],
            [
                'code' => 'ads_hero',
                'vas_id' => 'VAS-07',
                'title' => 'Hero slider banner',
                'category' => 'ads_cpm',
                'billing_model' => self::BILLING_CPM,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => AdPolicy::CPM_KZT[AdPolicy::FORMAT_HERO],
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_HERO],
                'toggle_handler' => 'ads',
                'ads_format' => AdPolicy::FORMAT_HERO,
                'notes' => 'CPM за 1000 учитываемых показов.',
            ],
            [
                'code' => 'ads_category',
                'vas_id' => 'VAS-08',
                'title' => 'Баннер категории',
                'category' => 'ads_cpm',
                'billing_model' => self::BILLING_CPM,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => AdPolicy::CPM_KZT[AdPolicy::FORMAT_CATEGORY],
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_CATEGORY],
                'toggle_handler' => 'ads',
                'ads_format' => AdPolicy::FORMAT_CATEGORY,
                'notes' => null,
            ],
            [
                'code' => 'ads_sidebar',
                'vas_id' => 'VAS-09',
                'title' => 'Боковой баннер',
                'category' => 'ads_cpm',
                'billing_model' => self::BILLING_CPM,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => AdPolicy::CPM_KZT[AdPolicy::FORMAT_SIDEBAR],
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_SIDEBAR],
                'toggle_handler' => 'ads',
                'ads_format' => AdPolicy::FORMAT_SIDEBAR,
                'notes' => null,
            ],
            [
                'code' => 'ads_exclusive',
                'vas_id' => 'VAS-10',
                'title' => 'Exclusive category branding',
                'category' => 'ads_exclusive',
                'billing_model' => self::BILLING_EXCLUSIVE_WEEKS,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => AdPolicy::EXCLUSIVE_WEEK_KZT,
                'currency' => 'KZT',
                'duration_days' => 7,
                'setting_key' => AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_EXCLUSIVE],
                'toggle_handler' => 'ads',
                'ads_format' => AdPolicy::FORMAT_EXCLUSIVE,
                'notes' => 'Тариф за неделю эксклюзива.',
            ],
            [
                'code' => 'target_push',
                'vas_id' => 'VAS-11',
                'title' => 'Target Push',
                'category' => 'messaging',
                'billing_model' => self::BILLING_PUSH_SEND,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => TargetPushPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => TargetPushPolicy::SETTING_KEY,
                'toggle_handler' => 'target_push',
                'notes' => 'In-app notifications; max ' . TargetPushPolicy::MAX_RECIPIENTS . ' получателей.',
            ],
            [
                'code' => 'mass_push',
                'vas_id' => 'VAS-12',
                'title' => 'Mass Push',
                'category' => 'messaging',
                'billing_model' => self::BILLING_PUSH_SEND,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => MassPushPolicy::PRICE_KZT,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => MassPushPolicy::SETTING_KEY,
                'toggle_handler' => 'mass_push',
                'notes' => 'Требует модерации администратора.',
            ],
            [
                'code' => 'in_app_offers',
                'vas_id' => 'VAS-13',
                'title' => 'In-App «Акционные предложения»',
                'category' => 'messaging',
                'billing_model' => self::BILLING_NONE,
                'implementation_status' => self::STATUS_ABSENT,
                'can_toggle' => false,
                'price_kzt' => null,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => null,
                'toggle_handler' => null,
                'notes' => 'В репозитории не реализовано; toggle продаж не предоставляется.',
            ],
            [
                'code' => 'business_package_sales',
                'vas_id' => 'VAS-14',
                'title' => 'Business Package — продажи',
                'category' => 'business',
                'billing_model' => self::BILLING_SUBSCRIPTION,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => 29900,
                'currency' => 'KZT',
                'duration_days' => 30,
                'setting_key' => BusinessPackagePolicy::SETTING_SALES,
                'toggle_handler' => 'business_sales',
                'notes' => 'Утверждён к продаже plan business-month. 6/12 мес. не в APPROVED_SALE_PLAN_SLUGS.',
            ],
            [
                'code' => 'business_package_features',
                'vas_id' => 'VAS-14',
                'title' => 'Business Package — преимущества',
                'category' => 'business',
                'billing_model' => self::BILLING_NONE,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => null,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => BusinessPackagePolicy::SETTING_FEATURES,
                'toggle_handler' => 'business_features',
                'notes' => 'Независимо от sales: доступ к импорту/AI-контрактам/аналитике.',
            ],
            [
                'code' => 'ai_addon_infographic_sales',
                'vas_id' => 'VAS-15',
                'title' => 'AI инфографика +500',
                'category' => 'business',
                'billing_model' => self::BILLING_ADDON_QUOTA,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => BusinessPackagePolicy::AI_ADDON_CATALOG[BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON]['price_kzt'],
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => BusinessPackagePolicy::SETTING_AI_INFOGRAPHIC_ADDON_SALES,
                'toggle_handler' => 'ai_addon_sales',
                'package_slug' => BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON,
                'notes' => 'Продажа аддона; генератор может быть unimplemented.',
            ],
            [
                'code' => 'ai_addon_tryon_sales',
                'vas_id' => 'VAS-15',
                'title' => 'AI примерка +100',
                'category' => 'business',
                'billing_model' => self::BILLING_ADDON_QUOTA,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => BusinessPackagePolicy::AI_ADDON_CATALOG[BusinessPackagePolicy::SLUG_AI_TRYON_ADDON]['price_kzt'],
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => BusinessPackagePolicy::SETTING_AI_TRYON_ADDON_SALES,
                'toggle_handler' => 'ai_addon_sales',
                'package_slug' => BusinessPackagePolicy::SLUG_AI_TRYON_ADDON,
                'notes' => 'Независимо от продаж инфографики.',
            ],
            [
                'code' => 'service_listing_publish',
                'vas_id' => 'VAS-16',
                'title' => 'Публикация объявлений «Услуги»',
                'category' => 'listing_fee',
                'billing_model' => self::BILLING_LISTING_FEE,
                'implementation_status' => self::STATUS_FULL,
                'can_toggle' => true,
                'price_kzt' => ServiceListingFeePolicy::FEE_KZT,
                'currency' => 'KZT',
                'duration_days' => null,
                'setting_key' => ServiceListingFeePolicy::SETTING_KEY,
                'toggle_handler' => 'service_listing',
                'notes' => 'Плата платформе за размещение; не эскроу услуги заказчику.',
            ],
        ];
    }

    public static function find(string $code): ?array
    {
        foreach (self::definitions() as $row) {
            if (($row['code'] ?? '') === $code) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function toggleableCodes(): array
    {
        $out = [];
        foreach (self::definitions() as $row) {
            if (!empty($row['can_toggle'])) {
                $out[] = (string) $row['code'];
            }
        }

        return $out;
    }
}
