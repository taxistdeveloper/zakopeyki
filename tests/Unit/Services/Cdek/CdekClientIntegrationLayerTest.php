<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\CdekApi;
use App\Services\Cdek\CdekApiResponse;
use App\Services\Cdek\CdekAuthService;
use App\Services\Cdek\CdekErrorMapper;
use App\Services\Cdek\CdekRequestLogger;
use App\Services\Cdek\Client;
use PHPUnit\Framework\TestCase;

final class CdekClientIntegrationLayerTest extends TestCase
{
    /** @var list<string> */
    private array $tokenCachePaths = [];

    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        $path = sys_get_temp_dir() . '/cdek_token_test_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.json';
        $this->tokenCachePaths[] = $path;
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
            'token_cache_path' => $path,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->tokenCachePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tokenCachePaths = [];
        parent::tearDown();
    }

    public function testOauthTokenFetch(): void
    {
        $authCalls = 0;
        $auth = new CdekAuthService($this->baseConfig(), null, function () use (&$authCalls) {
            $authCalls++;
            return [200, json_encode([
                'access_token' => 'tok-1',
                'token_type' => 'bearer',
                'expires_in' => 3600,
                'scope' => 'api',
                'jti' => 'j1',
            ])];
        });

        $result = $auth->getAccessToken(true);
        $this->assertTrue($result['ok']);
        $this->assertSame('tok-1', $result['token']);
        $this->assertSame(1, $authCalls);
    }

    public function testCachedTokenReused(): void
    {
        $authCalls = 0;
        $auth = new CdekAuthService($this->baseConfig(), null, function () use (&$authCalls) {
            $authCalls++;
            return [200, json_encode([
                'access_token' => 'tok-cached',
                'token_type' => 'bearer',
                'expires_in' => 3600,
                'scope' => 'api',
                'jti' => 'j1',
            ])];
        });

        $a = $auth->getAccessToken(true);
        $b = $auth->getAccessToken(false);
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertSame('tok-cached', $b['token']);
        $this->assertSame('memory', $b['source']);
        $this->assertSame(1, $authCalls);
    }

    public function testExpiredTokenRefreshed(): void
    {
        $authCalls = 0;
        $auth = new CdekAuthService($this->baseConfig(), null, function () use (&$authCalls) {
            $authCalls++;
            return [200, json_encode([
                'access_token' => 'tok-' . $authCalls,
                'token_type' => 'bearer',
                'expires_in' => 3600,
                'scope' => 'api',
                'jti' => 'j' . $authCalls,
            ])];
        });

        $first = $auth->getAccessToken(true);
        $this->assertSame('tok-1', $first['token']);

        $second = $auth->getAccessToken(true);
        $this->assertSame('tok-2', $second['token']);
        $this->assertSame(2, $authCalls);
    }

    public function testAuthorizationHeaderSent(): void
    {
        $seenAuth = null;
        $client = $this->makeClient(
            function (string $method, string $url, ?string $body, array $headers) use (&$seenAuth) {
                foreach ($headers as $h) {
                    if (stripos($h, 'Authorization:') === 0) {
                        $seenAuth = $h;
                    }
                }
                return [200, json_encode(['tariff_codes' => []])];
            }
        );

        $res = $client->get('/location/cities', ['city' => 'Алматы']);
        $this->assertTrue($res['ok']);
        $this->assertNotNull($seenAuth);
        $this->assertStringContainsString('Bearer tok-live', (string) $seenAuth);
        $this->assertStringNotContainsString('test-secret', (string) $seenAuth);
    }

    public function testSuccessfulGet(): void
    {
        $client = $this->makeClient(function () {
            return [200, json_encode([['code' => 4756, 'city' => 'Алматы', 'country_code' => 'KZ']])];
        });
        $res = $client->get('/location/cities', ['city' => 'Алматы']);
        $this->assertTrue($res['ok']);
        $this->assertSame(200, $res['code']);
        $this->assertSame(CdekApiResponse::STATUS_SUCCESSFUL, $res['internal_status']);
    }

    public function testHttp202AcceptedNotFinalSuccess(): void
    {
        $client = $this->makeClient(function () {
            return [202, json_encode([
                'entity' => ['uuid' => 'order-uuid-1'],
                'requests' => [[
                    'request_uuid' => 'req-1',
                    'type' => 'CREATE',
                    'state' => 'ACCEPTED',
                    'date_time' => '2026-01-01T00:00:00+0000',
                ]],
            ])];
        });

        $api = $client->call('POST', '/orders', ['tariff_code' => 136, 'recipient' => ['name' => 'A'], 'packages' => []], null, [], [
            'allow_retry' => false,
        ]);

        $this->assertTrue($api->ok);
        $this->assertSame(202, $api->httpStatus);
        $this->assertSame(CdekApiResponse::STATUS_ACCEPTED, $api->internalStatus);
        $this->assertTrue($api->isAcceptedPending());
        $this->assertFalse($api->isFinalSuccess());
        $this->assertSame('order-uuid-1', $api->cdekUuid);
    }

    public function testHttp400ValidationError(): void
    {
        $client = $this->makeClient(function () {
            return [400, json_encode([
                'errors' => [['code' => 'v2_tariff_not_found', 'message' => 'no tariff']],
            ])];
        });
        $res = $client->post('/calculator/tariff', ['tariff_code' => 1], [], ['allow_retry' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame(400, $res['code']);
        $this->assertSame(CdekErrorMapper::INTERNAL_TARIFF, $res['error_mapped']['internal_code']);
        $this->assertSame('business', $res['error_mapped']['error_type']);
    }

    public function testHttp401MappedAsAuth(): void
    {
        // First call: 401; Client refreshes token and retries once → still 401.
        $client = $this->makeClient(function () {
            return [401, json_encode(['error' => 'unauthorized'])];
        });
        $res = $client->request('GET', '/orders', null, null, [], ['allow_retry' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_AUTH, $res['error_mapped']['internal_code']);
        $this->assertSame('authentication', $res['error_mapped']['error_type']);
    }

    public function testHttp429RateLimit(): void
    {
        $client = $this->makeClient(function () {
            return [429, json_encode(['message' => 'too many requests'])];
        });
        $res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame(429, $res['code']);
        $this->assertSame(CdekErrorMapper::INTERNAL_RATE_LIMIT, $res['error_mapped']['internal_code']);
        $this->assertSame('rate_limit', $res['error_mapped']['error_type']);
    }

    public function testHttp500ServerError(): void
    {
        $client = $this->makeClient(function () {
            return [500, json_encode(['message' => 'boom'])];
        });
        $res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_SERVER, $res['error_mapped']['internal_code']);
        $this->assertSame('server', $res['error_mapped']['error_type']);
    }

    public function testTimeoutMapped(): void
    {
        $client = $this->makeClient(function () {
            return [0, '', 'Operation timed out after 5000 milliseconds'];
        });

        $res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_TIMEOUT, $res['error_mapped']['internal_code']);
        $this->assertSame('timeout', $res['error_mapped']['error_type']);
    }

    public function testNetworkErrorMapped(): void
    {
        $client = $this->makeClient(function () {
            return [0, '', 'Could not resolve host'];
        });

        $res = $client->request('GET', '/location/cities', null, null, [], ['allow_retry' => false]);
        $this->assertFalse($res['ok']);
        $this->assertSame(CdekErrorMapper::INTERNAL_NETWORK, $res['error_mapped']['internal_code']);
        $this->assertSame('network', $res['error_mapped']['error_type']);
    }

    public function testDuplicateImOrderNumberError(): void
    {
        $mapper = new CdekErrorMapper();
        $mapped = $mapper->map([
            'requests' => [[
                'errors' => [[
                    'code' => 'v2_similar_order_exists',
                    'message' => 'Order with same number exists',
                ]],
            ]],
        ], 400);

        $this->assertSame(CdekErrorMapper::INTERNAL_DUPLICATE, $mapped['internal_code']);
        $this->assertSame('duplicate_order', $mapped['error_type']);

        $api = CdekApiResponse::fromClientResult([
            'ok' => false,
            'code' => 400,
            'data' => [
                'requests' => [[
                    'errors' => [['code' => 'v2_similar_order_exists', 'message' => 'dup']],
                ]],
            ],
            'error_mapped' => $mapped,
            'request_id' => 'r',
            'duration_ms' => 1,
        ], $mapper);
        $this->assertTrue($api->isDuplicateOrder());
    }

    public function testOrderCreateDoesNotAutoRetryOn500(): void
    {
        $calls = 0;
        $client = $this->makeClient(function () use (&$calls) {
            $calls++;
            return [500, json_encode(['message' => 'temp'])];
        });

        $res = $client->post('/orders', [
            'tariff_code' => 136,
            'recipient' => ['name' => 'X'],
            'packages' => [['number' => '1', 'weight' => 1000]],
        ]);

        $this->assertFalse($res['ok']);
        $this->assertSame(1, $calls, 'POST /orders must not auto-retry on 5xx');
    }

    public function testCalculatorPostMayRetryOn500(): void
    {
        $calls = 0;
        $client = $this->makeClient(function () use (&$calls) {
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

        $this->assertTrue($res['ok']);
        $this->assertGreaterThanOrEqual(2, $calls);
    }

    public function testCdekApiFacadeCreateOrderUsesNoRetry(): void
    {
        $calls = 0;
        $client = $this->makeClient(function () use (&$calls) {
            $calls++;
            return [202, json_encode([
                'entity' => ['uuid' => 'u-9'],
                'requests' => [['request_uuid' => 'r9', 'type' => 'CREATE', 'state' => 'ACCEPTED', 'date_time' => 'x']],
            ])];
        });

        $api = new CdekApi($client);
        $resp = $api->createOrder([
            'tariff_code' => 136,
            'recipient' => ['name' => 'B'],
            'packages' => [['number' => '1', 'weight' => 500]],
        ]);

        $this->assertTrue($resp->isAcceptedPending());
        $this->assertSame(1, $calls);
        $this->assertSame('u-9', $resp->cdekUuid);
    }

    public function testDeliveryPointsByPolygonsMarkedAsGap(): void
    {
        $api = new CdekApi($this->makeClient(function () {
            return [200, '{}'];
        }));
        $resp = $api->deliveryPointsByPolygons([]);
        $this->assertFalse($resp->ok);
        $this->assertStringContainsString('GAP', (string) $resp->message);
    }

    /**
     * @param callable(string,string,?string,array):(?array{0:int,1:string,2?:string}) $transport
     */
    private function makeClient(callable $transport): Client
    {
        $cfg = $this->baseConfig();
        $auth = new CdekAuthService($cfg, null, function () {
            return [200, json_encode([
                'access_token' => 'tok-live',
                'token_type' => 'bearer',
                'expires_in' => 3600,
                'scope' => 'api',
                'jti' => 'j',
            ])];
        });

        return new Client(
            $cfg,
            $auth,
            new CdekErrorMapper(),
            new CdekRequestLogger(['http_log_enabled' => 0]),
            $transport
        );
    }
}
