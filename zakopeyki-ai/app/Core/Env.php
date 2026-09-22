<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Загрузчик переменных окружения из .env файла.
 *
 * Значения из реального окружения (getenv/$_ENV) имеют приоритет
 * над значениями из файла.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    /**
     * Загрузка .env файла. Безопасно вызывать несколько раз.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    // Strip surrounding quotes if present
                    if (strlen($value) >= 2
                        && (($value[0] === '"' && str_ends_with($value, '"'))
                            || ($value[0] === "'" && str_ends_with($value, "'")))
                    ) {
                        $value = substr($value, 1, -1);
                    }
                    self::$values[$name] = $value;
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Чтение переменной окружения: сначала реальное окружение, затем .env.
     */
    public static function get(string $name, ?string $default = null): ?string
    {
        $fromEnv = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if ($fromEnv !== false && $fromEnv !== null && $fromEnv !== '') {
            return (string) $fromEnv;
        }

        if (array_key_exists($name, self::$values) && self::$values[$name] !== '') {
            return self::$values[$name];
        }

        return $default;
    }

    public static function getInt(string $name, int $default): int
    {
        $value = self::get($name);

        return $value === null ? $default : (int) $value;
    }

    public static function getBool(string $name, bool $default): bool
    {
        $value = self::get($name);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Сброс состояния (используется только в тестах).
     */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
