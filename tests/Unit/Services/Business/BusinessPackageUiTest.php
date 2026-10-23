<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use PHPUnit\Framework\TestCase;

final class BusinessPackageUiTest extends TestCase
{
    public function testPackagePageKeepsBoostIsolationMarker(): void
    {
        $package = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/business/package.php');
        $this->assertStringContainsString("t('business.lim_boosts')", $package);
        $this->assertStringNotContainsString('boost_enabled', $package);
    }

    public function testPackagePageDoesNotShowUnapprovedLongTermPrices(): void
    {
        $package = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/business/package.php');
        $this->assertStringContainsString('pendingLongTerm', $package);
        $this->assertStringContainsString('longterm_pending', $package);
        $this->assertStringNotContainsString('149900', $package);
        $this->assertStringNotContainsString('279900', $package);
        $this->assertStringNotContainsString('best_value', $package);
    }

    public function testPackagePageDoesNotPromiseAiGenerators(): void
    {
        $package = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/business/package.php');
        $this->assertStringContainsString('tools_ai_unavailable', $package);
        $this->assertStringContainsString('feat_ai_infographic_soon', $package);
        $this->assertStringContainsString('ai_run_check_hint', $package);
        $this->assertStringNotContainsString('sec2_i1', $package);
    }

    public function testPackagePageShowsAiAddonsAndQuotas(): void
    {
        $package = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/business/package.php');
        $this->assertStringContainsString('id="ai-addons"', $package);
        $this->assertStringContainsString('ai_addons_title', $package);
        $this->assertStringContainsString('aiAddonSalesFlags', $package);
        $this->assertStringContainsString('ai_quota_title', $package);
        $this->assertStringContainsString('/business/ai/infographic', $package);
        $this->assertStringContainsString('/business/ai/tryon', $package);
        $this->assertStringContainsString('js-bp-buy-form', $package);
    }

    public function testAiAddonRoutesRegistered(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/config/routes.php');
        $this->assertStringContainsString("/business/ai/infographic", $routes);
        $this->assertStringContainsString("/business/ai/tryon", $routes);
        $this->assertStringContainsString('/admin/business/ai-addon-sales', $routes);
    }

    public function testPackageBuyUsesServerIdempotencyAndCsrf(): void
    {
        $package = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/business/package.php');
        $this->assertStringContainsString('idempotency_key', $package);
        $this->assertStringContainsString('csrf_field()', $package);
        $this->assertStringContainsString('js-bp-buy-form', $package);
    }

    public function testAdminBusinessHasSeparateSalesAndFeaturesToggles(): void
    {
        $admin = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/admin/business.php');
        $this->assertStringContainsString('/admin/business/package-sales', $admin);
        $this->assertStringContainsString('/admin/business/package-features', $admin);
        $this->assertStringContainsString('business_sales_disable_confirm', $admin);
        $this->assertStringContainsString('business_features_disable_confirm', $admin);
    }

    public function testUiRoutesForImportAndManagerExist(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/config/routes.php');
        foreach ([
            "/business/import/xml",
            "/business/import/csv",
            "/business/api-keys",
            "/business/manager/request",
        ] as $path) {
            $this->assertStringContainsString($path, $routes, $path);
        }
    }

    public function testProfilePhotoMaxUsesServerValue(): void
    {
        $profile = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/profile/index.php');
        $this->assertStringContainsString('packageMaxPhotos', $profile);
        $this->assertStringContainsString('const MAX = <?= (int) $packageMaxPhotos ?>', $profile);
    }
}
