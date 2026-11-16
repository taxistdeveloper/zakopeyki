#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Воркер виртуальной примерки (docs/tryon/01-architecture.md §4).
 *
 *   php bin/tryon_worker.php --max-runtime=55     # из cron каждую минуту
 *   php bin/tryon_worker.php                      # демон (Supervisor)
 *   php bin/tryon_worker.php --once               # один цикл (отладка)
 *
 * crontab:  * * * * * php /path/to/site/bin/tryon_worker.php --max-runtime=55 >> logs/tryon_worker.log 2>&1
 */

$root = require __DIR__ . '/bootstrap.php';

use App\Services\TryOn\Providers\TryOnProviderRouter;
use App\Services\TryOn\TryOnJobRunner;
use App\Services\TryOn\TryOnResultFetcher;
use App\Services\TryOn\TryOnSettings;
use App\Services\TryOn\TryOnStorage;

set_time_limit(0);

$opts = getopt('', ['max-runtime::', 'once']);
$maxRuntime = isset($opts['max-runtime']) ? max(5, (int) $opts['max-runtime']) : 0;
$once = isset($opts['once']);

$lockDir = $root . '/storage/cache';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lock = fopen($lockDir . '/tryon_worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Другой экземпляр уже работает — нормальная ситуация для cron.
    exit(0);
}

$shouldStop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$shouldStop): void { $shouldStop = true; });
    pcntl_signal(SIGINT, static function () use (&$shouldStop): void { $shouldStop = true; });
}

$settings = new TryOnSettings();
$cfg = $settings->config();
$fetchCfg = (array) ($cfg['result_fetch'] ?? []);
$runner = new TryOnJobRunner(
    $settings,
    new TryOnProviderRouter($settings),
    new TryOnStorage($settings->storageDir()),
    new TryOnResultFetcher((int) ($fetchCfg['max_bytes'] ?? 15728640), (int) ($fetchCfg['timeout'] ?? 20)),
    null,
    null,
    null,
    static function (string $line): void {
        // Без ПД: только public_id, провайдер, коды.
        echo '[' . date('Y-m-d H:i:s') . "] {$line}\n";
    }
);

$workerId = gethostname() . ':' . getmypid();
$interval = max(1, (int) ($cfg['worker']['poll_interval_sec'] ?? 2));
$cleanupEvery = max(60, (int) ($cfg['worker']['cleanup_every_sec'] ?? 600));
$cleanupStamp = $lockDir . '/tryon_cleanup.stamp';
$started = time();

do {
    $doCleanup = !is_file($cleanupStamp) || (time() - (int) @filemtime($cleanupStamp)) >= $cleanupEvery;
    try {
        $stats = $runner->runCycle($workerId, $doCleanup);
        if ($doCleanup) {
            @touch($cleanupStamp);
        }
        if (array_sum($stats) > 0) {
            echo '[' . date('Y-m-d H:i:s') . '] cycle ' . json_encode($stats) . "\n";
        }
    } catch (\Throwable $e) {
        echo '[' . date('Y-m-d H:i:s') . '] cycle error: ' . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
    @touch($lockDir . '/tryon_worker.heartbeat');

    if ($once || $shouldStop || ($maxRuntime > 0 && time() - $started + $interval >= $maxRuntime)) {
        break;
    }
    sleep($interval);
} while (!$shouldStop);

flock($lock, LOCK_UN);
fclose($lock);
