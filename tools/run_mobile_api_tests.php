<?php

declare(strict_types=1);

/**
 * M-02 мобильное API — тесты TokenService и Presenter (локальная БД).
 *   php tools/run_mobile_api_tests.php
 * Создаёт токены для несуществующего user_id и удаляет их в конце.
 */

require __DIR__ . '/../bin/bootstrap.php';

use App\Core\Database;
use App\Services\MobileApi\Presenter;
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

echo "=== M-02 Mobile API tests ===\n";
$db = Database::connect();
$uid = 900000000 + random_int(1, 99999);
$secret = bin2hex(random_bytes(32));
$svc = new TokenService($db, $secret);
$dev = ['device_id' => 'dev-A', 'device_name' => 'Test', 'platform' => 'ios', 'app_version' => '1.0.0'];

try {
    echo "\n-- access / refresh\n";
    $t = $svc->issue($uid, $dev);
    check(str_starts_with($t['access_token'], 'v1.') && strlen($t['refresh_token']) === 64, 'issue returns access + refresh');
    $row = $db->query("SELECT token_hash FROM api_refresh_tokens WHERE user_id = {$uid}")->fetchColumn();
    check($row === hash('sha256', $t['refresh_token']), 'refresh token stored only as sha256');
    $v = $svc->verifyAccess($t['access_token']);
    check(!empty($v['ok']) && $v['user_id'] === $uid, 'access token verifies');

    $other = new TokenService($db, bin2hex(random_bytes(32)));
    check(empty($other->verifyAccess($t['access_token'])['ok']), 'token signed with another secret rejected');
    $tampered = preg_replace('/\.([^.]+)\./', '.' . rtrim(strtr(base64_encode('{"typ":"access","uid":1,"fid":"x","exp":9999999999}'), '+/', '-_'), '=') . '.', $t['access_token']);
    check(empty($svc->verifyAccess((string) $tampered)['ok']), 'tampered payload rejected');

    $r = $svc->refresh($t['refresh_token'], ['device_id' => 'dev-A']);
    check(!empty($r['ok']) && $r['tokens']['refresh_token'] !== $t['refresh_token'], 'refresh rotates token');
    check(!empty($svc->verifyAccess($r['tokens']['access_token'])['ok']), 'new access valid');

    $reuse = $svc->refresh($t['refresh_token'], ['device_id' => 'dev-A']);
    check(empty($reuse['ok']), 'reuse of rotated refresh rejected');
    check(empty($svc->verifyAccess($r['tokens']['access_token'])['ok']), 'reuse detection revokes whole family');

    echo "\n-- device binding / logout\n";
    $t2 = $svc->issue($uid, $dev);
    check(empty($svc->refresh($t2['refresh_token'], ['device_id' => 'dev-B'])['ok']), 'refresh from another device rejected');
    $t3 = $svc->issue($uid, $dev);
    $svc->revokeByRefreshToken($t3['refresh_token']);
    check(empty($svc->verifyAccess($t3['access_token'])['ok']), 'logout revokes access immediately');
    $t4 = $svc->issue($uid, $dev);
    $t5 = $svc->issue($uid, ['device_id' => 'dev-C'] + $dev);
    $svc->revokeAllForUser($uid);
    check(empty($svc->verifyAccess($t4['access_token'])['ok']) && empty($svc->verifyAccess($t5['access_token'])['ok']), 'logout-all revokes every device');
    check(empty($svc->refresh('zz', [])['ok']), 'malformed refresh rejected');

    echo "\n-- 2FA challenge\n";
    $c = $svc->issueChallenge($uid, 'dev-A');
    check($svc->verifyChallenge($c, 'dev-A') === $uid, 'challenge valid on same device');
    check($svc->verifyChallenge($c, 'dev-B') === null, 'challenge bound to device');
    check(empty($svc->verifyAccess($c)['ok']), 'challenge cannot be used as access token');

    echo "\n-- presenter\n";
    check(Presenter::money(['type' => 'free', 'price' => 0]) === null, 'free item has no money');
    check(Presenter::money(['type' => 'new', 'price' => 12990]) === ['amount' => 12990, 'currency' => 'KZT'], 'money as integer tenge');
    check((string) Presenter::date('2026-11-10 08:00:00') === '2026-11-10T08:00:00+05:00', 'dates in ISO 8601 with Asia/Almaty offset');
    $me = Presenter::me(['id' => 1, 'iin' => '123456789012', 'password' => 'x', 'two_factor_secret' => 's']);
    check(!isset($me['iin']) && !isset($me['password']) && !isset($me['two_factor_secret']), 'profile never exposes IIN, password or 2FA secret');
} finally {
    $db->exec("DELETE FROM api_refresh_tokens WHERE user_id = {$uid}");
}

echo "\nSummary: {$passes} / {$total} tests passed.\n";
exit($passes === $total ? 0 : 1);
