<?php

/**
 * Cron: закрыть истёкшие VIP и выполнить подошедшие автоматические поднятия.
 * Пример: php C:\MAMP\htdocs\zakopeyki\scripts\cron_vip.php
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

$service = new \App\Services\Vip\VipService();
$expired = $service->expireDue();
$lifts = $service->runDueLifts(50);
$repaired = $service->repairApplied();

echo date('c') . " vip_expired={$expired} vip_lifts={$lifts} vip_repaired={$repaired}\n";
