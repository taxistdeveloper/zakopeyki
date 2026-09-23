<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

final class AiConfig
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $path = dirname(__DIR__, 4) . '/config/ai.php';
            self::$cache = is_file($path) ? require $path : [];
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cfg = self::all();
        $parts = explode('.', $key);
        $cur = $cfg;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
