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
use App\Services\PaidServices\PaidServicesAdminService;
use App\Services\TargetPush\TargetPushPolicy;
use App\Services\ThreeLifts\ThreeLiftsPolicy;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\Vip\VipPolicy;
use PHPUnit\Framework\TestCase;

/**
 * VAS-17.3: каталог поверх Policy; цены read-only; toggle делегируется; нет master-switch.
 */
final class PaidServicesAdminTest extends TestCase
{
    public function testCatalogCoversVas01To16WithoutMergingBillingModels(): void
    {
        $defs = PaidServiceCatalog::definitions();
        $vas = [];
        foreach ($defs as $row) {
            $vas[$row['vas_id']] = true;
            $this->assertNotSame('', $row['code']);
            $this->assertContains($row['billing_model'], [
                PaidServiceCatalog::BILLING_ONE_SHOT,
                PaidServiceCatalog::BILLING_TIMED_WINDOW,
                PaidServiceCatalog::BILLING_CPM,
                PaidServiceCatalog::BILLING_EXCLUSIVE_WEEKS,
                PaidServiceCatalog::BILLING_PUSH_SEND,
                PaidServiceCatalog::BILLING_SUBSCRIPTION,
                PaidServiceCatalog::BILLING_ADDON_QUOTA,
                PaidServiceCatalog::BILLING_LISTING_FEE,
                PaidServiceCatalog::BILLING_NONE,
            ], $row['code']);
        }
        foreach (['VAS-01', 'VAS-02', 'VAS-03', 'VAS-04', 'VAS-05', 'VAS-06', 'VAS-07', 'VAS-08', 'VAS-09', 'VAS-10', 'VAS-11', 'VAS-12', 'VAS-13', 'VAS-14', 'VAS-15', 'VAS-16'] as $id) {
            $this->assertArrayHasKey($id, $vas, $id);
        }

        $byCode = [];
        foreach ($defs as $row) {
            $byCode[$row['code']] = $row;
        }
        $this->assertSame(PaidServiceCatalog::STATUS_ABSENT, $byCode['in_app_offers']['implementation_status']);
        $this->assertFalse($byCode['in_app_offers']['can_toggle']);
        $this->assertSame(PaidServiceCatalog::STATUS_FLAG_ONLY, $byCode['three_lifts']['implementation_status']);
        $this->assertNotSame(
            $byCode['business_package_sales']['setting_key'],
            $byCode['business_package_features']['setting_key']
        );
        $this->assertNotSame(
            $byCode['ai_addon_infographic_sales']['setting_key'],
            $byCode['ai_addon_tryon_sales']['setting_key']
        );
    }

    public function testDisplayedPricesMatchPolicyConstants(): void
    {
        $map = [];
        foreach (PaidServiceCatalog::definitions() as $row) {
            $map[$row['code']] = $row['price_kzt'];
        }
        $this->assertSame(BoostPolicy::PRICE_KZT, $map['boost']);
        $this->assertSame(ThreeLiftsPolicy::PRICE_KZT, $map['three_lifts']);
        $this->assertSame(HighlightPolicy::PRICE_KZT, $map['highlight']);
        $this->assertSame(TopFixedPolicy::PRICE_KZT, $map['top_fixed']);
        $this->assertSame(TopFixedXlPolicy::PRICE_KZT, $map['top_fixed_xl']);
        $this->assertSame(VipPolicy::PRICE_KZT, $map['vip']);
        $this->assertSame(AdPolicy::CPM_KZT[AdPolicy::FORMAT_HERO], $map['ads_hero']);
        $this->assertSame(AdPolicy::CPM_KZT[AdPolicy::FORMAT_CATEGORY], $map['ads_category']);
        $this->assertSame(AdPolicy::CPM_KZT[AdPolicy::FORMAT_SIDEBAR], $map['ads_sidebar']);
        $this->assertSame(AdPolicy::EXCLUSIVE_WEEK_KZT, $map['ads_exclusive']);
        $this->assertSame(TargetPushPolicy::PRICE_KZT, $map['target_push']);
        $this->assertSame(MassPushPolicy::PRICE_KZT, $map['mass_push']);
        $this->assertSame(29900, $map['business_package_sales']);
        $this->assertSame(
            BusinessPackagePolicy::AI_ADDON_CATALOG[BusinessPackagePolicy::SLUG_AI_INFOGRAPHIC_ADDON]['price_kzt'],
            $map['ai_addon_infographic_sales']
        );
        $this->assertSame(
            BusinessPackagePolicy::AI_ADDON_CATALOG[BusinessPackagePolicy::SLUG_AI_TRYON_ADDON]['price_kzt'],
            $map['ai_addon_tryon_sales']
        );
        $this->assertSame(ServiceListingFeePolicy::FEE_KZT, $map['service_listing_publish']);
        $this->assertNull($map['in_app_offers']);
    }

    public function testGuestCannotListOrToggle(): void
    {
        $svc = new PaidServicesAdminService();
        $list = $svc->catalog();
        $this->assertFalse($list['ok']);
        $this->assertSame('forbidden', $list['error_code']);

        $tog = $svc->setAvailability('boost', false);
        $this->assertFalse($tog['ok']);
        $this->assertSame('forbidden', $tog['error_code']);
    }

    public function testPriceFieldsRejectedWithoutMutating(): void
    {
        $svc = new PaidServicesAdminService();
        foreach (['price' => 1, 'price_kzt' => 1, 'amount' => 1, 'cpm' => 1] as $key => $val) {
            $r = $svc->setAvailability('boost', true, [$key => $val]);
            // Without admin → forbidden first; with price key still forbidden or price_edit.
            // Ensure price_edit is checked: simulate by inspecting method order via source.
            $this->assertFalse($r['ok']);
        }

        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $method = $this->methodSource($src, 'setAvailability');
        $pricePos = strpos($method, 'price_edit_forbidden');
        $delegatePos = strpos($method, 'delegateSetEnabled');
        $this->assertNotFalse($pricePos);
        $this->assertNotFalse($delegatePos);
        $this->assertLessThan($delegatePos, $pricePos);
    }

    public function testAbsentAndUnknownCodesNotToggleable(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $method = $this->methodSource($src, 'setAvailability');
        $this->assertStringContainsString('not_found', $method);
        $this->assertStringContainsString('not_toggleable', $method);
        $this->assertStringContainsString('STATUS_ABSENT', $method);

        $this->assertNull(PaidServiceCatalog::find('no-such-code'));
        $absent = PaidServiceCatalog::find('in_app_offers');
        $this->assertNotNull($absent);
        $this->assertFalse($absent['can_toggle']);
    }

    public function testVipComponentsNotDoubleChargedInPolicy(): void
    {
        $this->assertSame(0, VipPolicy::COMPONENT_AMOUNT_KZT);
        $this->assertSame(2, VipPolicy::LIFT_COUNT);
        $vip = PaidServiceCatalog::find('vip');
        $this->assertNotNull($vip);
        $this->assertStringContainsString('amount 0', (string) $vip['notes']);
    }

    public function testServiceListingNotMixedWithEscrowPurchase(): void
    {
        $row = PaidServiceCatalog::find('service_listing_publish');
        $this->assertNotNull($row);
        $this->assertSame(PaidServiceCatalog::BILLING_LISTING_FEE, $row['billing_model']);
        $this->assertSame(ServiceListingFeePolicy::FEE_KZT, $row['price_kzt']);
        $this->assertStringContainsString('не эскроу', (string) $row['notes']);
    }

    public function testAdminRoutesAndControllerWired(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/config/routes.php');
        $this->assertStringContainsString("/admin/paid-services'", $routes);
        $this->assertStringContainsString('paidServicesCatalog', $routes);
        $this->assertStringContainsString('togglePaidServiceAvailability', $routes);

        $ctrl = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/AdminController.php');
        $list = $this->methodSource($ctrl, 'paidServicesCatalog');
        $this->assertStringContainsString('PaidServicesAdminService', $list);
        $tog = $this->methodSource($ctrl, 'togglePaidServiceAvailability');
        $this->assertStringContainsString('Csrf::enforce', $tog);
        $this->assertStringContainsString('setAvailability', $tog);
        $this->assertStringContainsString('price_edit_forbidden', $tog);
    }

    public function testAuditLogsBeforeAfterWithoutSecrets(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/PaidServices/PaidServicesAdminService.php');
        $method = $this->methodSource($src, 'setAvailability');
        $this->assertStringContainsString('paid_services.availability_changed', $method);
        $this->assertStringContainsString("'before'", $method);
        $this->assertStringContainsString("'after'", $method);
        $this->assertStringContainsString('no_refunds', $method);
        $this->assertStringContainsString('price_unchanged', $method);
        $this->assertStringNotContainsString('password', $method);
        $this->assertStringNotContainsString('card', strtolower($method));
    }

    public function testNoMasterSwitchInCatalog(): void
    {
        foreach (PaidServiceCatalog::definitions() as $row) {
            $this->assertNotSame('all', $row['code']);
            $this->assertNotSame('master', $row['code']);
        }
        $this->assertNotContains('all_paid_services', PaidServiceCatalog::toggleableCodes());
    }

    public function testExistingPurchaseGatesRemainServerSide(): void
    {
        // Регрессия: покупки по-прежнему проверяют свои isEnabled, не только каталог.
        $boost = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Boost/BoostService.php');
        $this->assertStringContainsString('isEnabled()', $boost);
        $listing = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/ProfileController.php');
        $this->assertStringContainsString('assertCanPublishNew', $listing);
        $biz = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php');
        $this->assertStringContainsString('isSalesEnabled', $biz);
    }

    private function methodSource(string $fileSrc, string $method): string
    {
        if (!preg_match(
            '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/',
            $fileSrc,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            $this->fail('Method not found: ' . $method);
        }
        $start = (int) $m[0][1];
        $brace = strpos($fileSrc, '{', $start);
        $this->assertNotFalse($brace);
        $depth = 0;
        $len = strlen($fileSrc);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $fileSrc[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($fileSrc, $start, $i - $start + 1);
                }
            }
        }
        $this->fail('Unbalanced braces for ' . $method);
    }
}
