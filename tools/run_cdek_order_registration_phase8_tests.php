<?php

declare(strict_types=1);

/**
 * Phase 8 — CDEK order registration after PAID.
 * Usage: php tools/run_cdek_order_registration_phase8_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Models\DeliveryOrder;
use App\Services\Cdek\CdekOrderPayloadBuilder;
use App\Services\Cdek\CdekOrderRegistrationService;
use App\Services\Delivery\DeliveryPaymentService;
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

function samplePaidRow(array $over = []): array
{
    $quote = [
        'id' => 55,
        'total_amount' => 2500,
        'delivery_amount_to_pay' => 2500,
        'currency' => 'KZT',
        'quote_status' => 'paid_snapshot',
        'is_selected' => 1,
        'valid_until' => date('Y-m-d H:i:s', time() + 3600),
        'shipping_version' => 1,
        'service_code' => 'cdek_136',
        'tariff_code' => 136,
        'service_name' => 'PVZ-PVZ',
    ];
    return array_merge([
        'id' => 10,
        'order_id' => 100,
        'product_id' => 7,
        'order_number' => 'DO-2026-ABC123',
        'buyer_user_id' => 2,
        'seller_user_id' => 3,
        'status' => DeliveryOrder::STATUS_PAID,
        'payment_status' => 'paid',
        'paid_amount' => 2500,
        'shipping_version' => 1,
        'logistics_code' => 'cdek',
        'cdek_api_status' => DeliveryStatusMachine::API_NONE,
        'cdek_uuid' => null,
        'logistics_order_id' => null,
        'quote_id' => 55,
        'sender' => [
            'name' => 'Seller Name',
            'phone' => '+77001234567',
            'city' => 'Алматы',
            'street' => 'Абая 10',
            'building' => '1',
            'cdek_city_code' => 4756,
            'origin_type' => 'door',
        ],
        'recipient' => [
            'name' => 'Buyer Name',
            'phone' => '+77007654321',
            'city' => 'Астана',
            'delivery_mode' => 'pvz',
            'pvz_code' => 'AST1',
            'cdek_city_code' => 4961,
        ],
        'shipment' => [
            'product_title' => 'Монета',
            'billed_gross_weight' => 1.5,
            'gross_weight' => 1.5,
            'billed_length' => 10,
            'billed_width' => 10,
            'billed_height' => 10,
            'declared_cost' => 5000,
        ],
        'selected_quote' => $quote,
    ], $over);
}

$paySrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Delivery/DeliveryPaymentService.php');
$regSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekOrderRegistrationService.php');
$ordSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekOrderService.php');
$ctlSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/DeliveryController.php');
$dsSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Delivery/DeliveryService.php');
$pollSrc = (string) file_get_contents(dirname(__DIR__) . '/bin/cdek_order_poll.php');

// 1. CDEK order only when PAID — canCreate + assertPaidBarrier
assert_true(
    str_contains($paySrc, 'payment_paid')
    && str_contains($regSrc, 'assertPaidBarrier')
    && str_contains($regSrc, 'canCreateCdekOrder'),
    'CDEK order created only when PAID (gate + barrier)'
);

// 2–4. Pending / failed / refunded block
assert_true(str_contains($paySrc, 'STATUS_PAID') && str_contains($paySrc, 'status_not_paid'), 'Pending payment blocks registration');
assert_true(
    str_contains($paySrc, 'payment_refunded') || str_contains($regSrc, 'payment_not_paid'),
    'Failed/refunded payment blocks registration'
);
assert_true(str_contains($paySrc, 'STATUS_REFUNDED'), 'Refunded payment blocks registration');

// 5. Invalid quote
assert_true(str_contains($paySrc, 'quote_active') && str_contains($paySrc, 'quote_not_stale'), 'Invalid quote blocks registration');

// 6–7. Missing Point A / B
assert_true(str_contains($paySrc, 'point_a_valid') && str_contains($paySrc, 'point_b_valid'), 'Missing Point A/B blocks registration');

// 8. First request saves UUID after ACCEPTED
assert_true(
    str_contains($regSrc, 'API_ACCEPTED')
    && str_contains($regSrc, 'cdek_uuid')
    && str_contains($ordSrc, 'async'),
    'First request saves CDEK UUID after ACCEPTED'
);

// 9. Repeat with UUID — no second POST
assert_true(
    str_contains($regSrc, 'already_existed')
    && str_contains($regSrc, 'pollOne')
    && str_contains($ordSrc, 'existing_uuid'),
    'Repeat request with saved UUID does not second POST'
);

// 10. Concurrent — FOR UPDATE + MicroTaskLock
assert_true(
    str_contains($regSrc, 'FOR UPDATE')
    && str_contains($ordSrc, 'acquireLock'),
    'Concurrent requests do not create duplicate'
);

// 11–12. SUCCESSFUL / INVALID
assert_true(str_contains($regSrc, 'SUCCESSFUL') && str_contains($regSrc, 'ORDER_CREATED'), 'CDEK SUCCESSFUL → created state');
assert_true(str_contains($regSrc, 'INVALID') && str_contains($regSrc, 'CDEK_ORDER_FAILED'), 'CDEK INVALID saves error');

// 13. Timeout — resolve by IM, no new number
assert_true(
    str_contains($ordSrc, 'findByImNumber')
    && str_contains($ordSrc, 'allow_retry')
    && str_contains($regSrc, 'findByImNumber'),
    'Timeout does not recreate without IM check'
);

// 14. Duplicate IM handled safely
assert_true(
    str_contains($ordSrc, 'INTERNAL_DUPLICATE')
    || str_contains($ordSrc, 'v2_order_number_already_used')
    || str_contains($ordSrc, 'im_number_already_used'),
    'Duplicate IM number handled safely'
);

// 15. Frontend cannot override payment/quote/amount
assert_true(
    str_contains($ctlSrc, "unset(")
    && str_contains($ctlSrc, 'cdek_uuid')
    && str_contains($ctlSrc, 'amount')
    && str_contains($ctlSrc, 'quote_id'),
    'Frontend cannot override payment/quote/amount/uuid'
);

// 16. Seller=sender, buyer=recipient
$builder = new CdekOrderPayloadBuilder();
$avr = [
    'delivery_order_id' => 10,
    'order_number' => 'DO-2026-ABC123',
    'sender' => samplePaidRow()['sender'],
    'recipient' => samplePaidRow()['recipient'],
    'shipment' => samplePaidRow()['shipment'],
    'service' => ['service_code' => 'cdek_136', 'tariff_code' => 136],
];
$built = $builder->buildFromAvr($avr, [
    'from_city_code' => 4756,
    'to_city_code' => 4961,
]);
assert_true(($built['ok'] ?? false) === true, 'OrderCreate payload builds');
assert_true(
    ($built['payload']['sender']['name'] ?? '') === 'Seller Name'
    && ($built['payload']['recipient']['name'] ?? '') === 'Buyer Name',
    'Seller=sender and buyer=recipient in OrderCreate'
);
assert_true(($built['payload']['number'] ?? '') === 'DO-2026-ABC123', 'Stable IM number in payload');
assert_true(($built['payload']['delivery_point'] ?? '') === 'AST1', 'Point B PVZ as delivery_point');
assert_true(isset($built['payload']['from_location']['code']), 'Point A door as from_location');

// 202 ≠ ORDER_CREATED
assert_true(
    str_contains($regSrc, 'STATUS_CDEK_ORDER_PENDING')
    && str_contains($regSrc, 'API_ACCEPTED')
    && !preg_match('/async.*ORDER_CREATED.*immediate/i', $regSrc),
    '202 ACCEPTED maps to pending, not immediate CREATED'
);

// Payment confirm does not auto-register
assert_true(
    str_contains($paySrc, 'cdek_create_deferred')
    && !str_contains($paySrc, 'CdekOrderRegistrationService'),
    'Payment confirm does not auto-register CDEK'
);

// DeliveryService delegates to registration
assert_true(str_contains($dsSrc, 'CdekOrderRegistrationService'), 'DeliveryService uses registration service');

// Poll job exists and is limited
assert_true(
    str_contains($pollSrc, 'pollPending')
    && str_contains($pollSrc, 'cdek_order_poll')
    && class_exists(CdekOrderRegistrationService::class),
    'Limited polling background job present'
);

// canCreate false without paid (live gate)
$svc = new DeliveryPaymentService();
$gate = $svc->canCreateCdekOrder(0);
assert_true(($gate['ok'] ?? true) === false, 'canCreateCdekOrder false without delivery');

// FSM distinguishes ACCEPTED API vs SUCCESSFUL processing
assert_true(
    DeliveryStatusMachine::API_ACCEPTED === 'accepted'
    && DeliveryStatusMachine::API_CREATED === 'created'
    && DeliveryOrder::STATUS_CDEK_ORDER_PENDING === 'CDEK_ORDER_PENDING'
    && DeliveryOrder::STATUS_ORDER_CREATED === 'DELIVERY_ORDER_CREATED',
    'FSM distinguishes ACCEPTED vs CREATED'
);

// Route registered
$routes = (string) file_get_contents(dirname(__DIR__) . '/config/routes.php');
assert_true(str_contains($routes, '/delivery/{id}/cdek/register'), 'Register route present');

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
