<?php

declare(strict_types=1);

/**
 * Phase 12 — CDEK tracking, statuses, notifications.
 * php tools/run_cdek_tracking_phase12_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Models\DeliveryOrder;
use App\Models\DeliveryPrintForm;
use App\Services\Cdek\CdekOrderService;
use App\Services\Cdek\CdekReconciliationService;
use App\Services\Cdek\CdekStatusCatalog;
use App\Services\Cdek\CdekTrackingNotifier;
use App\Services\Cdek\CdekTrackingPolicy;
use App\Services\Cdek\CdekTrackingService;
use App\Services\Cdek\CdekWebhookService;
use App\Services\Cdek\Client;

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

final class Phase12Orders
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var list<array<string, mixed>> */
    public array $tracking = [];
    /** @var list<array<string, mixed>> */
    public array $events = [];
    public int $transitions = 0;
    public ?int $scheduledSeconds = null;

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function trackingFor(int $deliveryOrderId): array
    {
        $out = [];
        foreach (array_reverse($this->tracking) as $row) {
            if ((int) ($row['delivery_order_id'] ?? 0) === $deliveryOrderId) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    public function addTrackingEvent(int $deliveryOrderId, array $data): void
    {
        $this->tracking[] = $data + ['delivery_order_id' => $deliveryOrderId];
    }

    public function transitionStatus(int $id, string $status, ?int $actor = null, string $actorType = 'system', string $event = ''): bool
    {
        $this->transitions++;
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['status'] = $status;
        return true;
    }

    /** @param array<string, mixed> $fields */
    public function updateFields(int $id, array $fields): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = array_merge($this->rows[$id], $fields);
        }
    }

    public function logEvent(int $id, ?int $actor, string $role, string $type, mixed $from = null, mixed $to = null, ?array $payload = null): void
    {
        $this->events[] = ['id' => $id, 'type' => $type, 'payload' => $payload];
    }

    public function scheduleTrackingPoll(int $id, int $seconds): void
    {
        $this->scheduledSeconds = $seconds;
    }
}

final class Phase12Notices
{
    /** @var array<string, true> */
    public array $keys = [];

    public function claim(int $deliveryOrderId, string $noticeKey): bool
    {
        $key = $deliveryOrderId . '|' . $noticeKey;
        if (isset($this->keys[$key])) {
            return false;
        }
        $this->keys[$key] = true;
        return true;
    }
}

final class Phase12Notes
{
    /** @var list<array{user: int, message: string, link: ?string}> */
    public array $items = [];

    public function createFor(int $userId, string $message, ?string $link = null): void
    {
        $this->items[] = ['user' => $userId, 'message' => $message, 'link' => $link];
    }
}

function phase12_client(array $response): Client
{
    return new class($response) extends Client {
        public function __construct(private array $response)
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
            if (!empty($this->response['forbid_get'])) {
                throw new RuntimeException('GET must not run');
            }
            return $this->response;
        }
    };
}

function phase12_stack(Phase12Orders $orders, Phase12Notices $notices, Phase12Notes $notes, Client $client): CdekTrackingService
{
    $webhook = new CdekWebhookService(
        ['test_mode' => 1, 'webhook_token' => 't'],
        new stdClass(),
        $orders,
        null,
        new CdekTrackingNotifier($notices, $notes)
    );
    return new CdekTrackingService(new CdekOrderService($client), $webhook, $orders);
}

$buyerRow = [
    'id' => 10,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'payment_status' => 'paid',
    'cdek_api_status' => 'created',
    'cdek_status_code' => 'SENT_TO_RECIPIENT_CITY',
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'order_number' => 'DO-10',
    'logistics_order_id' => 'uuid-10',
    'last_carrier_event_at' => '2026-02-02 10:00:00',
    'planned_delivery_date' => '2026-02-20',
    'sender' => ['phone' => '+77001112233', 'address' => 'secret street'],
    'recipient' => ['phone' => '+77004445566'],
];

$summary = CdekTrackingService::summaryForUser($buyerRow, 5, false, [[
    'id' => 3,
    'status' => DeliveryPrintForm::STATUS_READY,
    'form_type' => 'tpl_russia',
    'format' => 'pdf',
    'storage_path' => '10/3.pdf',
    'file_url' => 'https://cdek.example/secret.pdf',
]]);
assert_true(($summary['ok'] ?? false) === true, 'buyer opens own delivery');
$public = $summary['summary'] ?? [];
assert_true(($public['carrier_status_label'] ?? '') === 'Отправление в пути.', 'known status has a plain label');
assert_true(($public['eta'] ?? null) === '2026-02-20', 'ETA only when CDEK date is stored');
assert_true(($public['completed'] ?? true) === false, 'in transit is not completed');
assert_true(($public['print_forms_available'] ?? false) === true, 'ready print form is visible');
assert_true(!isset($public['cdek_status_code']), 'buyer does not receive raw status code');
$encoded = json_encode($public, JSON_UNESCAPED_UNICODE);
assert_true(is_string($encoded) && !str_contains($encoded, 'secret') && !str_contains($encoded, '7700') && !str_contains($encoded, 'access_token'), 'summary has no PII or token');
assert_true(!str_contains((string) $encoded, 'file_url') && !str_contains((string) $encoded, 'storage_path'), 'print form has no CDEK file url');

$seller = CdekTrackingService::summaryForUser($buyerRow, 9, false, []);
assert_true(($seller['ok'] ?? false) === true, 'seller opens related delivery');
assert_true(($seller['summary']['carrier_status_label'] ?? '') === 'Отправление в пути.', 'seller sees carrier text');

$foreign = CdekTrackingService::summaryForUser($buyerRow, 3, false, []);
assert_true(($foreign['ok'] ?? false) === false && ($foreign['error'] ?? '') === 'forbidden' && !isset($foreign['summary']), 'foreign delivery is denied');

$unknownRow = $buyerRow;
$unknownRow['cdek_status_code'] = 'ZZ_NOT_A_DOCUMENTED_CODE';
$unknownRow['status'] = DeliveryOrder::STATUS_IN_TRANSIT;
$unknownSummary = CdekTrackingService::summarize($unknownRow, 'buyer');
assert_true(($unknownSummary['carrier_status_label'] ?? '') === 'Статус уточняется', 'unknown code is neutral');
assert_true(($unknownSummary['carrier_known'] ?? true) === false, 'unknown code is flagged');
assert_true(($unknownSummary['completed'] ?? true) === false, 'unknown code is not delivery');

$noEta = $buyerRow;
unset($noEta['planned_delivery_date']);
assert_true(CdekTrackingService::summarize($noEta, 'buyer')['eta'] === null, 'missing ETA stays empty');
assert_true(CdekTrackingPolicy::plannedDate('next week') === null, 'unparsed ETA is dropped');

$awaiting = $buyerRow;
$awaiting['cdek_status_code'] = '';
$awaiting['status'] = DeliveryOrder::STATUS_ACCEPTED;
assert_true(
    str_contains((string) CdekTrackingService::summarize($awaiting, 'buyer')['carrier_status_label'], 'не подтверждена'),
    'registration is not handover'
);

assert_true(CdekStatusCatalog::group('CREATED') === CdekStatusCatalog::GROUP_REGISTERED, 'CREATED is registration, not ACCEPTED');
assert_true(CdekStatusCatalog::group('DELIVERED') === 'DELIVERED', 'DELIVERED maps to final group');
assert_true(CdekStatusCatalog::fsmForGroup('READY_FOR_PICKUP') === DeliveryOrder::STATUS_IN_TRANSIT, 'pickup stays IN_TRANSIT in FSM');
assert_true(CdekTrackingPolicy::backoffSeconds('rate_limit', 1) === 1800, '429 backoff is 30 minutes');
assert_true(CdekTrackingPolicy::backoffSeconds('rate_limit', 2) === 3600, '429 backoff grows');
assert_true(CdekTrackingPolicy::backoffSeconds('timeout', 8) === 21600, 'repeated failures slow to 6 hours');
assert_true(CdekTrackingPolicy::classifyFailure(503, null, 'down') === 'unavailable', '5xx is unavailable');
assert_true(CdekTrackingPolicy::classifyFailure(0, 'network', 'curl') === 'network', 'network failure is classified');
assert_true(CdekTrackingPolicy::isStaleEvent('2026-02-01 09:00:00', '2026-02-02 10:00:00') === true, 'older event is stale');

$orders = new Phase12Orders();
$orders->rows[10] = [
    'id' => 10,
    'status' => DeliveryOrder::STATUS_ORDER_CREATED,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'order_number' => 'DO-10',
    'logistics_order_id' => 'uuid-10',
];
$notices = new Phase12Notices();
$notes = new Phase12Notes();
$webhook = new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $orders, null, new CdekTrackingNotifier($notices, $notes));

$first = $webhook->applyParsed([
    'delivery_order_id' => 10,
    'status' => 'IN_TRANSIT',
    'raw_code' => 'SENT_TO_RECIPIENT_CITY',
    'event_at' => '2026-02-02T10:00:00+0000',
    'message' => CdekStatusCatalog::text('SENT_TO_RECIPIENT_CITY', 'buyer'),
    'tracking_number' => 'CN-1',
]);
assert_true(($first['ok'] ?? false) === true && ($orders->rows[10]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'newer transit is applied');
assert_true($notes->items === [], 'plain transit does not notify');

$late = $webhook->applyParsed([
    'delivery_order_id' => 10,
    'status' => 'DELIVERED',
    'raw_code' => 'DELIVERED',
    'event_at' => '2026-02-01T09:00:00+0000',
    'message' => CdekStatusCatalog::text('DELIVERED', 'buyer'),
    'tracking_number' => 'CN-1',
]);
assert_true(!empty($late['stale']) && ($orders->rows[10]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'out-of-order webhook does not overwrite');
assert_true($notes->items === [], 'stale delivered event does not notify');

$final = $webhook->applyParsed([
    'delivery_order_id' => 10,
    'status' => 'DELIVERED',
    'raw_code' => 'DELIVERED',
    'event_at' => '2026-02-03T12:00:00+0000',
    'message' => CdekStatusCatalog::text('DELIVERED', 'buyer'),
    'tracking_number' => 'CN-1',
]);
assert_true(($orders->rows[10]['status'] ?? '') === DeliveryOrder::STATUS_DELIVERED, 'final status is stored');
assert_true(count($notes->items) === 2, 'buyer and seller notified once');
foreach ($notes->items as $item) {
    assert_true(!str_contains($item['message'], '7700'), 'notification has no phone');
}

$again = $webhook->applyParsed([
    'delivery_order_id' => 10,
    'status' => 'DELIVERED',
    'raw_code' => 'DELIVERED',
    'event_at' => '2026-02-03T12:00:00+0000',
    'message' => CdekStatusCatalog::text('DELIVERED', 'buyer'),
    'tracking_number' => 'CN-1',
]);
assert_true(count($notes->items) === 2 && empty($again['changed']), 'repeat webhook does not notify again');

$rollback = $webhook->applyParsed([
    'delivery_order_id' => 10,
    'status' => 'IN_TRANSIT',
    'raw_code' => 'TAKEN_BY_COURIER',
    'event_at' => '2026-02-04T12:00:00+0000',
    'message' => CdekStatusCatalog::text('TAKEN_BY_COURIER', 'buyer'),
    'tracking_number' => 'CN-1',
]);
assert_true(($orders->rows[10]['status'] ?? '') === DeliveryOrder::STATUS_DELIVERED, 'status does not roll back');
assert_true(($orders->rows[10]['cdek_status_code'] ?? '') === 'DELIVERED', 'stored code does not roll back');
assert_true(count($notes->items) === 2 && ($rollback['changed'] ?? true) === false, 'rollback does not notify');

$doneSummary = CdekTrackingService::summarize($orders->rows[10], 'buyer');
assert_true(($doneSummary['completed'] ?? false) === true, 'completed only after final status');

$gapOrders = new Phase12Orders();
$gapOrders->rows[11] = [
    'id' => 11,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'logistics_order_id' => 'uuid-11',
];
$gapHook = new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $gapOrders, null, new CdekTrackingNotifier(new Phase12Notices(), new Phase12Notes()));
$gapHook->applyParsed([
    'delivery_order_id' => 11,
    'status' => 'UNKNOWN',
    'raw_code' => 'ZZ_NOT_A_DOCUMENTED_CODE',
    'event_at' => '2026-03-01T00:00:00+0000',
    'message' => 'Статус уточняется',
]);
assert_true(($gapOrders->rows[11]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'unknown code does not finish delivery');
assert_true(($gapOrders->rows[11]['cdek_status_code'] ?? '') === 'ZZ_NOT_A_DOCUMENTED_CODE', 'unknown code is stored');
assert_true(($gapOrders->events[0]['type'] ?? '') === 'cdek_status_gap', 'unknown code records a GAP');

$remoteOrders = new Phase12Orders();
$remoteOrders->rows[12] = [
    'id' => 12,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'order_number' => 'DO-12',
    'logistics_order_id' => 'uuid-12',
    'cdek_api_status' => 'created',
];
$remoteNotes = new Phase12Notes();
$remoteNotices = new Phase12Notices();
$deliveredClient = phase12_client([
    'ok' => true,
    'code' => 200,
    'data' => [
        'entity' => [
            'uuid' => 'uuid-12',
            'cdek_number' => 'CN-12',
            'planned_delivery_date' => '2026-04-01',
            'statuses' => [
                ['code' => 'DELIVERED', 'date_time' => '2026-01-01T00:00:00+0000', 'deleted' => true],
                ['code' => 'ACCEPTED', 'date_time' => '2026-03-01T00:00:00+0000'],
                ['code' => 'SENT_TO_RECIPIENT_CITY', 'date_time' => '2026-03-02T00:00:00+0000'],
            ],
        ],
    ],
]);
$reconciled = (new CdekReconciliationService(new CdekOrderService($deliveredClient), new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $remoteOrders, null, new CdekTrackingNotifier($remoteNotices, $remoteNotes)), $remoteOrders))->reconcileOne($remoteOrders->rows[12]);
assert_true(($reconciled['ok'] ?? false) === true, 'GET reconciliation runs');
assert_true(($remoteOrders->rows[12]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'deleted DELIVERED is ignored');
assert_true(($remoteOrders->rows[12]['cdek_status_code'] ?? '') === 'SENT_TO_RECIPIENT_CITY', 'latest live code is kept');
assert_true(($remoteOrders->rows[12]['planned_delivery_date'] ?? '') === '2026-04-01', 'GET stores CDEK planned date');
assert_true($remoteNotes->items === [], 'same-path poll of transit does not notify');

$pendingOrders = new Phase12Orders();
$pendingOrders->rows[13] = [
    'id' => 13,
    'status' => DeliveryOrder::STATUS_CDEK_ORDER_PENDING,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'logistics_order_id' => 'uuid-13',
    'cdek_api_status' => 'pending',
];
$pendingClient = phase12_client([
    'ok' => true,
    'code' => 202,
    'internal_status' => 'accepted',
    'data' => [
        'entity' => ['uuid' => 'uuid-13', 'statuses' => []],
        'requests' => [['state' => 'ACCEPTED']],
    ],
]);
$pendingHook = new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $pendingOrders);
$pendingResult = (new CdekTrackingService(new CdekOrderService($pendingClient), $pendingHook, $pendingOrders))->refreshOne($pendingOrders->rows[13]);
assert_true(($pendingResult['ok'] ?? false) === true && ($pendingOrders->rows[13]['status'] ?? '') === DeliveryOrder::STATUS_CDEK_ORDER_PENDING, 'HTTP 202 is not delivery');
assert_true(CdekTrackingService::summarize($pendingOrders->rows[13], 'buyer')['completed'] === false, 'accepted request is not completed');

$errorOrders = new Phase12Orders();
$errorOrders->rows[14] = [
    'id' => 14,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'logistics_order_id' => 'uuid-14',
    'tracking_poll_attempts' => 0,
];
$limitClient = phase12_client([
    'ok' => false,
    'code' => 429,
    'error' => 'slow down',
    'error_mapped' => ['error_type' => 'rate_limit', 'http_status' => 429, 'message' => 'slow down'],
]);
$limitResult = (new CdekTrackingService(new CdekOrderService($limitClient), new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $errorOrders), $errorOrders))->refreshOne($errorOrders->rows[14]);
assert_true(($limitResult['ok'] ?? true) === false && ($errorOrders->rows[14]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, '429 does not finish delivery');
assert_true(($errorOrders->rows[14]['last_error_code'] ?? '') === 'rate_limit', '429 is stored as rate limit');
assert_true(($errorOrders->scheduledSeconds ?? 0) === 1800, '429 schedules backoff');

$downOrders = new Phase12Orders();
$downOrders->rows[15] = [
    'id' => 15,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'logistics_order_id' => 'uuid-15',
    'tracking_poll_attempts' => 1,
];
$downClient = phase12_client([
    'ok' => false,
    'code' => 0,
    'error' => 'timed out',
    'error_mapped' => ['error_type' => 'timeout', 'http_status' => null, 'message' => 'timed out'],
]);
$downResult = (new CdekTrackingService(new CdekOrderService($downClient), new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $downOrders), $downOrders))->refreshOne($downOrders->rows[15]);
assert_true(($downResult['ok'] ?? true) === false && ($downOrders->rows[15]['status'] ?? '') === DeliveryOrder::STATUS_IN_TRANSIT, 'unavailable API does not finish delivery');
assert_true(($downOrders->rows[15]['last_error_code'] ?? '') === 'timeout', 'timeout is classified');

$missing = (new CdekTrackingService(new CdekOrderService(phase12_client(['forbid_get' => true])), new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), new Phase12Orders()), new Phase12Orders()))
    ->refreshOne(['id' => 16, 'status' => DeliveryOrder::STATUS_PAID]);
assert_true(($missing['error'] ?? '') === 'missing_uuid', 'tracking without UUID does not call CDEK');

$svcSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekTrackingService.php');
$ctlSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/DeliveryController.php');
assert_true(str_contains($svcSrc, 'getByUuid'), 'tracking reads GET order');
assert_true(!str_contains($svcSrc, 'CdekOrderRegistrationService') && !str_contains($svcSrc, '->create('), 'tracking cannot create a CDEK order');
assert_true(str_contains($ctlSrc, 'function tracking') && str_contains($ctlSrc, 'function refreshTracking'), 'user tracking endpoints exist');
assert_true(!str_contains($ctlSrc, 'getByUuid') && !str_contains($ctlSrc, '->create('), 'user endpoint does not call CDEK create');

$pollOrders = new Phase12Orders();
$pollOrders->rows[17] = [
    'id' => 17,
    'status' => DeliveryOrder::STATUS_IN_TRANSIT,
    'buyer_user_id' => 5,
    'seller_user_id' => 9,
    'order_number' => 'DO-17',
    'logistics_order_id' => 'uuid-17',
];
$pollNotes = new Phase12Notes();
$pollNotices = new Phase12Notices();
$pollClient = phase12_client([
    'ok' => true,
    'code' => 200,
    'data' => [
        'entity' => [
            'uuid' => 'uuid-17',
            'statuses' => [
                ['code' => 'DELIVERED', 'date_time' => '2026-05-01T00:00:00+0000'],
            ],
        ],
    ],
]);
$pollHook = new CdekWebhookService(['test_mode' => 1, 'webhook_token' => 't'], new stdClass(), $pollOrders, null, new CdekTrackingNotifier($pollNotices, $pollNotes));
$pollSvc = new CdekTrackingService(new CdekOrderService($pollClient), $pollHook, $pollOrders);
$pollSvc->refreshOne($pollOrders->rows[17]);
$afterFirst = count($pollNotes->items);
$pollSvc->refreshOne($pollOrders->rows[17]);
assert_true($afterFirst === 2 && count($pollNotes->items) === 2, 'polling the same status does not notify again');

echo "passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
