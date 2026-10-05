<?php

declare(strict_types=1);

/**
 * Phase 6 — CDEK Calculator / Delivery Quote.
 * Usage: php tools/run_cdek_calculator_phase6_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\Cdek\CdekAuthService;
use App\Services\Cdek\CdekCalculatorRequestBuilder;
use App\Services\Cdek\CdekCalculatorResponseMapper;
use App\Services\Cdek\CdekCalculatorService;
use App\Services\Cdek\CdekErrorMapper;
use App\Services\Cdek\CdekRequestLogger;
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

function sampleSender(): array
{
    return [
        'name' => 'Seller',
        'phone' => '+77001112233',
        'city' => 'Алматы',
        'country' => 'KZ',
        'origin_type' => 'door',
        'cdek_city_code' => 4756,
        'street' => 'Абая 1',
    ];
}

function sampleRecipient(string $mode = 'pvz'): array
{
    if ($mode === 'courier') {
        return [
            'name' => 'Buyer',
            'phone' => '+77007654321',
            'city' => 'Астана',
            'country' => 'KZ',
            'delivery_mode' => 'courier',
            'cdek_city_code' => 4961,
            'street' => 'Қабанбай 10',
            'building' => '5',
        ];
    }
    return [
        'name' => 'Buyer',
        'phone' => '+77007654321',
        'city' => 'Астана',
        'country' => 'KZ',
        'delivery_mode' => 'pvz',
        'cdek_city_code' => 4961,
        'pvz_code' => 'AST1',
        'delivery_point' => 'AST1',
    ];
}

function sampleShipment(array $over = []): array
{
    return array_merge([
        'billed_gross_weight' => 1.5,
        'gross_weight' => 1.5,
        'billed_length' => 20,
        'billed_width' => 15,
        'billed_height' => 10,
        'package_length' => 20,
        'package_width' => 15,
        'package_height' => 10,
    ], $over);
}

function fakeTarifflistBody(): array
{
    return [
        'tariff_codes' => [[
            'tariff_code' => 136,
            'tariff_name' => 'Посылка склад-склад',
            'delivery_mode' => 4,
            'delivery_sum' => 1890,
            'period_min' => 2,
            'period_max' => 4,
        ]],
    ];
}

function makeService(callable $transport): CdekCalculatorService
{
    $tokenPath = sys_get_temp_dir() . '/cdek_p6_' . getmypid() . '_' . bin2hex(random_bytes(3)) . '.json';
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
        'token_cache_path' => $tokenPath,
        'currency' => 2,
        'currency_label' => 'KZT',
        'order_type' => 1,
        'lang' => 'rus',
        'quote_ttl_seconds' => 7200,
    ];
    $auth = new CdekAuthService($cfg, null, static function () {
        return [200, json_encode([
            'access_token' => 'tok-live',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => 'api',
            'jti' => 'j',
        ])];
    });
    $client = new Client(
        $cfg,
        $auth,
        new CdekErrorMapper(),
        new CdekRequestLogger(['http_log_enabled' => 0]),
        $transport
    );
    return new CdekCalculatorService($client);
}

$okTransport = static function (string $method, string $url, ?string $body, array $headers): array {
    if (str_contains($url, 'calculator/tarifflist')) {
        return [200, json_encode(fakeTarifflistBody())];
    }
    return [404, '{"errors":[{"message":"not found"}]}'];
};

$svc = makeService($okTransport);

// 1. Correct calculation
$calc = $svc->calculate([
    'delivery_order_id' => 1,
    'product_id' => 10,
    'order_id' => 20,
    'sender' => sampleSender(),
    'recipient' => sampleRecipient('pvz'),
    'shipment' => sampleShipment(),
    'packaging_price' => 0,
]);
assert_true(($calc['ok'] ?? false) === true && (int) ($calc['quotes'][0]['delivery_amount_to_pay'] ?? 0) === 1890, 'Correct calculation');

// 2. Missing Point A
$ready = $svc->validateReady([
    'id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => null,
    'recipient' => sampleRecipient(),
    'shipment' => sampleShipment(),
]);
assert_true(($ready['ok'] ?? true) === false && ($ready['error_code'] ?? '') === 'point_a_missing', 'Missing Point A');

// 3. Missing Point B
$ready = $svc->validateReady([
    'id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => null,
    'shipment' => sampleShipment(),
]);
assert_true(($ready['ok'] ?? true) === false && ($ready['error_code'] ?? '') === 'point_b_missing', 'Missing Point B');

// 4. Missing Shipment
$ready = $svc->validateReady([
    'id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => sampleRecipient(),
    'shipment' => null,
]);
assert_true(($ready['ok'] ?? true) === false && ($ready['error_code'] ?? '') === 'shipment_missing', 'Missing Shipment');

// 5. Missing weight
$ready = $svc->validateReady([
    'id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => sampleRecipient(),
    'shipment' => sampleShipment(['billed_gross_weight' => 0, 'gross_weight' => 0, 'item_weight' => 0]),
]);
assert_true(($ready['ok'] ?? true) === false && ($ready['error_code'] ?? '') === 'weight_missing', 'Missing weight');

// 6. Incorrect dimensions/weight rejected by builder
$builder = new CdekCalculatorRequestBuilder();
$badPkg = $builder->build(
    ['from_location' => ['code' => 4756]],
    ['to_location' => ['code' => 4961]],
    [['weight' => 0, 'length' => -1]]
);
assert_true(($badPkg['ok'] ?? true) === false, 'Incorrect dimensions/weight rejected by builder');

// 7. Unsuitable PVZ (mode=pvz without code)
$ready = $svc->validateReady([
    'id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => [
        'name' => 'Buyer', 'phone' => '+77007654321', 'city' => 'Астана',
        'delivery_mode' => 'pvz', 'cdek_city_code' => 4961,
    ],
    'shipment' => sampleShipment(),
]);
assert_true(($ready['ok'] ?? true) === false && ($ready['error_code'] ?? '') === 'pvz_required', 'Unsuitable PVZ without code');

// 8. CDEK 400
$err400 = makeService(static function (string $method, string $url, ?string $body, array $headers): array {
    return [400, json_encode(['errors' => [['code' => 'v2_delivery_location_not_recognized', 'message' => 'bad dest']]])];
});
$r400 = $err400->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(), 'recipient' => sampleRecipient(), 'shipment' => sampleShipment(),
]);
assert_true(($r400['ok'] ?? true) === false && (int) ($r400['http_status'] ?? 0) === 400, 'CDEK 400 error');

// 9. Timeout
$errTo = makeService(static function (string $method, string $url, ?string $body, array $headers): array {
    return [0, '', 'Operation timed out after 45000 milliseconds'];
});
$rTo = $errTo->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(), 'recipient' => sampleRecipient(), 'shipment' => sampleShipment(),
]);
assert_true(($rTo['ok'] ?? true) === false && ($rTo['error_code'] ?? '') === CdekErrorMapper::INTERNAL_TIMEOUT, 'Timeout');

// 10. CDEK 429
$err429 = makeService(static function (string $method, string $url, ?string $body, array $headers): array {
    return [429, json_encode(['errors' => [['message' => 'rate limit']]])];
});
$r429 = $err429->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(), 'recipient' => sampleRecipient(), 'shipment' => sampleShipment(),
]);
assert_true(($r429['ok'] ?? true) === false && ($r429['error_code'] ?? '') === CdekErrorMapper::INTERNAL_RATE_LIMIT, 'CDEK 429');

// 11. CDEK 5xx
$err500 = makeService(static function (string $method, string $url, ?string $body, array $headers): array {
    return [503, json_encode(['errors' => [['message' => 'unavailable']]])];
});
$r500 = $err500->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(), 'recipient' => sampleRecipient(), 'shipment' => sampleShipment(),
]);
assert_true(($r500['ok'] ?? true) === false && ($r500['error_code'] ?? '') === CdekErrorMapper::INTERNAL_SERVER, 'CDEK 5xx');

// 12–13. Quote stores cost + currency + deliveryAmountToPay
$q = $calc['quotes'][0] ?? [];
assert_true(
    isset($q['cdek_delivery_sum'], $q['delivery_amount_to_pay'], $q['currency'], $q['tariff_code'])
    && $q['currency'] === 'KZT'
    && (int) $q['delivery_amount_to_pay'] === (int) $q['total_amount'],
    'Quote stores cost, currency, deliveryAmountToPay'
);

// 14. Recalculation (door)
$doorSvc = makeService(static function (string $method, string $url, ?string $body, array $headers): array {
    return [200, json_encode(['tariff_codes' => [[
        'tariff_code' => 137,
        'tariff_name' => 'door',
        'delivery_mode' => 1,
        'delivery_sum' => 2500,
        'period_min' => 1,
        'period_max' => 3,
    ]]])];
});
$calcDoor = $doorSvc->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => sampleRecipient('courier'),
    'shipment' => sampleShipment(),
]);
assert_true(($calcDoor['ok'] ?? false) === true && (int) ($calcDoor['quotes'][0]['tariff_code'] ?? 0) === 137, 'Recalculation door tariff');

// 15. Point B change invalidates via different request_hash
$hash1 = (string) ($calc['request_hash'] ?? '');
$hashDoor = (string) ($calcDoor['request_hash'] ?? '');
assert_true($hash1 !== '' && $hashDoor !== '' && $hash1 !== $hashDoor, 'Point B change changes request hash');

// 16. Weight change → different package_hash
$calcHeavy = $svc->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => sampleRecipient('pvz'),
    'shipment' => sampleShipment(['billed_gross_weight' => 5.0, 'gross_weight' => 5.0]),
]);
assert_true(
    ($calcHeavy['ok'] ?? false) === true
    && ($calcHeavy['package_hash'] ?? '') !== ($calc['package_hash'] ?? ''),
    'Weight change changes package hash'
);

// 17. Frontend cannot substitute amount
$calcAmt = $svc->calculate([
    'delivery_order_id' => 1, 'product_id' => 1, 'order_id' => 1,
    'sender' => sampleSender(),
    'recipient' => sampleRecipient('pvz'),
    'shipment' => sampleShipment(),
    'total_amount' => 1,
    'delivery_amount_to_pay' => 1,
    'packaging_price' => 0,
]);
assert_true((int) ($calcAmt['quotes'][0]['delivery_amount_to_pay'] ?? 0) === 1890, 'Frontend cannot substitute delivery amount');

// 18. Foreign order blocked in DeliveryService
$src = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Delivery/DeliveryService.php');
assert_true(
    str_contains($src, 'calculateQuotesForBuyer')
    && str_contains($src, "\$row['buyer_user_id'] !== \$actorId"),
    'Foreign order blocked in calculateQuotesForBuyer'
);

assert_true(CdekCalculatorService::ENDPOINT === '/calculator/tarifflist', 'Primary endpoint is tarifflist');

$mapper = new CdekCalculatorResponseMapper();
$mapped = $mapper->map(fakeTarifflistBody(), [
    'delivery_mode_filter' => 'pvz',
    'currency_label' => 'KZT',
    'request_hash' => 'x',
]);
assert_true((int) ($mapped['quotes'][0]['delivery_amount_to_pay'] ?? 0) === 1890, 'Mapper sets delivery_amount_to_pay');

assert_true(empty($r400['quotes']), 'No Delivery Quote on failed calculation');

// Invalidation hooks exist
assert_true(
    str_contains($src, "invalidateQuotes(\$deliveryOrderId, 'address_changed')")
    && str_contains($src, "invalidateQuotes(\$deliveryOrderId, 'shipment_changed')"),
    'Point B / weight changes invalidate quotes'
);

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
