<?php

declare(strict_types=1);

/**
 * Phase 13 — delivery problems, refusal, clientReturn.
 * php tools/run_cdek_delivery_problems_phase13_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Models\DeliveryCdekAction;
use App\Models\DeliveryOrder;
use App\Services\Cdek\CdekDeliveryActionPolicy;
use App\Services\Cdek\CdekDeliveryActionService;
use App\Services\Cdek\CdekDeliveryProblemService;

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

final class Phase13Orders
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function findByOrderNumber(string $number): ?array
    {
        foreach ($this->rows as $row) {
            if ((string) ($row['order_number'] ?? '') === $number) {
                return $row;
            }
        }
        return null;
    }

    public function findByLogisticsOrderId(string $uuid): ?array
    {
        foreach ($this->rows as $row) {
            if ((string) ($row['logistics_order_id'] ?? '') === $uuid) {
                return $row;
            }
        }
        return null;
    }

    public function findByTrackingNumber(string $number): ?array
    {
        return null;
    }

    public function logEvent(int $id, ?int $actor, string $role, string $type, ?string $from = null, ?string $to = null, ?array $payload = null): void
    {
        $this->events[] = ['type' => $type, 'payload' => $payload];
    }
}

final class Phase13Problems
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @param array<string, mixed> $row */
    public function insertIgnore(array $row): array
    {
        foreach ($this->rows as $existing) {
            if (($existing['event_hash'] ?? '') === ($row['event_hash'] ?? '')) {
                return ['inserted' => false, 'id' => (int) $existing['id']];
            }
        }
        $row['id'] = count($this->rows) + 1;
        $this->rows[] = $row;
        return ['inserted' => true, 'id' => (int) $row['id']];
    }

    public function forDelivery(int $deliveryOrderId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => (int) ($row['delivery_order_id'] ?? 0) === $deliveryOrderId
        ));
    }
}

final class Phase13Actions
{
    /** @var array<string, array<string, mixed>> */
    public array $byKey = [];
    /** @var array<int, array<string, mixed>> */
    public array $byId = [];
    public int $seq = 0;

    public function findByKey(string $key): ?array
    {
        return $this->byKey[$key] ?? null;
    }

    public function findById(int $id): ?array
    {
        return $this->byId[$id] ?? null;
    }

    public function forDelivery(int $deliveryOrderId): array
    {
        $out = [];
        foreach ($this->byId as $row) {
            if ((int) ($row['delivery_order_id'] ?? 0) === $deliveryOrderId) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        $row['id'] = ++$this->seq;
        $this->byKey[(string) $row['idempotency_key']] = $row;
        $this->byId[$row['id']] = $row;
        return $row['id'];
    }

    /** @param array<string, mixed> $fields */
    public function updateState(int $id, array $fields): void
    {
        if (!isset($this->byId[$id])) {
            return;
        }
        $this->byId[$id] = array_merge($this->byId[$id], $fields);
        $key = (string) ($this->byId[$id]['idempotency_key'] ?? '');
        if ($key !== '') {
            $this->byKey[$key] = $this->byId[$id];
        }
    }
}

final class Phase13Lock
{
    public bool $busy = false;

    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        if ($this->busy) {
            return false;
        }
        $this->busy = true;
        return true;
    }

    public function release(string $key): void
    {
        $this->busy = false;
    }
}

final class Phase13Api
{
    public int $calls = 0;

    public function refuseOrder(string $uuid): never
    {
        $this->calls++;
        throw new RuntimeException('refusal must not be called');
    }

    public function clientReturn(string $uuid, array $body): never
    {
        $this->calls++;
        throw new RuntimeException('clientReturn must not be called');
    }

    public function createOrder(array $body): never
    {
        $this->calls++;
        throw new RuntimeException('create order must not be called');
    }
}

$orders = new Phase13Orders();
$orders->rows[4] = [
    'id' => 4,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'order_number' => 'DO-4',
    'logistics_order_id' => 'uuid-4',
    'recipient' => ['phone' => '+77001112233'],
];
$problems = new Phase13Problems();
$actions = new Phase13Actions();
$api = new Phase13Api();
$problemSvc = new CdekDeliveryProblemService($problems, $orders);
$actionSvc = new CdekDeliveryActionService($actions, $orders, $api, new Phase13Lock());

$saved = $problemSvc->applyWebhook([
    'type' => 'DELIV_PROBLEM',
    'uuid' => 'evt-1',
    'attributes' => [
        'number' => 'DO-4',
        'code' => 'ADDR',
        'create_date' => '2026-04-01T10:00:00+0000',
        'phone' => '+77001112233',
        'address' => 'secret street',
    ],
]);
assert_true(($saved['ok'] ?? false) === true && count($problems->rows) === 1, 'DELIV_PROBLEM is stored');
assert_true(($orders->rows[4]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'problem does not change delivery status');
assert_true($actions->byId === [], 'problem does not start refusal or clientReturn');
$encodedProblem = json_encode($problems->rows[0], JSON_UNESCAPED_UNICODE);
assert_true(is_string($encodedProblem) && !str_contains($encodedProblem, '7700') && !str_contains($encodedProblem, 'secret'), 'problem row has no phone or address');
$buyerView = $problemSvc->listForUser(4, 5, false);
assert_true(($buyerView['ok'] ?? false) === true && !isset($buyerView['problems'][0]['problem_code']), 'buyer sees the problem without the raw code');
assert_true(($buyerView['problems'][0]['summary'] ?? '') === 'Требуется уточнение по доставке. CDEK сообщил о проблеме, но это не возврат товара и не отказ от отправления.', 'neutral problem text');
$adminView = $problemSvc->listForUser(4, 1, true);
assert_true(($adminView['problems'][0]['problem_code'] ?? '') === 'ADDR', 'admin can see the problem code');

$again = $problemSvc->applyWebhook([
    'type' => 'DELIV_PROBLEM',
    'uuid' => 'evt-1',
    'attributes' => ['number' => 'DO-4', 'code' => 'ADDR', 'create_date' => '2026-04-01T10:00:00+0000'],
]);
assert_true(!empty($again['duplicate']) && count($problems->rows) === 1, 'repeat problem webhook is not duplicated');

$unknown = $problemSvc->applyWebhook([
    'type' => 'DELIV_PROBLEM',
    'attributes' => ['number' => 'NO-SUCH', 'code' => 'ADDR'],
]);
assert_true(($unknown['error'] ?? '') === 'unresolved_order', 'unknown order is not stored');

$foreign = $problemSvc->listForUser(4, 3, false);
assert_true(($foreign['error_code'] ?? '') === 'forbidden' && !isset($foreign['problems']), 'foreign user cannot read problems');
$missingOrder = $actionSvc->listForUser(404, 5, false);
assert_true(($missingOrder['error_code'] ?? '') === 'not_found', 'unknown delivery id is not found');

$noConfirm = $actionSvc->request(4, 5, false, 'refusal', []);
assert_true(($noConfirm['error_code'] ?? '') === 'needs_confirmation' && $api->calls === 0 && $actions->byId === [], 'explicit confirmation is required');

$orders->rows[8] = [
    'id' => 8,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
];
$missingUuid = $actionSvc->request(8, 5, false, 'refusal', ['confirm' => '1']);
assert_true(($missingUuid['error_code'] ?? '') === 'missing_uuid' && $api->calls === 0, 'missing CDEK UUID does not call CDEK');

$orders->rows[9] = [
    'id' => 9,
    'status' => DeliveryOrder::STATUS_DELIVERED,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'logistics_order_id' => 'uuid-9',
];
$terminal = $actionSvc->request(9, 5, false, 'refusal', ['confirm' => '1', 'reason' => 'no']);
assert_true(($terminal['error_code'] ?? '') === 'operation_not_allowed' && $api->calls === 0, 'terminal status blocks the operation');

$foreignAction = $actionSvc->request(4, 3, false, 'refusal', ['confirm' => '1']);
assert_true(($foreignAction['error_code'] ?? '') === 'forbidden' && $api->calls === 0, 'foreign user cannot request an action');

$blocked = $actionSvc->request(4, 5, false, 'refusal', ['confirm' => '1', 'reason' => '<b>phone +7700</b>']);
assert_true(($blocked['error_code'] ?? '') === 'right_unconfirmed' && ($blocked['cdek_called'] ?? true) === false, 'unconfirmed right does not call CDEK');
assert_true(($blocked['marketplace_return'] ?? true) === false, 'refusal is not a marketplace return');
assert_true(($actions->byId[1]['state'] ?? '') === DeliveryCdekAction::CANCELLED, 'blocked request is stored as not sent');
assert_true(!str_contains((string) ($actions->byId[1]['reason'] ?? ''), '<b>'), 'reason is stripped');
assert_true($api->calls === 0, 'GAP: live refusal POST is not sent');

$repeat = $actionSvc->request(4, 5, false, 'refusal', ['confirm' => '1']);
assert_true(($repeat['error_code'] ?? '') === 'duplicate' && count($actions->byId) === 1 && $api->calls === 0, 'repeat request does not call CDEK');

$lock = new Phase13Lock();
$lock->busy = true;
$parallelSvc = new CdekDeliveryActionService($actions, $orders, $api, $lock);
$parallel = $parallelSvc->request(4, 9, false, 'refusal', ['confirm' => '1']);
assert_true(($parallel['error_code'] ?? '') === 'in_progress' && $api->calls === 0, 'parallel request is locked');

$badTariff = $actionSvc->request(4, 5, false, 'client_return', ['confirm' => '1']);
assert_true(($badTariff['error_code'] ?? '') === 'tariff_invalid' && $api->calls === 0, 'clientReturn without tariff is rejected');
$tariff = $actionSvc->request(4, 5, false, 'client_return', ['confirm' => '1', 'tariff_code' => 136]);
assert_true(($tariff['error_code'] ?? '') === 'right_unconfirmed' && $api->calls === 0, 'GAP: live clientReturn POST is not sent');

$accepted = CdekDeliveryActionPolicy::interpretApiResult([
    'http_status' => 202,
    'data' => ['requests' => [['state' => 'ACCEPTED', 'request_uuid' => 'req-1']]],
]);
assert_true($accepted['state'] === DeliveryCdekAction::SUBMITTED && $accepted['confirmed'] === false && $accepted['retry_post'] === false, 'HTTP 202 is accepted, not completed');

$invalid = CdekDeliveryActionPolicy::interpretApiResult([
    'http_status' => 400,
    'data' => ['requests' => [['state' => 'INVALID']]],
]);
assert_true($invalid['state'] === DeliveryCdekAction::FAILED && $invalid['retry_post'] === false, 'INVALID does not confirm the operation');

$timeout = CdekDeliveryActionPolicy::interpretApiResult([
    'http_status' => 0,
    'error' => 'timed out',
    'error_type' => 'timeout',
]);
assert_true($timeout['state'] === DeliveryCdekAction::UNKNOWN_RESULT && $timeout['retry_post'] === false, 'timeout does not allow a blind retry');

$follow = $actionSvc->applyFollowUp(
    ['id' => 1, 'cdek_request_uuid' => 'req-1', 'operation_type' => 'client_return', 'state' => DeliveryCdekAction::SUBMITTED],
    [
        'requests' => [['request_uuid' => 'req-1', 'state' => 'SUCCESSFUL']],
        'related_entities' => [['type' => 'client_return_order', 'uuid' => 'return-uuid']],
        'entity' => ['is_client_return' => true],
    ]
);
assert_true(($follow['action']['state'] ?? '') === DeliveryCdekAction::CONFIRMED, 'GET SUCCESSFUL confirms the request');
assert_true(($follow['marketplace_return'] ?? true) === false && ($follow['delivery_status_changed'] ?? true) === false, 'confirmation is not a marketplace return');
assert_true(($orders->rows[4]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT && $api->calls === 0, 'follow-up does not create an order or change payment state');
assert_true(($actions->byId[1]['related_uuid'] ?? '') === 'return-uuid', 'related return UUID is stored only');

$available = CdekDeliveryActionService::availableActions();
assert_true($available['refusal']['available'] === false && $available['client_return']['available'] === false && $available['marketplace_return']['available'] === false, 'no action is offered in the UI contract');

$actionSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekDeliveryActionService.php');
$problemSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekDeliveryProblemService.php');
$apiSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekApi.php');
assert_true(!str_contains($actionSrc, 'createOrder') && !str_contains($problemSrc, 'createOrder'), 'operations do not create a CDEK order');
assert_true(!str_contains($actionSrc, 'DeliveryPayment') && !str_contains($problemSrc, 'DeliveryPayment'), 'payment flow is not referenced');
assert_true(!str_contains($problemSrc, '->refuseOrder') && !str_contains($problemSrc, '->clientReturn('), 'a problem does not call refusal or clientReturn');
assert_true(str_contains($apiSrc, '/refusal') && str_contains($apiSrc, '/clientReturn'), 'documented endpoints exist on the API client');
assert_true(CdekDeliveryActionPolicy::mayCallCdek() === false, 'production gate stays closed');

$problemSvc->ingestFromEntity(4, [
    'delivery_problem' => [
        ['code' => 'LATE', 'create_date' => '2026-04-02T08:00:00+0000'],
    ],
]);
assert_true(count($problems->rows) === 2 && ($orders->rows[4]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'GET delivery_problem is stored without a return');

echo "passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
