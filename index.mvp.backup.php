<?php

declare(strict_types=1);

$GLOBALS['appConfig'] = require __DIR__ . '/config/app.php';
date_default_timezone_set($GLOBALS['appConfig']['timezone']);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/app/' . $relative . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

\App\Core\Auth::start();

$router = require __DIR__ . '/config/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
