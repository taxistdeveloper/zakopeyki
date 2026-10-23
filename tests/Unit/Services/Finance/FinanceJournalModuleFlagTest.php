<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Services\Ads\AdPolicy;
use App\Services\Boost\BoostPolicy;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\Finance\FinanceJournalPolicy;
use App\Services\Finance\FinanceJournalService;
use App\Services\Highlight\HighlightPolicy;
use App\Services\Listing\ServiceListingFeePolicy;
use App\Services\MassPush\MassPushPolicy;
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
 * VAS-18.5: независимый флаг админ-журнала ≠ VAS-17 ≠ продажи VAS-01…16 ≠ платежи.
 */
final class FinanceJournalModuleFlagTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
    }

    public function testSettingKeyIsDedicatedAndIndependent(): void
    {
        $this->assertSame('finance_journal_admin_enabled', FinanceJournalPolicy::SETTING_KEY);
        $this->assertNotSame(PaidServicesAdminPolicy::SETTING_KEY, FinanceJournalPolicy::SETTING_KEY);

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
            PaidServicesAdminPolicy::SETTING_KEY,
        ];
        foreach (AdPolicy::SETTING_KEYS as $k) {
            $salesKeys[] = $k;
        }
        $this->assertNotContains(FinanceJournalPolicy::SETTING_KEY, $salesKeys);
    }

    public function testOnlyAdminCanToggleAndView(): void
    {
        $this->assertTrue(FinanceJournalPolicy::canToggle('admin'));
        $this->assertTrue(FinanceJournalPolicy::canView('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(FinanceJournalPolicy::canToggle($role), (string) $role);
            $this->assertFalse(FinanceJournalPolicy::canView($role), (string) $role);
        }
    }

    public function testResolveEnabledDefaultsOnAndFailClosedOnReadError(): void
    {
        $this->assertTrue(FinanceJournalPolicy::resolveEnabled(null));
        $this->assertTrue(FinanceJournalPolicy::resolveEnabled(true));
        $this->assertTrue(FinanceJournalPolicy::resolveEnabled(1));
        $this->assertTrue(FinanceJournalPolicy::resolveEnabled('1'));
        $this->assertTrue(FinanceJournalPolicy::resolveEnabled('on'));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled(false));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled(0));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled('0'));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled('off'));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled([], false));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled(true, true));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled(null, true));
        $this->assertFalse(FinanceJournalPolicy::resolveEnabled('1', true));
    }

    public function testToggleAuditKeepsVas17SalesPaymentsAndHistory(): void
    {
        $off = FinanceJournalPolicy::toggleAudit(9, true, false, '2026-10-23T23:00:00+05:00');
        $this->assertSame('finance_journal.module_off', $off['action']);
        $this->assertSame(FinanceJournalPolicy::SETTING_KEY, $off['context']['setting']);
        $this->assertTrue($off['context']['blocks_admin_journal']);
        $this->assertTrue($off['context']['blocks_admin_journal_api']);
        $this->assertTrue($off['context']['keeps_vas17_module']);
        $this->assertTrue($off['context']['keeps_service_sales_flags']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_payments']);
        $this->assertTrue($off['context']['keeps_payment_webhooks']);
        $this->assertTrue($off['context']['keeps_background_jobs']);
        $this->assertTrue($off['context']['keeps_entitlements']);
        $this->assertTrue($off['context']['no_mutation_of_finance_rows']);
        $this->assertTrue($off['context']['no_refunds']);
        $this->assertContains(PaidServicesAdminPolicy::SETTING_KEY, $off['context']['unaffected']);
        $this->assertContains(BoostPolicy::SETTING_KEY, $off['context']['unaffected']);
        $this->assertContains(VipPolicy::SETTING_KEY, $off['context']['unaffected']);
        $this->assertContains('freedompay', $off['context']['unaffected']);
        $this->assertContains('halyk_epay', $off['context']['unaffected']);

        $on = FinanceJournalPolicy::toggleAudit(9, false, true);
        $this->assertSame('finance_journal.module_on', $on['action']);
        $this->assertFalse($on['context']['blocks_admin_journal']);
    }

    public function testModuleOffGatesListAndDetailInServiceSource(): void
    {
        $src = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $list = $this->methodSource($src, 'list');
        $detail = $this->methodSource($src, 'detail');
        $module = $this->methodSource($src, 'setModuleEnabled');

        $this->assertStringContainsString('isModuleEnabled()', $list);
        $this->assertStringContainsString('module_disabled', $list);
        $this->assertStringContainsString('isModuleEnabled()', $detail);
        $this->assertStringContainsString('module_disabled', $detail);

        // Переключение модуля само не блокируется module_disabled.
        $this->assertStringNotContainsString('module_disabled', $module);
        $this->assertStringContainsString('FinanceJournalPolicy::canToggle', $module);
        $this->assertStringContainsString('unchanged', $module);
        $this->assertStringContainsString('isModuleEnabled()', $module);
        $this->assertStringNotContainsString('DELETE FROM', $module);
        $this->assertStringNotContainsString('UPDATE ', strtoupper($module));
        $this->assertStringNotContainsString('setEnabled(', $module);
        $this->assertStringNotContainsString('setSalesEnabled', $module);
        $this->assertStringNotContainsString('PaidServicesAdmin', $module);
    }

    public function testGuestSetModuleDeniedWithoutTouchingSettings(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        unset($_SESSION['user'], $_SESSION['user_id']);

        $svc = new FinanceJournalService();
        $result = $svc->setModuleEnabled(false);
        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error_code']);
    }

    public function testDisablingVas18DoesNotShareKeyWithVas17OrSales(): void
    {
        $this->assertNotSame(BoostPolicy::SETTING_KEY, FinanceJournalPolicy::SETTING_KEY);
        $this->assertNotSame(PaidServicesAdminPolicy::SETTING_KEY, FinanceJournalPolicy::SETTING_KEY);
        $this->assertNotContains(
            FinanceJournalPolicy::SETTING_KEY,
            FinanceJournalPolicy::UNRELATED_CONTROLS
        );
        $boostOff = BoostPolicy::toggleAudit(1, true, false);
        $this->assertNotSame(FinanceJournalPolicy::SETTING_KEY, $boostOff['context']['setting']);
        $vas17Off = PaidServicesAdminPolicy::toggleAudit(1, true, false);
        $this->assertNotSame(FinanceJournalPolicy::SETTING_KEY, $vas17Off['context']['setting']);
    }

    public function testVas17ModuleFlagRemainsIndependentInPolicy(): void
    {
        $this->assertContains(
            FinanceJournalPolicy::SETTING_KEY,
            PaidServicesAdminPolicy::UNRELATED_CONTROLS
        );
        $this->assertContains(
            PaidServicesAdminPolicy::SETTING_KEY,
            FinanceJournalPolicy::UNRELATED_CONTROLS
        );
        $svc17 = (string) file_get_contents($this->root . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $set17 = $this->methodSource($svc17, 'setModuleEnabled');
        $this->assertStringNotContainsString('finance_journal', $set17);
        $this->assertStringNotContainsString(FinanceJournalPolicy::SETTING_KEY, $set17);
    }

    public function testPaymentHandlersNotCoupledToJournalModuleFlag(): void
    {
        $journalSvc = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $this->assertStringNotContainsString('completeFromGateway', $journalSvc);
        $this->assertStringNotContainsString('FreedomPay', $journalSvc);
        $this->assertStringNotContainsString('Halyk', $journalSvc);
        $this->assertStringNotContainsString('webhook', strtolower($journalSvc));

        foreach ([
            'app/Services/EscrowService.php',
            'app/Controllers/PaymentController.php',
            'app/Controllers/WebhookController.php',
        ] as $rel) {
            $path = $this->root . '/' . $rel;
            if (!is_file($path)) {
                continue;
            }
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString(
                FinanceJournalPolicy::SETTING_KEY,
                $src,
                $rel . ' must not depend on VAS-18 module flag'
            );
            $this->assertStringNotContainsString('FinanceJournalService', $src, $rel);
            $this->assertStringNotContainsString('isModuleEnabled', $src, $rel);
        }
    }

    public function testControllerAndRoutesWireModuleGateAndToggle(): void
    {
        $routes = (string) file_get_contents($this->root . '/config/routes.php');
        $this->assertStringContainsString("/admin/finance-journal/module'", $routes);
        $this->assertStringContainsString('toggleFinanceJournalModule', $routes);
        $posModule = strpos($routes, "/admin/finance-journal/module'");
        $posDetail = strpos($routes, "/admin/finance-journal/{entryId}'");
        $this->assertNotFalse($posModule);
        $this->assertNotFalse($posDetail);
        $this->assertLessThan($posDetail, $posModule);

        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $tog = $this->methodSource($ctrl, 'toggleFinanceJournalModule');
        $this->assertStringContainsString('setModuleEnabled', $tog);
        $this->assertStringContainsString('FinanceJournalPolicy::canToggle', $tog);

        $list = $this->methodSource($ctrl, 'financeJournalList');
        $this->assertStringContainsString('module_disabled', $list);
        $detail = $this->methodSource($ctrl, 'financeJournalDetail');
        $this->assertStringContainsString('module_disabled', $detail);
    }

    public function testAdminIndexShowsModuleToggleAndHidesJournalWhenOff(): void
    {
        $index = (string) file_get_contents($this->root . '/app/Views/admin/index.php');
        $this->assertStringContainsString('/admin/finance-journal/module', $index);
        $this->assertStringContainsString('financeJournalEnabled', $index);
        $this->assertStringContainsString('finance_journal_module_disable_confirm', $index);
        $this->assertStringContainsString('if ($financeJournalEnabled)', $index);
        $this->assertStringContainsString('finance_journal_module_hint', $index);
        $this->assertStringNotContainsString('all_finance', $index);
    }

    public function testIsModuleEnabledFailClosedOnThrowable(): void
    {
        $method = new ReflectionMethod(FinanceJournalService::class, 'isModuleEnabled');
        $src = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $body = $this->methodSource($src, 'isModuleEnabled');
        $this->assertStringContainsString('catch (Throwable)', $body);
        $this->assertStringContainsString('resolveEnabled(null, true)', $body);
        $this->assertTrue($method->isPublic());
    }

    public function testNoDuplicateFlagServiceAndReadOnlyJournalIntact(): void
    {
        $files = glob($this->root . '/app/Services/Finance/*.php') ?: [];
        $names = array_map('basename', $files);
        $this->assertContains('FinanceJournalPolicy.php', $names);
        $this->assertContains('FinanceJournalService.php', $names);
        $flagFiles = array_filter($names, static fn (string $n): bool => str_contains(strtolower($n), 'featureflag'));
        $this->assertSame([], array_values($flagFiles));

        $svc = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $this->assertStringNotContainsString("->exec('DELETE", $svc);
        $this->assertDoesNotMatchRegularExpression('/\bUPDATE\b/i', $this->methodSource($svc, 'list'));
        $this->assertDoesNotMatchRegularExpression('/\bUPDATE\b/i', $this->methodSource($svc, 'detail'));
    }

    public function testIdempotentUnchangedPathInSetModuleEnabled(): void
    {
        $src = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $body = $this->methodSource($src, 'setModuleEnabled');
        $this->assertStringContainsString("\$before === \$enabled", $body);
        $this->assertStringContainsString("'unchanged' => true", $body);
        $this->assertStringContainsString('setBool', $body);
    }

    public function testPaidServicesAdminServiceStillIndependent(): void
    {
        $this->assertTrue(method_exists(PaidServicesAdminService::class, 'isModuleEnabled'));
        $this->assertTrue(method_exists(FinanceJournalService::class, 'isModuleEnabled'));
        $this->assertNotSame(
            PaidServicesAdminPolicy::SETTING_KEY,
            FinanceJournalPolicy::SETTING_KEY
        );
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
