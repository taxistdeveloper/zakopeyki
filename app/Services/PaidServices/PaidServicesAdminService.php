<?php

declare(strict_types=1);

namespace App\Services\PaidServices;

use App\Core\Auth;
use App\Helpers\ActivityLogger;
use App\Models\Setting;
use App\Services\Ads\AdService;
use App\Services\Boost\BoostService;
use App\Services\BusinessPackageService;
use App\Services\Highlight\HighlightService;
use App\Services\Listing\ServiceListingFeeService;
use App\Services\MassPush\MassPushService;
use App\Services\TargetPush\TargetPushService;
use App\Services\ThreeLifts\ThreeLiftsService;
use App\Services\TopFixed\TopFixedService;
use App\Services\TopFixedXl\TopFixedXlService;
use App\Services\Vip\VipService;
use Throwable;

/**
 * Админ-операции каталога: просмотр и изменение доступности через существующие сервисы.
 * Редактирование цен этим сервисом не поддерживается (VAS-17.2 §12 B1).
 * Флаг модуля (paid_services_admin_enabled) независим от флагов продаж VAS-01…16.
 */
final class PaidServicesAdminService
{
    private Setting $settings;

    public function __construct(?Setting $settings = null)
    {
        $this->settings = $settings ?? new Setting();
    }

    /**
     * Доступен ли административный модуль VAS-17 (не продажи отдельных услуг).
     */
    public function isModuleEnabled(): bool
    {
        try {
            $this->settings->forget(PaidServicesAdminPolicy::SETTING_KEY);

            return PaidServicesAdminPolicy::resolveEnabled(
                $this->settings->getBool(PaidServicesAdminPolicy::SETTING_KEY, true)
            );
        } catch (Throwable) {
            return PaidServicesAdminPolicy::resolveEnabled(null, true);
        }
    }

    /**
     * Вкл/выкл модуля каталога. Работает и при выключенном модуле (иначе нельзя включить обратно).
     *
     * @return array{ok: bool, error_code?: string, enabled?: bool, unchanged?: bool, before?: bool, after?: bool}
     */
    public function setModuleEnabled(bool $enabled): array
    {
        $role = Auth::check() ? (string) (Auth::user()['role'] ?? '') : '';
        $actorId = Auth::check() ? (int) Auth::id() : 0;
        if (!PaidServicesAdminPolicy::canToggle($role) || $actorId <= 0) {
            ActivityLogger::warning(
                'paid_services.module_toggle_denied',
                'Модуль каталога платных услуг: отказано — не admin',
                'settings',
                null,
                ['actor_id' => $actorId, 'role' => $role]
            );

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        $before = $this->isModuleEnabled();
        if ($before === $enabled) {
            return [
                'ok' => true,
                'unchanged' => true,
                'enabled' => $enabled,
                'before' => $before,
                'after' => $enabled,
            ];
        }

        try {
            $this->settings->setBool(PaidServicesAdminPolicy::SETTING_KEY, $enabled);
            $after = $this->isModuleEnabled();
            if ($after !== $enabled) {
                return ['ok' => false, 'error_code' => 'toggle_failed'];
            }
            $audit = PaidServicesAdminPolicy::toggleAudit($actorId, $before, $after);
            ActivityLogger::info(
                $audit['action'],
                $after
                    ? 'Админ-модуль каталога платных услуг включён'
                    : 'Админ-модуль каталога платных услуг выключен',
                'settings',
                null,
                $audit['context']
            );

            return [
                'ok' => true,
                'enabled' => $after,
                'before' => $before,
                'after' => $after,
                'unchanged' => false,
            ];
        } catch (Throwable) {
            ActivityLogger::warning(
                'paid_services.module_toggle_failed',
                'Не удалось переключить модуль каталога платных услуг',
                'settings',
                null
            );

            return ['ok' => false, 'error_code' => 'toggle_failed'];
        }
    }

    /**
     * @return array{ok: bool, services?: list<array<string, mixed>>, error_code?: string}
     */
    public function catalog(): array
    {
        if (!$this->actorIsAdmin()) {
            ActivityLogger::warning(
                'paid_services.catalog_denied',
                'Каталог платных услуг: отказано — не admin',
                'settings',
                null,
                ['actor_id' => Auth::check() ? (int) Auth::id() : 0]
            );

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        if (!$this->isModuleEnabled()) {
            ActivityLogger::warning(
                'paid_services.catalog_denied',
                'Каталог платных услуг: модуль VAS-17 выключен',
                'settings',
                null,
                ['actor_id' => (int) Auth::id(), 'setting' => PaidServicesAdminPolicy::SETTING_KEY]
            );

            return ['ok' => false, 'error_code' => 'module_disabled'];
        }

        $rows = [];
        foreach (PaidServiceCatalog::definitions() as $def) {
            $rows[] = $this->enrich($def);
        }

        return ['ok' => true, 'services' => $rows];
    }

    /**
     * Изменить доступность новых покупок/публикаций. Цены из input игнорируются/запрещены.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, error_code?: string, code?: string, enabled?: bool, unchanged?: bool, before?: bool, after?: bool}
     */
    public function setAvailability(string $code, bool $enabled, array $input = []): array
    {
        if (!$this->actorIsAdmin()) {
            ActivityLogger::warning(
                'paid_services.availability_denied',
                'Доступность платной услуги: отказано — не admin',
                'settings',
                null,
                ['code' => $code, 'actor_id' => Auth::check() ? (int) Auth::id() : 0]
            );

            return ['ok' => false, 'error_code' => 'forbidden'];
        }

        if (!$this->isModuleEnabled()) {
            ActivityLogger::warning(
                'paid_services.availability_denied',
                'Доступность платной услуги: модуль VAS-17 выключен',
                'settings',
                null,
                [
                    'code' => $code,
                    'actor_id' => (int) Auth::id(),
                    'setting' => PaidServicesAdminPolicy::SETTING_KEY,
                ]
            );

            return ['ok' => false, 'error_code' => 'module_disabled', 'code' => $code];
        }

        // Запрет редактирования тарифов через этот API (спека VAS-17.2).
        foreach (['price', 'price_kzt', 'amount', 'total', 'sum', 'cpm', 'duration_days', 'fee'] as $banned) {
            if (array_key_exists($banned, $input)) {
                return ['ok' => false, 'error_code' => 'price_edit_forbidden', 'code' => $code];
            }
        }

        $def = PaidServiceCatalog::find($code);
        if ($def === null) {
            return ['ok' => false, 'error_code' => 'not_found', 'code' => $code];
        }
        if (empty($def['can_toggle']) || ($def['implementation_status'] ?? '') === PaidServiceCatalog::STATUS_ABSENT) {
            return ['ok' => false, 'error_code' => 'not_toggleable', 'code' => $code];
        }

        $before = $this->readEnabled($def);
        if ($before === $enabled) {
            return [
                'ok' => true,
                'unchanged' => true,
                'code' => $code,
                'enabled' => $enabled,
                'before' => $before,
                'after' => $enabled,
            ];
        }

        $expected = null;
        if (array_key_exists('expected', $input)) {
            $raw = $input['expected'];
            if ($raw === null || $raw === '') {
                $expected = null;
            } elseif (is_bool($raw)) {
                $expected = $raw;
            } else {
                $expected = in_array((string) $raw, ['1', 'true', 'on', 'yes'], true);
            }
        }

        $write = $this->delegateSetEnabled($def, $enabled, $expected);
        if (!$write['ok']) {
            return [
                'ok' => false,
                'error_code' => $write['error_code'] ?? 'toggle_failed',
                'code' => $code,
            ];
        }

        $after = $this->readEnabled($def);
        ActivityLogger::info(
            'paid_services.availability_changed',
            'Доступность «' . ($def['title'] ?? $code) . '»: ' . ($after ? 'on' : 'off'),
            'settings',
            null,
            [
                'code' => $code,
                'vas_id' => $def['vas_id'] ?? null,
                'setting_key' => $def['setting_key'] ?? null,
                'before' => $before,
                'after' => $after,
                'actor_id' => (int) Auth::id(),
                'at' => date('c'),
                'result' => 'ok',
                'keeps_paid_entitlements' => true,
                'no_refunds' => true,
                'price_unchanged' => true,
            ]
        );

        return [
            'ok' => true,
            'code' => $code,
            'enabled' => $after,
            'before' => $before,
            'after' => $after,
            'unchanged' => !empty($write['unchanged']),
        ];
    }

    private function actorIsAdmin(): bool
    {
        return Auth::check() && (string) (Auth::user()['role'] ?? '') === 'admin';
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private function enrich(array $def): array
    {
        $enabled = null;
        if (!empty($def['can_toggle']) && ($def['implementation_status'] ?? '') !== PaidServiceCatalog::STATUS_ABSENT) {
            $enabled = $this->readEnabled($def);
        }

        return [
            'code' => $def['code'],
            'vas_id' => $def['vas_id'],
            'title' => $def['title'],
            'category' => $def['category'],
            'billing_model' => $def['billing_model'],
            'implementation_status' => $def['implementation_status'],
            'can_toggle' => (bool) ($def['can_toggle'] ?? false),
            'price_kzt' => $def['price_kzt'],
            'currency' => $def['currency'] ?? 'KZT',
            'duration_days' => $def['duration_days'],
            'setting_key' => $def['setting_key'],
            'sales_enabled' => $enabled,
            'price_editable' => false,
            'notes' => $def['notes'],
        ];
    }

    /** @param array<string, mixed> $def */
    private function readEnabled(array $def): bool
    {
        $handler = (string) ($def['toggle_handler'] ?? '');

        return match ($handler) {
            'boost' => (new BoostService())->isEnabled(),
            'three_lifts' => (new ThreeLiftsService())->isEnabled(),
            'highlight' => (new HighlightService())->isEnabled(),
            'top_fixed' => (new TopFixedService())->isEnabled(),
            'top_fixed_xl' => (new TopFixedXlService())->isEnabled(),
            'vip' => (new VipService())->isEnabled(),
            'ads' => (new AdService())->isEnabled((string) ($def['ads_format'] ?? '')),
            'target_push' => (new TargetPushService())->isEnabled(),
            'mass_push' => (new MassPushService())->isEnabled(),
            'business_sales' => (new BusinessPackageService())->isSalesEnabled(),
            'business_features' => (new BusinessPackageService())->isFeaturesEnabled(),
            'ai_addon_sales' => (new BusinessPackageService())->isAiAddonSalesEnabledForSlug((string) ($def['package_slug'] ?? '')),
            'service_listing' => (new ServiceListingFeeService())->isPublishEnabled(),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $def
     * @return array{ok: bool, error_code?: string, unchanged?: bool}
     */
    private function delegateSetEnabled(array $def, bool $enabled, ?bool $expected): array
    {
        $handler = (string) ($def['toggle_handler'] ?? '');

        try {
            return match ($handler) {
                'boost' => ['ok' => (new BoostService())->setEnabled($enabled)],
                'three_lifts' => $this->mapThreeLifts((new ThreeLiftsService())->setEnabled($enabled, $expected)),
                'highlight' => ['ok' => (new HighlightService())->setEnabled($enabled)],
                'top_fixed' => ['ok' => (new TopFixedService())->setEnabled($enabled)],
                'top_fixed_xl' => ['ok' => (new TopFixedXlService())->setEnabled($enabled)],
                'vip' => ['ok' => (new VipService())->setEnabled($enabled)],
                'ads' => ['ok' => (new AdService())->setEnabled((string) ($def['ads_format'] ?? ''), $enabled)],
                'target_push' => ['ok' => (new TargetPushService())->setEnabled($enabled)],
                'mass_push' => ['ok' => (new MassPushService())->setEnabled($enabled)],
                'business_sales' => ['ok' => (new BusinessPackageService())->setSalesEnabled($enabled)],
                'business_features' => ['ok' => (new BusinessPackageService())->setFeaturesEnabled($enabled)],
                'ai_addon_sales' => [
                    'ok' => (new BusinessPackageService())->setAiAddonSalesEnabledForSlug(
                        (string) ($def['package_slug'] ?? ''),
                        $enabled
                    ),
                ],
                'service_listing' => ['ok' => (new ServiceListingFeeService())->setPublishEnabled($enabled)],
                default => ['ok' => false, 'error_code' => 'not_toggleable'],
            };
        } catch (\Throwable) {
            return ['ok' => false, 'error_code' => 'toggle_failed'];
        }
    }

    /**
     * @param array{ok: bool, unchanged?: bool, error_code?: string} $result
     * @return array{ok: bool, error_code?: string, unchanged?: bool}
     */
    private function mapThreeLifts(array $result): array
    {
        if (!empty($result['ok'])) {
            return [
                'ok' => true,
                'unchanged' => !empty($result['unchanged']),
            ];
        }

        return [
            'ok' => false,
            'error_code' => (string) ($result['error_code'] ?? 'toggle_failed'),
        ];
    }
}
