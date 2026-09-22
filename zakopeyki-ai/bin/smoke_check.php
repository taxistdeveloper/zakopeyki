<?php

declare(strict_types=1);

/**
 * Smoke-check скелета: контейнер, автозагрузка, ключевые зависимости.
 */

use App\Core\Container;

$container = require __DIR__ . '/../config/test_container.php';

$checks = [
    'container is App\Core\Container' => $container instanceof Container,
    'app.env = testing'               => $container->parameter('app.env') === 'testing',
    'db suffix _test'                 => str_ends_with((string) $container->parameter('db.database'), '_test'),
    'guzzlehttp/guzzle'               => class_exists(\GuzzleHttp\Client::class),
    'illuminate/database (Capsule)'   => class_exists(\Illuminate\Database\Capsule\Manager::class),
    'php-ai/php-ml (SVC)'             => class_exists(\Phpml\Classification\SVC::class),
    'php-ai/php-ml (NaiveBayes)'      => class_exists(\Phpml\Classification\NaiveBayes::class),
    'predis/predis'                   => class_exists(\Predis\Client::class),
    'psr/log'                         => interface_exists(\Psr\Log\LoggerInterface::class),
    'phpunit'                         => class_exists(\PHPUnit\Framework\TestCase::class),
    'ext mbstring'                    => extension_loaded('mbstring'),
    'ext openssl'                     => extension_loaded('openssl'),
    'ext curl'                        => extension_loaded('curl'),
    'ext pdo_mysql'                   => extension_loaded('pdo_mysql'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) {
        $failed++;
    }
}

printf("\n%s (%d/%d)\n", $failed === 0 ? 'SMOKE CHECK PASSED' : 'SMOKE CHECK FAILED', count($checks) - $failed, count($checks));
exit($failed === 0 ? 0 : 1);
