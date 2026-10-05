<?php

declare(strict_types=1);

/**
 * Lightweight runner for Phase 2 delivery model tests (no Composer/phpunit binary required).
 * Usage: php tools/run_delivery_phase2_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Models\DeliveryOrder;
use App\Services\Delivery\DeliveryModelService;
use App\Services\Delivery\DeliveryPii;
use App\Services\Delivery\DeliveryPointValidator;
use App\Services\Delivery\DeliveryStatusMachine;

$failed = 0;
$passed = 0;

function assert_true(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "OK  {$msg}\n";
        return;
    }
    $failed++;
    echo "FAIL {$msg}\n";
}

$v = new DeliveryPointValidator();

$r = $v->validatePointA([
    'name' => 'Иван Продавец',
    'phone' => '+77001234567',
    'city' => 'Алматы',
    'street' => 'Абая 10',
    'origin_type' => 'door',
    'cdek_city_code' => 271,
], true);
assert_true($r['ok'] === true, 'Point A valid');

$r = $v->validatePointA(['name' => 'S', 'phone' => '123', 'city' => 'Алматы'], false);
assert_true($r['ok'] === false && ($r['error'] ?? '') === 'sender_phone_invalid', 'Point A invalid phone');

$r = $v->validatePointA([
    'name' => 'Seller',
    'phone' => '+77001234567',
    'city' => 'Алматы',
    'origin_type' => 'pvz',
], true);
assert_true($r['ok'] === false && ($r['error'] ?? '') === 'shipment_point_required', 'Point A PVZ requires shipment_point');

$r = $v->validatePointB([
    'name' => 'Buyer',
    'phone' => '+77007654321',
    'city' => 'Астана',
    'street' => 'Кабанбай 1',
    'delivery_mode' => 'courier',
]);
assert_true($r['ok'] === true, 'Point B courier valid');

$r = $v->validatePointB([
    'name' => 'Buyer',
    'phone' => '+77007654321',
    'city' => 'Астана',
    'delivery_mode' => 'pvz',
]);
assert_true($r['ok'] === false && ($r['error'] ?? '') === 'pvz_required', 'Point B PVZ requires code');

$r = $v->validateShipment([
    'item_weight' => 1.5,
    'item_length' => 20,
    'item_width' => 15,
    'item_height' => 10,
    'declared_cost' => 15000,
]);
assert_true($r['ok'] === true && $r['data']['declared_cost'] === 15000, 'Shipment valid');

$r = $v->validateShipment(['item_weight' => 0, 'item_length' => 10, 'item_width' => 10, 'item_height' => 10]);
assert_true($r['ok'] === false, 'Shipment rejects zero weight');

$fsm = new DeliveryStatusMachine();
assert_true($fsm->canTransition(DeliveryOrder::STATUS_PAID, DeliveryOrder::STATUS_CDEK_ORDER_PENDING), 'PAID → CDEK_ORDER_PENDING');
assert_true(!$fsm->canTransition(DeliveryOrder::STATUS_DATA_COLLECTION, DeliveryOrder::STATUS_DELIVERED), 'reject DATA→DELIVERED');
assert_true(!$fsm->canApplyCarrierProgress(DeliveryOrder::STATUS_DELIVERED, DeliveryOrder::STATUS_EXCEPTION), 'no downgrade from DELIVERED');
assert_true($fsm->isCreateAllowed(DeliveryOrder::STATUS_PAID, 'none', null), 'create allowed after paid');
assert_true(!$fsm->isCreateAllowed(DeliveryOrder::STATUS_QUOTE_RECEIVED, 'none', null), 'quote does not allow create');
assert_true(!$fsm->isCreateAllowed(DeliveryOrder::STATUS_PAID, 'none', 'uuid'), 'uuid blocks second create');

$masked = DeliveryPii::maskPhone('+77001234567');
assert_true($masked === '*******4567', 'phone mask');
$payload = DeliveryPii::redactPayload(['name' => 'Иван', 'amount' => 1000]);
assert_true($payload['amount'] === 1000 && $payload['name'] !== 'Иван', 'payload redact');

$orders = new class extends DeliveryOrder {
    public array $updates = [];
    public function __construct()
    {
    }
    public function find($id): ?array
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
$svc = (new ReflectionClass(DeliveryModelService::class))->newInstanceWithoutConstructor();
$ref = new ReflectionProperty(DeliveryModelService::class, 'orders');
$ref->setAccessible(true);
$ref->setValue($svc, $orders);
$r = $svc->saveCdekIdentifiers(1, 'uuid-abc', '1234567890', 'req-1');
assert_true($r['ok'] === true && $orders->updates[0]['cdek_uuid'] === 'uuid-abc', 'save CDEK uuid/number');

$conflictOrders = new class extends DeliveryOrder {
    public function __construct()
    {
    }
    public function find($id): ?array
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
$ref->setValue($svc, $conflictOrders);
$r = $svc->saveCdekIdentifiers(1, 'uuid-new');
assert_true($r['ok'] === false && ($r['error'] ?? '') === 'cdek_uuid_conflict', 'reject conflicting uuid');

$pendingUpdates = [];
$pendingOrders = new class ($pendingUpdates) extends DeliveryOrder {
    public array $updates;
    public function __construct(array &$updates)
    {
        $this->updates = &$updates;
    }
    public function find($id): ?array
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
$ref->setValue($svc, $pendingOrders);
$refFsm = new ReflectionProperty(DeliveryModelService::class, 'fsm');
$refFsm->setAccessible(true);
$refFsm->setValue($svc, $fsm);
$r = $svc->markCreatePending(5);
assert_true(
    $r['ok'] === true
    && !empty($r['deferred'])
    && $pendingUpdates[0]['status'] === DeliveryOrder::STATUS_CDEK_ORDER_PENDING
    && $pendingUpdates[0]['cdek_api_status'] === 'pending',
    'markCreatePending defers CDEK API'
);

$alreadyUpdates = [];
$alreadyOrders = new class ($alreadyUpdates) extends DeliveryOrder {
    public array $updates;
    public function __construct(array &$updates)
    {
        $this->updates = &$updates;
    }
    public function find($id): ?array
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
    public function buildCreateIdempotencyKey(int $deliveryOrderId, ?int $paymentId = null): string
    {
        return 'idem';
    }
};
$ref->setValue($svc, $alreadyOrders);
$r = $svc->markCreatePending(5);
assert_true($r['ok'] === true && !empty($r['already']) && $alreadyUpdates === [], 'idempotent skip when uuid exists');

$do = (new ReflectionClass(DeliveryOrder::class))->newInstanceWithoutConstructor();
$a = $do->buildCreateIdempotencyKey(42, 7);
$b = $do->buildCreateIdempotencyKey(42, 7);
$c = $do->buildCreateIdempotencyKey(42, 8);
assert_true($a === $b && $a !== $c && strlen($a) === 64, 'idempotency key stable/unique');

assert_true(DeliveryModelService::isOrderCreateEnabled() === false || DeliveryModelService::isOrderCreateEnabled() === true, 'order_create_enabled readable');
// Default from example is false unless env set
putenv('CDEK_ORDER_CREATE_ENABLED=0');
assert_true(DeliveryModelService::isOrderCreateEnabled() === false, 'order create disabled by default env');

echo "\nPassed: {$passed}; Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
