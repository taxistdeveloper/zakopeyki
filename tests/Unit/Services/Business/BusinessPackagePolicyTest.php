<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessPackagePolicy;
use PHPUnit\Framework\TestCase;

final class BusinessPackagePolicyTest extends TestCase
{
    public function testSalesFlagDefaultsOnWhenMissing(): void
    {
        $this->assertTrue(BusinessPackagePolicy::resolveFlag(null, true));
        $this->assertFalse(BusinessPackagePolicy::resolveFlag(null, false));
        $this->assertTrue(BusinessPackagePolicy::resolveFlag(true));
        $this->assertTrue(BusinessPackagePolicy::resolveFlag('1'));
        $this->assertTrue(BusinessPackagePolicy::resolveFlag('on'));
        $this->assertFalse(BusinessPackagePolicy::resolveFlag(false));
        $this->assertFalse(BusinessPackagePolicy::resolveFlag('0'));
        $this->assertFalse(BusinessPackagePolicy::resolveFlag('off'));
    }

    public function testOnlyAdminCanToggle(): void
    {
        $this->assertTrue(BusinessPackagePolicy::canToggle('admin'));
        foreach (['manager', 'user', '', null] as $role) {
            $this->assertFalse(BusinessPackagePolicy::canToggle($role), (string) $role);
        }
    }

    public function testTrustedTariffIgnoresClientPriceAndDays(): void
    {
        $package = [
            'id' => 5,
            'slug' => 'business-month',
            'is_active' => 1,
            'kind' => 'plan',
            'price_kzt' => 29900,
            'duration_days' => 30,
        ];
        $tariff = BusinessPackagePolicy::trustedTariff($package, [
            'price' => 1,
            'price_kzt' => 1,
            'amount' => 1,
            'days' => 999,
            'duration_days' => 999,
        ]);
        $this->assertTrue($tariff['ok']);
        $this->assertSame(29900, $tariff['price_kzt']);
        $this->assertSame(30, $tariff['duration_days']);
    }

    public function testTrustedTariffRejectsInactivePackage(): void
    {
        $tariff = BusinessPackagePolicy::trustedTariff([
            'id' => 1,
            'slug' => 'business-month',
            'is_active' => 0,
            'kind' => 'plan',
            'price_kzt' => 29900,
            'duration_days' => 30,
        ]);
        $this->assertFalse($tariff['ok']);
        $this->assertSame('package_inactive', $tariff['error_code']);
    }

    public function testTrustedTariffRejectsUnapprovedLongTermEvenIfActive(): void
    {
        foreach (['business-6m', 'business-12m', 'business-3m'] as $slug) {
            $tariff = BusinessPackagePolicy::trustedTariff([
                'id' => 9,
                'slug' => $slug,
                'is_active' => 1,
                'kind' => 'plan',
                'price_kzt' => 1,
                'duration_days' => 180,
            ]);
            $this->assertFalse($tariff['ok'], $slug);
            $this->assertSame('package_not_approved', $tariff['error_code'], $slug);
        }
    }

    public function testPurchaseBlockSalesDisabled(): void
    {
        $user = ['id' => 1, 'account_type' => 'business', 'business_status' => 'verified'];
        $this->assertSame('sales_disabled', BusinessPackagePolicy::purchaseBlock($user, false, true));
        $this->assertSame('business_required', BusinessPackagePolicy::purchaseBlock($user, true, false));
        $this->assertNull(BusinessPackagePolicy::purchaseBlock($user, true, true));
    }

    public function testFeaturesToggleDoesNotCancelPaidPeriods(): void
    {
        $audit = BusinessPackagePolicy::featuresToggleAudit(3, true, false, '2026-10-23T12:00:00+05:00');
        $this->assertSame('business.features_off', $audit['action']);
        $this->assertFalse($audit['context']['cancels_paid_periods']);
        $this->assertTrue($audit['context']['keeps_subscriptions']);
        $this->assertFalse($audit['context']['refunds']);
        $this->assertSame([], $audit['context']['other_settings']);
    }

    public function testSalesToggleKeepsHistory(): void
    {
        $audit = BusinessPackagePolicy::salesToggleAudit(3, true, false);
        $this->assertSame('business.sales_off', $audit['action']);
        $this->assertTrue($audit['context']['keeps_history']);
        $this->assertSame(BusinessPackagePolicy::SETTING_SALES, $audit['context']['setting']);
    }

    public function testUnimplementedGeneratorsListed(): void
    {
        $this->assertTrue(BusinessPackagePolicy::isUnimplementedGenerator(BusinessPackagePolicy::FEATURE_AI_INFOGRAPHIC));
        $this->assertTrue(BusinessPackagePolicy::isUnimplementedGenerator(BusinessPackagePolicy::FEATURE_AI_SEO));
        $this->assertTrue(BusinessPackagePolicy::isUnimplementedGenerator(BusinessPackagePolicy::FEATURE_AI_TRYON));
        $this->assertFalse(BusinessPackagePolicy::isUnimplementedGenerator(BusinessPackagePolicy::FEATURE_IMPORT_CSV));
    }

    public function testUnrelatedControlsDoNotIncludeBusinessFlagsInBoostSense(): void
    {
        foreach (BusinessPackagePolicy::UNRELATED_CONTROLS as $key) {
            $this->assertStringNotContainsString('business_package_', $key);
        }
        $this->assertContains('boost_enabled', BusinessPackagePolicy::UNRELATED_CONTROLS);
    }
}
