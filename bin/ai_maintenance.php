#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ежедневное обслуживание: экспорт датасета, чистка логов интентов.
 * php bin/ai_maintenance.php
 */

$root = require __DIR__ . '/bootstrap.php';

use App\Models\AiSupport;
use App\Services\AI\SelfLearningService;

echo '[' . date('Y-m-d H:i:s') . "] AI maintenance start\n";

$learning = new SelfLearningService();
$exportDir = $root . '/storage/datasets';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}

$exportPath = $exportDir . '/auto_dataset_' . date('Y_m_d') . '.jsonl';
$jsonl = $learning->exportJsonlDataset();
if ($jsonl !== '') {
    file_put_contents($exportPath, $jsonl);
    echo '[' . date('Y-m-d H:i:s') . "] Exported: {$exportPath}\n";
} else {
    echo '[' . date('Y-m-d H:i:s') . "] No new dataset rows\n";
}

$pdo = (new AiSupport())->pdo();
$deletedLogs = $pdo->exec(
    'DELETE FROM ai_intent_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
);
echo '[' . date('Y-m-d H:i:s') . "] Deleted intent logs: {$deletedLogs}\n";

$deletedJobs = $pdo->exec(
    'DELETE FROM ai_queue_jobs WHERE reserved_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
);
echo '[' . date('Y-m-d H:i:s') . "] Deleted stale queue jobs: {$deletedJobs}\n";

echo '[' . date('Y-m-d H:i:s') . "] Done\n";
