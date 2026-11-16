<?php

declare(strict_types=1);

/**
 * M-01 security hardening — регрессионные тесты.
 *
 *   php tools/run_security_m01_tests.php           # всё, включая тест escrow на локальной БД
 *   php tools/run_security_m01_tests.php --unit    # без БД
 *
 * Тест escrow создаёт одну временную строку в orders и удаляет её.
 */

require __DIR__ . '/../bin/bootstrap.php';

use App\Core\Database;
use App\Helpers\ClientIp;
use App\Models\Order;
use App\Services\Cdek\CdekWebhookService;
use App\Services\EscrowService;
use App\Services\HalykEpay\IntentStore;
use App\Services\Payment\HalykEpayGateway;
use App\Services\Streaming\CloudflareStreamClient;

$unitOnly = in_array('--unit', $argv, true);
$passes = 0;
$total = 0;

function check(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    if ($cond) {
        $passes++;
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}\n";
    }
}

echo "=== M-01 Security hardening tests ===\n";

// ---------------------------------------------------------------- B0.1 Halyk postLink
echo "\n-- B0.1 Halyk postLink validation\n";
$token = 'm01test' . bin2hex(random_bytes(6));
$invoice = '9' . str_pad((string) random_int(0, 99999999999999), 14, '0', STR_PAD_LEFT);
IntentStore::put($token, [
    'order_id' => 'WALLET-1-' . time() . '-m01',
    'invoice_id' => $invoice,
    'amount' => 5000,
    'currency' => 'KZT',
    'secret_hash' => 'expectedsecret123',
    'payment_id' => 'halyk-' . $invoice,
    'payment_type' => 'payment',
]);
$base = ['invoiceId' => $invoice, 'secret_hash' => 'expectedsecret123', 'code' => 'ok', 'amount' => '5000', 'currency' => 'KZT'];

$r = HalykEpayGateway::validatePostLink($base, 'TERM1');
check(!empty($r['ok']) && ($r['intent']['order_id'] ?? '') !== '', 'valid callback accepted, order id taken from server intent');
$r = HalykEpayGateway::validatePostLink(['code' => 'ok', 'amount' => '5000000', 'pg_order_id' => 'WALLET-7-1-x'], 'TERM1');
check(empty($r['ok']) && ($r['error'] ?? '') === 'invoice_missing', 'forged callback without invoice rejected');
$r = HalykEpayGateway::validatePostLink(['invoiceId' => $invoice, 'code' => 'ok', 'amount' => '5000'] , 'TERM1');
check(($r['error'] ?? '') === 'secret_hash_missing', 'callback without secret_hash rejected');
$r = HalykEpayGateway::validatePostLink(['invoiceId' => '123456789', 'secret_hash' => 'x', 'code' => 'ok', 'amount' => '1'], 'TERM1');
check(($r['error'] ?? '') === 'intent_not_found', 'unknown invoice rejected');
$r = HalykEpayGateway::validatePostLink(array_merge($base, ['secret_hash' => 'wrong']), 'TERM1');
check(($r['error'] ?? '') === 'secret_hash_mismatch', 'wrong secret_hash rejected');
$r = HalykEpayGateway::validatePostLink(array_merge($base, ['amount' => '5000000']), 'TERM1');
check(($r['error'] ?? '') === 'amount_mismatch', 'inflated amount rejected');
$r = HalykEpayGateway::validatePostLink(array_merge($base, ['currency' => 'USD']), 'TERM1');
check(($r['error'] ?? '') === 'currency_mismatch', 'currency mismatch rejected');
$r = HalykEpayGateway::validatePostLink(array_merge($base, ['terminal' => 'OTHER']), 'TERM1');
check(($r['error'] ?? '') === 'terminal_mismatch', 'terminal mismatch rejected');
$r = HalykEpayGateway::validatePostLink(array_merge($base, ['code' => 'error', 'reasonCode' => '05', 'amount' => '0']), 'TERM1');
check(!empty($r['ok']), 'authentic failure callback accepted regardless of amount');

check(IntentStore::claimInvoice($invoice) === true, 'first claim of invoice succeeds');
check(IntentStore::claimInvoice($invoice) === false, 'repeated postLink for same invoice is ignored');

IntentStore::forget($token);
@unlink(dirname(__DIR__) . '/storage/halyk_intents/done_' . $invoice . '.lock');

$src = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/PaymentController.php');
$start = (int) strpos($src, 'function halykPostLink');
$fn = substr($src, $start, (int) strpos($src, 'function halykSuccess') - $start);
check(
    strpos($fn, 'validatePostLink') !== false
        && strpos($fn, 'validatePostLink') < strpos($fn, 'applySimulatedGatewayResult')
        && !str_contains($fn, "params['pg_order_id']"),
    'halykPostLink validates before applying and never reads pg_order_id from request'
);

// ---------------------------------------------------------------- B0.2 config flags
echo "\n-- B0.2 config flags\n";
$php = escapeshellarg(PHP_BINARY);
$cfg = escapeshellarg(dirname(__DIR__) . '/config/app.php');
$cmd = 'APP_ENV=production APP_ALLOW_SIMULATED_PAYMENTS=1 APP_DEBUG=1 ' . $php
    . ' -r ' . escapeshellarg('$c = require ' . $cfg . '; echo json_encode([$c["allow_simulated_payments"], $c["debug"]]);');
$out = json_decode((string) shell_exec($cmd . ' 2>/dev/null'), true);
check($out === [false, false], 'production forces allow_simulated_payments=false and debug=false');

// ---------------------------------------------------------------- B0.3 wallet deposit
echo "\n-- B0.3 wallet deposit\n";
$wsrc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/WalletController.php');
check(!str_contains($wsrc, '->createPaymentIntent(') && method_exists(\App\Services\HalykEpay\Client::class, 'initPayment')
    && str_contains($wsrc, '->initPayment('), 'wallet deposit uses existing HalykEpay\\Client::initPayment');

// ---------------------------------------------------------------- B0.4 reset link
echo "\n-- B0.4 password reset link exposure\n";
$asrc = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/AuthController.php');
$seg = substr($asrc, (int) strpos($asrc, 'auth_reset_url') - 600, 700);
check(!str_contains($seg, 'HTTP_HOST'), 'reset URL display no longer depends on Host header');

// ---------------------------------------------------------------- B0.8 client IP
echo "\n-- B0.8 client IP resolution\n";
check(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], []) === '203.0.113.5', 'spoofed X-Forwarded-For from direct client ignored');
check(ClientIp::resolve(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'], []) === '203.0.113.5', 'spoofed CF-Connecting-IP from non-Cloudflare ignored');
check(ClientIp::resolve(['REMOTE_ADDR' => '172.64.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.7'], []) === '198.51.100.7', 'CF-Connecting-IP trusted from Cloudflare range');
check(ClientIp::resolve(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9'], []) === '198.51.100.9', 'loopback proxy: rightmost untrusted hop used (prepended fake ignored)');
check(ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.10'], ['10.0.0.0/8']) === '198.51.100.10', 'configured trusted proxy honoured');
check(ClientIp::inCidr('2606:4700::1', '2606:4700::/32') && !ClientIp::inCidr('2607::1', '2606:4700::/32'), 'IPv6 CIDR matching');

// ---------------------------------------------------------------- B0.7 webhooks
echo "\n-- B0.7 webhooks\n";
$body = '{"uid":"abc","readyToStream":true}';
$now = 1_800_000_000;
$sig = hash_hmac('sha256', $now . '.' . $body, 's3cret');
check(CloudflareStreamClient::verifySignatureWithSecret($body, "time={$now},sig1={$sig}", 's3cret', $now), 'Cloudflare time/sig1 signature accepted');
check(!CloudflareStreamClient::verifySignatureWithSecret($body, "time={$now},sig1={$sig}", 's3cret', $now + 3600), 'stale Cloudflare signature rejected');
check(!CloudflareStreamClient::verifySignatureWithSecret($body, "time={$now},sig1=deadbeef", 's3cret', $now), 'wrong Cloudflare signature rejected');
check(!CloudflareStreamClient::verifySignatureWithSecret($body, '', '', $now), 'no secret configured → rejected');

$stub = new stdClass();
$prevEnv = $GLOBALS['appConfig']['env'] ?? null;
$GLOBALS['appConfig']['env'] = 'production';
$svc = new CdekWebhookService(['webhook_token' => '', 'test_mode' => 1], $stub, $stub, $stub, $stub);
check(empty($svc->authorize([], [])['ok']), 'CDEK webhook without token rejected in production even with test_mode');
$GLOBALS['appConfig']['env'] = 'local';
check(!empty($svc->authorize([], [])['ok']), 'CDEK test_mode stays open in local env');
$GLOBALS['appConfig']['env'] = $prevEnv;

// ---------------------------------------------------------------- B0.6 private uploads
echo "\n-- B0.6 private uploads\n";
$ht = dirname(__DIR__) . '/public/uploads/business/.htaccess';
check(is_file($ht) && str_contains((string) file_get_contents($ht), 'Require all denied'), 'public/uploads/business denies direct access');
check(method_exists(\App\Controllers\AdminController::class, 'businessDocument'), 'admin-only business document endpoint exists');

// ---------------------------------------------------------------- B0.5 escrow race
if (!$unitOnly) {
    echo "\n-- B0.5 escrow settle under stale snapshot (local DB)\n";
    $db = Database::connect();
    $ref = $db->query('SELECT product_id, buyer_id, seller_id FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    if (!$ref) {
        echo " [SKIP] no orders in local DB to borrow FK ids\n";
    } else {
        $db->prepare(
            "INSERT INTO orders (product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold)
             VALUES (?, ?, ?, 1000, 'wallet', 'pickup', 'completed', 'released_seller')"
        )->execute([$ref['product_id'], $ref['buyer_id'], $ref['seller_id']]);
        $testId = (int) $db->lastInsertId();
        try {
            $stale = new class extends Order {
                public function find(int $id): ?array
                {
                    $row = parent::find($id);
                    if ($row) {
                        $row['status'] = 'delivered';
                        $row['escrow_hold'] = 'holding';
                    }
                    return $row;
                }
            };
            $countTx = static function () use ($db): int {
                return (int) $db->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn();
            };
            $before = $countTx();
            $res = (new EscrowService($stale))->settle($testId, 0, ['auto' => true, 'status' => 'completed']);
            $after = $countTx();
            $row = $db->query('SELECT status, escrow_hold FROM orders WHERE id = ' . $testId)->fetch();
            check(!empty($res['ok']), 'settle on already-settled order returns ok (idempotent)');
            check($before === $after, 'no wallet movements on second settle');
            check(($row['status'] ?? '') === 'completed' && ($row['escrow_hold'] ?? '') === 'released_seller', 'order row unchanged');
        } finally {
            $db->prepare('DELETE FROM orders WHERE id = ?')->execute([$testId]);
        }
    }
}

echo "\nSummary: {$passes} / {$total} tests passed.\n";
exit($passes === $total ? 0 : 1);
