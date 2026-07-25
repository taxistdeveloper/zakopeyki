<?php

declare(strict_types=1);

$appConfig = require __DIR__ . '/config/app.php';

// Автопуть: /zakapeiku  (без http:// — иначе браузер склеит адрес)
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
$detectedBase = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($detectedBase === '/' || $detectedBase === '.') {
    $detectedBase = '';
}
$appConfig['url'] = $detectedBase;
$GLOBALS['appConfig'] = $appConfig;

date_default_timezone_set($appConfig['timezone']);

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
\App\Core\Lang::boot();

if (!function_exists('js_encode')) {
    function js_encode(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: 'null';
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return \App\Core\Csrf::field();
    }
}

$router = require __DIR__ . '/config/routes.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
