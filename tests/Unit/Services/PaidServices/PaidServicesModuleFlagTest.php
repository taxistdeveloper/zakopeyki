<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PaidServices;

use App\Services\Ads\AdPolicy;
use App\Services\Boost\BoostPolicy;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\Highlight\HighlightPolicy;
use App\Services\Listing\ServiceListingFeePolicy;
use App\Services\MassPush\MassPushPolicy;
use App\Services\PaidServices\PaidServiceCatalog;
use App\Services\PaidServices\PaidServicesAdminPolicy;
use App\Services\PaidServices\PaidServicesAdminService;
use App\Services\TargetPush\TargetPushPolicy;
use App\Services\ThreeLifts\ThreeLiftsPolicy;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\Vip\VipPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * VAS-17.5: независимый флаг модуля админ-каталога ≠ флаги продаж VAS-01…16.
 */
final class PaidServicesModuleFlagTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
    }

    public function testSettingKeyIsDedicatedAndNotASalesFlag(): void
    {
        $this->assertSame('paid_services_admin_enabled', PaidServicesAdminPolicy::SETTING_KEY);
        $salesKeys = [
            BoostPolicy::SETTING_KEY,
            ThreeLiftsPolicy::SETTING_KEY,
            HighlightPolicy::SETTING_KEY,
            TopFixedPolicy::SETTING_KEY,
            TopFixedXlPolicy::SETTING_KEY,
            VipPolicy::SETTING_KEY,
            TargetPushPolicy::SETTING_KEY,
            MassPushPolicy::SETTING_KEY,
            BusinessPackagePolicy::SETTING_SALES,
            BusinessPackagePolicy::SETTING_FEATURES,
            ServiceListingFeePolicy::SETTING_KEY,
        ];
        foreach (AdPolicy::SETTING_KEYS as $k) {
            $salesKeys[] = $k;
        }
        $this->assertNotContains(PaidServicesAdminPolicy::SETTING_KEY, $salesKeys);
        foreach (PaidServiceCatalog::definitions() as $row) {
            $this->assertNotSame(PaidServicesAdminPolicy::SETTING_KEY, $row['setting_key'] ?? null);
            $this->assertNotSame('paid_services_admin', $row['code'] ?? null);
        }
    }

    public function testOnlyAdminCanToggleModuleFlag(): void
    {
        $this->assertTrue(PaidServicesAdminPolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(PaidServicesAdminPolicy::canToggle($role), (string) $role);
        }
    }

    public function testResolveEnabledDefaultsOnAndFailClosedOnReadError(): void
    {
        $this->assertTrue(PaidServicesAdminPolicy::resolveEnabled(null));
        $this->assertTrue(PaidServicesAdminPolicy::resolveEnabled(true));
        $this->assertTrue(PaidServicesAdminPolicy::resolveEnabled(1));
        $this->assertTrue(PaidServicesAdminPolicy::resolveEnabled('1'));
        $this->assertTrue(PaidServicesAdminPolicy::resolveEnabled('on'));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled(false));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled(0));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled('0'));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled('off'));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled([], false));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled(true, true));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled(null, true));
        $this->assertFalse(PaidServicesAdminPolicy::resolveEnabled('1', true));
    }

    public function testToggleAuditDoesNotTouchSalesOrPayments(): void
    {
        $off = PaidServicesAdminPolicy::toggleAudit(9, true, false, '2026-10-23T22:00:00+05:00');
        $this->assertSame('paid_services.module_off', $off['action']);
        $this->assertSame(PaidServicesAdminPolicy::SETTING_KEY, $off['context']['setting']);
        $this->assertTrue($off['context']['blocks_admin_catalog']);
        $this->assertTrue($off['context']['blocks_admin_availability_api']);
        $this->assertTrue($off['context']['keeps_service_sales_flags']);
        $this->assertTrue($off['context']['keeps_prices']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_payments']);
        $this->assertTrue($off['context']['keeps_entitlements']);
        $this->assertTrue($off['context']['keeps_quotas']);
        $this->assertTrue($off['context']['keeps_ad_placements']);
        $this->assertTrue($off['context']['no_refunds']);
        $this->assertContains(BoostPolicy::SETTING_KEY, $off['context']['unaffected']);
        $this->assertContains(VipPolicy::SETTING_KEY, $off['context']['unaffected']);
        $this->assertContains(ServiceListingFeePolicy::SETTING_KEY, $off['context']['unaffected']);

        $on = PaidServicesAdminPolicy::toggleAudit(9, false, true);
        $this->assertSame('paid_services.module_on', $on['action']);
        $this->assertFalse($on['context']['blocks_admin_catalog']);
    }

    public function testModuleOffGatesCatalogAndAvailabilityInServiceSource(): void
    {
        $src = (string) file_get_contents($this->root . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $catalog = $this->methodSource($src, 'catalog');
        $avail = $this->methodSource($src, 'setAvailability');
        $module = $this->methodSource($src, 'setModuleEnabled');

        $this->assertStringContainsString('isModuleEnabled()', $catalog);
        $this->assertStringContainsString('module_disabled', $catalog);
        $this->assertStringContainsString('isModuleEnabled()', $avail);
        $this->assertStringContainsString('module_disabled', $avail);

        // Переключение модуля само не блокируется module_disabled (иначе нельзя включить обратно).
        $this->assertStringNotContainsString('module_disabled', $module);
        $this->assertStringContainsString('PaidServicesAdminPolicy::canToggle', $module);
        $this->assertStringContainsString('unchanged', $module);
        $this->assertStringContainsString('isModuleEnabled()', $module);
        $this->assertStringContainsString('paid_services.module_on', (string) file_get_contents(
            $this->root . '/app/Services/PaidServices/PaidServicesAdminPolicy.php'
        ));
    }

    public function testGuestSetModuleDeniedWithoutTouchingSettings(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        unset($_SESSION['user'], $_SESSION['user_id']);

        $svc = new PaidServicesAdminService();
        $result = $svc->setModuleEnabled(false);
        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error_code']);
    }

    public function testDisablingOneServiceDoesNotDisableModuleFlagInPolicy(): void
    {
        $this->assertNotSame(BoostPolicy::SETTING_KEY, PaidServicesAdminPolicy::SETTING_KEY);
        $this->assertNotContains(
            PaidServicesAdminPolicy::SETTING_KEY,
            PaidServicesAdminPolicy::UNRELATED_CONTROLS
        );
        // Выключение boost — другой ключ; модуль VAS-17 остаётся своим.
        $boostOff = BoostPolicy::toggleAudit(1, true, false);
        $this->assertSame(BoostPolicy::SETTING_KEY, $boostOff['context']['setting']);
        $this->assertNotSame(PaidServicesAdminPolicy::SETTING_KEY, $boostOff['context']['setting']);
    }

    public function testModuleFlagDoesNotAlterPolicyPrices(): void
    {
        $before = [
            BoostPolicy::PRICE_KZT,
            VipPolicy::PRICE_KZT,
            ServiceListingFeePolicy::FEE_KZT,
            HighlightPolicy::PRICE_KZT,
        ];
        // «Изменение» флага модуля — только Setting, константы цен не трогаются.
        $this->assertSame('paid_services_admin_enabled', PaidServicesAdminPolicy::SETTING_KEY);
        $this->assertSame($before, [
            BoostPolicy::PRICE_KZT,
            VipPolicy::PRICE_KZT,
            ServiceListingFeePolicy::FEE_KZT,
            HighlightPolicy::PRICE_KZT,
        ]);
        $src = (string) file_get_contents($this->root . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $setModule = $this->methodSource($src, 'setModuleEnabled');
        $this->assertStringNotContainsString('PRICE_KZT', $setModule);
        $this->assertStringNotContainsString('FEE_KZT', $setModule);
        $this->assertStringNotContainsString('setEnabled(', $setModule);
        $this->assertStringNotContainsString('setPublishEnabled', $setModule);
        $this->assertStringNotContainsString('setSalesEnabled', $setModule);
    }

    public function testControllerAndRoutesWireModuleGateAndToggle(): void
    {
        $routes = (string) file_get_contents($this->root . '/config/routes.php');
        $this->assertStringContainsString("/admin/paid-services/module'", $routes);
        $this->assertStringContainsString('togglePaidServicesModule', $routes);
        // module route before {code}/availability
        $posModule = strpos($routes, "/admin/paid-services/module'");
        $posAvail = strpos($routes, "/admin/paid-services/{code}/availability'");
        $this->assertNotFalse($posModule);
        $this->assertNotFalse($posAvail);
        $this->assertLessThan($posAvail, $posModule);

        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $tog = $this->methodSource($ctrl, 'togglePaidServicesModule');
        $this->assertStringContainsString('setModuleEnabled', $tog);
        $this->assertStringContainsString('PaidServicesAdminPolicy::canToggle', $tog);

        $list = $this->methodSource($ctrl, 'paidServicesCatalog');
        $this->assertStringContainsString('module_disabled', $list);

        $avail = $this->methodSource($ctrl, 'togglePaidServiceAvailability');
        $this->assertStringContainsString('module_disabled', $avail);
    }

    public function testAdminIndexShowsModuleToggleAndHidesCatalogWhenOff(): void
    {
        $index = (string) file_get_contents($this->root . '/app/Views/admin/index.php');
        $this->assertStringContainsString('/admin/paid-services/module', $index);
        $this->assertStringContainsString('paidServicesAdminEnabled', $index);
        $this->assertStringContainsString('paid_services_module_disable_confirm', $index);
        $this->assertStringContainsString('if ($paidServicesAdminEnabled)', $index);
        $this->assertStringContainsString('paid_services_module_hint', $index);
        // Не смешивать с массовым отключением продаж
        $this->assertStringNotContainsString('all_paid_services', $index);
    }

    public function testIsModuleEnabledFailClosedOnThrowable(): void
    {
        $method = new ReflectionMethod(PaidServicesAdminService::class, 'isModuleEnabled');
        $src = (string) file_get_contents($this->root . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $body = $this->methodSource($src, 'isModuleEnabled');
        $this->assertStringContainsString('catch (Throwable)', $body);
        $this->assertStringContainsString('resolveEnabled(null, true)', $body);
        $this->assertTrue($method->isPublic());
    }

    public function testNoSecondDuplicateFlagService(): void
    {
        $files = glob($this->root . '/app/Services/PaidServices/*.php') ?: [];
        $names = array_map('basename', $files);
        $this->assertContains('PaidServicesAdminPolicy.php', $names);
        $this->assertContains('PaidServicesAdminService.php', $names);
        $flagFiles = array_filter($names, static fn (string $n): bool => str_contains(strtolower($n), 'featureflag'));
        $this->assertSame([], array_values($flagFiles));
    }

    private function methodSource(string $src, string $method): string
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*\S+\s*)?\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('method not found: ' . $method);
        }
        $start = (int) $m[0][1];
        $brace = strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        return substr($src, $start);
    }
}
