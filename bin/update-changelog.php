<?php

declare(strict_types=1);

/**
 * Пересобрать changelog вручную.
 * Обычно не нужно: главная сама обновляет при изменении кода/коммита.
 *
 * php bin/update-changelog.php
 * php bin/update-changelog.php --force
 */

$root = dirname(__DIR__);
require $root . '/app/Helpers/ChangelogHelper.php';

$appConfig = is_file($root . '/config/app.php') ? require $root . '/config/app.php' : [];
if (!empty($appConfig['timezone'])) {
    date_default_timezone_set($appConfig['timezone']);
}

// Минимальный stub Lang для CLI
if (!class_exists(\App\Core\Lang::class, false)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = dirname(__DIR__) . '/app/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

$force = in_array('--force', $argv ?? [], true);
$ok = \App\Helpers\ChangelogHelper::sync($force);
$data = \App\Helpers\ChangelogHelper::load();

if ($data === null) {
    fwrite(STDERR, "Failed to build changelog.\n");
    exit(1);
}

echo ($ok || $force ? 'Updated' : 'Up to date') . ": {$data['version']} (" . count($data['items']) . " items)\n";
foreach ($data['items'] as $item) {
    echo ' - ' . $item . "\n";
}
