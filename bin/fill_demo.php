<?php
/**
 * Полное демо: товары / аукционы / услуги / бартер / даром / сторис / стримы / биржа.
 *
 *   php bin/fill_demo.php
 *
 * Пароль демо-аккаунтов: demo1234
 */
declare(strict_types=1);

$php = PHP_BINARY ?: 'php';
$root = dirname(__DIR__);

$steps = [
    $root . '/bin/seed_demo.php',
    $root . '/bin/fix_seed_product_images.php',
    $root . '/bin/fix_seed_story_images.php',
];

foreach ($steps as $script) {
    if (!is_file($script)) {
        fwrite(STDERR, "Skip missing: {$script}\n");
        continue;
    }
    echo "\n>>> " . basename($script) . "\n";
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($script), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Failed: " . basename($script) . " (exit {$code})\n");
        exit($code);
    }
}

echo "\n=== fill_demo готово ===\n";
echo "На сервере: php bin/fill_demo.php\n";
