<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekAuthService;
use App\Services\Cdek\CdekCalculatorService;
use App\Services\Cdek\CdekErrorMapper;
use App\Services\Cdek\CdekRequestLogger;
use App\Services\Cdek\Client;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 — calculator pre-checks + tarifflist mapping (no live CDEK).
 * Full suite: php tools/run_cdek_calculator_phase6_tests.php
 */
final class CdekCalculatorServicePhase6Test extends TestCase
{
    private function makeService(callable $transport): CdekCalculatorService
    {
        $cfg = [
            'account' => 'test-account',
            'secure_password' => 'test-secret',
            'api_url' => 'https://example.test/v2',
            'test_mode' => 1,
            'timeout' => 5,
            'connect_timeout' => 3,
            'retry_max' => 1,
            'retry_backoff_ms' => 1,
            'http_log_enabled' => 0,
            'token_cache_path' => sys_get_temp_dir() . '/cdek_phpunit_' . bin2hex(random_bytes(4)) . '.json',
            'currency' => 2,
            'currency_label' => 'KZT',
            'order_type' => 1,
            'lang' => 'rus',
            'quote_ttl_seconds' => 7200,
        ];
        $auth = new CdekAuthService($cfg, null, static function () {
            return [200, json_encode([
                'access_token' => 'tok',
                'token_type' => 'bearer',
                'expires_in' => 3600,
            ])];
        });
        $client = new Client($cfg, $auth, new CdekErrorMapper(), new CdekRequestLogger(['http_log_enabled' => 0]), $transport);
        return new CdekCalculatorService($client);
    }

    public function testCalculateSetsDeliveryAmountToPay(): void
    {
        $svc = $this->makeService(static function () {
            return [200, json_encode(['tariff_codes' => [[
                'tariff_code' => 136,
                'tariff_name' => 'PVZ',
                'delivery_mode' => 4,
                'delivery_sum' => 1500,
                'period_min' => 1,
                'period_max' => 2,
            ]]])];
        });

        $r = $svc->calculate([
            'delivery_order_id' => 1,
            'product_id' => 1,
            'order_id' => 1,
            'sender' => [
                'city' => 'Алматы', 'country' => 'KZ', 'cdek_city_code' => 4756, 'origin_type' => 'door',
            ],
            'recipient' => [
                'name' => 'B', 'phone' => '+77001112233', 'city' => 'Астана',
                'delivery_mode' => 'pvz', 'pvz_code' => 'AST1', 'delivery_point' => 'AST1', 'cdek_city_code' => 4961,
            ],
            'shipment' => [
                'billed_gross_weight' => 1.2, 'gross_weight' => 1.2,
                'billed_length' => 10, 'billed_width' => 10, 'billed_height' => 10,
            ],
        ]);

        $this->assertTrue($r['ok'] ?? false);
        $this->assertSame(1500, $r['quotes'][0]['delivery_amount_to_pay'] ?? null);
        $this->assertSame('KZT', $r['quotes'][0]['currency'] ?? null);
        $this->assertSame(CdekCalculatorService::ENDPOINT, '/calculator/tarifflist');
    }

    public function testMissingPointABlocked(): void
    {
        $svc = $this->makeService(static fn () => [200, '{}']);
        $r = $svc->validateReady([
            'id' => 1, 'product_id' => 1, 'order_id' => 1,
            'sender' => null,
            'recipient' => ['name' => 'B', 'phone' => '+77001112233', 'city' => 'Астана', 'delivery_mode' => 'courier', 'street' => 'x', 'cdek_city_code' => 1],
            'shipment' => ['billed_gross_weight' => 1],
        ]);
        $this->assertFalse($r['ok'] ?? true);
        $this->assertSame('point_a_missing', $r['error_code'] ?? null);
    }

    public function testRateLimitMapped(): void
    {
        $svc = $this->makeService(static fn () => [429, json_encode(['errors' => [['message' => 'rl']]])]);
        $r = $svc->calculate([
            'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
            'sender' => ['city' => 'Алматы', 'cdek_city_code' => 4756, 'origin_type' => 'door'],
            'recipient' => [
                'name' => 'B', 'phone' => '+77001112233', 'city' => 'Астана',
                'delivery_mode' => 'pvz', 'pvz_code' => 'AST1', 'cdek_city_code' => 4961,
            ],
            'shipment' => ['billed_gross_weight' => 1.2, 'billed_length' => 10, 'billed_width' => 10, 'billed_height' => 10],
        ]);
        $this->assertFalse($r['ok'] ?? true);
        $this->assertSame(CdekErrorMapper::INTERNAL_RATE_LIMIT, $r['error_code'] ?? null);
        $this->assertArrayNotHasKey('quotes', $r);
    }
}
