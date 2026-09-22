<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Services\Delivery\CdekLogisticsProvider;
use PHPUnit\Framework\TestCase;

final class CdekLogisticsProviderWebhookTest extends TestCase
{
    public function testResolvesByImNumberNotDeliveryOrderId(): void
    {
        $orders = new class {
            public function findByOrderNumber(string $n): ?array
            {
                return $n === 'DO-42' ? ['id' => 42, 'order_number' => 'DO-42'] : null;
            }

            public function findByLogisticsOrderId(string $u): ?array
            {
                return null;
            }

            public function findByTrackingNumber(string $t): ?array
            {
                return null;
            }
        };

        $provider = new CdekLogisticsProvider(null, $orders);
        $parsed = $provider->handleStatusWebhook([
            'type' => 'ORDER_STATUS',
            'uuid' => 'event-uuid-should-not-match-order',
            'delivery_order_id' => 999,
            'attributes' => [
                'uuid' => 'order-uuid-unknown',
                'number' => 'DO-42',
                'code' => 'DELIVERED',
                'cdek_number' => '1100',
            ],
        ]);

        $this->assertNotNull($parsed);
        $this->assertSame(42, $parsed['delivery_order_id']);
        $this->assertSame('DELIVERED', $parsed['status']);
        $this->assertSame('1100', $parsed['tracking_number']);
    }

    public function testIgnoresForgedDeliveryOrderIdWithoutIdentifiers(): void
    {
        $orders = new class {
            public function findByOrderNumber(string $n): ?array
            {
                return null;
            }

            public function findByLogisticsOrderId(string $u): ?array
            {
                return null;
            }

            public function findByTrackingNumber(string $t): ?array
            {
                return null;
            }
        };

        $provider = new CdekLogisticsProvider(null, $orders);
        $parsed = $provider->handleStatusWebhook([
            'type' => 'ORDER_STATUS',
            'delivery_order_id' => 1,
            'attributes' => ['code' => 'ACCEPTED'],
        ]);

        $this->assertNull($parsed);
    }

    public function testMapsReadyForPickup(): void
    {
        $orders = new class {
            public function findByOrderNumber(string $n): ?array
            {
                return ['id' => 7];
            }

            public function findByLogisticsOrderId(string $u): ?array
            {
                return null;
            }

            public function findByTrackingNumber(string $t): ?array
            {
                return null;
            }
        };

        $provider = new CdekLogisticsProvider(null, $orders);
        $parsed = $provider->handleStatusWebhook([
            'type' => 'ORDER_STATUS',
            'attributes' => [
                'number' => 'DO-7',
                'code' => 'READY_FOR_PICKUP',
            ],
        ]);

        $this->assertNotNull($parsed);
        $this->assertSame('READY_FOR_PICKUP', $parsed['status']);
    }
}
