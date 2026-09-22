<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekOrderPayloadBuilder;
use App\Services\Cdek\CdekOrderService;
use App\Services\Cdek\Client;
use App\Services\MicroTaskLock;
use PHPUnit\Framework\TestCase;

/**
 * CdekOrderService с mock Client через subclass.
 */
final class CdekOrderServiceTest extends TestCase
{
    public function testCreateReturnsExistingUuidWithoutPost(): void
    {
        $client = new class extends Client {
            public int $posts = 0;
            public int $gets = 0;

            public function __construct()
            {
                parent::__construct([
                    'account' => 'test',
                    'secure_password' => 'test',
                    'api_url' => 'https://example.test/v2',
                    'test_mode' => 1,
                ]);
            }

            public function post(string $path, array $body, array $extraHeaders = []): array
            {
                $this->posts++;
                return ['ok' => false, 'code' => 500, 'error' => 'should not post', 'request_id' => 'x'];
            }

            public function get(string $path, ?array $query = null): array
            {
                $this->gets++;
                return [
                    'ok' => true,
                    'code' => 200,
                    'data' => ['entity' => ['uuid' => 'u-1', 'cdek_number' => '123']],
                    'request_id' => 'g1',
                ];
            }
        };

        $service = new CdekOrderService($client, new CdekOrderPayloadBuilder(), null, null, new MicroTaskLock());
        $result = $service->create([
            'delivery_order_id' => 7,
            'order_number' => 'DO-7',
            'identifiers' => ['logistics_order_id' => 'u-1'],
        ], ['existing_uuid' => 'u-1']);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['already_existed']);
        $this->assertSame('u-1', $result['logistics_order_id']);
        $this->assertSame('123', $result['tracking_number']);
        $this->assertSame(0, $client->posts);
        $this->assertSame(1, $client->gets);
    }

    public function testCreatePostsAndParsesUuid(): void
    {
        $client = new class extends Client {
            public array $lastBody = [];
            public array $lastHeaders = [];

            public function __construct()
            {
                parent::__construct([
                    'account' => 'test',
                    'secure_password' => 'test',
                    'api_url' => 'https://example.test/v2',
                    'order_type' => 1,
                    'test_mode' => 1,
                ]);
            }

            public function post(string $path, array $body, array $extraHeaders = []): array
            {
                $this->lastBody = $body;
                $this->lastHeaders = $extraHeaders;
                return [
                    'ok' => true,
                    'code' => 202,
                    'data' => ['entity' => ['uuid' => 'new-uuid', 'cdek_number' => null]],
                    'request_id' => 'r1',
                ];
            }

            public function get(string $path, ?array $query = null): array
            {
                // findByImNumber before create → not found; soft poll after create
                if (str_contains($path, 'new-uuid')) {
                    return [
                        'ok' => true,
                        'code' => 200,
                        'data' => ['entity' => ['uuid' => 'new-uuid', 'cdek_number' => '999']],
                        'request_id' => 'g2',
                    ];
                }
                return ['ok' => false, 'code' => 404, 'error' => 'not found', 'request_id' => 'g0'];
            }
        };

        $service = new CdekOrderService($client, new CdekOrderPayloadBuilder(), null, null, new MicroTaskLock());
        $avr = [
            'delivery_order_id' => 55,
            'order_number' => 'DO-55',
            'sender' => [
                'name' => 'S',
                'phone' => '+77001112233',
                'street' => 'A',
                'building' => '1',
                'city' => 'Алматы',
            ],
            'recipient' => [
                'name' => 'B',
                'phone' => '+77009998877',
                'street' => 'B',
                'building' => '2',
                'city' => 'Астана',
                'delivery_mode' => 'courier',
            ],
            'shipment' => [
                'gross_weight' => 1,
                'product_title' => 'Item',
            ],
            'service' => ['service_code' => 'cdek_139'],
        ];

        $result = $service->create($avr, [
            'from_city_code' => 4756,
            'to_city_code' => 4961,
        ]);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('new-uuid', $result['logistics_order_id']);
        $this->assertSame('999', $result['tracking_number']);
        $this->assertFalse($result['already_existed']);
        $this->assertSame(139, $client->lastBody['tariff_code']);
        $this->assertSame('zk-del-55', $client->lastBody['developer_key']);
        $this->assertContains('developer-key: zk-del-55', $client->lastHeaders);
    }

    public function testBusyLockReturnsInProgress(): void
    {
        $lock = new class extends MicroTaskLock {
            public function set(string $key, mixed $value, mixed $options = null): bool
            {
                return false;
            }
        };

        $client = new class extends Client {
            public function __construct()
            {
                parent::__construct(['account' => 'a', 'secure_password' => 'b', 'test_mode' => 1]);
            }
            public function post(string $path, array $body, array $extraHeaders = []): array
            {
                self::fail('post must not be called when lock is busy');
            }
            public function get(string $path, ?array $query = null): array
            {
                return ['ok' => false, 'code' => 404, 'error' => 'nf', 'request_id' => 'g'];
            }
        };

        $service = new CdekOrderService($client, new CdekOrderPayloadBuilder(), null, null, $lock);
        $result = $service->create([
            'delivery_order_id' => 88,
            'order_number' => 'DO-88',
            'sender' => ['name' => 'S', 'phone' => '+77001112233', 'street' => 'A', 'building' => '1'],
            'recipient' => ['name' => 'B', 'phone' => '+77009998877', 'street' => 'B', 'building' => '2', 'delivery_mode' => 'courier'],
            'shipment' => ['gross_weight' => 1],
            'service' => ['service_code' => 'cdek_1'],
        ], ['from_city_code' => 1, 'to_city_code' => 2]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('in progress', (string) $result['error']);
    }
}
