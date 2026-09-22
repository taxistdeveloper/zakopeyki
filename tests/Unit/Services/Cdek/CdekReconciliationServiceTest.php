<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Models\DeliveryOrder;
use App\Services\Cdek\CdekOrderService;
use App\Services\Cdek\CdekReconciliationService;
use App\Services\Cdek\CdekWebhookService;
use App\Services\Cdek\Client;
use PHPUnit\Framework\TestCase;

final class CdekReconciliationServiceTest extends TestCase
{
    public function testReconcileOneUpdatesFromRemoteStatus(): void
    {
        $client = new class extends Client {
            public function __construct()
            {
                parent::__construct([
                    'account' => 't',
                    'secure_password' => 't',
                    'api_url' => 'https://example.test/v2',
                    'test_mode' => 1,
                ]);
            }

            public function get(string $path, ?array $query = null): array
            {
                return [
                    'ok' => true,
                    'code' => 200,
                    'data' => [
                        'entity' => [
                            'uuid' => 'ord-1',
                            'cdek_number' => 'CN-9',
                            'statuses' => [
                                ['code' => 'ACCEPTED', 'date_time' => '2026-01-01T08:00:00+0000'],
                                ['code' => 'DELIVERED', 'date_time' => '2026-01-02T12:00:00+0000'],
                            ],
                        ],
                    ],
                    'request_id' => 'r1',
                ];
            }
        };

        $orders = new FakeOrders();
        $orders->rows[3] = [
            'id' => 3,
            'status' => DeliveryOrder::STATUS_IN_TRANSIT,
            'logistics_order_id' => 'ord-1',
            'order_number' => 'DO-3',
        ];

        $orderService = new CdekOrderService($client);
        $webhook = new CdekWebhookService(['test_mode' => 1], new FakeWebhookEvents(), $orders);
        $svc = new CdekReconciliationService($orderService, $webhook, $orders);

        $result = $svc->reconcileOne($orders->rows[3]);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['changed']);
        $this->assertSame(DeliveryOrder::STATUS_DELIVERED, $orders->rows[3]['status']);
        $this->assertSame(1, $orders->transitions);
    }

    public function testOpenStatusesIncludeInTransit(): void
    {
        $list = CdekReconciliationService::openStatuses();
        $this->assertContains(DeliveryOrder::STATUS_IN_TRANSIT, $list);
        $this->assertNotContains(DeliveryOrder::STATUS_DELIVERED, $list);
    }
}
