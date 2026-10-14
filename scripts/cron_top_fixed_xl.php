<?php

/**
 * Cron: закрыть истёкшие окна Top Fixed XL.
 * Выдача и так смотрит на ends_at, эта задача только переводит статус в expired.
 * Пример: php C:\MAMP\htdocs\zakopeyki\scripts\cron_top_fixed_xl.php
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

$expired = (new \App\Services\TopFixedXl\TopFixedXlService())->expireDue();

echo date('c') . " top_fixed_xl_expired={$expired}\n";
