<?php

/**
 * Дописывает уже запущенные Target Push. Новые запуски отсюда не создаются.
 * Пример: php C:\MAMP\htdocs\zakopeyki\scripts\cron_target_push.php
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

$written = (new \App\Services\TargetPush\TargetPushService())->dispatchDue(200);

echo date('c') . " target_push_accepted={$written}\n";
