<?php

namespace App\Core;

class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    public static function meta(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<meta name="csrf-token" content="' . $token . '">';
    }

    public static function validate(?string $token): bool
    {
        Auth::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function tokenFromRequest(): ?string
    {
        $fromPost = $_POST['_csrf'] ?? null;
        if (is_string($fromPost) && $fromPost !== '') {
            return $fromPost;
        }

        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return null;
    }

    public static function enforce(): void
    {
        if (self::validate(self::tokenFromRequest())) {
            return;
        }

        $wantsJson = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        http_response_code(419);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => t('security.csrf')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['error'] = t('security.csrf');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $base = rtrim((string) ($GLOBALS['appConfig']['url'] ?? ''), '/');
        if ($referer !== '' && ($base === '' || str_contains($referer, $base) || str_starts_with($referer, '/'))) {
            header('Location: ' . $referer);
            exit;
        }

        header('Location: ' . \App\Helpers\ProductHelper::url('/'));
        exit;
    }
}
