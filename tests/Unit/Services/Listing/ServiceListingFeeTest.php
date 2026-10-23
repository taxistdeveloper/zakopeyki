<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Listing;

use App\Helpers\ProductHelper;
use App\Services\Listing\ServiceListingFeePolicy;
use App\Services\Listing\ServiceListingFeeService;
use PHPUnit\Framework\TestCase;

/**
 * VAS-16.3: тариф 500 ₸ за service, без бесплатной квоты, без Safe Deal для service.
 */
final class ServiceListingFeeTest extends TestCase
{
    public function testFeeIs500AndIgnoresClientAmount(): void
    {
        $this->assertSame(500, ServiceListingFeePolicy::FEE_KZT);
        $this->assertSame(500, ProductHelper::SERVICE_LISTING_FEE);
        $this->assertSame(500, ProductHelper::serviceListingFee());
        $this->assertFalse(ProductHelper::isServiceListingFreePromo());

        $this->assertSame(500, ServiceListingFeePolicy::feeKztForType('service'));
        $this->assertSame(500, ServiceListingFeePolicy::feeKztForType('service', 0));
        $this->assertSame(500, ServiceListingFeePolicy::feeKztForType('service', 1));
        $this->assertSame(500, ServiceListingFeePolicy::feeKztForType('service', 99999));

        $svc = new ServiceListingFeeService();
        $this->assertSame(500, $svc->feeForType('service', 0));
    }

    public function testNoFeeForOtherTypes(): void
    {
        foreach (['used', 'new', 'free', 'exchange', 'gig', 'auction', 'course'] as $type) {
            $this->assertSame(0, ServiceListingFeePolicy::feeKztForType($type), $type);
            $this->assertSame(0, ServiceListingFeePolicy::feeKztForType($type, 500), $type);
        }
    }

    public function testNoFreePromoQuota(): void
    {
        $this->assertFalse(ProductHelper::isServiceListingFreePromo());
        $this->assertSame(500, ProductHelper::serviceListingFee());

        $pkgSrc = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/BusinessPackageService.php');
        $method = $this->methodSource($pkgSrc, 'freeServiceListing');
        $this->assertStringContainsString('return false', $method);
        $this->assertStringNotContainsString('free_service_listing', $method);
    }

    public function testServiceNotPurchasableNoEscrowPath(): void
    {
        $service = ['type' => 'service', 'status' => 'active', 'price' => 0];
        $this->assertFalse(ProductHelper::isPurchasable($service));
        $this->assertFalse(ProductHelper::supportsDirectBuy($service));

        // Даже при подмене price на клиенте — type service не в PURCHASABLE_TYPES
        $spoofed = ['type' => 'service', 'status' => 'active', 'price' => 5000];
        $this->assertFalse(ProductHelper::isPurchasable($spoofed));
        $this->assertFalse(ProductHelper::supportsDirectBuy($spoofed));

        $this->assertNotContains('service', ProductHelper::PURCHASABLE_TYPES);
    }

    public function testSafeDealTypesRemainPurchasable(): void
    {
        foreach (['used', 'new', 'gig'] as $type) {
            $this->assertContains($type, ProductHelper::PURCHASABLE_TYPES, $type);
            $item = ['type' => $type, 'status' => 'active', 'price' => 1000, 'quantity' => 1];
            $this->assertTrue(ProductHelper::isPurchasable($item), $type);
        }
        $this->assertTrue(ProductHelper::supportsDirectBuy([
            'type' => 'used',
            'status' => 'active',
            'price' => 1000,
        ]));
    }

    public function testProfileStoreChargesServerFeeNotClient(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/ProfileController.php');
        $store = $this->methodSource($src, 'store');
        $this->assertStringContainsString('ServiceListingFeeService', $store);
        $this->assertStringContainsString('assertAffordable', $store);
        $this->assertStringContainsString('chargeForPublishedProduct', $store);
        $this->assertStringNotContainsString('freeServiceListing', $store);
        // Клиентская сумма для fee не читается
        $this->assertStringNotContainsString("\$_POST['listing_fee']", $store);
        $this->assertStringNotContainsString("\$_POST['service_fee']", $store);
    }

    public function testUpdateLotDoesNotChargeListingFee(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/ProfileController.php');
        $update = $this->methodSource($src, 'updateLot');
        $this->assertStringNotContainsString('chargeListingFee', $update);
        $this->assertStringNotContainsString('ServiceListingFeeService', $update);
        $this->assertStringNotContainsString('chargeForPublishedProduct', $update);
    }

    public function testAiDraftPublishRequiresFee(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Listing/ListingDraftService.php');
        $publish = $this->methodSource($src, 'publishDraft');
        $this->assertStringContainsString('ServiceListingFeeService', $publish);
        $this->assertStringContainsString('assertAffordable', $publish);
        $this->assertStringContainsString('chargeForPublishedProduct', $publish);
        $this->assertStringContainsString('delete($productId)', $publish);
    }

    public function testCatalogImportCreateRequiresFee(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Business/CatalogImportService.php');
        $persist = $this->methodSource($src, 'persistRow');
        $this->assertStringContainsString('ServiceListingFeeService', $persist);
        $this->assertStringContainsString('chargeForPublishedProduct', $persist);
        $this->assertStringContainsString('listing_fee', $persist);
    }

    public function testWalletListingFeeIdempotentByProductMeta(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Models/Wallet.php');
        $method = $this->methodSource($src, 'chargeListingFee');
        $this->assertStringContainsString('TYPE_LISTING_FEE', $method);
        $this->assertStringContainsString('listing:', $method);
        $this->assertStringContainsString('idempotent', $method);
        $this->assertStringContainsString('FOR UPDATE', $src); // lockWalletRow
        $this->assertStringContainsString('lockWallet', $method);
        $posLock = strpos($method, 'lockWallet');
        $posDebit = strpos($method, 'applyDebit');
        $this->assertNotFalse($posLock);
        $this->assertNotFalse($posDebit);
        $this->assertLessThan($posDebit, $posLock);
    }

    public function testCheckoutRejectsNonPurchasableIncludingService(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/CheckoutController.php');
        $this->assertStringContainsString('isPurchasable', $src);
        $show = $this->methodSource($src, 'show');
        $this->assertStringContainsString('isPurchasable', $show);
    }

    public function testWalletMetaLinksProduct(): void
    {
        $this->assertSame('listing:42', ServiceListingFeePolicy::walletMeta(42));
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
