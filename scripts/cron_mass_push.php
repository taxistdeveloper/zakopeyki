<?php

/**
 * Дописывает уже запущенные Mass Push. Новые запуски отсюда не создаются.
 * Пример: php C:\MAMP\htdocs\zakopeyki\scripts\cron_mass_push.php
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

$written = (new \App\Services\MassPush\MassPushService())->dispatchDue(200);

echo date('c') . " mass_push_accepted={$written}\n";
