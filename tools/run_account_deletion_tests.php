<?php

declare(strict_types=1);

/**
 * Удаление аккаунта (AccountDeletionService) — локальная БД.
 *   php tools/run_account_deletion_tests.php
 * Создаёт временного пользователя/товар/заказы *@m02-test.local и удаляет их в конце.
 */

require __DIR__ . '/../bin/bootstrap.php';

use App\Core\Database;
use App\Services\Auth\AccountDeletionService;
use App\Services\Auth\AccountService;
use App\Services\MobileApi\TokenService;

$passes = 0;
$total = 0;
function check(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    $passes += $cond ? 1 : 0;
    echo ($cond ? ' [PASS] ' : ' [FAIL] ') . $desc . "\n";
}

echo "=== Account deletion tests ===\n";
$db = Database::connect();
$tag = bin2hex(random_bytes(3));
$email = "del{$tag}@m02-test.local";
$svc = new AccountDeletionService($db);
$uid = 0;
$productId = 0;

try {
    $reg = (new AccountService())->register(['name' => 'Удаляемый Тест', 'email' => $email, 'password' => 'DeleteMe123', 'phone' => '+77001234567', 'accept_offer' => true]);
    $uid = (int) $reg['user']['id'];
    $db->prepare('UPDATE users SET iin = ?, bio = ? WHERE id = ?')->execute(['900101300123', 'Личная информация', $uid]);
    $db->prepare("INSERT INTO products (user_id, type, title, description, price, status) VALUES (?, 'used', 'Тестовый товар', 'desc', 1000, 'active')")->execute([$uid]);
    $productId = (int) $db->lastInsertId();
    $db->prepare('INSERT IGNORE INTO favorites (user_id, product_id) VALUES (?, ?)')->execute([$uid, $productId]);
    $buyer = (int) $db->query("SELECT id FROM users WHERE email NOT LIKE '%@m02-test.local' ORDER BY id LIMIT 1")->fetchColumn();

    echo "\n-- blockers\n";
    $bonusOnly = $svc->blockers($uid);
    check($bonusOnly === [], 'fresh account (only bonus points) has no blockers');

    $db->prepare("INSERT INTO orders (product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold) VALUES (?, ?, ?, 1000, 'wallet', 'pickup', 'escrowed', 'holding')")
        ->execute([$productId, $buyer, $uid]);
    $openOrder = (int) $db->lastInsertId();
    $b = $svc->blockers($uid);
    check(($b[0]['code'] ?? '') === 'open_orders' && ($b[0]['count'] ?? 0) === 1, 'open deal as seller blocks deletion');
    $blocked = $svc->delete($uid, 'test');
    check(empty($blocked['ok']) && $blocked['error'] === 'deletion_blocked', 'delete() refuses while blocked');
    $db->prepare("UPDATE orders SET status = 'completed', escrow_hold = 'released_seller' WHERE id = ?")->execute([$openOrder]);

    (new \App\Models\Wallet())->deposit($uid, 500, 'test');
    $b = $svc->blockers($uid);
    check(($b[0]['code'] ?? '') === 'wallet_not_empty' && ($b[0]['amount'] ?? 0) === 500, 'money on wallet blocks deletion');
    $db->prepare('DELETE FROM wallet_transactions WHERE user_id = ?')->execute([$uid]);
    $db->prepare('DELETE FROM wallets WHERE user_id = ?')->execute([$uid]);
    check($svc->blockers($uid) === [], 'completed deal and empty wallet → no blockers');

    echo "\n-- delete (anonymize)\n";
    $tokens = (new TokenService($db))->issue($uid, ['device_id' => 'del-dev']);
    check($svc->hasPassword($uid), 'password account detected');
    $res = $svc->delete($uid, 'test');
    check(!empty($res['ok']), 'deletion succeeds');

    $row = $db->query("SELECT * FROM users WHERE id = {$uid}")->fetch(PDO::FETCH_ASSOC);
    check($row !== false, 'user row kept for integrity of orders');
    check($row['email'] !== $email && str_ends_with((string) $row['email'], '@deleted.invalid'), 'email anonymized');
    check($row['name'] === 'Удалённый пользователь', 'name anonymized');
    check($row['password'] === null && $row['phone'] === null && $row['iin'] === null && $row['bio'] === null, 'password, phone, IIN, bio erased');
    check(!empty($row['blocked_at']) && !empty($row['deleted_at']), 'account blocked and marked deleted');
    check(empty((new TokenService($db))->verifyAccess($tokens['access_token'])['ok']), 'mobile sessions revoked');
    check((string) $db->query("SELECT status FROM products WHERE id = {$productId}")->fetchColumn() === 'archived', 'listing archived (not visible in catalog)');
    check((int) $db->query("SELECT COUNT(*) FROM favorites WHERE user_id = {$uid}")->fetchColumn() === 0, 'favorites removed');
    check((int) $db->query("SELECT COUNT(*) FROM orders WHERE id = {$openOrder}")->fetchColumn() === 1, 'order history kept for the buyer');
    check((new \App\Models\User())->findByEmail($email) === null, 'old email is free to register again');
    check(($svc->delete($uid, 'test')['error'] ?? '') === 'not_found', 'second deletion is a no-op');
} finally {
    if ($uid > 0) {
        $db->exec("DELETE FROM orders WHERE seller_id = {$uid} OR buyer_id = {$uid}");
        $db->exec("DELETE FROM products WHERE user_id = {$uid}");
        $db->exec("DELETE FROM api_refresh_tokens WHERE user_id = {$uid}");
        $db->exec("DELETE FROM users WHERE id = {$uid}");
    }
    $db->exec("DELETE FROM users WHERE email LIKE '%@m02-test.local'");
}

echo "\nSummary: {$passes} / {$total} tests passed.\n";
exit($passes === $total ? 0 : 1);
