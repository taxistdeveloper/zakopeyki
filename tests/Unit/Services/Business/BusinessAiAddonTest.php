<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessPackagePolicy;
use App\Services\BusinessPackageService;
use PHPUnit\Framework\TestCase;

/**
 * VAS-15.3: каталог AI-аддонов, флаги, контракты начисления/операций (без MySQL e2e).
 */
final class BusinessAiAddonTest extends TestCase
{
    public function testApprovedCatalogPricesAndUnits(): void
    {
        $a = BusinessPackagePolicy::aiAddonSpec('addon-ai-infographic-500');
        $b = BusinessPackagePolicy::aiAddonSpec('addon-ai-tryon-100');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame(4900, $a['price_kzt']);
        $this->assertSame(500, $a['extra_ai_infographic']);
        $this->assertSame(7900, $b['price_kzt']);
        $this->assertSame(100, $b['extra_ai_tryon']);
    }

    public function testTrustedAiAddonRejectsClientPriceAndWrongUnits(): void
    {
        $ok = BusinessPackagePolicy::trustedTariff([
            'id' => 10,
            'slug' => 'addon-ai-infographic-500',
            'kind' => 'addon',
            'is_active' => 1,
            'price_kzt' => 4900,
            'limits_json' => json_encode(['extra_ai_infographic' => 500]),
        ], ['price_kzt' => 1, 'amount' => 1]);
        $this->assertTrue($ok['ok']);
        $this->assertSame(4900, $ok['price_kzt']);

        $badPrice = BusinessPackagePolicy::trustedAiAddon([
            'id' => 10,
            'slug' => 'addon-ai-infographic-500',
            'kind' => 'addon',
            'is_active' => 1,
            'price_kzt' => 100,
            'limits' => ['extra_ai_infographic' => 500],
        ]);
        $this->assertFalse($badPrice['ok']);
        $this->assertSame('package_misconfigured', $badPrice['error_code']);

        $badUnits = BusinessPackagePolicy::trustedAiAddon([
            'id' => 10,
            'slug' => 'addon-ai-tryon-100',
            'kind' => 'addon',
            'is_active' => 1,
            'price_kzt' => 7900,
            'limits' => ['extra_ai_tryon' => 999],
        ]);
        $this->assertFalse($badUnits['ok']);
    }

    public function testAiAddonSalesFlagDefaultsOffAndKeepsExtras(): void
    {
        $this->assertFalse(BusinessPackagePolicy::resolveFlag(null, false));
        $audit = BusinessPackagePolicy::aiAddonSalesToggleAudit(
            1,
            true,
            false,
            BusinessPackagePolicy::SLUG_AI_TRYON_ADDON
        );
        $this->assertSame('business.ai_addon_sales_off_tryon', $audit['action']);
        $this->assertTrue($audit['context']['keeps_extra_quotas']);
        $this->assertTrue($audit['context']['keeps_history']);
        $this->assertFalse($audit['context']['cancels_paid_periods']);
        $this->assertFalse($audit['context']['refunds']);
        $this->assertTrue($audit['context']['blocks_new_purchases']);
        $this->assertSame([], $audit['context']['other_settings']);
    }

    public function testPurchaseAddonSourceGatesAiSalesAfterIdempotentPaid(): void
    {
        $src = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'purchaseAddon'
        );
        $paidPos = strpos($src, 'PAYMENT_PAID');
        $aiPos = strpos($src, 'isAiAddonSalesEnabledForSlug');
        $this->assertNotFalse($paidPos);
        $this->assertNotFalse($aiPos);
        $this->assertLessThan($aiPos, $paidPos);
        $this->assertStringContainsString('ai_addon_sales_disabled', $src);
        $this->assertStringContainsString('aiAddonSpec', $src);
    }

    public function testGeneratorsRemainUnimplementedWithoutFakeSuccess(): void
    {
        $this->assertTrue(BusinessPackagePolicy::isUnimplementedGenerator(BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC));
        $this->assertTrue(BusinessPackagePolicy::isUnimplementedGenerator(BusinessPackagePolicy::FEATURE_AI_TRYON));
        $svc = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Business/BusinessAiOperationService.php');
        $this->assertStringContainsString('feature_not_implemented', $svc);
        $this->assertStringContainsString('tryReserve', $svc);
        $this->assertStringContainsString('releaseReserve', $svc);
        $this->assertStringContainsString('markSucceeded', $svc);
        $this->assertStringNotContainsString('fake_success', $svc);
    }

    public function testUsageAtomicHelpersExist(): void
    {
        $usage = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Models/BusinessUsage.php');
        $this->assertStringContainsString('function tryReserve', $usage);
        $this->assertStringContainsString('function releaseReserve', $usage);
        $this->assertStringContainsString('used <= ?', $usage);
        $this->assertStringContainsString('used >= ?', $usage);
    }

    public function testAdminAiAddonSalesRouteAndIsolationFromBoost(): void
    {
        $root = dirname(__DIR__, 4);
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $this->assertStringContainsString('/admin/business/ai-addon-sales', $routes);
        $admin = (string) file_get_contents($root . '/app/Views/admin/business.php');
        $this->assertStringContainsString('ai-addon-sales', $admin);
        $boost = (string) file_get_contents($root . '/app/Services/Boost/BoostPolicy.php');
        $this->assertStringNotContainsString('business_ai_addon_sales_enabled', $boost);
        $this->assertTrue(method_exists(BusinessPackageService::class, 'isAiAddonSalesEnabled'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'isAiAddonSalesEnabledForSlug'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'setAiAddonSalesEnabledForSlug'));
    }

    public function testSeedMatchesApprovedCatalog(): void
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Models/BusinessPackage.php');
        $this->assertStringContainsString("'slug' => 'addon-ai-infographic-500'", $seed);
        $this->assertStringContainsString("'price_kzt' => 4900", $seed);
        $this->assertStringContainsString("'extra_ai_infographic' => 500", $seed);
        $this->assertStringContainsString("'slug' => 'addon-ai-tryon-100'", $seed);
        $this->assertStringContainsString("'price_kzt' => 7900", $seed);
        $this->assertStringContainsString("'extra_ai_tryon' => 100", $seed);
    }

    public function testApiAiContractPassesOperationKey(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/BusinessApiController.php');
        $this->assertStringContainsString('operation_key', $api);
        $this->assertStringContainsString('ai_addon_sales_enabled', $api);
        $this->assertStringContainsString('501', $api);
    }

    private function methodSource(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method);
        $this->assertNotFalse($start, $method);
        $next = strpos($source, "\n    public function ", $start + 10);
        if ($next === false) {
            $next = strpos($source, "\n    private function ", $start + 10);
        }
        if ($next === false) {
            return substr($source, $start);
        }

        return substr($source, $start, $next - $start);
    }
}
