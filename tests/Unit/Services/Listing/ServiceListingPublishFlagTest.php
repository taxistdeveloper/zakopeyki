<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Listing;

use App\Helpers\ProductHelper;
use App\Services\Listing\ServiceListingFeePolicy;
use PHPUnit\Framework\TestCase;

/**
 * VAS-16.5: независимый флаг новых публикаций service.
 */
final class ServiceListingPublishFlagTest extends TestCase
{
    public function testDefaultMissingSettingIsEnabled(): void
    {
        $this->assertTrue(ServiceListingFeePolicy::resolveEnabled(null));
        $this->assertTrue(ServiceListingFeePolicy::resolveEnabled(true));
        $this->assertTrue(ServiceListingFeePolicy::resolveEnabled('1'));
        $this->assertTrue(ServiceListingFeePolicy::resolveEnabled('on'));
    }

    public function testDisabledValuesAndFailClosedOnReadError(): void
    {
        $this->assertFalse(ServiceListingFeePolicy::resolveEnabled(false));
        $this->assertFalse(ServiceListingFeePolicy::resolveEnabled('0'));
        $this->assertFalse(ServiceListingFeePolicy::resolveEnabled('off'));
        $this->assertFalse(ServiceListingFeePolicy::resolveEnabled(null, true));
    }

    public function testAssertBlocksOnlyServiceWhenOff(): void
    {
        $off = ServiceListingFeePolicy::assertNewPublishAllowed('service', false);
        $this->assertFalse($off['ok']);
        $this->assertSame('publish_disabled', $off['code']);

        $on = ServiceListingFeePolicy::assertNewPublishAllowed('service', true);
        $this->assertTrue($on['ok']);

        foreach (['used', 'new', 'free', 'exchange', 'gig', 'auction'] as $type) {
            $this->assertTrue(ServiceListingFeePolicy::assertNewPublishAllowed($type, false)['ok'], $type);
        }
    }

    public function testOnlyAdminCanToggle(): void
    {
        $this->assertTrue(ServiceListingFeePolicy::canToggle('admin'));
        foreach (['manager', 'user', '', null] as $role) {
            $this->assertFalse(ServiceListingFeePolicy::canToggle($role), (string) $role);
        }
    }

    public function testToggleAuditKeepsNeighborsAndHistory(): void
    {
        $audit = ServiceListingFeePolicy::toggleAudit(7, true, false);
        $this->assertSame('service_listing.feature_off', $audit['action']);
        $this->assertTrue($audit['context']['blocks_new_service_publish']);
        $this->assertTrue($audit['context']['keeps_existing_listings']);
        $this->assertTrue($audit['context']['keeps_payment_history']);
        $this->assertTrue($audit['context']['no_refunds']);
        $this->assertContains('boost_enabled', $audit['context']['unaffected']);
        $this->assertSame(ServiceListingFeePolicy::SETTING_KEY, $audit['context']['setting']);
    }

    public function testFeeUnchangedAndSafeDealIntact(): void
    {
        $this->assertSame(500, ServiceListingFeePolicy::FEE_KZT);
        $this->assertSame(500, ProductHelper::serviceListingFee());
        $this->assertNotContains('service', ProductHelper::PURCHASABLE_TYPES);
        $this->assertContains('used', ProductHelper::PURCHASABLE_TYPES);
        $this->assertTrue(ProductHelper::isPurchasable([
            'type' => 'used',
            'status' => 'active',
            'price' => 1000,
            'quantity' => 1,
        ]));
        $this->assertFalse(ProductHelper::isPurchasable([
            'type' => 'service',
            'status' => 'active',
            'price' => 0,
        ]));
    }

    public function testServerGatesWiredOnAllPublishPaths(): void
    {
        $store = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/ProfileController.php'),
            'store'
        );
        $this->assertStringContainsString('assertCanPublishNew', $store);

        $draft = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Listing/ListingDraftService.php'),
            'publishDraft'
        );
        $this->assertStringContainsString('assertCanPublishNew', $draft);

        $import = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Business/CatalogImportService.php'),
            'persistRow'
        );
        $this->assertStringContainsString('assertCanPublishNew', $import);

        $update = $this->methodSource(
            (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/ProfileController.php'),
            'updateLot'
        );
        $this->assertStringNotContainsString('assertCanPublishNew', $update);
    }

    public function testAdminToggleRouteAndUnauthorizedDenied(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/config/routes.php');
        $this->assertStringContainsString('/admin/service-listing-publish', $routes);
        $this->assertStringContainsString('toggleServiceListingPublish', $routes);

        $ctrl = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/AdminController.php');
        $toggle = $this->methodSource($ctrl, 'toggleServiceListingPublish');
        $this->assertStringContainsString('requireServiceListingAdmin', $toggle);
        $this->assertStringContainsString('setPublishEnabled', $toggle);

        $deny = $this->methodSource($ctrl, 'requireServiceListingAdmin');
        $this->assertStringContainsString('canToggle', $deny);
        $this->assertStringContainsString('403', $deny);
    }

    public function testUiReflectsDisabledState(): void
    {
        $catalog = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/catalog/index.php');
        $this->assertStringContainsString('isPublishEnabled', $catalog);
        $this->assertStringContainsString('service_publish_disabled', $catalog);

        $profile = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/profile/index.php');
        $this->assertStringContainsString('lot-service-disabled', $profile);
        $this->assertStringContainsString('servicePublishOn', $profile);

        $admin = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/admin/index.php');
        $this->assertStringContainsString('service-listing-publish', $admin);
        $this->assertStringContainsString('service_listing_disable_confirm', $admin);
    }

    public function testNoAsyncPendingPaymentPolicyDocumented(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Listing/ServiceListingFeePolicy.php');
        $this->assertStringContainsString('синхронный кошелёк', $src);
        $this->assertStringContainsString('не трогает', $src);
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
