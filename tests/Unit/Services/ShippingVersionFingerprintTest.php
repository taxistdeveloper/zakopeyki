<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ProductListingShipping;
use PHPUnit\Framework\TestCase;

/**
 * Fingerprint / version bump logic tested via reflection on private method
 * without DB — mirrors ProductListingShipping::shippingFingerprint behaviour.
 */
final class ShippingVersionFingerprintTest extends TestCase
{
    public function testFingerprintChangesWhenWeightChanges(): void
    {
        $model = new ProductListingShipping();
        $ref = new \ReflectionClass($model);
        $method = $ref->getMethod('shippingFingerprint');
        $method->setAccessible(true);

        $a = [
            'fulfillment_mode' => 'delivery',
            'gross_weight' => '1.000',
            'ship_city' => 'Алматы',
            'packaging_id' => 1,
        ];
        $b = $a;
        $b['gross_weight'] = '2.000';

        $this->assertNotSame(
            $method->invoke($model, $a),
            $method->invoke($model, $b)
        );
    }

    public function testFingerprintStableForSameData(): void
    {
        $model = new ProductListingShipping();
        $ref = new \ReflectionClass($model);
        $method = $ref->getMethod('shippingFingerprint');
        $method->setAccessible(true);

        $row = [
            'fulfillment_mode' => 'delivery',
            'gross_weight' => '1.5',
            'ship_city' => 'Астана',
        ];
        $this->assertSame(
            $method->invoke($model, $row),
            $method->invoke($model, $row)
        );
    }
}
