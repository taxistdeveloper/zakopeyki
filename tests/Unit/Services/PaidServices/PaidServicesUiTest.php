<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PaidServices;

use App\Core\Lang;
use App\Services\PaidServices\PaidServiceCatalog;
use PHPUnit\Framework\TestCase;

/**
 * VAS-17.4: админ UI каталога платных услуг — контракт view + API, без фиктивных данных.
 */
final class PaidServicesUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
        $GLOBALS['appConfig']['url'] = '';
        $GLOBALS['appConfig']['locale'] = 'ru';
        Lang::boot();
        Lang::set('ru');
    }

    public function testAdminPageUsesBackendServiceNotFakeCatalog(): void
    {
        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $method = $this->methodSource($ctrl, 'paidServicesCatalog');
        $this->assertStringContainsString('PaidServicesAdminService', $method);
        $this->assertStringContainsString("view('admin/paid-services'", $method);
        $this->assertStringContainsString('application/json', $method);
        $this->assertStringContainsString("format'] ?? '') === 'json'", $method);
        $this->assertStringContainsString('requireAdmin', $method);
        $this->assertStringNotContainsString('demo_services', $method);
        $this->assertStringNotContainsString('fake', strtolower($method));
    }

    public function testViewBindsRealApiToggleAndNoPriceEdit(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/paid-services.php');
        $this->assertStringContainsString('/admin/paid-services', $view);
        $this->assertStringContainsString('/availability', $view);
        $this->assertStringContainsString('js-ps-toggle', $view);
        $this->assertStringContainsString('pending[code]', $view);
        $this->assertStringContainsString('window.confirm', $view);
        $this->assertStringContainsString('aria-busy', $view);
        $this->assertStringContainsString('Accept', $view);
        $this->assertStringContainsString('_csrf', $view);
        $this->assertStringContainsString('price_locked', $view);
        $this->assertStringContainsString('price_readonly_note', $view);
        $this->assertStringNotContainsString('name="price"', $view);
        $this->assertStringNotContainsString('name="price_kzt"', $view);
        $this->assertStringNotContainsString('contenteditable', $view);
    }

    public function testSuccessOnlyAfterServerOk(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/paid-services.php');
        $this->assertStringContainsString('if (!pack.okHttp || !data.ok)', $view);
        $this->assertStringContainsString('paintRow(row, after)', $view);
        $posGate = strpos($view, 'if (!pack.okHttp || !data.ok)');
        $posSuccess = strpos($view, "showBanner('ok'");
        $this->assertNotFalse($posGate);
        $this->assertNotFalse($posSuccess);
        $this->assertGreaterThan($posGate, $posSuccess);
    }

    public function testIndependentRowUpdatesOnlyTouchedService(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/paid-services.php');
        $this->assertStringContainsString('function paintRow(row, enabled)', $view);
        $this->assertStringContainsString('paintRow(row, after)', $view);
        $this->assertStringContainsString('data-code', $view);
        $this->assertStringNotContainsString('toggle-all', $view);
        $this->assertStringNotContainsString('mass-disable', $view);
        $this->assertStringNotContainsString('all_paid_services', $view);
    }

    public function testConflictAndErrorHandling(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/paid-services.php');
        $this->assertStringContainsString('conflict', $view);
        $this->assertStringContainsString('data-msg-error', $view);
        $this->assertStringContainsString('data-msg-forbidden', $view);
        $this->assertStringContainsString('mapError', $view);
    }

    public function testAuditLinkUsesExistingLogsNotFakeHistory(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/paid-services.php');
        $this->assertStringContainsString('/admin/logs?action=paid_services', $view);
        $this->assertStringContainsString('paid_services_audit_note', $view);
        $this->assertStringNotContainsString('fake_history', $view);
        $this->assertStringNotContainsString('mockAudit', $view);
        $this->assertStringNotContainsString('demo_log', $view);
    }

    public function testBillingLabelsCoverVasModels(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/paid-services.php');
        foreach ([
            'one_shot',
            'timed_window',
            'cpm_thousands',
            'exclusive_weeks',
            'push_send',
            'subscription_month',
            'addon_quota',
            'listing_per_item',
            'none',
        ] as $model) {
            $this->assertArrayHasKey($model, [
                'one_shot' => 1,
                'timed_window' => 1,
                'cpm_thousands' => 1,
                'exclusive_weeks' => 1,
                'push_send' => 1,
                'subscription_month' => 1,
                'addon_quota' => 1,
                'listing_per_item' => 1,
                'none' => 1,
            ]);
            $this->assertStringContainsString("'" . $model . "'", $view);
        }

        $models = [];
        foreach (PaidServiceCatalog::definitions() as $row) {
            $models[(string) $row['billing_model']] = true;
        }
        foreach (array_keys($models) as $m) {
            $this->assertStringContainsString("'" . $m . "'", $view);
        }
    }

    public function testVipShownAsSinglePackageAndTwoAiQuotasSeparate(): void
    {
        $vip = PaidServiceCatalog::find('vip');
        $this->assertNotNull($vip);
        $this->assertSame('VAS-06', $vip['vas_id']);
        $this->assertStringContainsString('Пакет', (string) $vip['notes']);

        $ai1 = PaidServiceCatalog::find('ai_addon_infographic_sales');
        $ai2 = PaidServiceCatalog::find('ai_addon_tryon_sales');
        $this->assertNotNull($ai1);
        $this->assertNotNull($ai2);
        $this->assertSame('VAS-15', $ai1['vas_id']);
        $this->assertSame('VAS-15', $ai2['vas_id']);
        $this->assertNotSame($ai1['code'], $ai2['code']);

        $listing = PaidServiceCatalog::find('service_listing_publish');
        $this->assertNotNull($listing);
        $this->assertSame(PaidServiceCatalog::BILLING_LISTING_FEE, $listing['billing_model']);
        $this->assertStringContainsString('не эскроу', (string) $listing['notes']);

        $note = t('admin.paid_services_billing_listing');
        $this->assertMatchesRegularExpression('/Услуг|Қызмет/u', $note);
    }

    public function testLangKeysPresentRuAndKk(): void
    {
        foreach (['ru', 'kk'] as $locale) {
            Lang::set($locale);
            $this->assertNotSame('admin.paid_services', t('admin.paid_services'));
            $this->assertNotSame('', trim(t('admin.paid_services')));
            $this->assertStringContainsString('VAS', t('admin.paid_services_hint_short'));
            $this->assertNotEmpty(t('admin.paid_services_disable_confirm'));
        }
        Lang::set('ru');
    }

    public function testIndexNavLinksToPaidServicesWhenModuleEnabled(): void
    {
        $index = (string) file_get_contents($this->root . '/app/Views/admin/index.php');
        $this->assertStringContainsString('/admin/paid-services', $index);
        $this->assertStringContainsString('paidServicesAdminEnabled', $index);
        $this->assertStringContainsString('if ($paidServicesAdminEnabled)', $index);
        $this->assertStringContainsString('/admin/paid-services/module', $index);
    }

    public function testToggleEndpointStillServerAuthoritative(): void
    {
        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $tog = $this->methodSource($ctrl, 'togglePaidServiceAvailability');
        $this->assertStringContainsString('Csrf::enforce', $tog);
        $this->assertStringContainsString('setAvailability', $tog);
        $this->assertStringContainsString('price_edit_forbidden', $tog);
    }

    private function methodSource(string $src, string $method): string
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
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
