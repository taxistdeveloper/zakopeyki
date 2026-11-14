<?php

namespace App\Core;

use App\Models\MobileToken;

/**
 * Авторизация мобильного приложения.
 *
 * Токен устройства приходит:
 *  - в заголовке Authorization: Bearer zkm_… (нативные запросы к /api/mobile/v1);
 *  - в заголовке X-Mobile-Token (если Apache съел Authorization);
 *  - в cookie zk_mt (WebView внутри приложения).
 *
 * Если сессии нет или она принадлежит другому пользователю — поднимаем сессию по токену,
 * поэтому весь существующий сайт (корзина, чаты, оплата) работает в WebView без повторного входа.
 */
class MobileAuth
{
    public const COOKIE = 'zk_mt';
    public const APP_HEADER = 'HTTP_X_ZK_APP';

    private static ?string $token = null;
    private static bool $resolved = false;

    public static function bootstrap(): void
    {
        $token = self::token();
        if ($token === null) {
            return;
        }

        try {
            $user = (new MobileToken())->userForAccess($token);
        } catch (\Throwable) {
            return;
        }

        if (!$user) {
            self::$token = null;
            return;
        }

        if (!Auth::check() || (int) Auth::id() !== (int) $user['id']) {
            Auth::login($user, true);
        }
    }

    /** Токен текущего запроса (без проверки в БД). */
    public static function token(): ?string
    {
        if (self::$resolved) {
            return self::$token;
        }
        self::$resolved = true;

        $candidates = [];
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            $candidates[] = $m[1];
        }
        $candidates[] = (string) ($_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '');
        $candidates[] = (string) ($_COOKIE[self::COOKIE] ?? '');

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if (MobileToken::looksLikeAccessToken($candidate)) {
                return self::$token = $candidate;
            }
        }

        return self::$token = null;
    }

    /** Отозвать токен текущего запроса и стереть cookie WebView. */
    public static function revokeCurrent(): void
    {
        $token = self::token();
        if ($token !== null) {
            try {
                (new MobileToken())->revokeAccess($token);
            } catch (\Throwable) {
            }
        }
        if (isset($_COOKIE[self::COOKIE])) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }

    /** Запрос пришёл из приложения (кастомный заголовок → браузер не пошлёт его кросс-доменно без CORS). */
    public static function isAppRequest(): bool
    {
        return !empty($_SERVER[self::APP_HEADER]);
    }
}
