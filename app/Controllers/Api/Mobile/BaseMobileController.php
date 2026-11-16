<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Core\Lang;
use App\Models\User;
use App\Services\MobileApi\TokenService;

/**
 * Базовый контроллер мобильного API /api/mobile/v1 (M-02).
 *
 * Контракт ответа:
 *   { "success": true,  "data": {...}, "meta": {...}? }
 *   { "success": false, "error": { "code": "snake_case", "message": "<локализовано>", "fields": {...}? } }
 *
 * Авторизация — только `Authorization: Bearer <access>`; cookie-сессия сайта не используется.
 */
abstract class BaseMobileController
{
    private ?int $userId = null;
    private ?string $familyId = null;
    private ?array $bodyCache = null;

    public function __construct()
    {
        $this->applyLocale();
    }

    // ---------------------------------------------------------------- responses

    /** @param array<string, mixed>|list<mixed> $data */
    protected function ok(array $data = [], array $meta = [], int $status = 200): never
    {
        $out = ['success' => true, 'data' => $data === [] ? new \stdClass() : $data];
        if ($meta !== []) {
            $out['meta'] = $meta;
        }
        self::send($out, $status);
    }

    /** @param array<string, string> $fields */
    protected function fail(string $code, int $status, ?string $message = null, array $fields = [], array $headers = []): never
    {
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        $error = ['code' => $code, 'message' => $message ?? self::message($code)];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        self::send(['success' => false, 'error' => $error], $status);
    }

    public static function send(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function message(string $code): string
    {
        $key = 'mobile_api.' . $code;
        $text = Lang::get($key);

        return $text !== $key ? $text : Lang::get('mobile_api.generic_error', 'Error');
    }

    // ---------------------------------------------------------------- input

    /** @return array<string, mixed> */
    protected function body(): array
    {
        if ($this->bodyCache !== null) {
            return $this->bodyCache;
        }
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($type, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            $json = $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($json)) {
                $this->fail('invalid_json', 400);
            }

            return $this->bodyCache = $json;
        }

        return $this->bodyCache = $_POST;
    }

    protected function input(string $key, string $default = ''): string
    {
        $v = $this->body()[$key] ?? $default;

        return is_scalar($v) ? trim((string) $v) : $default;
    }

    protected function queryInt(string $key, int $default, int $min, int $max): int
    {
        $v = isset($_GET[$key]) && is_scalar($_GET[$key]) ? (int) $_GET[$key] : $default;

        return max($min, min($max, $v));
    }

    protected function queryString(string $key, int $maxLen = 120): string
    {
        $v = isset($_GET[$key]) && is_scalar($_GET[$key]) ? trim((string) $_GET[$key]) : '';

        return mb_substr($v, 0, $maxLen);
    }

    /** @return array{device_id: string, device_name: string, platform: string, app_version: string} */
    protected function device(): array
    {
        $platform = strtolower((string) ($_SERVER['HTTP_X_PLATFORM'] ?? $this->input('platform')));

        return [
            'device_id' => mb_substr((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? $this->input('device_id')), 0, 64),
            'device_name' => mb_substr($this->input('device_name'), 0, 120),
            'platform' => in_array($platform, ['ios', 'android'], true) ? $platform : 'other',
            'app_version' => mb_substr((string) ($_SERVER['HTTP_X_APP_VERSION'] ?? ''), 0, 32),
        ];
    }

    // ---------------------------------------------------------------- auth

    protected function bearer(): ?string
    {
        $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Текущий пользователь или null (для публичных endpoint'ов с опциональной авторизацией). */
    protected function optionalUserId(): ?int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }
        $token = $this->bearer();
        if ($token === null) {
            return null;
        }
        $res = (new TokenService())->verifyAccess($token);
        if (empty($res['ok'])) {
            $this->fail((string) $res['error'], 401, null, [], ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }
        $user = (new User())->find((int) $res['user_id']);
        if (!$user || (new User())->isBlocked($user)) {
            (new TokenService())->revokeFamily((string) $res['family_id'], 'blocked');
            $this->fail('account_blocked', 403);
        }
        $this->familyId = (string) $res['family_id'];

        return $this->userId = (int) $res['user_id'];
    }

    protected function requireUserId(): int
    {
        $id = $this->optionalUserId();
        if ($id === null) {
            $this->fail('auth_required', 401, null, [], ['WWW-Authenticate' => 'Bearer']);
        }

        return $id;
    }

    /** Режим заглушки сайта действует и для приложения: доступ только admin / site_access (как Auth::hasSiteAccess). */
    protected function guardSiteOpen(): void
    {
        if (!self::siteClosed()) {
            return;
        }
        $uid = $this->optionalUserId();
        if ($uid !== null) {
            $user = (new User())->find($uid);
            if ($user && (($user['role'] ?? '') === 'admin' || !empty($user['site_access']))) {
                return;
            }
        }
        $this->fail('site_closed', 503);
    }

    public static function siteClosed(): bool
    {
        return !empty($GLOBALS['appConfig']['stub_mode']);
    }

    protected function currentFamilyId(): ?string
    {
        return $this->familyId;
    }

    // ---------------------------------------------------------------- locale

    private function applyLocale(): void
    {
        $supported = $GLOBALS['appConfig']['locales'] ?? ['ru', 'kk'];
        $header = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        foreach (explode(',', $header) as $part) {
            $code = substr(trim(explode(';', $part)[0]), 0, 2);
            if (in_array($code, $supported, true)) {
                Lang::set($code);

                return;
            }
        }
    }
}
