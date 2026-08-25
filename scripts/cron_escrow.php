<?php

/**
 * Cron: авторазморозка эскроу и таймеры возвратов.
 * php C:\MAMP\htdocs\zakapeiku\scripts\cron_escrow.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$appConfig = require $root . '/config/app.php';
$GLOBALS['appConfig'] = $appConfig;
date_default_timezone_set($appConfig['timezone'] ?? 'Asia/Almaty');

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = $root . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\App\Core\Lang::boot();
(new \App\Services\EscrowService())->processDeadlines();

echo date('c') . " escrow_deadlines_processed\n";
