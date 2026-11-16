<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Проверка ID-токенов OpenID Connect (Sign in with Apple, Google Sign-In) на сервере.
 *
 * Проверяется: подпись RS256 по опубликованным ключам провайдера (JWKS), iss, aud,
 * exp/iat (допуск 60 с), для Apple — nonce (sha256 от одноразового значения клиента).
 * Клиенту не доверяем ничего, кроме самого подписанного токена.
 */
final class IdTokenVerifier
{
    public const APPLE = [
        'issuers' => ['https://appleid.apple.com'],
        'jwks' => 'https://appleid.apple.com/auth/keys',
    ];

    public const GOOGLE = [
        'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
    ];

    private const LEEWAY = 60;
    private const JWKS_TTL = 6 * 3600;

    /**
     * @param \Closure(string): ?array $jwksFetcher для тестов; по умолчанию — HTTPS + файловый кэш
     */
    public function __construct(private ?\Closure $jwksFetcher = null, private ?int $now = null)
    {
    }

    /**
     * @param array{issuers: list<string>, jwks: string} $provider
     * @param list<string> $audiences допустимые client id / bundle id
     * @return array{ok: true, claims: array<string, mixed>}|array{ok: false, error: string}
     */
    public function verify(string $jwt, array $provider, array $audiences, ?string $expectedNonceHash = null): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return self::fail('malformed');
        }
        $header = json_decode((string) self::b64d($parts[0]), true);
        $claims = json_decode((string) self::b64d($parts[1]), true);
        $signature = self::b64d($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === false) {
            return self::fail('malformed');
        }
        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return self::fail('unsupported_alg');
        }

        $jwks = $this->jwks($provider['jwks']);
        $jwk = null;
        foreach (($jwks['keys'] ?? []) as $k) {
            if (is_array($k) && ($k['kid'] ?? null) === $header['kid'] && ($k['kty'] ?? '') === 'RSA') {
                $jwk = $k;
                break;
            }
        }
        if ($jwk === null) {
            return self::fail('unknown_key');
        }
        $pem = self::rsaPemFromJwk((string) $jwk['n'], (string) $jwk['e']);
        if ($pem === null || openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return self::fail('bad_signature');
        }

        $now = $this->now ?? time();
        if (!in_array((string) ($claims['iss'] ?? ''), $provider['issuers'], true)) {
            return self::fail('bad_issuer');
        }
        $aud = $claims['aud'] ?? null;
        $audList = is_array($aud) ? $aud : [(string) $aud];
        if ($audiences === [] || array_intersect($audList, $audiences) === []) {
            return self::fail('bad_audience');
        }
        if ((int) ($claims['exp'] ?? 0) < $now - self::LEEWAY) {
            return self::fail('expired');
        }
        if ((int) ($claims['iat'] ?? 0) > $now + self::LEEWAY) {
            return self::fail('issued_in_future');
        }
        if ($expectedNonceHash !== null && !hash_equals($expectedNonceHash, (string) ($claims['nonce'] ?? ''))) {
            return self::fail('bad_nonce');
        }
        if ((string) ($claims['sub'] ?? '') === '') {
            return self::fail('no_subject');
        }

        return ['ok' => true, 'claims' => $claims];
    }

    /** Провайдеры передают email_verified как bool или строку "true". */
    public static function emailVerified(array $claims): bool
    {
        $v = $claims['email_verified'] ?? false;

        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    /** @return array<string, mixed> */
    private function jwks(string $url): array
    {
        if ($this->jwksFetcher !== null) {
            return ($this->jwksFetcher)($url) ?? [];
        }
        $cacheDir = dirname(__DIR__, 3) . '/storage/cache';
        $cacheFile = $cacheDir . '/jwks_' . md5($url) . '.json';
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < self::JWKS_TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['keys'])) {
            return [];
        }
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cacheFile, $raw);

        return $data;
    }

    /** Публичный ключ RSA (n, e в base64url) → PEM SubjectPublicKeyInfo. */
    public static function rsaPemFromJwk(string $n, string $e): ?string
    {
        $mod = self::b64d($n);
        $exp = self::b64d($e);
        if ($mod === false || $exp === false || $mod === '' || $exp === '') {
            return null;
        }
        $int = static function (string $bytes): string {
            if (ord($bytes[0]) > 0x7f) {
                $bytes = "\x00" . $bytes;
            }
            return "\x02" . self::derLen(strlen($bytes)) . $bytes;
        };
        $rsaKey = $int($mod) . $int($exp);
        $rsaKey = "\x30" . self::derLen(strlen($rsaKey)) . $rsaKey;
        $bitString = "\x03" . self::derLen(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = "\x30" . self::derLen(strlen($algo . $bitString)) . $algo . $bitString;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function b64d(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** @return array{ok: false, error: string} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'error' => $reason];
    }
}
