#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ежедневное обслуживание: экспорт датасета, learning pipeline, чистка логов.
 * php bin/ai_maintenance.php
 */

$root = require __DIR__ . '/bootstrap.php';

use App\Models\AiSupport;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Learning\LearningPipeline;
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

$pipeStats = (new LearningPipeline())->processPending(100);
echo '[' . date('Y-m-d H:i:s') . '] Learning pipeline: processed=' . $pipeStats['processed']
    . ' failed=' . $pipeStats['failed']
    . ' skipped=' . $pipeStats['skipped'] . "\n";
if ($pipeStats['candidates'] !== []) {
    echo '[' . date('Y-m-d H:i:s') . '] Candidate prompts (need admin approve): '
        . implode(', ', $pipeStats['candidates']) . "\n";
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

$learnDays = (int) AiConfig::get('retention.learning_events_days', 365);
$deletedLearn = $pdo->exec(
    "DELETE FROM ai_learning_events
     WHERE status IN ('processed','skipped','failed')
       AND created_at < DATE_SUB(NOW(), INTERVAL {$learnDays} DAY)"
);
echo '[' . date('Y-m-d H:i:s') . "] Deleted old learning events: {$deletedLearn}\n";

try {
    $pdo->exec(
        'DELETE FROM ai_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 DAY)'
    );
} catch (Throwable $e) {
    // table may not exist yet
}

echo '[' . date('Y-m-d H:i:s') . "] Done\n";
