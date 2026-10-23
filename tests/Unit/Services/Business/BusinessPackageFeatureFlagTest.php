<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessPackagePolicy;
use App\Services\BusinessPackageService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Независимый переключатель бизнес-пакета (VAS-14.5).
 * Без записи в БД: политика, контракты, изоляция от VAS, UI/маршруты.
 */
final class BusinessPackageFeatureFlagTest extends TestCase
{
    public function testOnlyAdminCanToggle(): void
    {
        $this->assertTrue(BusinessPackagePolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(BusinessPackagePolicy::canToggle($role), (string) $role);
        }
    }

    public function testSalesOffBlocksPurchasePolicy(): void
    {
        $user = ['id' => 1, 'account_type' => 'business', 'business_status' => 'verified'];
        $this->assertSame('sales_disabled', BusinessPackagePolicy::purchaseBlock($user, false, true));
        $this->assertNull(BusinessPackagePolicy::purchaseBlock($user, true, true));
    }

    public function testSalesOffAuditKeepsSubscriptionsAndHistory(): void
    {
        $audit = BusinessPackagePolicy::salesToggleAudit(9, true, false, '2026-10-23T22:00:00+05:00');
        $this->assertSame('business.sales_off', $audit['action']);
        $this->assertTrue($audit['context']['keeps_subscriptions']);
        $this->assertTrue($audit['context']['keeps_history']);
        $this->assertFalse($audit['context']['cancels_paid_periods']);
        $this->assertFalse($audit['context']['refunds']);
        $this->assertTrue($audit['context']['blocks_new_purchases']);
        $this->assertSame([], $audit['context']['other_settings']);
        $this->assertSame(BusinessPackagePolicy::SETTING_SALES, $audit['context']['setting']);
    }

    public function testFeaturesOffAuditDoesNotCancelPaidPeriods(): void
    {
        $audit = BusinessPackagePolicy::featuresToggleAudit(9, true, false);
        $this->assertSame('business.features_off', $audit['action']);
        $this->assertFalse($audit['context']['cancels_paid_periods']);
        $this->assertTrue($audit['context']['keeps_subscriptions']);
        $this->assertFalse($audit['context']['refunds']);
    }

    public function testSalesAndFeaturesAreSeparateSettings(): void
    {
        $this->assertNotSame(
            BusinessPackagePolicy::SETTING_SALES,
            BusinessPackagePolicy::SETTING_FEATURES
        );
        $this->assertContains('purchase_plan', BusinessPackagePolicy::BLOCKED_WHEN_SALES_OFF);
        $this->assertContains('grant_trial', BusinessPackagePolicy::BLOCKED_WHEN_SALES_OFF);
        $this->assertContains('extend_subscription', BusinessPackagePolicy::BLOCKED_WHEN_SALES_OFF);
    }

    public function testToggleMethodsSkipDuplicateAuditWhenUnchanged(): void
    {
        $sales = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'setSalesEnabled'
        );
        $features = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'setFeaturesEnabled'
        );
        $this->assertStringContainsString('before === $enabled', $sales);
        $this->assertStringContainsString('before === $enabled', $features);
        $this->assertStringContainsString('canToggle', $sales);
        $this->assertStringContainsString('canToggle', $features);
        foreach (['boost_enabled', 'DELETE FROM business_subscriptions', 'cancelActiveForUser', 'refund'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $sales, $foreign);
            $this->assertStringNotContainsString($foreign, $features, $foreign);
        }
    }

    public function testTrialGrantRespectsSalesFlag(): void
    {
        $src = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'grantTrialIfEligible'
        );
        $this->assertStringContainsString('isSalesEnabled', $src);
        $this->assertStringContainsString('trial_skipped_sales_off', $src);
    }

    public function testPurchaseChecksSalesAfterIdempotentPaid(): void
    {
        $src = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'purchase'
        );
        $paidPos = strpos($src, 'PAYMENT_PAID');
        $salesPos = strpos($src, 'sales_disabled');
        $this->assertNotFalse($paidPos);
        $this->assertNotFalse($salesPos);
        $this->assertLessThan($salesPos, $paidPos, 'idempotent paid must be resolved before sales block');
    }

    public function testAssertFeatureGatesOnFeaturesFlagNotSales(): void
    {
        $src = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'assertFeature'
        );
        $this->assertStringContainsString('isFeaturesEnabled', $src);
        $this->assertStringNotContainsString('isSalesEnabled', $src);
    }

    public function testUnrelatedVasControlsAreNotTouchedByBusinessFlags(): void
    {
        foreach (BusinessPackagePolicy::UNRELATED_CONTROLS as $key) {
            $this->assertStringNotContainsString('business_package_', $key);
        }
        $service = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php');
        $toggleSales = $this->methodSource($service, 'setSalesEnabled');
        foreach (BusinessPackagePolicy::UNRELATED_CONTROLS as $control) {
            $this->assertStringNotContainsString($control, $toggleSales, $control);
        }
    }

    public function testAdminRoutesAndUiExposeBothToggles(): void
    {
        $root = dirname(__DIR__, 4);
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $adminView = (string) file_get_contents($root . '/app/Views/admin/business.php');
        $packageView = (string) file_get_contents($root . '/app/Views/business/package.php');
        $adminCtrl = (string) file_get_contents($root . '/app/Controllers/AdminController.php');

        $this->assertStringContainsString("post('/admin/business/package-sales'", $routes);
        $this->assertStringContainsString("post('/admin/business/package-features'", $routes);
        $this->assertDoesNotMatchRegularExpression("/get\\('\\/admin\\/business\\/package-sales'/", $routes);
        $this->assertStringContainsString('requireAdmin', $this->methodSource($adminCtrl, 'toggleBusinessPackageSales'));
        $this->assertStringContainsString('requireAdmin', $this->methodSource($adminCtrl, 'toggleBusinessPackageFeatures'));
        $this->assertStringContainsString('package-sales', $adminView);
        $this->assertStringContainsString('package-features', $adminView);
        $this->assertStringContainsString('salesEnabled', $packageView);
        $this->assertStringContainsString('sales_off_notice', $packageView);
        $this->assertStringContainsString('features_off_notice', $packageView);
    }

    public function testApiPurchaseReturnsForbiddenCodeForSalesOff(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/BusinessApiController.php');
        $purchase = $this->methodSource($api, 'purchase');
        $this->assertStringContainsString('sales_disabled', $purchase);
        $this->assertStringContainsString('403', $purchase);
    }

    public function testCancelActiveOnlyOnBusinessRevokeNotOnFlagToggle(): void
    {
        $upgrade = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessUpgradeService.php');
        $this->assertStringContainsString('cancelActiveForUser', $upgrade);
        $toggleSales = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'setSalesEnabled'
        );
        $toggleFeat = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php'),
            'setFeaturesEnabled'
        );
        $this->assertStringNotContainsString('cancelActiveForUser', $toggleSales);
        $this->assertStringNotContainsString('cancelActiveForUser', $toggleFeat);
    }

    public function testBoostFlagRemainsIndependent(): void
    {
        $boost = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Boost/BoostService.php');
        $toggle = $this->methodSource($boost, 'setEnabled');
        $this->assertStringNotContainsString('business_package_sales_enabled', $toggle);
        $this->assertStringNotContainsString('business_package_features_enabled', $toggle);
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
