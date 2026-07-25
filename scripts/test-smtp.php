<?php

/**
 * Диагностика SMTP: php scripts/test-smtp.php [email@example.com]
 */
declare(strict_types=1);

use App\Helpers\Mail;

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require $root . '/config/mail.php';
$to = $argv[1] ?? (string) ($config['from_address'] ?? 'info@zakopeyki.kz');

echo 'driver: ' . ($config['driver'] ?? '?') . PHP_EOL;
echo 'from:   ' . ($config['from_address'] ?? '?') . PHP_EOL;
echo 'smtp:   ' . ($config['smtp']['host'] ?? '?')
    . ':' . ($config['smtp']['port'] ?? '?')
    . ' (' . ($config['smtp']['encryption'] ?? '?') . ')' . PHP_EOL;
echo 'user:   ' . ($config['smtp']['username'] ?? '?') . PHP_EOL;
echo 'pass:   ' . (trim((string) ($config['smtp']['password'] ?? '')) !== '' ? '(set)' : '(EMPTY!)') . PHP_EOL;
echo "to:     {$to}" . PHP_EOL;
echo str_repeat('-', 40) . PHP_EOL;

$mail = new Mail($config);
$ok = $mail->send(
    $to,
    'Тест SMTP zakopeyki.kz',
    "Тестовое письмо.\nЕсли вы его видите — SMTP работает.\n",
    '<p>Тестовое письмо.<br>Если вы его видите — SMTP работает.</p>'
);

echo $ok ? "OK: письмо отправлено\n" : "FAIL: смотри ошибку ниже\n";
if (!$ok && is_file($root . '/storage/mail.log')) {
    $lines = file($root . '/storage/mail.log') ?: [];
    echo implode('', array_slice($lines, -25));
}
exit($ok ? 0 : 1);
