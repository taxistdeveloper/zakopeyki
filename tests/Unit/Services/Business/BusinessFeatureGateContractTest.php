<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessPackagePolicy;
use App\Services\BusinessPackageService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Контрактные проверки без записи в БД (рефлексия / политика).
 * Полный purchase/import e2e требует MySQL и здесь не утверждается пройденным.
 */
final class BusinessFeatureGateContractTest extends TestCase
{
    public function testInvokeGeneratorContractRequiresFeatureConstants(): void
    {
        foreach (BusinessPackagePolicy::UNIMPLEMENTED_GENERATORS as $feature) {
            $this->assertNotSame('', $feature);
        }
    }

    public function testPurchaseSignatureAcceptsIdempotencyKey(): void
    {
        $m = new ReflectionMethod(BusinessPackageService::class, 'purchase');
        $params = $m->getParameters();
        $this->assertGreaterThanOrEqual(3, count($params));
        $this->assertSame('idempotencyKey', $params[2]->getName());
    }

    public function testAssertFeatureAndFlagsExist(): void
    {
        $this->assertTrue(method_exists(BusinessPackageService::class, 'assertFeature'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'isSalesEnabled'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'isFeaturesEnabled'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'setSalesEnabled'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'setFeaturesEnabled'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'invokeGeneratorContract'));
        $this->assertTrue(method_exists(BusinessPackageService::class, 'paymentHistory'));
    }

    public function testListingDraftPublishReferencesPackageGate(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Listing/ListingDraftService.php');
        $this->assertStringContainsString('BusinessPackageService', $src);
        $this->assertStringContainsString('assertCanCreateListing', $src);
        $this->assertStringContainsString('PersonalLimitService', $src);
        $this->assertStringContainsString('consumeListing', $src);
    }

    public function testBusinessApiRoutesRegistered(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 4) . '/config/routes.php');
        foreach ([
            '/api/v1/business/status',
            '/api/v1/business/import/xml',
            '/api/v1/business/import/csv',
            '/api/v1/business/catalog/items',
            '/api/v1/business/ai/infographic',
            '/api/v1/business/analytics/extended',
            '/api/v1/business/manager/request',
            '/admin/business/package-sales',
            '/admin/business/package-features',
        ] as $path) {
            $this->assertStringContainsString($path, $routes, $path);
        }
    }

    public function testBoostIsolationFromBusinessFlags(): void
    {
        $boostPolicy = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Services/Boost/BoostPolicy.php');
        $this->assertStringNotContainsString('business_package_sales_enabled', $boostPolicy);
        $this->assertStringNotContainsString('business_package_features_enabled', $boostPolicy);
    }
}
