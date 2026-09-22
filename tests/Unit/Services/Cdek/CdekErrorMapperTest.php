<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekErrorMapper;
use PHPUnit\Framework\TestCase;

final class CdekErrorMapperTest extends TestCase
{
    private CdekErrorMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new CdekErrorMapper();
    }

    public function testMapsKnownCdekCode(): void
    {
        $mapped = $this->mapper->map([
            'errors' => [[
                'code' => 'v2_office_by_delivery_point_not_found',
                'message' => 'Not found',
            ]],
        ], 400);

        $this->assertSame(CdekErrorMapper::INTERNAL_POINT_NOT_FOUND, $mapped['internal_code']);
        $this->assertSame('v2_office_by_delivery_point_not_found', $mapped['cdek_code']);
        $this->assertSame('Not found', $mapped['message']);
    }

    public function testMapsRequestsErrors(): void
    {
        $mapped = $this->mapper->map([
            'requests' => [[
                'errors' => [[
                    'code' => 'v2_tariff_not_found',
                    'message' => 'no tariff',
                ]],
            ]],
        ], 400);

        $this->assertSame(CdekErrorMapper::INTERNAL_TARIFF, $mapped['internal_code']);
    }

    public function testRedactsBearer(): void
    {
        $mapped = $this->mapper->mapLocal(
            CdekErrorMapper::INTERNAL_AUTH,
            'fail Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc'
        );
        $this->assertStringContainsString('[redacted]', $mapped['message']);
        $this->assertStringNotContainsString('eyJ', $mapped['message']);
    }

    public function testHttp401(): void
    {
        $mapped = $this->mapper->map(null, 401, 'unauthorized');
        $this->assertSame(CdekErrorMapper::INTERNAL_AUTH, $mapped['internal_code']);
    }
}
