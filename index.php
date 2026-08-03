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
\App\Helpers\ActivityLogger::registerHandlers();

// stub_mode из БД (админка) перекрывает config/app.php
try {
    $settings = new \App\Models\Setting();
    $dbStub = $settings->getBool('stub_mode');
    if ($dbStub !== null) {
        $appConfig['stub_mode'] = $dbStub;
    }
    $dbOpensAt = $settings->get('stub_opens_at');
    if ($dbOpensAt !== null && $dbOpensAt !== '') {
        $appConfig['stub_opens_at'] = $dbOpensAt;
    }
    $GLOBALS['appConfig'] = $appConfig;
} catch (\Throwable) {
    // при недоступной БД остаётся значение из config
}

// Режим заглушки: сайт открыт админу и пользователям с персональным доступом.
if (!empty($appConfig['stub_mode']) && !\App\Core\Auth::hasSiteAccess(true)) {
    $stubPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $stubBase = rtrim((string) ($appConfig['url'] ?? ''), '/');
    if ($stubBase !== '' && str_starts_with($stubPath, $stubBase)) {
        $stubPath = substr($stubPath, strlen($stubBase)) ?: '/';
    }
    $stubPath = '/' . trim($stubPath, '/');
    if ($stubPath !== '/') {
        $stubPath = rtrim($stubPath, '/');
    }

    $stubAllowed = [
        '/login',
        '/login/2fa',
        '/logout',
        '/register',
        '/forgot-password',
        '/auth/google',
        '/auth/google/callback',
        '/offer',
    ];
    $stubAllowedPrefix = '/reset-password/';
    $stubOk = in_array($stubPath, $stubAllowed, true)
        || str_starts_with($stubPath, $stubAllowedPrefix);

    if (!$stubOk) {
        \App\Core\View::render('stub/coming-soon', [
            'title' => 'Скоро открытие',
            'opensAt' => (string) ($appConfig['stub_opens_at'] ?? '2026-08-06 00:00:00'),
        ], '');
        exit;
    }
}

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
