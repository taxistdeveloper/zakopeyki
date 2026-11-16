<?php

/**
 * Роутер для встроенного сервера PHP (только локальная разработка / мобильное приложение):
 *   php -S 127.0.0.1:8137 bin/dev_router.php
 * Отдаёт существующие статические файлы из public/ напрямую (как Apache с .htaccess),
 * остальное — через index.php. Каталог bin/ закрыт для веба в .htaccess.
 */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$root = dirname(__DIR__);
$file = realpath($root . $path);
if ($file !== false && is_file($file)
    && str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR)
    && !preg_match('/\.php$/i', $file)
) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
