#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Обработка очереди обучения AI.
 *
 * php bin/ai_learning_worker.php
 * php bin/ai_learning_worker.php --once --limit=100
 */

$root = require __DIR__ . '/bootstrap.php';

use App\Services\AI\Learning\LearningPipeline;

$once = in_array('--once', $argv, true);
$limit = 50;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
}

echo '[' . date('Y-m-d H:i:s') . "] AI Learning worker start\n";

$pipeline = new LearningPipeline();

do {
    $stats = $pipeline->processPending($limit);
    echo '[' . date('Y-m-d H:i:s') . '] processed=' . $stats['processed']
        . ' failed=' . $stats['failed']
        . ' skipped=' . $stats['skipped']
        . "\n";
    if ($stats['candidates'] !== []) {
        echo '[' . date('Y-m-d H:i:s') . '] candidate prompts: ' . implode(', ', $stats['candidates']) . "\n";
        echo "  → Approve in /admin/ai (не активируются автоматически)\n";
    }
    if ($once) {
        break;
    }
    if ($stats['processed'] === 0 && $stats['failed'] === 0) {
        sleep(5);
    } else {
        usleep(200000);
    }
} while (true);

echo '[' . date('Y-m-d H:i:s') . "] Done\n";
