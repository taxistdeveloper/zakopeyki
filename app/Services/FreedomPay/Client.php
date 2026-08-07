<?php

namespace App\Services\FreedomPay;

use App\Helpers\ProductHelper;

/**
 * FreedomPay Gateway client (Hosted Checkout via init_payment.php).
 * @see https://docs.freedompay.kz/
 */
class Client
{
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
            return;
        }

        $path = dirname(__DIR__, 3) . '/config/freedompay.php';
        $this->config = is_file($path) ? (require $path) : [];
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['merchant_id'] ?? '')) !== ''
            && trim((string) ($this->config['secret_key'] ?? '')) !== '';
    }

    public function config(): array
    {
        return $this->config;
    }

    /**
     * MD5 signature: scriptName;sortedValues;secret_key
     * Flat fields: ksort by key, then values. Nested arrays use PayBox flatten.
     * @param array<string, mixed> $params without pg_sig
     */
    public function makeSig(string $scriptName, array $params): string
    {
        unset($params['pg_sig']);
        $hasNested = false;
        foreach ($params as $val) {
            if (is_array($val)) {
                $hasNested = true;
                break;
            }
        }

        if ($hasNested) {
            $flat = $this->flattenParams($params);
            ksort($flat, SORT_STRING);
            $values = array_values($flat);
        } else {
            $clean = [];
            foreach ($params as $key => $val) {
                if ($val === null) {
                    continue;
                }
                $clean[(string) $key] = (string) $val;
            }
            ksort($clean, SORT_STRING);
            $values = array_values($clean);
        }

        $parts = array_merge([$scriptName], $values, [$this->secretKey()]);
        return md5(implode(';', $parts));
    }

    /**
     * @param array<string, scalar|null> $params including pg_sig
     */
    public function verifySig(string $scriptName, array $params): bool
    {
        $sig = (string) ($params['pg_sig'] ?? '');
        if ($sig === '') {
            return false;
        }
        $expected = $this->makeSig($scriptName, $params);
        return hash_equals($expected, strtolower($sig));
    }

    /**
     * Initialize payment; returns redirect URL on success.
     *
     * @param array{
     *   order_id: string,
     *   amount: int|float,
     *   description: string,
     *   user_id?: string|int,
     *   user_email?: string,
     *   user_phone?: string,
     *   user_ip?: string,
     *   param1?: string,
     *   param2?: string,
     *   param3?: string,
     * } $payload
     * @return array{ok: bool, redirect_url?: string, payment_id?: string, error?: string, raw?: array}
     */
    public function initPayment(array $payload): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'FreedomPay is not configured'];
        }

        $request = [
            'pg_merchant_id' => (string) (int) $this->config['merchant_id'],
            'pg_order_id' => (string) $payload['order_id'],
            'pg_amount' => (string) $payload['amount'],
            'pg_description' => (string) $payload['description'],
            'pg_currency' => (string) ($this->config['currency'] ?? 'KZT'),
            'pg_salt' => bin2hex(random_bytes(8)),
            'pg_testing_mode' => (string) (int) ($this->config['testing_mode'] ?? 1),
            'pg_lifetime' => (string) (int) ($this->config['lifetime'] ?? 86400),
            'pg_language' => (string) ($this->config['language'] ?? 'ru'),
            'pg_request_method' => 'POST',
            'pg_success_url_method' => 'GET',
            'pg_failure_url_method' => 'GET',
            'pg_result_url' => $this->url('result_url', '/payments/freedompay/result'),
            'pg_success_url' => $this->url('success_url', '/payments/freedompay/success'),
            'pg_failure_url' => $this->url('failure_url', '/payments/freedompay/failure'),
            'pg_site_url' => $this->url('site_url', '/'),
        ];

        // merchant_id в API — только число; если в конфиге ключ/мусор — init упадёт
        if ($request['pg_merchant_id'] === '0' || !ctype_digit(trim((string) $this->config['merchant_id']))) {
            return ['ok' => false, 'error' => 'Неверный merchant_id: нужно числовой ID магазина из ЛК'];
        }

        if (!empty($payload['user_id'])) {
            $request['pg_user_id'] = (string) $payload['user_id'];
        }
        if (!empty($payload['user_email'])) {
            $request['pg_user_contact_email'] = (string) $payload['user_email'];
        }
        if (!empty($payload['user_phone'])) {
            $request['pg_user_phone'] = preg_replace('/\D+/', '', (string) $payload['user_phone']) ?: '';
            if ($request['pg_user_phone'] === '') {
                unset($request['pg_user_phone']);
            }
        }
        if (!empty($payload['user_ip'])) {
            $request['pg_user_ip'] = (string) $payload['user_ip'];
        }
        foreach (['param1', 'param2', 'param3'] as $key) {
            if (!empty($payload[$key])) {
                $request['pg_' . $key] = (string) $payload[$key];
            }
        }

        $request['pg_sig'] = $this->makeSig('init_payment.php', $request);

        $endpoint = rtrim((string) ($this->config['api_url'] ?? 'https://api.freedompay.kz'), '/')
            . '/init_payment.php';

        $http = $this->httpPost($endpoint, $request);
        if ($http === null) {
            return ['ok' => false, 'error' => 'FreedomPay request failed'];
        }

        [$httpCode, $responseBody] = $http;
        if ($httpCode >= 400) {
            return [
                'ok' => false,
                'error' => 'FreedomPay HTTP ' . $httpCode,
                'raw' => ['body' => mb_substr($responseBody, 0, 500)],
            ];
        }

        $parsed = $this->parseXml($responseBody);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'Invalid FreedomPay response', 'raw' => ['body' => $responseBody]];
        }

        if (($parsed['pg_status'] ?? '') !== 'ok') {
            $code = (string) ($parsed['pg_error_code'] ?? '');
            $desc = (string) ($parsed['pg_error_description'] ?? $parsed['pg_description'] ?? 'FreedomPay error');
            if ($code !== '') {
                $desc = '[' . $code . '] ' . $desc;
            }
            return [
                'ok' => false,
                'error' => $desc,
                'raw' => $parsed,
            ];
        }

        $redirect = (string) ($parsed['pg_redirect_url'] ?? '');
        if ($redirect === '') {
            return ['ok' => false, 'error' => 'No redirect URL from FreedomPay', 'raw' => $parsed];
        }

        return [
            'ok' => true,
            'redirect_url' => $redirect,
            'payment_id' => (string) ($parsed['pg_payment_id'] ?? ''),
            'raw' => $parsed,
        ];
    }

    /**
     * Build signed XML response for result_url callback.
     * @param array<string, string> $fields without pg_sig
     */
    public function xmlResponse(string $scriptName, array $fields): string
    {
        if (!isset($fields['pg_salt'])) {
            $fields['pg_salt'] = bin2hex(random_bytes(8));
        }
        $fields['pg_sig'] = $this->makeSig($scriptName, $fields);

        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n<response>\n";
        foreach ($fields as $key => $value) {
            $xml .= '    <' . $key . '>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
                . '</' . $key . ">\n";
        }
        $xml .= '</response>';
        return $xml;
    }

    /**
     * Collect request params from POST/GET for callbacks.
     * @return array<string, string>
     */
    public static function requestParams(): array
    {
        $src = array_merge($_GET, $_POST);
        $out = [];
        foreach ($src as $key => $value) {
            if (!is_string($key) || is_array($value)) {
                continue;
            }
            $out[$key] = (string) $value;
        }
        return $out;
    }

    private function secretKey(): string
    {
        return (string) ($this->config['secret_key'] ?? '');
    }

    private function url(string $configKey, string $fallbackPath): string
    {
        $custom = trim((string) ($this->config[$configKey] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        return ProductHelper::absoluteUrl($fallbackPath);
    }

    /**
     * Flatten nested arrays for signature (PayBox/FreedomPay convention).
     * @param array<string, mixed> $arr
     * @return array<string, string>
     */
    private function flattenParams(array $arr, string $parentName = ''): array
    {
        $flat = [];
        $i = 0;
        foreach ($arr as $key => $val) {
            $i++;
            $name = $parentName . $key . sprintf('%03d', $i);
            if (is_array($val)) {
                $flat = array_merge($flat, $this->flattenParams($val, $name));
                continue;
            }
            if ($val === null) {
                continue;
            }
            $flat[$name] = (string) $val;
        }
        return $flat;
    }

    /**
     * @param array<string, string> $fields
     * @return array{0: int, 1: string}|null [httpCode, body]
     */
    private function httpPost(string $url, array $fields): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($body)) {
            return null;
        }
        return [$code, $body];
    }

    /** @return array<string, string>|null */
    private function parseXml(string $xml): ?array
    {
        $prev = libxml_use_internal_errors(true);
        $el = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($el === false) {
            return null;
        }

        $out = [];
        foreach ($el->children() as $child) {
            $out[$child->getName()] = (string) $child;
        }
        return $out;
    }
}
