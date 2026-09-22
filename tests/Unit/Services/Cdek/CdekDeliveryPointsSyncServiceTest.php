<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekDeliveryPointsSyncService;
use App\Services\Cdek\CdekErrorMapper;
use PHPUnit\Framework\TestCase;

final class CdekDeliveryPointsSyncServiceTest extends TestCase
{
    private CdekDeliveryPointsSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CdekDeliveryPointsSyncService();
    }

    public function testNormalizeOfficeMapsOpenApiFields(): void
    {
        $row = $this->service->normalizeOffice([
            'code' => 'ALA1',
            'uuid' => 'u-1',
            'type' => 'PVZ',
            'work_time' => 'Пн-Пт 09:00-18:00',
            'is_handout' => true,
            'is_reception' => true,
            'take_only' => false,
            'have_cashless' => true,
            'have_cash' => false,
            'allowed_cod' => true,
            'weight_max' => 30,
            'location' => [
                'country_code' => 'KZ',
                'city_code' => 4756,
                'city' => 'Алматы',
                'region' => 'Алматы',
                'address' => 'ул. Абая 10',
                'address_full' => 'Казахстан, Алматы, ул. Абая 10',
                'postal_code' => '050000',
                'longitude' => 76.9,
                'latitude' => 43.2,
            ],
        ], 'KZ');

        $this->assertNotNull($row);
        $this->assertSame('ALA1', $row['code']);
        $this->assertSame('PVZ', $row['type']);
        $this->assertSame(4756, $row['city_code']);
        $this->assertSame('Алматы', $row['city']);
        $this->assertSame('ул. Абая 10', $row['address']);
        $this->assertTrue($row['is_handout']);
        $this->assertSame(64, strlen((string) $row['raw_hash']));
    }

    public function testNormalizeRejectsEmptyCode(): void
    {
        $this->assertNull($this->service->normalizeOffice(['type' => 'PVZ'], 'KZ'));
    }

    public function testValidateCodeRequiresDirectoryHit(): void
    {
        $points = new class {
            public function findByCode(string $code, bool $activeOnly = true): ?array
            {
                return null;
            }
        };

        $svc = new CdekDeliveryPointsSyncService(null, $points);
        $result = $svc->validateCode('FAKE');
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_POINT_NOT_FOUND, $result['internal_code']);
    }

    public function testValidateCodeAcceptsActiveHandoutPoint(): void
    {
        $points = new class {
            public function findByCode(string $code, bool $activeOnly = true): ?array
            {
                return [
                    'code' => 'AST99',
                    'city' => 'Астана',
                    'is_handout' => 1,
                    'name' => 'ПВЗ Астана',
                    'address' => 'ул. 1',
                    'weight_max' => 50,
                ];
            }
        };

        $svc = new CdekDeliveryPointsSyncService(null, $points);
        $result = $svc->validateCode('AST99', 'Астана', 2.0);
        $this->assertTrue($result['ok']);
        $this->assertSame('AST99', $result['point']['code']);
    }

    public function testValidateCodeRejectsWeightOverLimit(): void
    {
        $points = new class {
            public function findByCode(string $code, bool $activeOnly = true): ?array
            {
                return [
                    'code' => 'AST99',
                    'city' => 'Астана',
                    'is_handout' => 1,
                    'weight_max' => 5,
                ];
            }
        };

        $svc = new CdekDeliveryPointsSyncService(null, $points);
        $result = $svc->validateCode('AST99', 'Астана', 12.0);
        $this->assertFalse($result['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_PACKAGE, $result['internal_code']);
    }
}
