<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Listing;

use App\Models\ProductListingShipping;
use App\Services\Delivery\DeliveryPointValidator;
use App\Services\Listing\ListingShippingService;
use PHPUnit\Framework\TestCase;

final class ListingCdekPublishGateTest extends TestCase
{
    private ListingShippingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ListingShippingService(
            new DeliveryPointValidator(),
            static function (string $city, ?string $country = null): ?array {
                $map = ['алматы' => 4756, 'астана' => 4961];
                $key = mb_strtolower(trim($city));
                return isset($map[$key])
                    ? ['code' => $map[$key], 'city' => $city, 'country_code' => $country ?: 'KZ']
                    : null;
            },
            static fn () => []
        );
    }

    /** @return array<string, mixed> */
    private function fullPost(array $overrides = []): array
    {
        return array_merge([
            'fulfillment_mode' => ProductListingShipping::FULFILLMENT_DELIVERY,
            'param_mode' => ProductListingShipping::MODE_EXACT,
            'ship_contact_name' => 'Иван Продавец',
            'ship_phone' => '+77001234567',
            'ship_city' => 'Алматы',
            'ship_street' => 'Абая 10',
            'ship_postal_code' => '050000',
            'ship_country' => 'KZ',
            'origin_type' => 'door',
            'item_weight' => '1.5',
            'item_length' => '20',
            'item_width' => '15',
            'item_height' => '10',
            'package_count' => '1',
            'declared_value' => '15000',
        ], $overrides);
    }

    public function testPickupOnlyWithoutCdek(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', [
            'fulfillment_mode' => ProductListingShipping::FULFILLMENT_PICKUP,
            'location' => 'Алматы',
        ]);
        $this->assertTrue($r['ok']);
        $this->assertSame(0, (int) $r['data']['cdek_ready']);
    }

    public function testCdekWithPointAAllowed(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost());
        $this->assertTrue($r['ok']);
        $this->assertSame(1, (int) $r['data']['cdek_ready']);
        $this->assertSame(4756, (int) $r['data']['cdek_city_code']);
    }

    public function testMissingCityRejected(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost(['ship_city' => '']));
        $this->assertFalse($r['ok']);
        $this->assertContains('ship_city', $r['missing_fields'] ?? []);
    }

    public function testMissingAddressRejected(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost(['ship_street' => '']));
        $this->assertFalse($r['ok']);
        $this->assertSame('sender_address_required', $r['error_code']);
    }

    public function testMissingWeightRejected(): void
    {
        $post = $this->fullPost();
        unset($post['item_weight']);
        $r = $this->svc->validateAndBuild(1, 'used', $post);
        $this->assertFalse($r['ok']);
        $this->assertSame('weight_required', $r['error_code']);
    }

    public function testInvalidWeightRejected(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost(['item_weight' => 'abc']));
        $this->assertFalse($r['ok']);
    }

    public function testInvalidDimensionsRejected(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost([
            'item_width' => '',
            'item_height' => '',
        ]));
        $this->assertFalse($r['ok']);
        $this->assertSame('dimensions_required', $r['error_code']);
    }

    public function testPointAPayloadSnapshotStable(): void
    {
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost());
        $this->assertTrue($r['ok']);
        $this->assertSame('Иван Продавец', $r['data']['ship_contact_name']);
        $this->assertSame('door', $r['data']['origin_type']);
    }

    public function testProfileTemplateDoesNotLiveLink(): void
    {
        $profile = [
            'name' => 'Old',
            'phone' => '+77001112233',
            'ship_city' => 'Алматы',
            'ship_street' => 'Старый 1',
            'ship_contact_name' => 'Old',
            'ship_phone' => '+77001112233',
            'ship_country' => 'KZ',
        ];
        $r = $this->svc->validateAndBuild(1, 'used', $this->fullPost([
            'use_default_ship_from' => '1',
        ]), $profile);
        $this->assertTrue($r['ok']);
        $this->assertSame('Старый 1', $r['data']['ship_street']);
        $profile['ship_street'] = 'Новый 99';
        $this->assertSame('Старый 1', $r['data']['ship_street']);
    }
}
