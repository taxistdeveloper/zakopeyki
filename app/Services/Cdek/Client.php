<?php

namespace App\Services\Cdek;

/**
 * HTTP-клиент СДЭК API v2.
 *
 * Только транспортный слой:
 * - HTTP (curl);
 * - Authorization через CdekAuthService;
 * - timeout / один retry на 401;
 * - JSON encode/decode;
 * - correlation / request ID;
 * - маппинг ошибок через CdekErrorMapper.
 *
 * НЕ содержит marketplace-логики (quotes, payment, FSM).
 *
 * @see https://apidoc.cdek.ru/#tag/common/Vvedenie
 * @see openapi_api_v2_integration.json
 */
class Client
{
    private array $config;
    private CdekAuthService $auth;
    private CdekErrorMapper $errorMapper;
    private ?string $lastError = null;
    private ?string $lastCurlError = null;
    private ?string $lastRequestId = null;

    public function __construct(?array $config = null, ?CdekAuthService $auth = null, ?CdekErrorMapper $errorMapper = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $path = dirname(__DIR__, 3) . '/config/cdek.php';
            $this->config = is_file($path) ? (require $path) : [];
        }

        $this->auth = $auth ?? CdekAuthService::fromConfig($this->config);
        $this->errorMapper = $errorMapper ?? new CdekErrorMapper();
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
     * @param array<string, mixed>|null $jsonBody
     * @param array<string, scalar|null>|null $query
     * @param list<string> $extraHeaders raw header lines (без Authorization)
     * @return array{
     *   ok: bool,
     *   code: int,
     *   data?: mixed,
     *   error?: string,
     *   error_mapped?: array,
     *   body?: string,
     *   request_id: string,
     *   duration_ms: int
     * }
     */
    public function request(
        string $method,
        string $path,
        ?array $jsonBody = null,
        ?array $query = null,
        array $extraHeaders = []
    ): array
    {
        $this->lastError = null;
        $requestId = bin2hex(random_bytes(8));
        $this->lastRequestId = $requestId;
        $started = hrtime(true);

        $auth = $this->authorize();
        if (!$auth['ok']) {
            $mapped = $this->errorMapper->mapLocal(CdekErrorMapper::INTERNAL_AUTH, (string) ($auth['error'] ?? 'auth failed'));
            return [
                'ok' => false,
                'code' => 0,
                'error' => $mapped['message'],
                'error_mapped' => $mapped,
                'request_id' => $requestId,
                'duration_ms' => $this->elapsedMs($started),
            ];
        }

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

        $token = (string) $auth['token'];
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Request-ID: ' . $requestId,
        ];
        foreach ($extraHeaders as $h) {
            $h = trim((string) $h);
            // Не даём перезаписать Authorization извне (защита от утечки/подмены).
            if ($h === '' || stripos($h, 'Authorization:') === 0) {
                continue;
            }
            $headers[] = $h;
        }
        $payload = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }

        $http = $this->rawRequest(strtoupper($method), $url, $payload, $headers, true);
        if ($http === null) {
            $this->lastError = 'CDEK request failed: ' . $path;
            $mapped = $this->errorMapper->mapLocal(CdekErrorMapper::INTERNAL_HTTP, $this->lastError);
            return [
                'ok' => false,
                'code' => 0,
                'error' => $this->lastError,
                'error_mapped' => $mapped,
                'request_id' => $requestId,
                'duration_ms' => $this->elapsedMs($started),
            ];
        }

        [$code, $body] = $http;

        // Один повтор при протухшем токене (race между воркерами / Redis TTL).
        if ($code === 401) {
            $auth = $this->authorize(true);
            if ($auth['ok']) {
                $headers = [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $auth['token'],
                    'X-Request-ID: ' . $requestId,
                ];
                foreach ($extraHeaders as $h) {
                    $h = trim((string) $h);
                    if ($h === '' || stripos($h, 'Authorization:') === 0) {
                        continue;
                    }
                    $headers[] = $h;
                }
                if ($jsonBody !== null) {
                    $headers[] = 'Content-Type: application/json';
                }
                $http = $this->rawRequest(strtoupper($method), $url, $payload, $headers, true);
                if ($http !== null) {
                    [$code, $body] = $http;
                }
            }
        }

        $data = json_decode($body, true);
        if ($code >= 400) {
            $mapped = $this->errorMapper->map(is_array($data) ? $data : null, $code);
            $this->lastError = $mapped['message'];
            return [
                'ok' => false,
                'code' => $code,
                'error' => $mapped['message'],
                'error_mapped' => $mapped,
                'data' => is_array($data) ? $data : null,
                'body' => mb_substr($body, 0, 800),
                'request_id' => $requestId,
                'duration_ms' => $this->elapsedMs($started),
            ];
        }

        return [
            'ok' => true,
            'code' => $code,
            'data' => $data,
            'body' => $body,
            'request_id' => $requestId,
            'duration_ms' => $this->elapsedMs($started),
        ];
    }

    /**
     * @return array{ok: bool, code: int, data?: mixed, error?: string, request_id?: string, duration_ms?: int}
     */
    public function get(string $path, ?array $query = null): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $extraHeaders
     * @return array{ok: bool, code: int, data?: mixed, error?: string, request_id?: string, duration_ms?: int}
     */
    public function post(string $path, array $body, array $extraHeaders = []): array
    {
        return $this->request('POST', $path, $body, null, $extraHeaders);
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

    private function elapsedMs(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1e6);
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}|null
     */
    private function rawRequest(string $method, string $url, ?string $body, array $headers, bool $allowEmptyBody): ?array
    {
        $this->lastCurlError = null;
        $ch = curl_init($url);
        if ($ch === false) {
            $this->lastCurlError = 'curl_init failed';
            return null;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 20,
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
