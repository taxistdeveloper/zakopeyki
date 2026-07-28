#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Фоновый воркер очереди AI-поддержки.
 *
 * php bin/ai_worker.php
 *
 * Для MAMP (Windows) обычно достаточно process_mode=sync в config/ai.php.
 * В продакшене: process_mode=async + этот воркер под Supervisor.
 */

$root = require __DIR__ . '/bootstrap.php';

use App\Jobs\ProcessAiMessageJob;
use App\Models\AiQueue;
use App\Services\AI\IntentClassifier;
use App\Services\AI\OllamaClient;
use App\Services\AI\RagEngine;
use App\Services\AI\SelfLearningService;
use App\Services\AI\SupportAiService;
use App\Models\AiSupport;

set_time_limit(0);
ini_set('memory_limit', '512M');

echo '[' . date('Y-m-d H:i:s') . "] AI Worker zakopeyki.kz started\n";

$queue = new AiQueue();
$support = new AiSupport();
$ollama = OllamaClient::fromConfig();
$aiService = new SupportAiService(
    $ollama,
    new RagEngine(),
    new IntentClassifier($ollama),
    new SelfLearningService($support),
    $support
);
$jobHandler = new ProcessAiMessageJob($aiService, $support);

$maxAttempts = 3;
$shouldStop = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$shouldStop): void {
        $shouldStop = true;
        echo '[' . date('Y-m-d H:i:s') . "] SIGTERM — stopping\n";
    });
    pcntl_signal(SIGINT, static function () use (&$shouldStop): void {
        $shouldStop = true;
        echo '[' . date('Y-m-d H:i:s') . "] SIGINT — stopping\n";
    });
}

while (!$shouldStop) {
    try {
        $job = $queue->pop('default');

        if ($job === null) {
            usleep(500000);
            continue;
        }

        $jobId = (int) $job['id'];
        $payload = $job['payload'];
        $jobClass = (string) ($payload['job_class'] ?? '');

        echo '[' . date('Y-m-d H:i:s') . "] Job #{$jobId} {$jobClass}\n";

        if ($jobClass === ProcessAiMessageJob::class) {
            $jobHandler->handle($payload['payload'] ?? []);
            $queue->delete($jobId);
            echo '[' . date('Y-m-d H:i:s') . "] Job #{$jobId} done\n";
        } else {
            echo '[' . date('Y-m-d H:i:s') . "] Unknown job class, deleting\n";
            $queue->delete($jobId);
        }
    } catch (Throwable $e) {
        echo '[' . date('Y-m-d H:i:s') . '] Error: ' . $e->getMessage() . "\n";

        if (isset($job) && is_array($job)) {
            $attempts = (int) ($job['attempts'] ?? 0);
            if ($attempts >= $maxAttempts) {
                $queue->delete((int) $job['id']);
                echo '[' . date('Y-m-d H:i:s') . "] Job #{$job['id']} dropped after {$maxAttempts} attempts\n";
            } else {
                $queue->release((int) $job['id'], 10);
            }
        }

        sleep(2);
    }
}

echo '[' . date('Y-m-d H:i:s') . "] Worker stopped\n";
