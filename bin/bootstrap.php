<?php

declare(strict_types=1);

/**
 * Общий bootstrap для CLI-скриптов (воркер, healthcheck, migrate).
 */

$root = dirname(__DIR__);

$appConfig = is_file($root . '/config/app.php') ? require $root . '/config/app.php' : [];
$GLOBALS['appConfig'] = $appConfig;

if (!empty($appConfig['timezone'])) {
    date_default_timezone_set($appConfig['timezone']);
} else {
    date_default_timezone_set('Asia/Almaty');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    \App\Core\Auth::start();
}

if (class_exists(\App\Core\Lang::class)) {
    try {
        \App\Core\Lang::boot();
    } catch (\Throwable $e) {
        // CLI без HTTP — Lang может работать частично
    }
}

return $root;
