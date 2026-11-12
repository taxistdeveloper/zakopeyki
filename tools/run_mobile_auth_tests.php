<?php

declare(strict_types=1);

/**
 * Регистрация и внешние провайдеры (Apple/Google) для мобильного API.
 *   php tools/run_mobile_auth_tests.php
 * Подписывает тестовые ID-токены локально сгенерированным RSA-ключом (имитация JWKS Apple),
 * создаёт тестовых пользователей *@m02-test.local в локальной БД и удаляет их в конце.
 */

require __DIR__ . '/../bin/bootstrap.php';

use App\Core\Database;
use App\Services\Auth\AccountService;
use App\Services\Auth\IdTokenVerifier;

$passes = 0;
$total = 0;
function check(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    $passes += $cond ? 1 : 0;
    echo ($cond ? ' [PASS] ' : ' [FAIL] ') . $desc . "\n";
}
function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

echo "=== Mobile auth tests (register / Apple / Google) ===\n";

// ---------------------------------------------------------------- JWT fixtures
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$details = openssl_pkey_get_details($key);
$jwks = ['keys' => [[
    'kty' => 'RSA', 'kid' => 'test-kid', 'alg' => 'RS256', 'use' => 'sig',
    'n' => b64u($details['rsa']['n']), 'e' => b64u($details['rsa']['e']),
]]];
$now = 1_800_000_000;
$sign = static function (array $claims, array $header = ['alg' => 'RS256', 'kid' => 'test-kid']) use ($key): string {
    $input = b64u(json_encode($header)) . '.' . b64u(json_encode($claims));
    openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256);

    return $input . '.' . b64u($sig);
};
$verifier = new IdTokenVerifier(static fn () => $jwks, $now);
$aud = ['kz.zakopeyki.zakopeyki'];
$nonce = 'raw-nonce-1234567890';
$base = [
    'iss' => 'https://appleid.apple.com', 'aud' => 'kz.zakopeyki.zakopeyki', 'sub' => '001234.apple.sub',
    'email' => 'x@privaterelay.appleid.com', 'email_verified' => 'true',
    'iat' => $now - 10, 'exp' => $now + 600, 'nonce' => hash('sha256', $nonce),
];

echo "\n-- IdTokenVerifier\n";
$r = $verifier->verify($sign($base), IdTokenVerifier::APPLE, $aud, hash('sha256', $nonce));
check(!empty($r['ok']) && $r['claims']['sub'] === '001234.apple.sub', 'valid Apple token accepted');
check(IdTokenVerifier::emailVerified($r['claims'] ?? []), 'email_verified "true" string understood');
$cases = [
    'bad_audience' => [$sign(['aud' => 'com.other.app'] + $base), 'token for another app rejected'],
    'bad_issuer' => [$sign(['iss' => 'https://evil.example'] + $base), 'foreign issuer rejected'],
    'expired' => [$sign(['exp' => $now - 3600] + $base), 'expired token rejected'],
    'bad_nonce' => [$sign(['nonce' => hash('sha256', 'other')] + $base), 'nonce mismatch (replay) rejected'],
    'unknown_key' => [$sign($base, ['alg' => 'RS256', 'kid' => 'nope']), 'unknown key id rejected'],
    'unsupported_alg' => [b64u('{"alg":"none","kid":"test-kid"}') . '.' . b64u(json_encode($base)) . '.', 'alg=none rejected'],
];
foreach ($cases as $expected => [$jwt, $desc]) {
    $r = $verifier->verify($jwt, IdTokenVerifier::APPLE, $aud, hash('sha256', $nonce));
    check(($r['error'] ?? '') === $expected, $desc . " ({$expected})");
}
$good = $sign($base);
[$h, $p, $s] = explode('.', $good);
$forged = $h . '.' . b64u(json_encode(['sub' => 'attacker'] + $base)) . '.' . $s;
check(($verifier->verify($forged, IdTokenVerifier::APPLE, $aud, hash('sha256', $nonce))['error'] ?? '') === 'bad_signature', 'payload swapped under valid signature rejected');
$g = $verifier->verify($sign(['iss' => 'accounts.google.com', 'aud' => 'gid.apps.googleusercontent.com', 'nonce' => null] + $base), IdTokenVerifier::GOOGLE, ['gid.apps.googleusercontent.com']);
check(!empty($g['ok']), 'Google token accepted with Google issuer and client id');
check(($verifier->verify($good, IdTokenVerifier::APPLE, [], hash('sha256', $nonce))['error'] ?? '') === 'bad_audience', 'no configured audience → rejected');

// ---------------------------------------------------------------- AccountService (DB)
echo "\n-- AccountService (local DB)\n";
$db = Database::connect();
$tag = bin2hex(random_bytes(3));
$svc = new AccountService();
try {
    $e1 = "reg{$tag}@m02-test.local";
    check(($svc->register(['name' => 'A', 'email' => 'bad', 'password' => 'longenough', 'accept_offer' => true])['error'] ?? '') === 'invalid_email', 'invalid email rejected');
    check(($svc->register(['name' => 'A', 'email' => $e1, 'password' => 'longenough'])['error'] ?? '') === 'offer_required', 'offer acceptance required');
    check(($svc->register(['name' => 'A', 'email' => $e1, 'password' => 'short', 'accept_offer' => true])['error'] ?? '') === 'weak_password', 'short password rejected');
    $ok = $svc->register(['name' => 'Тест Регистрации', 'email' => $e1, 'password' => 'longenough1', 'accept_offer' => true]);
    check(!empty($ok['ok']) && password_verify('longenough1', (string) $ok['user']['password']), 'registration creates user with bcrypt password');
    check(($svc->register(['name' => 'A', 'email' => $e1, 'password' => 'longenough1', 'accept_offer' => true])['error'] ?? '') === 'email_taken', 'duplicate email rejected');

    $sub = "apple.{$tag}";
    $e2 = "apple{$tag}@m02-test.local";
    $a1 = $svc->resolveExternalIdentity('apple', $sub, $e2, true, 'Apple User');
    check(!empty($a1['ok']) && $a1['created'] === true && !empty($a1['user']['email_verified_at']), 'Apple: new verified account created');
    $a2 = $svc->resolveExternalIdentity('apple', $sub, null, false);
    check(!empty($a2['ok']) && $a2['created'] === false && (int) $a2['user']['id'] === (int) $a1['user']['id'], 'Apple: repeat sign-in without email finds same account by sub');
    $a3 = $svc->resolveExternalIdentity('apple', "other.{$tag}", $e1, true);
    check(!empty($a3['ok']) && (int) $a3['user']['id'] === (int) $ok['user']['id'], 'Apple: verified email links to existing password account');
    check(($svc->resolveExternalIdentity('apple', "new.{$tag}", null, true)['error'] ?? '') === 'email_missing', 'Apple: unknown sub without email → email_missing');
    check(($svc->resolveExternalIdentity('google', "g.{$tag}", "g{$tag}@m02-test.local", false)['error'] ?? '') === 'email_unverified', 'unverified provider email never creates/links account');
    $relay = $svc->resolveExternalIdentity('apple', "relay.{$tag}", "r{$tag}@privaterelay.appleid.com", true);
    check(!empty($relay['ok']) && $relay['user']['name'] !== '' && !str_contains((string) $relay['user']['name'], 'privaterelay'), 'Apple private relay email not used as display name');
} finally {
    $db->exec("DELETE FROM users WHERE email LIKE '%{$tag}@m02-test.local' OR email LIKE 'r{$tag}@privaterelay.appleid.com'");
}

echo "\nSummary: {$passes} / {$total} tests passed.\n";
exit($passes === $total ? 0 : 1);
