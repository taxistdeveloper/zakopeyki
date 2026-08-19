#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Services\AmlListSyncService;

$lockFilePath = sys_get_temp_dir() . '/zakapeiku_aml_sync.lock';
$lockFile = fopen($lockFilePath, 'c+');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] previous run still active\n";
    exit(0);
}

$logDir = $root . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$log = static function (string $message) use ($logDir): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;
    file_put_contents($logDir . '/aml_sync.log', $line, FILE_APPEND);
};

$config = is_file($root . '/config/aml.php') ? require $root . '/config/aml.php' : [];

$redis = null;
if (class_exists(Redis::class)) {
    try {
        $redis = new Redis();
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        if (!$redis->connect($host, $port, 1.0)) {
            $redis = null;
        } elseif (!empty(getenv('REDIS_PASSWORD'))) {
            $redis->auth((string) getenv('REDIS_PASSWORD'));
        }
    } catch (Throwable) {
        $redis = null;
    }
}

try {
    $service = new AmlListSyncService(Database::connect(), $config, $redis);
    $result = $service->sync();
    if (!$result['ok']) {
        $log('FAIL ' . ($result['error'] ?? 'unknown'));
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
        exit(1);
    }
    $log('imported=' . (int) $result['imported']);
} catch (Throwable $e) {
    $log('FATAL ' . $e->getMessage());
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
    exit(1);
}

flock($lockFile, LOCK_UN);
fclose($lockFile);
exit(0);
