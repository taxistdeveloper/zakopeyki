<?php

declare(strict_types=1);

/**
 * M-07: корзина в БД, оформление и отмена заказа, подсказки действий по сделке.
 *   php tools/run_mobile_commerce_tests.php
 * Использует локальную БД: временный покупатель *@m02-test.local, заказ на существующий товар
 * другого продавца; всё созданное удаляется.
 */

require __DIR__ . '/../bin/bootstrap.php';

use App\Controllers\Api\Mobile\OrderApiController;
use App\Core\Database;
use App\Models\Order;
use App\Services\Auth\AccountService;
use App\Services\Cart;
use App\Services\EscrowService;

$passes = 0;
$total = 0;
function check(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    $passes += $cond ? 1 : 0;
    echo ($cond ? ' [PASS] ' : ' [FAIL] ') . $desc . "\n";
}

echo "=== Mobile commerce tests (M-07) ===\n";
$db = Database::connect();
$tag = bin2hex(random_bytes(3));
$uid = 0;
$orderIds = [];

try {
    $uid = (int) (new AccountService())->register(['name' => 'Покупатель', 'email' => "buy{$tag}@m02-test.local", 'password' => 'Buyer12345', 'accept_offer' => true])['user']['id'];
    $product = $db->query("SELECT * FROM products WHERE status = 'active' AND type IN ('new','used') AND price > 0 AND quantity > 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException('Нужен активный товар с остатком > 1 в локальной БД');
    }
    $pid = (int) $product['id'];
    $stock0 = (int) $product['quantity'];

    echo "\n-- cart in DB\n";
    $_SESSION = ['cart' => [$pid => 1]];
    Cart::forUser($uid);
    check(Cart::map() === [], 'guest session cart is not merged for API users (explicit owner)');
    $_SESSION = [];
    $res = Cart::add($pid, 2);
    check(!empty($res['ok']) && Cart::qty($pid) === 2, 'add stores qty for the user');
    check((int) $db->query("SELECT qty FROM user_cart_items WHERE user_id = {$uid} AND product_id = {$pid}")->fetchColumn() === 2, 'cart persisted in user_cart_items (shared web + app)');
    Cart::forUser((int) $product['user_id']);
    check(empty(Cart::add($pid)['ok']), "seller can't add own product");
    Cart::forUser($uid);
    Cart::setQty($pid, 1);
    check(Cart::count() === 1 && count(Cart::items()) === 1, 'qty update and items() with seller data');
    Cart::clear();
    check(Cart::count() === 0, 'clear empties DB cart');

    echo "\n-- totals (server-side)\n";
    $amount = (int) $product['price'];
    check(EscrowService::buyerChargeTotal($amount) === $amount + EscrowService::arbitrationFee($amount), 'Safe Deal total = price + 3% fee');

    echo "\n-- DDL inside transaction (regression)\n";
    $db->beginTransaction();
    new \App\Models\Payment();
    check($db->inTransaction(), 'new Payment() inside a transaction no longer commits it implicitly');
    $db->rollBack();

    echo "\n-- order + cancel\n";
    $r = (new Order())->createEscrow($pid, $uid, 'card', 'pickup', 'escrow', 1, null);
    check(!empty($r['ok']), 'card checkout creates order');
    $oid = (int) $r['order_id'];
    $orderIds[] = $oid;
    $o = $db->query("SELECT * FROM orders WHERE id = {$oid}")->fetch(PDO::FETCH_ASSOC);
    check((int) $o['arbitration_fee'] === EscrowService::arbitrationFee($amount), 'arbitration fee stored on order');
    $c = (new EscrowService())->cancelByBuyer($oid, $uid);
    $after = $db->query("SELECT o.status, p.status AS pay FROM orders o LEFT JOIN payments p ON p.order_id = o.id WHERE o.id = {$oid}")->fetch(PDO::FETCH_ASSOC);
    check(!empty($c['ok']) && $after['status'] === 'cancelled' && $after['pay'] === 'cancelled', 'cancel marks order and pending payment cancelled');
    check((int) $db->query("SELECT quantity FROM products WHERE id = {$pid}")->fetchColumn() === $stock0, 'stock restored after cancel');

    echo "\n-- action hints\n";
    $ctl = (new ReflectionClass(OrderApiController::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod(OrderApiController::class, 'actions');
    $act = static fn (array $o, bool $buyer): array => $m->invoke($ctl, $o, $buyer);
    check($act(['status' => 'escrowed', 'deal_mode' => 'escrow'], false) === ['ship'], 'seller can ship a paid order');
    check($act(['status' => 'escrowed', 'deal_mode' => 'escrow'], true) === ['cancel'], 'buyer can cancel before shipping');
    check($act(['status' => 'shipped', 'deal_mode' => 'escrow'], true) === ['delivered', 'confirm', 'dispute'], 'buyer: received / confirm / dispute when shipped');
    check($act(['status' => 'delivered', 'deal_mode' => 'escrow', 'inspect_until' => date('Y-m-d H:i:s', time() + 3600)], true) === ['confirm', 'dispute'], 'dispute allowed inside 24h window');
    check($act(['status' => 'delivered', 'deal_mode' => 'escrow', 'inspect_until' => date('Y-m-d H:i:s', time() - 60)], true) === ['confirm'], 'no dispute after 24h window');
    check($act(['status' => 'completed', 'deal_mode' => 'direct'], true) === [], 'direct deal has no escrow actions');
} finally {
    foreach ($orderIds as $oid) {
        $db->exec("DELETE FROM payments WHERE order_id = {$oid}");
        $db->exec("DELETE FROM orders WHERE id = {$oid}");
    }
    if ($uid > 0) {
        $db->exec("DELETE FROM user_cart_items WHERE user_id = {$uid}");
    }
    $db->exec("DELETE FROM users WHERE email LIKE '%@m02-test.local'");
}

echo "\nSummary: {$passes} / {$total} tests passed.\n";
exit($passes === $total ? 0 : 1);
