<?php

namespace App\Services\Streaming;

use App\Models\Setting;

/**
 * HTTP-клиент Cloudflare Stream API (live inputs, VOD, signed playback, webhooks).
 * Без Composer: cURL + OpenSSL RS256.
 */
class CloudflareStreamClient
{
    private Setting $settings;

    public function __construct(?Setting $settings = null)
    {
        $this->settings = $settings ?? new Setting();
    }

    public function isConfigured(): bool
    {
        return $this->accountId() !== '' && $this->apiToken() !== '';
    }

    public function canSignPlayback(): bool
    {
        return $this->signingKeyId() !== '' && $this->signingPem() !== '';
    }

    public function accountId(): string
    {
        return trim((string) $this->settings->get('cf_stream_account_id', ''));
    }

    public function customerSubdomain(): string
    {
        return trim((string) $this->settings->get('cf_stream_customer_subdomain', ''));
    }

    public function requireSignedPlayback(): bool
    {
        $v = $this->settings->getBool('cf_stream_require_signed', true);
        return $v !== false;
    }

    public function createLiveInput(string $name, bool $record = true): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('digital.cf_not_configured')];
        }

        $body = [
            'meta' => ['name' => mb_substr($name, 0, 120)],
            'recording' => [
                'mode' => $record ? 'automatic' : 'off',
                'requireSignedURLs' => $this->requireSignedPlayback(),
            ],
        ];

        $res = $this->request('POST', '/stream/live_inputs', $body);
        if (!$res['ok']) {
            return $res;
        }

        $row = $res['result'] ?? [];
        return [
            'ok' => true,
            'uid' => (string) ($row['uid'] ?? ''),
            'rtmps_url' => (string) ($row['rtmps']['url'] ?? ''),
            'stream_key' => (string) ($row['rtmps']['streamKey'] ?? ''),
            'srt_url' => (string) ($row['srt']['url'] ?? ''),
            'raw' => $row,
        ];
    }

    public function getLiveInput(string $uid): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('digital.cf_not_configured')];
        }
        if ($uid === '') {
            return ['ok' => false, 'error' => t('digital.live_missing')];
        }
        $res = $this->request('GET', '/stream/live_inputs/' . rawurlencode($uid));
        if (!$res['ok']) {
            return $res;
        }
        $row = $res['result'] ?? [];
        return [
            'ok' => true,
            'uid' => (string) ($row['uid'] ?? $uid),
            'status' => (string) ($row['status']['current']['state'] ?? ($row['status'] ?? '')),
            'rtmps_url' => (string) ($row['rtmps']['url'] ?? ''),
            'stream_key' => (string) ($row['rtmps']['streamKey'] ?? ''),
            'srt_url' => (string) ($row['srt']['url'] ?? ''),
            'raw' => $row,
        ];
    }

    public function deleteLiveInput(string $uid): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('digital.cf_not_configured')];
        }
        return $this->request('DELETE', '/stream/live_inputs/' . rawurlencode($uid));
    }

    /** @return array{ok: bool, uid?: string, upload_url?: string, error?: string} */
    public function createDirectUpload(string $name, int $maxDurationSeconds = 21600): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('digital.cf_not_configured')];
        }

        $res = $this->request('POST', '/stream/direct_upload', [
            'maxDurationSeconds' => max(60, min(21600, $maxDurationSeconds)),
            'requireSignedURLs' => $this->requireSignedPlayback(),
            'meta' => ['name' => mb_substr($name, 0, 120)],
        ]);
        if (!$res['ok']) {
            return $res;
        }
        $row = $res['result'] ?? [];
        return [
            'ok' => true,
            'uid' => (string) ($row['uid'] ?? ''),
            'upload_url' => (string) ($row['uploadURL'] ?? ''),
        ];
    }

    public function getVideo(string $uid): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => t('digital.cf_not_configured')];
        }
        $res = $this->request('GET', '/stream/' . rawurlencode($uid));
        if (!$res['ok']) {
            return $res;
        }
        $row = $res['result'] ?? [];
        return [
            'ok' => true,
            'uid' => (string) ($row['uid'] ?? $uid),
            'ready' => !empty($row['readyToStream']),
            'status' => (string) ($row['status']['state'] ?? ''),
            'duration' => (float) ($row['duration'] ?? 0),
            'preview' => (string) ($row['preview'] ?? ''),
            'raw' => $row,
        ];
    }

    /**
     * JWT RS256 для signed playback (sub = video/live uid).
     * @return array{ok: bool, token?: string, exp?: int, error?: string}
     */
    public function signPlayback(string $videoUid, int $ttlSeconds = 90): array
    {
        if (!$this->canSignPlayback()) {
            return ['ok' => false, 'error' => t('digital.cf_signing_missing')];
        }
        if ($videoUid === '') {
            return ['ok' => false, 'error' => t('digital.video_missing')];
        }

        $ttl = max(30, min(600, $ttlSeconds));
        $now = time();
        $nbf = $now - 5;
        $exp = $now + $ttl;
        $kid = $this->signingKeyId();

        $header = $this->b64url(json_encode(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload = $this->b64url(json_encode([
            'sub' => $videoUid,
            'kid' => $kid,
            'exp' => $exp,
            'nbf' => $nbf,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $payload;

        $pem = $this->signingPem();
        $ok = openssl_sign($unsigned, $signature, $pem, OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') {
            return ['ok' => false, 'error' => t('digital.cf_sign_failed')];
        }

        return [
            'ok' => true,
            'token' => $unsigned . '.' . $this->b64url($signature),
            'exp' => $exp,
        ];
    }

    public function iframeUrl(string $videoUid, ?string $token = null): string
    {
        $host = $this->customerSubdomain();
        if ($host !== '') {
            $host = preg_replace('#^https?://#', '', $host);
            $host = rtrim((string) $host, '/');
            $base = 'https://' . $host . '/' . rawurlencode($videoUid) . '/iframe';
        } else {
            $base = 'https://iframe.videodelivery.net/' . rawurlencode($videoUid);
        }
        if ($token) {
            $base .= (str_contains($base, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
        }
        return $base;
    }

    public function verifyWebhookSignature(string $rawBody, string $headerSignature): bool
    {
        $secret = trim((string) $this->settings->get('cf_stream_webhook_secret', ''));
        if ($secret === '') {
            return false;
        }
        $calc = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($calc, strtolower(trim($headerSignature)));
    }

    /**
     * @return array{ok: bool, result?: mixed, error?: string, http?: int, errors?: mixed}
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $url = 'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode($this->accountId()) . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => t('digital.cf_http_failed')];
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiToken(),
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($json !== null && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => t('digital.cf_http_failed') . ($cerr !== '' ? (': ' . $cerr) : '')];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => t('digital.cf_bad_response'), 'http' => $http];
        }

        if (empty($decoded['success']) && $method !== 'DELETE') {
            $msg = t('digital.cf_api_error');
            if (!empty($decoded['errors'][0]['message'])) {
                $msg .= ': ' . (string) $decoded['errors'][0]['message'];
            }
            return ['ok' => false, 'error' => $msg, 'http' => $http, 'errors' => $decoded['errors'] ?? null];
        }

        if ($method === 'DELETE' && $http >= 200 && $http < 300) {
            return ['ok' => true, 'result' => $decoded['result'] ?? true];
        }

        return ['ok' => true, 'result' => $decoded['result'] ?? null, 'http' => $http];
    }

    private function apiToken(): string
    {
        return trim((string) $this->settings->get('cf_stream_api_token', ''));
    }

    private function signingKeyId(): string
    {
        return trim((string) $this->settings->get('cf_stream_signing_key_id', ''));
    }

    private function signingPem(): string
    {
        $pem = (string) $this->settings->get('cf_stream_signing_key_pem', '');
        return str_replace(["\r\n", "\r"], "\n", trim($pem));
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
