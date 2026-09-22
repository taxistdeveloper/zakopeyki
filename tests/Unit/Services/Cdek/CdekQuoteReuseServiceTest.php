<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekCalculatorRequestBuilder;
use App\Services\Cdek\CdekQuoteReuseService;
use PHPUnit\Framework\TestCase;

final class CdekQuoteReuseServiceTest extends TestCase
{
    public function testReusesFreshMatchingQuotes(): void
    {
        $svc = new CdekQuoteReuseService();
        $hash = 'abc' . str_repeat('0', 61);
        $rows = [
            [
                'id' => 1,
                'request_payload_hash' => $hash,
                'shipping_version' => 2,
                'quote_status' => 'active',
                'valid_until' => date('Y-m-d H:i:s', time() + 3600),
                'service_code' => 'cdek_136',
                'service_name' => 'PVZ',
                'total_amount' => 1500,
                'base_amount' => 1500,
                'currency' => 'KZT',
                'route_hash' => 'r1',
                'package_hash' => 'p1',
            ],
        ];

        $result = $svc->tryReuse($rows, $hash, 2, 'r1', 'p1');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['quotes'][0]['reused']);
        $this->assertSame(1500, $result['quotes'][0]['total_amount']);
    }

    public function testRejectsExpired(): void
    {
        $svc = new CdekQuoteReuseService();
        $hash = str_repeat('a', 64);
        $rows = [[
            'id' => 1,
            'request_payload_hash' => $hash,
            'shipping_version' => 1,
            'quote_status' => 'active',
            'valid_until' => date('Y-m-d H:i:s', time() - 10),
            'service_code' => 'cdek_1',
            'service_name' => 'x',
            'total_amount' => 100,
        ]];

        $result = $svc->tryReuse($rows, $hash, 1, null, null, time());
        $this->assertFalse($result['ok']);
        $this->assertSame('expired', $result['reason']);
    }

    public function testRejectsShippingVersionMismatch(): void
    {
        $svc = new CdekQuoteReuseService();
        $hash = str_repeat('b', 64);
        $rows = [[
            'id' => 1,
            'request_payload_hash' => $hash,
            'shipping_version' => 1,
            'quote_status' => 'active',
            'valid_until' => date('Y-m-d H:i:s', time() + 1000),
            'service_code' => 'cdek_1',
            'service_name' => 'x',
            'total_amount' => 100,
        ]];

        $result = $svc->tryReuse($rows, $hash, 2);
        $this->assertFalse($result['ok']);
        $this->assertSame('shipping_version_mismatch', $result['reason']);
    }

    public function testBuilderHashesStableAndSplit(): void
    {
        $builder = new CdekCalculatorRequestBuilder();
        $a = $builder->build(
            ['from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['weight' => 1000, 'length' => 10, 'width' => 10, 'height' => 10]],
            ['type' => 1, 'currency' => 2]
        );
        $b = $builder->build(
            ['from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['weight' => 1000, 'length' => 10, 'width' => 10, 'height' => 10]],
            ['type' => 1, 'currency' => 2]
        );
        $c = $builder->build(
            ['from_location' => ['code' => 1]],
            ['to_location' => ['code' => 2]],
            [['weight' => 2000, 'length' => 10, 'width' => 10, 'height' => 10]],
            ['type' => 1, 'currency' => 2]
        );

        $this->assertTrue($a['ok'] && $b['ok'] && $c['ok']);
        $this->assertSame($a['request_hash'], $b['request_hash']);
        $this->assertSame($a['route_hash'], $b['route_hash']);
        $this->assertSame($a['package_hash'], $b['package_hash']);
        $this->assertSame($a['route_hash'], $c['route_hash']);
        $this->assertNotSame($a['package_hash'], $c['package_hash']);
        $this->assertNotSame($a['request_hash'], $c['request_hash']);
    }
}
