#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$root = require __DIR__ . '/bootstrap.php';

use App\Services\AuctionService;

$lockFilePath = sys_get_temp_dir() . '/zakapeiku_auction_cron.lock';
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
    file_put_contents($logDir . '/auction_cron.log', $line, FILE_APPEND);
};

try {
    $service = new AuctionService();
    $closed = $service->finalizeExpired();
    $dutch = $service->updateDutchDisplayedPrices();
    $log("closed={$closed} dutch_updated={$dutch}");
} catch (Throwable $e) {
    $log('FATAL ' . $e->getMessage());
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
    exit(1);
}

flock($lockFile, LOCK_UN);
fclose($lockFile);
exit(0);
