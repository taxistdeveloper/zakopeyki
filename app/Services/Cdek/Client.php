<?php

namespace App\Services\Cdek;

/**
 * HTTP-клиент СДЭК API v2 — единая точка всех CDEK HTTP-запросов.
 *
 * Только транспортный слой:
 * - HTTP (curl);
 * - Authorization через CdekAuthService;
 * - timeout / retry (только для безопасных idempotent запросов);
 * - один повтор при 401 (refresh token);
 * - JSON encode/decode UTF-8;
 * - correlation / request ID;
 * - маппинг ошибок через CdekErrorMapper;
 * - техлог без PII/секретов.
 *
 * НЕ содержит marketplace-логики (quotes, payment, FSM).
 * HTTP 202 трактуется как accepted/processing через CdekApiResponse — не как final SUCCESS.
 *
 * @see https://apidoc.cdek.ru/#tag/common/Vvedenie
 * @see openapi_api_v2_integration.json
 */
class Client
{
    private array $config;
    private CdekAuthService $auth;
    private CdekErrorMapper $errorMapper;
    private CdekRequestLogger $logger;
    private ?string $lastError = null;
    private ?string $lastCurlError = null;
    private ?string $lastRequestId = null;

    /** @var callable|null fn(string $method, string $url, ?string $body, array $headers): ?array{0:int,1:string,2?:string} */
    private $httpTransport;

    /**
     * @param array<string, mixed>|null $config
     * @param callable|null $httpTransport test double for curl
     */
    public function __construct(
        ?array $config = null,
        ?CdekAuthService $auth = null,
        ?CdekErrorMapper $errorMapper = null,
        ?CdekRequestLogger $logger = null,
        ?callable $httpTransport = null
    ) {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $path = dirname(__DIR__, 3) . '/config/cdek.php';
            $this->config = is_file($path) ? (require $path) : [];
        }

        $this->auth = $auth ?? CdekAuthService::fromConfig($this->config);
        $this->errorMapper = $errorMapper ?? new CdekErrorMapper();
        $this->logger = $logger ?? new CdekRequestLogger($this->config);
        $this->httpTransport = $httpTransport;
    }

    public function isConfigured(): bool
    {
        return $this->auth->isConfigured();
    }

    public function config(): array
    {
        return $this->config;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastCurlError(): ?string
    {
        return $this->lastCurlError;
    }

    public function lastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    public function isTestMode(): bool
    {
        return $this->auth->isTestMode();
    }

    public function auth(): CdekAuthService
    {
        return $this->auth;
    }

    public function errorMapper(): CdekErrorMapper
    {
        return $this->errorMapper;
    }

    /**
     * @return array{ok: bool, token?: string, error?: string, source?: string}
     */
    public function authorize(bool $force = false): array
    {
        $result = $this->auth->getAccessToken($force);
        if (!$result['ok']) {
            $this->lastError = $result['error'] ?? 'auth failed';
            $this->lastCurlError = $this->auth->lastCurlError();
        }
        return $result;
    }

    /**
     * Типизированный вызов → CdekApiResponse (рекомендуемый API для новых сервисов).
     *
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, scalar|null>|null $query
     * @param list<string> $extraHeaders
     * @param array{idempotent?: bool, allow_retry?: bool} $options
     */
    public function call(
        string $method,
        string $path,
        ?array $jsonBody = null,
        ?array $query = null,
        array $extraHeaders = [],
        array $options = []
    ): CdekApiResponse {
        $raw = $this->request($method, $path, $jsonBody, $query, $extraHeaders, $options);
        return CdekApiResponse::fromClientResult($raw, $this->errorMapper);
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, scalar|null>|null $query
     * @param list<string> $extraHeaders raw header lines (без Authorization)
     * @param array{idempotent?: bool, allow_retry?: bool} $options
     * @return array{
     *   ok: bool,
     *   code: int,
     *   data?: mixed,
     *   error?: string,
     *   error_mapped?: array,
     *   body?: string,
     *   request_id: string,
     *   duration_ms: int,
     *   async?: bool,
     *   internal_status?: string
     * }
     */
    public function request(
        string $method,
        string $path,
        ?array $jsonBody = null,
        ?array $query = null,
        array $extraHeaders = [],
        array $options = []
    ): array {
        $this->lastError = null;
        $requestId = bin2hex(random_bytes(8));
        $this->lastRequestId = $requestId;
        $started = hrtime(true);
        $method = strtoupper($method);

        $auth = $this->authorize();
        if (!$auth['ok']) {
            $mapped = $this->errorMapper->mapLocal(
                CdekErrorMapper::INTERNAL_AUTH,
                (string) ($auth['error'] ?? 'auth failed')
            );
            $this->logger->log([
                'method' => $method,
                'path' => $path,
                'http_status' => 0,
                'duration_ms' => $this->elapsedMs($started),
                'request_id' => $requestId,
                'error_type' => $mapped['error_type'] ?? 'authentication',
            ]);
            return [
                'ok' => false,
                'code' => 0,
                'error' => $mapped['message'],
                'error_mapped' => $mapped,
                'request_id' => $requestId,
                'duration_ms' => $this->elapsedMs($started),
                'internal_status' => CdekApiResponse::STATUS_ERROR,
            ];
        }

        $url = $this->buildUrl($path, $query);
        $payload = null;
        $headers = $this->buildHeaders((string) $auth['token'], $requestId, $extraHeaders, $jsonBody !== null);
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }

        $idempotent = array_key_exists('idempotent', $options)
            ? (bool) $options['idempotent']
            : $this->isIdempotentMethod($method, $path);
        $allowRetry = array_key_exists('allow_retry', $options)
            ? (bool) $options['allow_retry']
            : $idempotent;

        // Создание заказа: retry запрещён (кроме 401 token refresh ниже).
        if ($this->isOrderCreate($method, $path)) {
            $allowRetry = false;
            $idempotent = false;
        }

        $maxAttempts = $allowRetry ? max(1, (int) ($this->config['retry_max'] ?? 2)) : 1;
        $attempt = 0;
        $code = 0;
        $body = '';
        $curlKind = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $http = $this->rawRequest($method, $url, $payload, $headers, true);
            if ($http === null) {
                $curlKind = $this->classifyCurlFailure($this->lastCurlError);
                if ($allowRetry && $attempt < $maxAttempts && in_array($curlKind, ['timeout', 'network'], true)) {
                    $this->sleepRetry($attempt);
                    continue;
                }
                $mapped = $curlKind === 'timeout'
                    ? $this->errorMapper->mapNetwork($this->lastCurlError, true)
                    : $this->errorMapper->mapNetwork($this->lastCurlError, false);
                $this->lastError = $mapped['message'];
                $this->logger->log([
                    'method' => $method,
                    'path' => $path,
                    'http_status' => 0,
                    'duration_ms' => $this->elapsedMs($started),
                    'request_id' => $requestId,
                    'error_type' => $mapped['error_type'] ?? $curlKind,
                    'retry' => $attempt - 1,
                ]);
                return [
                    'ok' => false,
                    'code' => 0,
                    'error' => $this->lastError,
                    'error_mapped' => $mapped,
                    'request_id' => $requestId,
                    'duration_ms' => $this->elapsedMs($started),
                    'internal_status' => CdekApiResponse::STATUS_ERROR,
                ];
            }

            [$code, $body] = $http;

            // Один повтор при протухшем токене (не считается unsafe retry create).
            if ($code === 401 && $attempt === 1) {
                $auth = $this->authorize(true);
                if ($auth['ok']) {
                    $headers = $this->buildHeaders((string) $auth['token'], $requestId, $extraHeaders, $jsonBody !== null);
                    $http = $this->rawRequest($method, $url, $payload, $headers, true);
                    if ($http !== null) {
                        [$code, $body] = $http;
                    }
                }
            }

            // Safe retry только для GET/идемпотентных на 429/5xx.
            if ($allowRetry && $attempt < $maxAttempts && ($code === 429 || $code >= 500)) {
                $this->sleepRetry($attempt);
                continue;
            }
            break;
        }

        $data = json_decode($body, true);
        $result = $this->finalizeResult($method, $path, $code, $body, is_array($data) ? $data : null, $requestId, $started, $attempt - 1);
        return $result;
    }

    /**
     * @return array{ok: bool, code: int, data?: mixed, error?: string, request_id?: string, duration_ms?: int, async?: bool, internal_status?: string}
     */
    public function get(string $path, ?array $query = null): array
    {
        return $this->request('GET', $path, null, $query, [], ['idempotent' => true, 'allow_retry' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $extraHeaders
     * @param array{idempotent?: bool, allow_retry?: bool} $options
     * @return array{ok: bool, code: int, data?: mixed, error?: string, request_id?: string, duration_ms?: int, async?: bool, internal_status?: string}
     */
    public function post(string $path, array $body, array $extraHeaders = [], array $options = []): array
    {
        return $this->request('POST', $path, $body, null, $extraHeaders, $options);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $extraHeaders
     * @return array{ok: bool, code: int, data?: mixed, error?: string, request_id?: string, duration_ms?: int, async?: bool, internal_status?: string}
     */
    public function patch(string $path, array $body, array $extraHeaders = []): array
    {
        // PATCH заказа не ретраим автоматически.
        return $this->request('PATCH', $path, $body, null, $extraHeaders, [
            'idempotent' => false,
            'allow_retry' => false,
        ]);
    }

    /**
     * @return array{ok: bool, code: int, data?: mixed, error?: string, request_id?: string, duration_ms?: int, async?: bool, internal_status?: string}
     */
    public function delete(string $path, ?array $query = null): array
    {
        return $this->request('DELETE', $path, null, $query, [], [
            'idempotent' => false,
            'allow_retry' => false,
        ]);
    }

    /**
     * Поиск кода города СДЭК по названию.
     * @return array{code: int, city: string, country_code: string}|null
     */
    public function findCityCode(string $city, ?string $countryCode = null): ?array
    {
        $city = trim($city);
        if ($city === '') {
            return null;
        }

        $country = strtoupper(trim((string) ($countryCode ?: ($this->config['default_country'] ?? 'KZ'))));
        $aliases = $this->cityAliases($city);

        foreach ($aliases as $candidate) {
            $found = $this->lookupCityOnce($candidate, $country);
            if ($found !== null) {
                return $found;
            }
        }

        $known = $this->knownCityCodes();
        $key = mb_strtolower($city);
        if (isset($known[$key])) {
            $byCode = $this->lookupCityByCode($known[$key]);
            if ($byCode !== null) {
                return $byCode;
            }
            return [
                'code' => $known[$key],
                'city' => $city,
                'country_code' => $country,
            ];
        }

        return null;
    }

    public function developerKeyHeader(?string $key = null): array
    {
        $key = trim((string) ($key ?? ($this->config['developer_key'] ?? '')));
        if ($key === '') {
            return [];
        }
        return ['developer-key: ' . $key];
    }

    /** @return list<string> */
    private function cityAliases(string $city): array
    {
        $key = mb_strtolower(trim($city));
        $map = [
            'астана' => ['Астана (Нур-Султан)', 'Астана', 'Нур-Султан', 'Нурсултан', 'Astana'],
            'нур-султан' => ['Астана (Нур-Султан)', 'Нур-Султан', 'Астана', 'Нурсултан'],
            'нурсултан' => ['Астана (Нур-Султан)', 'Нурсултан', 'Нур-Султан', 'Астана'],
            'алматы' => ['Алматы', 'Алма-Ата', 'Almaty'],
            'алма-ата' => ['Алматы', 'Алма-Ата'],
            'шымкент' => ['Шымкент', 'Чимкент'],
            'чимкент' => ['Шымкент', 'Чимкент'],
        ];

        $list = $map[$key] ?? [$city];
        if (!in_array($city, $list, true)) {
            array_unshift($list, $city);
        }
        return array_values(array_unique($list));
    }

    /** @return array<string, int> */
    private function knownCityCodes(): array
    {
        return [
            'астана' => 4961,
            'нур-султан' => 4961,
            'нурсултан' => 4961,
            'астана (нур-султан)' => 4961,
            'алматы' => 4756,
            'алма-ата' => 4756,
            'шымкент' => 12787,
            'чимкент' => 12787,
            'караганда' => 7669,
        ];
    }

    /** @return array{code: int, city: string, country_code: string}|null */
    private function lookupCityByCode(int $code): ?array
    {
        $res = $this->get('/location/cities', ['code' => $code]);
        if (!$res['ok'] || !is_array($res['data'] ?? null)) {
            return null;
        }
        $list = $res['data'];
        $row = isset($list['code']) ? $list : ($list[0] ?? null);
        if (!is_array($row) || empty($row['code'])) {
            return null;
        }
        return [
            'code' => (int) $row['code'],
            'city' => (string) ($row['city'] ?? ''),
            'country_code' => (string) ($row['country_code'] ?? ''),
        ];
    }

    /** @return array{code: int, city: string, country_code: string}|null */
    private function lookupCityOnce(string $city, string $country): ?array
    {
        $res = $this->get('/location/cities', [
            'city' => $city,
            'country_codes' => $country,
            'size' => 10,
        ]);

        if (!$res['ok'] || !is_array($res['data'] ?? null)) {
            return null;
        }

        $list = $res['data'];
        if ($list === []) {
            return null;
        }
        if (isset($list['code'])) {
            $list = [$list];
        }

        $needle = mb_strtolower($city);
        $best = null;
        foreach ($list as $row) {
            if (!is_array($row) || empty($row['code'])) {
                continue;
            }
            $name = mb_strtolower((string) ($row['city'] ?? ''));
            if ($name === $needle) {
                $best = $row;
                break;
            }
            if ($best === null && (str_contains($name, $needle) || str_contains($needle, $name))) {
                $best = $row;
            }
            if ($best === null) {
                $best = $row;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'code' => (int) $best['code'],
            'city' => (string) ($best['city'] ?? $city),
            'country_code' => (string) ($best['country_code'] ?? $country),
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['api_url'] ?? 'https://api.edu.cdek.ru/v2'), '/');
    }

    /** @param array<string, scalar|null>|null $query */
    private function buildUrl(string $path, ?array $query): string
    {
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        if ($query) {
            $parts = [];
            foreach ($query as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
            if ($parts !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $parts);
            }
        }
        return $url;
    }

    /**
     * @param list<string> $extraHeaders
     * @return list<string>
     */
    private function buildHeaders(string $token, string $requestId, array $extraHeaders, bool $hasJsonBody): array
    {
        $headers = [
            'Accept: application/json',
            'Accept-Charset: utf-8',
            'Authorization: Bearer ' . $token,
            'X-Request-ID: ' . $requestId,
        ];
        foreach ($extraHeaders as $h) {
            $h = trim((string) $h);
            if ($h === '' || stripos($h, 'Authorization:') === 0) {
                continue;
            }
            $headers[] = $h;
        }
        if ($hasJsonBody) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        return $headers;
    }

    private function isIdempotentMethod(string $method, string $path): bool
    {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return true;
        }
        // Calculator POST — безопасен для retry (не создаёт заказ).
        if ($method === 'POST' && str_contains($path, 'calculator/')) {
            return true;
        }
        return false;
    }

    private function isOrderCreate(string $method, string $path): bool
    {
        $normalized = '/' . ltrim($path, '/');
        return $method === 'POST' && (
            $normalized === '/orders'
            || str_ends_with($normalized, '/orders')
            || preg_match('#/v2/orders$#', $normalized) === 1
        );
    }

    private function sleepRetry(int $attempt): void
    {
        $baseMs = max(50, (int) ($this->config['retry_backoff_ms'] ?? 200));
        usleep($baseMs * $attempt * 1000);
    }

    private function classifyCurlFailure(?string $error): string
    {
        $e = mb_strtolower((string) $error);
        if (str_contains($e, 'timed out') || str_contains($e, 'timeout') || str_contains($e, 'operation timed out')) {
            return 'timeout';
        }
        return 'network';
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    private function finalizeResult(
        string $method,
        string $path,
        int $code,
        string $body,
        ?array $data,
        string $requestId,
        int $started,
        int $retries
    ): array {
        $api = CdekApiResponse::fromClientResult([
            'ok' => $code > 0 && $code < 400,
            'code' => $code,
            'data' => $data,
            'error' => $code >= 400 ? ('CDEK HTTP ' . $code) : null,
            'request_id' => $requestId,
            'duration_ms' => $this->elapsedMs($started),
            'body' => $body,
        ], $this->errorMapper);

        // Если fromClientResult пометил invalid/error при code<400 — уважаем.
        if ($code >= 400) {
            $mapped = $this->errorMapper->map($data, $code);
            $this->lastError = $mapped['message'];
            $this->logger->log([
                'method' => $method,
                'path' => $path,
                'http_status' => $code,
                'duration_ms' => $this->elapsedMs($started),
                'request_id' => $requestId,
                'internal_status' => CdekApiResponse::STATUS_ERROR,
                'cdek_uuid' => $api->cdekUuid,
                'error_type' => $mapped['error_type'] ?? 'unknown',
                'retry' => $retries,
            ]);
            return [
                'ok' => false,
                'code' => $code,
                'error' => $mapped['message'],
                'error_mapped' => $mapped,
                'data' => $data,
                'body' => mb_substr($body, 0, 800),
                'request_id' => $requestId,
                'duration_ms' => $this->elapsedMs($started),
                'async' => false,
                'internal_status' => CdekApiResponse::STATUS_ERROR,
            ];
        }

        $this->logger->log([
            'method' => $method,
            'path' => $path,
            'http_status' => $code,
            'duration_ms' => $this->elapsedMs($started),
            'request_id' => $requestId,
            'internal_status' => $api->internalStatus,
            'cdek_uuid' => $api->cdekUuid,
            'error_type' => $api->ok ? null : ($api->errorMapped['error_type'] ?? null),
            'retry' => $retries,
        ]);

        // Backward compatible: ok=true for 2xx including 202, plus async metadata.
        return array_merge($api->toArray(), [
            'body' => $body,
        ]);
    }

    private function elapsedMs(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1e6);
    }

    private function timeoutSeconds(): int
    {
        return max(5, (int) ($this->config['timeout'] ?? 45));
    }

    private function connectTimeoutSeconds(): int
    {
        return max(3, (int) ($this->config['connect_timeout'] ?? 20));
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}|null
     */
    private function rawRequest(string $method, string $url, ?string $body, array $headers, bool $allowEmptyBody): ?array
    {
        $this->lastCurlError = null;

        if (is_callable($this->httpTransport)) {
            $result = ($this->httpTransport)($method, $url, $body, $headers);
            if ($result === null) {
                $this->lastCurlError = 'transport failed';
                return null;
            }
            // Optional [2] = transport error message; HTTP code 0 → network/timeout failure.
            if (isset($result[2]) && is_string($result[2]) && $result[2] !== '') {
                $this->lastCurlError = $result[2];
            }
            if ((int) $result[0] === 0) {
                if ($this->lastCurlError === null || $this->lastCurlError === '') {
                    $this->lastCurlError = 'transport failed';
                }
                return null;
            }
            return [(int) $result[0], (string) $result[1]];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastCurlError = 'curl_init failed';
            return null;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds(),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== null || !$allowEmptyBody) {
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($ca) && $ca !== '' && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        } elseif ($this->isTestMode()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($response)) {
            $this->lastCurlError = $error !== '' ? $error : ('errno ' . $errno);
            return null;
        }
        return [$code, $response];
    }
}
