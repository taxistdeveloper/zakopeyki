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
 * VAS-17: флаг административного модуля каталога платных услуг.
 *
 * Не путать с флагами продаж VAS-01…16 (boost_enabled, vip_enabled, …).
 * OFF: недоступны HTML/API каталога и смена availability через VAS-17.
 * Не отключает покупки, цены, заказы, квоты, рекламу и платёжный провайдер.
 */
final class PaidServicesAdminPolicy
{
    public const SETTING_KEY = 'paid_services_admin_enabled';

    /** Соседние контуры, которые этот переключатель не меняет. */
    public const UNRELATED_CONTROLS = [
        'finance_journal_admin_enabled', // VAS-18 — не тот же контур, что каталог VAS-17
        BoostPolicy::SETTING_KEY,
        ThreeLiftsPolicy::SETTING_KEY,
        HighlightPolicy::SETTING_KEY,
        TopFixedPolicy::SETTING_KEY,
        TopFixedXlPolicy::SETTING_KEY,
        VipPolicy::SETTING_KEY,
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_HERO],
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_CATEGORY],
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_SIDEBAR],
        AdPolicy::SETTING_KEYS[AdPolicy::FORMAT_EXCLUSIVE],
        TargetPushPolicy::SETTING_KEY,
        MassPushPolicy::SETTING_KEY,
        BusinessPackagePolicy::SETTING_SALES,
        BusinessPackagePolicy::SETTING_FEATURES,
        BusinessPackagePolicy::SETTING_AI_INFOGRAPHIC_ADDON_SALES,
        BusinessPackagePolicy::SETTING_AI_TRYON_ADDON_SALES,
        ServiceListingFeePolicy::SETTING_KEY,
        'stub_mode',
        'freedompay',
        'halyk_epay',
    ];

    public static function canToggle(?string $role): bool
    {
        return $role === 'admin';
    }

    /**
     * Нет ключа → модуль доступен (после внедрения VAS-17).
     * Сбой чтения → выключен (fail-closed для админ-операций модуля).
     */
    public static function resolveEnabled(mixed $stored, bool $readFailed = false): bool
    {
        if ($readFailed) {
            return false;
        }
        if ($stored === null) {
            return true;
        }
        if (is_bool($stored)) {
            return $stored;
        }
        if (is_int($stored)) {
            return $stored === 1;
        }
        if (is_string($stored)) {
            return in_array(strtolower(trim($stored)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @return array{action: string, context: array<string, mixed>}
     */
    public static function toggleAudit(int $actorId, bool $before, bool $after, ?string $at = null): array
    {
        return [
            'action' => $after ? 'paid_services.module_on' : 'paid_services.module_off',
            'context' => [
                'actor_id' => $actorId,
                'at' => $at ?? date('c'),
                'before' => $before,
                'after' => $after,
                'setting' => self::SETTING_KEY,
                'blocks_admin_catalog' => !$after,
                'blocks_admin_availability_api' => !$after,
                'keeps_service_sales_flags' => true,
                'keeps_prices' => true,
                'keeps_orders' => true,
                'keeps_payments' => true,
                'keeps_entitlements' => true,
                'keeps_quotas' => true,
                'keeps_ad_placements' => true,
                'no_refunds' => true,
                'unaffected' => self::UNRELATED_CONTROLS,
            ],
        ];
    }
}
