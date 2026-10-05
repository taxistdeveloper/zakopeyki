<?php

declare(strict_types=1);

/**
 * Lightweight runner for CDEK API client layer tests (no Composer/phpunit binary required).
 * Usage: php tools/run_cdek_client_tests.php
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\Cdek\CdekApi;
use App\Services\Cdek\CdekApiResponse;
use App\Services\Cdek\CdekAuthService;
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

function base_cfg(?string $tokenCachePath = null): array
{
    return [
        'account' => 'test-account',
        'secure_password' => 'test-secret',
        'api_url' => 'https://example.test/v2',
        'test_mode' => 1,
        'timeout' => 5,
        'connect_timeout' => 3,
        'retry_max' => 2,
        'retry_backoff_ms' => 1,
        'http_log_enabled' => 0,
        'token_cache_path' => $tokenCachePath ?? (sys_get_temp_dir() . '/cdek_token_run_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.json'),
    ];
}

/** @var list<string> */
$cleanupTokenPaths = [];

function make_client(callable $transport): Client
{
    global $cleanupTokenPaths;
    $cfg = base_cfg();
    $cleanupTokenPaths[] = $cfg['token_cache_path'];
    $auth = new CdekAuthService($cfg, null, static function () {
        return [200, json_encode([
            'access_token' => 'tok-live',
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => 'api',
            'jti' => 'j',
        ])];
    });
    return new Client($cfg, $auth, new CdekErrorMapper(), new CdekRequestLogger(['http_log_enabled' => 0]), $transport);
}

// 1. OAuth token fetch
$authCfg1 = base_cfg();
$cleanupTokenPaths[] = $authCfg1['token_cache_path'];
$authCalls = 0;
$auth = new CdekAuthService($authCfg1, null, function () use (&$authCalls) {
    $authCalls++;
    return [200, json_encode([
        'access_token' => 'tok-1',
        'token_type' => 'bearer',
        'expires_in' => 3600,
        'scope' => 'api',
        'jti' => 'j1',
    ])];
});
$r = $auth->getAccessToken(true);
assert_true(($r['ok'] ?? false) && ($r['token'] ?? '') === 'tok-1' && $authCalls === 1, 'OAuth token fetch');

// 2. Cached token reuse
$authCfg2 = base_cfg();
$cleanupTokenPaths[] = $authCfg2['token_cache_path'];
$authCalls = 0;
$auth = new CdekAuthService($authCfg2, null, function () use (&$authCalls) {
    $authCalls++;
    return [200, json_encode([
        'access_token' => 'tok-cached',
        'token_type' => 'bearer',
        'expires_in' => 3600,
        'scope' => 'api',
        'jti' => 'j1',
    ])];
});
$auth->getAccessToken(true);
$b = $auth->getAccessToken(false);
assert_true(($b['ok'] ?? false) && ($b['token'] ?? '') === 'tok-cached' && ($b['source'] ?? '') === 'memory' && $authCalls === 1, 'Cached token reused');

// 3. Expired / force refresh
$authCfg3 = base_cfg();
$cleanupTokenPaths[] = $authCfg3['token_cache_path'];
$authCalls = 0;
$auth = new CdekAuthService($authCfg3, null, function () use (&$authCalls) {
    $authCalls++;
    return [200, json_encode([
        'access_token' => 'tok-' . $authCalls,
        'token_type' => 'bearer',
        'expires_in' => 3600,
        'scope' => 'api',
        'jti' => 'j' . $authCalls,
    ])];
});
$auth->getAccessToken(true);
$second = $auth->getAccessToken(true);
assert_true(($second['token'] ?? '') === 'tok-2' && $authCalls === 2, 'Expired/forced token refreshed');

// 4. Authorization header
$seenAuth = null;
$client = make_client(function ($method, $url, $body, $headers) use (&$seenAuth) {
    foreach ($headers as $h) {
        if (stripos($h, 'Authorization:') === 0) {
            $seenAuth = $h;
        }
    }
    return [200, json_encode(['ok' => true])];
});
$res = $client->get('/location/cities', ['city' => 'Алматы']);
assert_true(
    ($res['ok'] ?? false)
    && is_string($seenAuth)
    && str_contains($seenAuth, 'Bearer tok-live')
    && !str_contains($seenAuth, 'test-secret'),
    'Authorization Bearer header sent'
);

// 5. Successful GET
$client = make_client(static fn () => [200, json_encode([['code' => 4756, 'city' => 'Алматы', 'country_code' => 'KZ']])]);
$res = $client->get('/location/cities', ['city' => 'Алматы']);
assert_true(($res['ok'] ?? false) && ($res['code'] ?? 0) === 200 && ($res['internal_status'] ?? '') === CdekApiResponse::STATUS_SUCCESSFUL, 'Successful GET');

// 6. HTTP 202
$client = make_client(static fn () => [202, json_encode([
    'entity' => ['uuid' => 'order-uuid-1'],
    'requests' => [['request_uuid' => 'req-1', 'type' => 'CREATE', 'state' => 'ACCEPTED', 'date_time' => 'x']],
])]);
$api = $client->call('POST', '/orders', ['tariff_code' => 136], null, [], ['allow_retry' => false]);
assert_true(
    $api->ok
    && $api->httpStatus === 202
    && $api->internalStatus === CdekApiResponse::STATUS_ACCEPTED
    && $api->isAcceptedPending()
    && !$api->isFinalSuccess()
    && $api->cdekUuid === 'order-uuid-1',
    'HTTP 202 accepted ≠ final success'
);

// 7. HTTP 400 validation/business
$client = make_client(static fn () => [400, json_encode([
    'errors' => [['code' => 'v2_tariff_not_found', 'message' => 'no tariff']],
])]);
$res = $client->post('/calculator/tariff', ['tariff_code' => 1], [], ['allow_retry' => false]);
assert_true(
    !($res['ok'] ?? true)
    && ($res['error_mapped']['internal_code'] ?? '') === CdekErrorMapper::INTERNAL_TARIFF
    && ($res['error_mapped']['error_type'] ?? '') === 'business',
    'HTTP 400 validation/business mapped'
);

// 8. HTTP 401
$client = make_client(static fn () => [401, json_encode(['error' => 'unauthorized'])]);
$res = $client->request('GET', '/orders', null, null, [], ['allow_retry' => false]);
assert_true(
    !($res['ok'] ?? true)
    && ($res['error_mapped']['internal_code'] ?? '') === CdekErrorMapper::INTERNAL_AUTH
    && ($res['error_mapped']['error_type'] ?? '') === 'authentication',
    'HTTP 401 authentication mapped'
);

// 9. HTTP 429
$client = make_client(static fn () => [429, json_encode(['message' => 'too many requests'])]);
$res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
assert_true(
    !($res['ok'] ?? true)
    && ($res['code'] ?? 0) === 429
    && ($res['error_mapped']['error_type'] ?? '') === 'rate_limit',
    'HTTP 429 rate limit mapped'
);

// 10. HTTP 500
$client = make_client(static fn () => [500, json_encode(['message' => 'boom'])]);
$res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
assert_true(
    !($res['ok'] ?? true)
    && ($res['error_mapped']['internal_code'] ?? '') === CdekErrorMapper::INTERNAL_SERVER
    && ($res['error_mapped']['error_type'] ?? '') === 'server',
    'HTTP 500 server error mapped'
);

// 11. Timeout
$client = make_client(static fn () => [0, '', 'Operation timed out after 5000 milliseconds']);
$res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
assert_true(
    !($res['ok'] ?? true)
    && ($res['error_mapped']['internal_code'] ?? '') === CdekErrorMapper::INTERNAL_TIMEOUT
    && ($res['error_mapped']['error_type'] ?? '') === 'timeout',
    'Timeout mapped'
);

// 12. Network error
$client = make_client(static fn () => [0, '', 'Could not resolve host']);
$res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
assert_true(
    !($res['ok'] ?? true)
    && ($res['error_mapped']['internal_code'] ?? '') === CdekErrorMapper::INTERNAL_NETWORK
    && ($res['error_mapped']['error_type'] ?? '') === 'network',
    'Network error mapped'
);

// 13. Duplicate IM / order number
$mapper = new CdekErrorMapper();
$mapped = $mapper->map([
    'requests' => [[
        'errors' => [['code' => 'v2_similar_order_exists', 'message' => 'Order with same number exists']],
    ]],
], 400);
$dupApi = CdekApiResponse::fromClientResult([
    'ok' => false,
    'code' => 400,
    'data' => [
        'requests' => [['errors' => [['code' => 'v2_similar_order_exists', 'message' => 'dup']]]],
    ],
    'error_mapped' => $mapped,
    'request_id' => 'r',
    'duration_ms' => 1,
], $mapper);
assert_true(
    ($mapped['internal_code'] ?? '') === CdekErrorMapper::INTERNAL_DUPLICATE
    && ($mapped['error_type'] ?? '') === 'duplicate_order'
    && $dupApi->isDuplicateOrder(),
    'Duplicate IM/order-number error'
);

// 14. No unsafe auto-retry on order create
$calls = 0;
$client = make_client(function () use (&$calls) {
    $calls++;
    return [500, json_encode(['message' => 'temp'])];
});
$res = $client->post('/orders', [
    'tariff_code' => 136,
    'recipient' => ['name' => 'X'],
    'packages' => [['number' => '1', 'weight' => 1000]],
]);
assert_true(!($res['ok'] ?? true) && $calls === 1, 'Order create does not auto-retry on 5xx');

// Bonus: calculator may retry
$calls = 0;
$client = make_client(function () use (&$calls) {
    $calls++;
    if ($calls === 1) {
        return [500, json_encode(['message' => 'temp'])];
    }
    return [200, json_encode(['tariff_codes' => []])];
});
$res = $client->post('/calculator/tarifflist', [
    'from_location' => ['code' => 1],
    'to_location' => ['code' => 2],
    'packages' => [['weight' => 1000]],
]);
assert_true(($res['ok'] ?? false) && $calls >= 2, 'Calculator POST may retry on 5xx');

// Bonus: facade + GAP
$calls = 0;
$client = make_client(function () use (&$calls) {
    $calls++;
    return [202, json_encode([
        'entity' => ['uuid' => 'u-9'],
        'requests' => [['request_uuid' => 'r9', 'type' => 'CREATE', 'state' => 'ACCEPTED', 'date_time' => 'x']],
    ])];
});
$facade = new CdekApi($client);
$resp = $facade->createOrder([
    'tariff_code' => 136,
    'recipient' => ['name' => 'B'],
    'packages' => [['number' => '1', 'weight' => 500]],
]);
assert_true($resp->isAcceptedPending() && $calls === 1 && $resp->cdekUuid === 'u-9', 'CdekApi createOrder no-retry + 202');

$gap = (new CdekApi(make_client(static fn () => [200, '{}'])))->deliveryPointsByPolygons([]);
assert_true(!$gap->ok && str_contains((string) $gap->message, 'GAP'), 'deliveryPointsByPolygons GAP');

foreach ($cleanupTokenPaths as $tokenPath) {
    if (is_string($tokenPath) && is_file($tokenPath)) {
        @unlink($tokenPath);
    }
}

echo "\nPassed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
