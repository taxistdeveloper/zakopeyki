<?php

declare(strict_types=1);

/**
 * Bootstrap for PHPUnit (no Composer).
 * App\ → app/, Tests\ → tests/
 */
spl_autoload_register(static function (string $class): void {
    $map = [
        'App\\' => dirname(__DIR__) . '/app/',
        'Tests\\' => dirname(__DIR__) . '/tests/',
    ];
    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $path = $base . $relative;
        if (is_file($path)) {
            require_once $path;
        }
    }
});

// Минимальный t() для unit-тестов без полного HTTP bootstrap.
if (!function_exists('t')) {
    require_once dirname(__DIR__) . '/app/Core/Lang.php';
    $GLOBALS['appConfig'] = $GLOBALS['appConfig'] ?? ['locale' => 'ru', 'locales' => ['ru', 'kk']];
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    \App\Core\Lang::boot();
}
