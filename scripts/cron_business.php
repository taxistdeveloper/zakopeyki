<?php

/**
 * Cron: сброс годовых лимитов personal + истечение бизнес-пакетов.
 * Пример Windows Task Scheduler / crontab:
 *   php C:\MAMP\htdocs\zakapeiku\scripts\cron_business.php
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

$reset = (new \App\Services\PersonalLimitService())->resetAllForNewYear();
$expired = (new \App\Models\BusinessSubscription())->expireOverdue();

echo date('c') . " personal_limits_reset={$reset} packages_expired={$expired}\n";
