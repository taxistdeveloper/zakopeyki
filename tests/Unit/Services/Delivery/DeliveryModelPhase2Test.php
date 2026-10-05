<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Models\DeliveryOrder;
use App\Services\Delivery\DeliveryModelService;
use App\Services\Delivery\DeliveryPii;
use App\Services\Delivery\DeliveryPointValidator;
use App\Services\Delivery\DeliveryStatusMachine;
use PHPUnit\Framework\TestCase;

final class DeliveryPointValidatorTest extends TestCase
{
    private DeliveryPointValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DeliveryPointValidator();
    }

    public function testCanValidatePointA(): void
    {
        $result = $this->validator->validatePointA([
            'name' => 'Иван Продавец',
            'phone' => '+77001234567',
            'city' => 'Алматы',
            'street' => 'Абая 10',
            'origin_type' => 'door',
            'cdek_city_code' => 271,
        ], true);

        $this->assertTrue($result['ok']);
        $this->assertSame('Иван Продавец', $result['data']['name']);
        $this->assertSame(271, $result['data']['cdek_city_code']);
    }

    public function testRejectsInvalidPointAPhone(): void
    {
        $result = $this->validator->validatePointA([
            'name' => 'Seller',
            'phone' => '123',
            'city' => 'Алматы',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('sender_phone_invalid', $result['error']);
    }

    public function testRejectsPvzOriginWithoutShipmentPoint(): void
    {
        $result = $this->validator->validatePointA([
            'name' => 'Seller',
            'phone' => '+77001234567',
            'city' => 'Алматы',
            'origin_type' => 'pvz',
        ], true);
        $this->assertFalse($result['ok']);
        $this->assertSame('shipment_point_required', $result['error']);
    }

    public function testCanValidatePointBCourier(): void
    {
        $result = $this->validator->validatePointB([
            'name' => 'Покупатель',
            'phone' => '+77007654321',
            'city' => 'Астана',
            'street' => 'Кабанбай 1',
            'delivery_mode' => 'courier',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('courier', $result['data']['delivery_mode']);
    }

    public function testRejectsPointBPvzWithoutCode(): void
    {
        $result = $this->validator->validatePointB([
            'name' => 'Buyer',
            'phone' => '+77007654321',
            'city' => 'Астана',
            'delivery_mode' => 'pvz',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('pvz_required', $result['error']);
    }

    public function testCanValidatePointBPvz(): void
    {
        $result = $this->validator->validatePointB([
            'name' => 'Buyer',
            'phone' => '+77007654321',
            'city' => 'Астана',
            'delivery_mode' => 'pvz',
            'pvz_code' => 'AST1',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame('AST1', $result['data']['delivery_point']);
    }

    public function testRejectsInvalidShipmentWeight(): void
    {
        $result = $this->validator->validateShipment([
            'item_weight' => 0,
            'item_length' => 10,
            'item_width' => 10,
            'item_height' => 10,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('weight_required', $result['error']);
    }

    public function testCanValidateShipment(): void
    {
        $result = $this->validator->validateShipment([
            'item_weight' => 1.5,
            'item_length' => 20,
            'item_width' => 15,
            'item_height' => 10,
            'declared_cost' => 15000,
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame(15000, $result['data']['declared_cost']);
    }
}

final class DeliveryStatusMachineTest extends TestCase
{
    public function testAllowsPaidToCdekPending(): void
    {
        $fsm = new DeliveryStatusMachine();
        $this->assertTrue($fsm->canTransition(
            DeliveryOrder::STATUS_PAID,
            DeliveryOrder::STATUS_CDEK_ORDER_PENDING
        ));
    }

    public function testRejectsRandomJumpToDelivered(): void
    {
        $fsm = new DeliveryStatusMachine();
        $this->assertFalse($fsm->canTransition(
            DeliveryOrder::STATUS_DATA_COLLECTION,
            DeliveryOrder::STATUS_DELIVERED
        ));
    }

    public function testDoesNotAllowDowngradeFromDelivered(): void
    {
        $fsm = new DeliveryStatusMachine();
        $this->assertFalse($fsm->canApplyCarrierProgress(
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_EXCEPTION
        ));
    }

    public function testCreateAllowedOnlyAfterPaidWithoutUuid(): void
    {
        $fsm = new DeliveryStatusMachine();
        $this->assertTrue($fsm->isCreateAllowed(DeliveryOrder::STATUS_PAID, DeliveryStatusMachine::API_NONE, null));
        $this->assertFalse($fsm->isCreateAllowed(DeliveryOrder::STATUS_READY_FOR_PAYMENT, DeliveryStatusMachine::API_NONE, null));
        $this->assertFalse($fsm->isCreateAllowed(DeliveryOrder::STATUS_PAID, DeliveryStatusMachine::API_NONE, 'already-uuid'));
    }
}

final class DeliveryPiiTest extends TestCase
{
    public function testMasksPhoneAndName(): void
    {
        $this->assertSame('*******4567', DeliveryPii::maskPhone('+77001234567'));
        $redacted = DeliveryPii::redactPayload(['name' => 'Иван', 'phone' => '+77001234567', 'amount' => 1000]);
        $this->assertSame(1000, $redacted['amount']);
        $this->assertNotSame('Иван', $redacted['name']);
    }
}

final class DeliveryModelServiceIdempotencyTest extends TestCase
{
    public function testSaveCdekIdentifiersRejectsConflictingUuid(): void
    {
        $orders = new class extends DeliveryOrder {
            public function __construct()
            {
            }
            public function find(int $id): ?array
            {
                return ['id' => 1, 'cdek_uuid' => 'uuid-old', 'logistics_order_id' => 'uuid-old'];
            }
            public function findByCdekUuid(string $uuid): ?array
            {
                return null;
            }
            public function updateFields(int $id, array $fields): void
            {
            }
        };

        $svc = (new \ReflectionClass(DeliveryModelService::class))->newInstanceWithoutConstructor();
        $p = new \ReflectionProperty(DeliveryModelService::class, 'orders');
        $p->setAccessible(true);
        $p->setValue($svc, $orders);

        $result = $svc->saveCdekIdentifiers(1, 'uuid-new');
        $this->assertFalse($result['ok']);
        $this->assertSame('cdek_uuid_conflict', $result['error']);
    }

    public function testSaveCdekIdentifiersStoresUuidAndNumber(): void
    {
        $updates = [];
        $orders = new class ($updates) extends DeliveryOrder {
            public array $updates;
            public function __construct(array &$updates)
            {
                $this->updates = &$updates;
            }
            public function find(int $id): ?array
            {
                return ['id' => 1, 'cdek_uuid' => null, 'logistics_order_id' => null];
            }
            public function findByCdekUuid(string $uuid): ?array
            {
                return null;
            }
            public function updateFields(int $id, array $fields): void
            {
                $this->updates[] = $fields;
            }
        };

        $svc = (new \ReflectionClass(DeliveryModelService::class))->newInstanceWithoutConstructor();
        $p = new \ReflectionProperty(DeliveryModelService::class, 'orders');
        $p->setAccessible(true);
        $p->setValue($svc, $orders);

        $result = $svc->saveCdekIdentifiers(1, 'uuid-abc', '1234567890', 'req-1');
        $this->assertTrue($result['ok']);
        $this->assertSame('uuid-abc', $updates[0]['cdek_uuid']);
        $this->assertSame('1234567890', $updates[0]['cdek_number']);
    }

    public function testMarkCreatePendingSetsPendingWithoutApi(): void
    {
        $updates = [];
        $orders = new class ($updates) extends DeliveryOrder {
            public array $updates;
            public function __construct(array &$updates)
            {
                $this->updates = &$updates;
            }
            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'status' => DeliveryOrder::STATUS_PAID,
                    'cdek_uuid' => null,
                    'logistics_order_id' => null,
                    'cdek_api_status' => 'none',
                    'create_idempotency_key' => '',
                    'payment_id' => 11,
                ];
            }
            public function updateFields(int $id, array $fields): void
            {
                $this->updates[] = $fields;
            }
            public function logEvent(...$args): void
            {
            }
            public function buildCreateIdempotencyKey(int $deliveryOrderId, ?int $paymentId = null): string
            {
                return 'idem-5-11';
            }
        };

        $svc = (new \ReflectionClass(DeliveryModelService::class))->newInstanceWithoutConstructor();
        $p = new \ReflectionProperty(DeliveryModelService::class, 'orders');
        $p->setAccessible(true);
        $p->setValue($svc, $orders);
        $f = new \ReflectionProperty(DeliveryModelService::class, 'fsm');
        $f->setAccessible(true);
        $f->setValue($svc, new DeliveryStatusMachine());

        $first = $svc->markCreatePending(5);
        $this->assertTrue($first['ok']);
        $this->assertTrue($first['deferred']);
        $this->assertSame(DeliveryOrder::STATUS_CDEK_ORDER_PENDING, $updates[0]['status']);
    }

    public function testMarkCreatePendingSkipsWhenUuidAlreadySet(): void
    {
        $updates = [];
        $orders = new class ($updates) extends DeliveryOrder {
            public array $updates;
            public function __construct(array &$updates)
            {
                $this->updates = &$updates;
            }
            public function find(int $id): ?array
            {
                return [
                    'id' => 5,
                    'status' => DeliveryOrder::STATUS_ORDER_CREATED,
                    'cdek_uuid' => 'existing',
                    'logistics_order_id' => 'existing',
                    'cdek_api_status' => 'created',
                    'create_idempotency_key' => 'idem',
                    'payment_id' => 11,
                ];
            }
            public function updateFields(int $id, array $fields): void
            {
                $this->updates[] = $fields;
            }
            public function logEvent(...$args): void
            {
            }
        };

        $svc = (new \ReflectionClass(DeliveryModelService::class))->newInstanceWithoutConstructor();
        $p = new \ReflectionProperty(DeliveryModelService::class, 'orders');
        $p->setAccessible(true);
        $p->setValue($svc, $orders);
        $f = new \ReflectionProperty(DeliveryModelService::class, 'fsm');
        $f->setAccessible(true);
        $f->setValue($svc, new DeliveryStatusMachine());

        $result = $svc->markCreatePending(5);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['already']);
        $this->assertSame([], $updates);
    }

    public function testBuildCreateIdempotencyKeyStable(): void
    {
        $orders = (new \ReflectionClass(DeliveryOrder::class))->newInstanceWithoutConstructor();
        $a = $orders->buildCreateIdempotencyKey(42, 7);
        $b = $orders->buildCreateIdempotencyKey(42, 7);
        $c = $orders->buildCreateIdempotencyKey(42, 8);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }

    public function testQuoteIsSeparateFromOrderCreateGate(): void
    {
        $fsm = new DeliveryStatusMachine();
        $this->assertFalse($fsm->isCreateAllowed(
            DeliveryOrder::STATUS_QUOTE_RECEIVED,
            DeliveryStatusMachine::API_NONE,
            null
        ));
    }
}
