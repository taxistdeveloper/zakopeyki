<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessPackagePolicy;
use App\Services\BusinessPackageService;
use PHPUnit\Framework\TestCase;

/**
 * VAS-15.5: независимое включение/отключение продажи двух AI-аддонов.
 */
final class BusinessAiAddonSalesIndependenceTest extends TestCase
{
    public function testSeparateSalesSettingsPerProduct(): void
    {
        $this->assertNotSame(
            BusinessPackagePolicy::SETTING_AI_INFOGRAPHIC_ADDON_SALES,
            BusinessPackagePolicy::SETTING_AI_TRYON_ADDON_SALES
        );
        $this->assertSame(
            BusinessPackagePolicy::SETTING_AI_INFOGRAPHIC_ADDON_SALES,
            BusinessPackagePolicy::aiAddonSalesSettingKey(BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON)
        );
        $this->assertSame(
            BusinessPackagePolicy::SETTING_AI_TRYON_ADDON_SALES,
            BusinessPackagePolicy::aiAddonSalesSettingKey(BusinessPackagePolicy::SLUG_AI_TRYON_ADDON)
        );
        $this->assertCount(2, BusinessPackagePolicy::aiAddonSlugs());
    }

    public function testToggleAuditKeepsBalancesAndDoesNotTouchOtherVas(): void
    {
        $audit = BusinessPackagePolicy::aiAddonSalesToggleAudit(
            7,
            true,
            false,
            BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON
        );
        $this->assertSame('business.ai_addon_sales_off_infographic', $audit['action']);
        $this->assertTrue($audit['context']['keeps_extra_quotas']);
        $this->assertTrue($audit['context']['keeps_history']);
        $this->assertTrue($audit['context']['keeps_payments']);
        $this->assertTrue($audit['context']['keeps_ai_operations']);
        $this->assertFalse($audit['context']['annuls_balances']);
        $this->assertTrue($audit['context']['does_not_disable_ai_feature']);
        $this->assertTrue($audit['context']['does_not_disable_business_package']);
        $this->assertSame([], $audit['context']['other_settings']);
        $this->assertSame(BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON, $audit['context']['package_slug']);
    }

    public function testPurchaseAddonGatesPerSlugNotCombinedOnly(): void
    {
        $src = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'purchaseAddon'
        );
        $this->assertStringContainsString('isAiAddonSalesEnabledForSlug', $src);
        $this->assertStringContainsString('ai_addon_sales_disabled', $src);
        // Повторная проверка внутри lock
        $this->assertSame(2, substr_count($src, 'isAiAddonSalesEnabledForSlug'));
        // Paid idempotent до блокировки продаж
        $paidPos = strpos($src, 'PAYMENT_PAID');
        $firstGate = strpos($src, 'isAiAddonSalesEnabledForSlug');
        $this->assertNotFalse($paidPos);
        $this->assertNotFalse($firstGate);
        $this->assertLessThan($firstGate, $paidPos);
    }

    public function testAssertFeatureDoesNotCheckAiAddonSales(): void
    {
        $src = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'assertFeature'
        );
        $this->assertStringNotContainsString('isAiAddonSalesEnabled', $src);
        $this->assertStringContainsString('isFeaturesEnabled', $src);
    }

    public function testToggleMethodsRequireAdminAndDoNotDeleteHistory(): void
    {
        $svc = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php');
        $set = $this->methodSource($svc, 'setAiAddonSalesEnabledForSlug');
        $this->assertStringContainsString('canToggle', $set);
        foreach (['DELETE FROM business_subscriptions', 'extra_ai_infographic = 0', 'cancelActiveForUser', 'refund'] as $bad) {
            $this->assertStringNotContainsString($bad, $set, $bad);
        }
        $this->assertTrue(method_exists(BusinessPackageService::class, 'aiAddonSalesFlags'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'isAiAddonSalesEnabledForSlug'));
    }

    public function testAdminUiHasIndependentProductToggles(): void
    {
        $root = dirname(__DIR__, 4);
        $admin = (string) file_get_contents($root . '/app/Views/admin/business.php');
        $this->assertStringContainsString('package_slug', $admin);
        $this->assertStringContainsString('SLUG_AI_INFOGRAPHIC_ADDON', $admin);
        $this->assertStringContainsString('SLUG_AI_TRYON_ADDON', $admin);
        $this->assertStringContainsString('business_ai_infographic_sales_label', $admin);
        $this->assertStringContainsString('business_ai_tryon_sales_label', $admin);
        $ctrl = $this->methodSource(
            (string) file_get_contents($root . '/app/Controllers/AdminController.php'),
            'toggleBusinessAiAddonSales'
        );
        $this->assertStringContainsString('requireAdmin', $ctrl);
        $this->assertStringContainsString('setAiAddonSalesEnabledForSlug', $ctrl);
        $this->assertStringContainsString('package_slug', $ctrl);
    }

    public function testPackageUiReflectsPerProductSalesFlags(): void
    {
        $package = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/business/package.php');
        $this->assertStringContainsString('aiAddonSalesFlags', $package);
        $this->assertStringContainsString('data-ai-addon-slug', $package);
        $this->assertStringContainsString('data-ai-addon-sales', $package);
        $this->assertStringContainsString('ai_addon_sales_off_one', $package);
    }

    public function testApiExposesPerSlugSalesMap(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/BusinessApiController.php');
        $this->assertStringContainsString('ai_addon_sales', $api);
        $this->assertStringContainsString('aiAddonSalesFlags', $api);
    }

    public function testBoostAndBusinessSalesRemainSeparateKeys(): void
    {
        foreach (BusinessPackagePolicy::UNRELATED_CONTROLS as $key) {
            $this->assertStringNotContainsString('ai_infographic_addon', $key);
            $this->assertStringNotContainsString('ai_tryon_addon', $key);
        }
        $this->assertNotSame(
            BusinessPackagePolicy::SETTING_SALES,
            BusinessPackagePolicy::SETTING_AI_INFOGRAPHIC_ADDON_SALES
        );
        $this->assertNotSame(
            BusinessPackagePolicy::SETTING_FEATURES,
            BusinessPackagePolicy::SETTING_AI_TRYON_ADDON_SALES
        );
        $boost = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Boost/BoostPolicy.php');
        $this->assertStringNotContainsString('business_ai_infographic_addon_sales_enabled', $boost);
        $this->assertStringNotContainsString('business_ai_tryon_addon_sales_enabled', $boost);
    }

    public function testCatalogPricesUnchanged(): void
    {
        $a = BusinessPackagePolicy::aiAddonSpec(BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON);
        $b = BusinessPackagePolicy::aiAddonSpec(BusinessPackagePolicy::SLUG_AI_TRYON_ADDON);
        $this->assertSame(4900, $a['price_kzt']);
        $this->assertSame(500, $a['extra_ai_infographic']);
        $this->assertSame(7900, $b['price_kzt']);
        $this->assertSame(100, $b['extra_ai_tryon']);
    }

    public function testOnlyAdminCanTogglePolicy(): void
    {
        $this->assertTrue(BusinessPackagePolicy::canToggle('admin'));
        $this->assertFalse(BusinessPackagePolicy::canToggle('manager'));
        $this->assertFalse(BusinessPackagePolicy::canToggle('user'));
        $this->assertFalse(BusinessPackagePolicy::canToggle(null));
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
