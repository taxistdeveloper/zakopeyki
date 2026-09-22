<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekWebhookService;
use PHPUnit\Framework\TestCase;

final class CdekWebhookServiceTest extends TestCase
{
    public function testAuthorizeRejectsWrongTokenInProd(): void
    {
        $svc = new CdekWebhookService([
            'webhook_token' => 'secret-token',
            'test_mode' => 0,
        ], new FakeWebhookEvents(), new FakeOrders());

        $bad = $svc->authorize([], ['token' => 'wrong']);
        $this->assertFalse($bad['ok']);
        $this->assertSame('unauthorized', $bad['error']);

        $ok = $svc->authorize(['x-cdek-webhook-token' => 'secret-token'], []);
        $this->assertTrue($ok['ok']);
        $this->assertSame('token', $ok['mode']);
    }

    public function testAuthorizeRequiresTokenInProdWhenEmpty(): void
    {
        $svc = new CdekWebhookService([
            'webhook_token' => '',
            'test_mode' => 0,
        ], new FakeWebhookEvents(), new FakeOrders());

        $res = $svc->authorize([], []);
        $this->assertFalse($res['ok']);
        $this->assertSame('webhook_token_not_configured', $res['error']);
    }

    public function testAuthorizeOpenInTestWithoutToken(): void
    {
        $svc = new CdekWebhookService([
            'webhook_token' => '',
            'test_mode' => 1,
        ], new FakeWebhookEvents(), new FakeOrders());

        $res = $svc->authorize([], []);
        $this->assertTrue($res['ok']);
        $this->assertSame('test_open', $res['mode']);
    }

    public function testDuplicateEventIsIdempotent(): void
    {
        $events = new FakeWebhookEvents();
        $orders = new FakeOrders();
        $orders->rows[10] = [
            'id' => 10,
            'status' => 'DELIVERY_ORDER_CREATED',
            'order_number' => 'DO-10',
            'logistics_order_id' => 'ord-uuid-1',
        ];

        $svc = new CdekWebhookService([
            'webhook_token' => 't',
            'test_mode' => 1,
        ], $events, $orders, new FakeProvider());

        $payload = [
            'type' => 'ORDER_STATUS',
            'uuid' => 'evt-1',
            'attributes' => [
                'uuid' => 'ord-uuid-1',
                'number' => 'DO-10',
                'code' => 'ACCEPTED',
                'status_date_time' => '2026-01-01T10:00:00+0000',
            ],
        ];

        $first = $svc->handle($payload, json_encode($payload));
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['duplicate'] ?? false);
        $this->assertSame(1, $orders->transitions);

        $second = $svc->handle($payload, json_encode($payload));
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['duplicate'] ?? false);
        $this->assertSame(1, $orders->transitions);
    }

    public function testDoesNotTrustPayloadDeliveryOrderId(): void
    {
        $orders = new FakeOrders();
        $orders->rows[99] = [
            'id' => 99,
            'status' => 'DELIVERY_ORDER_CREATED',
            'order_number' => 'DO-99',
            'logistics_order_id' => 'real-uuid',
        ];

        $svc = new CdekWebhookService([
            'webhook_token' => '',
            'test_mode' => 1,
        ], new FakeWebhookEvents(), $orders, new FakeProvider(null));

        $payload = [
            'type' => 'ORDER_STATUS',
            'uuid' => 'evt-2',
            'delivery_order_id' => 99,
            'attributes' => [
                'code' => 'ACCEPTED',
            ],
        ];

        $res = $svc->handle($payload);
        $this->assertFalse($res['ok']);
        $this->assertSame('unresolved_order', $res['error']);
        $this->assertSame(0, $orders->transitions);
    }

    public function testRegressionDoesNotDowngradeDelivered(): void
    {
        $orders = new FakeOrders();
        $orders->rows[5] = [
            'id' => 5,
            'status' => 'DELIVERED',
            'order_number' => 'DO-5',
            'logistics_order_id' => 'u5',
        ];

        $svc = new CdekWebhookService([
            'webhook_token' => '',
            'test_mode' => 1,
        ], new FakeWebhookEvents(), $orders);

        $apply = $svc->applyParsed([
            'delivery_order_id' => 5,
            'status' => 'IN_TRANSIT',
            'tracking_number' => '111',
            'message' => 'late',
        ]);

        $this->assertTrue($apply['ok']);
        $this->assertSame(0, $orders->transitions);
        $this->assertSame('DELIVERED', $orders->rows[5]['status']);
    }

    public function testBuildEventHashStable(): void
    {
        $svc = new CdekWebhookService(['test_mode' => 1], new FakeWebhookEvents(), new FakeOrders());
        $a = $svc->buildEventHash('ORDER_STATUS', 'e1', 'o1', 'DELIVERED', 't1', ['number' => 'DO-1']);
        $b = $svc->buildEventHash('ORDER_STATUS', 'e1', 'o1', 'DELIVERED', 't1', ['number' => 'DO-1']);
        $c = $svc->buildEventHash('ORDER_STATUS', 'e1', 'o1', 'IN_TRANSIT', 't1', ['number' => 'DO-1']);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }
}

/** @internal */
final class FakeWebhookEvents
{
    /** @var array<string, array<string, mixed>> */
    public array $byHash = [];
    public int $nextId = 1;

    /** @param array<string, mixed> $data */
    public function tryInsert(array $data): array
    {
        $hash = (string) $data['event_hash'];
        if (isset($this->byHash[$hash])) {
            return ['inserted' => false, 'id' => (int) $this->byHash[$hash]['id']];
        }
        $id = $this->nextId++;
        $this->byHash[$hash] = $data + ['id' => $id];
        return ['inserted' => true, 'id' => $id];
    }

    public function markProcessed(int $id, string $result, ?int $deliveryOrderId = null, ?string $error = null): void
    {
    }
}

/** @internal */
final class FakeOrders
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $transitions = 0;
    /** @var list<array<string, mixed>> */
    public array $tracking = [];

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function trackingFor(int $deliveryOrderId): array
    {
        $out = [];
        foreach (array_reverse($this->tracking) as $t) {
            if ((int) ($t['delivery_order_id'] ?? 0) === $deliveryOrderId) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    public function addTrackingEvent(int $deliveryOrderId, array $data): void
    {
        $this->tracking[] = $data + ['delivery_order_id' => $deliveryOrderId];
    }

    public function transitionStatus(
        int $id,
        string $status,
        ?int $actor = null,
        string $actorType = 'system',
        string $event = ''
    ): void {
        $this->transitions++;
        if (isset($this->rows[$id])) {
            $this->rows[$id]['status'] = $status;
        }
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $fields);
        }
    }

    public function logEvent(...$args): void
    {
    }
}

/** @internal */
final class FakeProvider
{
    public function __construct(private ?array $parsed = [
        'delivery_order_id' => 10,
        'status' => 'ACCEPTED',
        'tracking_number' => null,
        'message' => null,
        'location' => null,
    ]) {
    }

    public function handleStatusWebhook(array $payload): ?array
    {
        return $this->parsed;
    }
}
