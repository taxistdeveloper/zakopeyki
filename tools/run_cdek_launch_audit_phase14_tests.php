<?php

declare(strict_types=1);

/**
 * Phase 14 — launch audit guards. No network, no orders, no payments.
 * php tools/run_cdek_launch_audit_phase14_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\Cdek\CdekWebhookService;

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

$examplePath = dirname(__DIR__) . '/config/cdek.php.example';
$exampleSrc = (string) file_get_contents($examplePath);
assert_true(str_contains($exampleSrc, "getenv('CDEK_ACCOUNT') ?: ''"), 'account comes only from env');
assert_true(str_contains($exampleSrc, "getenv('CDEK_SECURE_PASSWORD') ?: ''"), 'password comes only from env');
assert_true(preg_match("/'account'\\s*=>\\s*getenv\\('CDEK_ACCOUNT'\\)\\s*\\?:\\s*''/", $exampleSrc) === 1, 'account fallback is empty');
assert_true(preg_match("/'secure_password'\\s*=>\\s*getenv\\('CDEK_SECURE_PASSWORD'\\)\\s*\\?:\\s*''/", $exampleSrc) === 1, 'password fallback is empty');

$saved = [];
foreach (['CDEK_ACCOUNT', 'CDEK_SECURE_PASSWORD', 'CDEK_TEST_MODE', 'CDEK_API_URL', 'CDEK_ORDER_CREATE_ENABLED'] as $name) {
    $saved[$name] = getenv($name);
    putenv($name . '=');
}
/** @var array<string, mixed> $cfg */
$cfg = require $examplePath;
foreach ($saved as $name => $value) {
    if ($value === false) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
}

assert_true(($cfg['account'] ?? null) === '' && ($cfg['secure_password'] ?? null) === '', 'missing env does not fill CDEK credentials');
assert_true(($cfg['order_create_enabled'] ?? true) === false, 'order create stays off without env');
assert_true(($cfg['test_mode'] ?? 0) === 1, 'default mode is test');
assert_true(($cfg['api_url'] ?? '') === 'https://api.edu.cdek.ru/v2', 'default URL is the edu sandbox');
assert_true(($cfg['webhook_token'] ?? 'x') === '', 'webhook token is empty until configured');

$prodHook = new CdekWebhookService(['test_mode' => 0, 'webhook_token' => '']);
$prod = $prodHook->authorize([], []);
assert_true(($prod['ok'] ?? true) === false, 'production rejects webhooks when token is not set');

$testHook = new CdekWebhookService(['test_mode' => 1, 'webhook_token' => '']);
$test = $testHook->authorize([], []);
assert_true(($test['ok'] ?? false) === true && ($test['mode'] ?? '') === 'test_open', 'test mode without token is open — must not be used on a public host');

$paySrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Delivery/DeliveryPaymentService.php');
$regSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekOrderRegistrationService.php');
assert_true(str_contains($paySrc, 'amount_sufficient') && str_contains($paySrc, 'currency_match'), 'payment gate checks amount and currency');
assert_true(str_contains($paySrc, 'cdek_create_deferred'), 'payment confirmation does not itself create a CDEK order');
assert_true(str_contains($regSrc, 'canCreateCdekOrder') && str_contains($regSrc, 'assertPaidBarrier'), 'registration repeats the paid barrier');
assert_true(str_contains($regSrc, 'findByImNumber'), 'timeout recovery looks up the order before another create');

$listSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Listing/ListingShippingService.php');
assert_true(str_contains($listSrc, 'Только самовывоз'), 'pickup-only listing is not blocked by CDEK point A');

$actionSrc = (string) file_get_contents(dirname(__DIR__) . '/app/Services/Cdek/CdekDeliveryActionService.php');
assert_true(str_contains($actionSrc, 'mayCallCdek') && !str_contains($actionSrc, 'DeliveryPayment'), 'refusal path does not touch payment');

echo "passed={$passed} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
