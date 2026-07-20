<?php

namespace App\Core {

class Lang
{
    private static string $locale = 'ru';

    /** @var array<string, mixed> */
    private static array $lines = [];

    public static function boot(): void
    {
        $supported = $GLOBALS['appConfig']['locales'] ?? ['ru', 'kk'];
        $default = $GLOBALS['appConfig']['locale'] ?? 'ru';

        if (isset($_GET['lang'])) {
            $requested = strtolower(trim((string) $_GET['lang']));
            if (in_array($requested, $supported, true)) {
                $_SESSION['lang'] = $requested;
            }

            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $parts = parse_url($uri);
            $path = $parts['path'] ?? '/';
            $query = [];
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
            }
            unset($query['lang']);
            $redirect = $path . ($query ? '?' . http_build_query($query) : '');
            header('Location: ' . $redirect);
            exit;
        }

        $fromSession = $_SESSION['lang'] ?? null;
        self::$locale = is_string($fromSession) && in_array($fromSession, $supported, true)
            ? $fromSession
            : $default;

        self::load(self::$locale);
    }

    public static function current(): string
    {
        return self::$locale;
    }

    public static function htmlLang(): string
    {
        return self::$locale === 'kk' ? 'kk' : 'ru';
    }

    public static function set(string $code): void
    {
        $supported = $GLOBALS['appConfig']['locales'] ?? ['ru', 'kk'];
        if (!in_array($code, $supported, true)) {
            return;
        }
        $_SESSION['lang'] = $code;
        self::$locale = $code;
        self::load($code);
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = self::resolve($key);
        if (is_string($value)) {
            return $value;
        }

        return $default ?? $key;
    }

    /** @param array<string, string|int|float> $replace */
    public static function getf(string $key, array $replace = [], ?string $default = null): string
    {
        $text = self::get($key, $default);
        foreach ($replace as $search => $value) {
            $text = str_replace(':' . $search, (string) $value, $text);
        }

        return $text;
    }

    public static function category(string $name): string
    {
        $map = self::$lines['categories'] ?? [];
        if (is_array($map) && isset($map[$name]) && is_string($map[$name])) {
            return $map[$name];
        }

        return $name;
    }

    /** Подмножество строк для JS (плоский словарь). */
    public static function forJs(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }

    private static function load(string $locale): void
    {
        $file = dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
        if (!is_file($file)) {
            $file = dirname(__DIR__, 2) . '/lang/ru.php';
        }
        /** @var array<string, mixed> $lines */
        $lines = require $file;
        self::$lines = $lines;
    }

    private static function resolve(string $key): mixed
    {
        $segments = explode('.', $key);
        $cursor = self::$lines;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}

}

namespace {
    if (!function_exists('t')) {
        /** @param array<string, string|int|float> $replace */
        function t(string $key, array $replace = [], ?string $default = null): string
        {
            if ($replace === []) {
                return \App\Core\Lang::get($key, $default);
            }

            return \App\Core\Lang::getf($key, $replace, $default);
        }
    }
}
