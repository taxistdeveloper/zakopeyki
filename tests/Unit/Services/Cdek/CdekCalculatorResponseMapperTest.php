<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekCalculatorResponseMapper;
use App\Services\Cdek\CdekErrorMapper;
use PHPUnit\Framework\TestCase;

final class CdekCalculatorResponseMapperTest extends TestCase
{
    private CdekCalculatorResponseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new CdekCalculatorResponseMapper();
    }

    public function testMapsTariffAndPreservesDeliverySumAudit(): void
    {
        $result = $this->mapper->map([
            'tariff_codes' => [[
                'tariff_code' => 136,
                'tariff_name' => 'Посылка склад-склад',
                'tariff_description' => 'desc',
                'delivery_mode' => 4,
                'delivery_sum' => 1890.4,
                'period_min' => 2,
                'period_max' => 4,
                'calendar_min' => 3,
                'calendar_max' => 5,
                'delivery_date_range' => ['min' => '2026-09-22', 'max' => '2026-09-25'],
            ]],
        ], [
            'packaging_amount' => 100,
            'handling_amount' => 0,
            'fragile_amount' => 0,
            'currency_label' => 'KZT',
            'request_hash' => 'abc',
            'delivery_mode_filter' => 'pvz',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['quotes']);
        $q = $result['quotes'][0];
        $this->assertSame('cdek_136', $q['service_code']);
        $this->assertSame(1890, $q['price']);
        $this->assertSame(1990, $q['total_amount']);
        $this->assertSame('1890.4000', $q['delivery_sum_raw']);
        $this->assertSame('1890.4000', $q['snapshot_json']['delivery_sum_audit']);
        $this->assertSame(2, $q['eta_days_min']);
        $this->assertSame('2026-09-22', $q['delivery_date_from']);
    }

    public function testFiltersCourierMode(): void
    {
        $result = $this->mapper->map([
            'tariff_codes' => [
                ['tariff_code' => 1, 'tariff_name' => 'door', 'delivery_mode' => 1, 'delivery_sum' => 100, 'period_min' => 1, 'period_max' => 2],
                ['tariff_code' => 2, 'tariff_name' => 'wh', 'delivery_mode' => 4, 'delivery_sum' => 50, 'period_min' => 1, 'period_max' => 2],
            ],
        ], ['delivery_mode_filter' => 'courier']);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['quotes']);
        $this->assertSame('cdek_1', $result['quotes'][0]['service_code']);
    }

    public function testNoMatchReturnsErrorNotUnfiltered(): void
    {
        $result = $this->mapper->map([
            'tariff_codes' => [
                ['tariff_code' => 2, 'tariff_name' => 'wh', 'delivery_mode' => 4, 'delivery_sum' => 50, 'period_min' => 1, 'period_max' => 2],
            ],
        ], ['delivery_mode_filter' => 'courier']);

        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_TARIFF, $result['error']['internal_code']);
        $this->assertSame([], $result['quotes']);
    }

    public function testMapsErrors(): void
    {
        $result = $this->mapper->map([
            'errors' => [[
                'code' => 'v2_office_by_delivery_point_not_found',
                'message' => 'office missing',
            ]],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_POINT_NOT_FOUND, $result['error']['internal_code']);
        $this->assertSame('v2_office_by_delivery_point_not_found', $result['error']['cdek_code']);
    }
}
