<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekCalculatorRequestBuilder;
use App\Services\Cdek\CdekErrorMapper;
use PHPUnit\Framework\TestCase;

final class CdekCalculatorRequestBuilderTest extends TestCase
{
    private CdekCalculatorRequestBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new CdekCalculatorRequestBuilder();
    }

    public function testBuildsFromLocationPayloadWithoutItems(): void
    {
        $result = $this->builder->build(
            ['from_location' => ['code' => 4756, 'city' => 'Алматы', 'extra_ignored' => 'x']],
            ['to_location' => ['code' => 4961]],
            [['weight' => 1500, 'length' => 20, 'width' => 15, 'height' => 10, 'items' => [['name' => 'no']]]],
            ['type' => 1, 'currency' => 2, 'lang' => 'rus']
        );

        $this->assertTrue($result['ok']);
        $payload = $result['payload'];
        $this->assertSame(4756, $payload['from_location']['code']);
        $this->assertSame('Алматы', $payload['from_location']['city']);
        $this->assertArrayNotHasKey('extra_ignored', $payload['from_location']);
        $this->assertSame(1500, $payload['packages'][0]['weight']);
        $this->assertArrayNotHasKey('items', $payload['packages'][0]);
        $this->assertArrayNotHasKey('shipment_point', $payload);
        $this->assertNotEmpty($result['request_hash']);
    }

    public function testRejectsBothShipmentPointAndFromLocation(): void
    {
        $result = $this->builder->build(
            ['shipment_point' => 'ALA1', 'from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['weight' => 1000]]
        );
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_ORIGIN, $result['error']['internal_code']);
    }

    public function testRejectsNeitherOrigin(): void
    {
        $result = $this->builder->build(
            [],
            ['to_location' => ['code' => 2]],
            [['weight' => 1000]]
        );
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_ORIGIN, $result['error']['internal_code']);
    }

    public function testDeliveryPointXorToLocation(): void
    {
        $ok = $this->builder->build(
            ['from_location' => ['code' => 1]],
            ['delivery_point' => 'PVZ123'],
            [['weight' => 500]]
        );
        $this->assertTrue($ok['ok']);
        $this->assertSame('PVZ123', $ok['payload']['delivery_point']);
        $this->assertArrayNotHasKey('to_location', $ok['payload']);

        $bad = $this->builder->build(
            ['from_location' => ['code' => 1]],
            ['delivery_point' => 'PVZ123', 'to_location' => ['code' => 2]],
            [['weight' => 500]]
        );
        $this->assertFalse($bad['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_DESTINATION, $bad['error']['internal_code']);
    }

    public function testWeightRequired(): void
    {
        $result = $this->builder->build(
            ['from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['length' => 10]]
        );
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_PACKAGE, $result['error']['internal_code']);
    }

    public function testBuildFromDeliveryContextConvertsKgToGrams(): void
    {
        $result = $this->builder->buildFromDeliveryContext(
            ['city' => 'Алматы', 'country' => 'KZ'],
            ['city' => 'Астана', 'delivery_mode' => 'courier'],
            ['gross_weight' => '1.250', 'package_length' => 30, 'package_width' => 20, 'package_height' => 10],
            ['currency' => 2],
            ['from_city_code' => 4756, 'to_city_code' => 4961]
        );
        $this->assertTrue($result['ok'], $result['error']['message'] ?? '');
        $this->assertSame(1250, $result['payload']['packages'][0]['weight']);
        $this->assertSame(30, $result['payload']['packages'][0]['length']);
    }

    public function testIdenticalPayloadSameHash(): void
    {
        $a = $this->builder->build(
            ['from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['weight' => 1000]]
        );
        $b = $this->builder->build(
            ['from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['weight' => 1000]]
        );
        $this->assertTrue($a['ok'] && $b['ok']);
        $this->assertSame($a['request_hash'], $b['request_hash']);
    }
}
