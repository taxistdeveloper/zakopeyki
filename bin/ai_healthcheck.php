#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Проверка здоровья AI-поддержки (MySQL + Ollama + очередь).
 * php bin/ai_healthcheck.php
 */

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Models\AiQueue;
use App\Services\AI\OllamaClient;

$status = [
    'status' => 'OK',
    'timestamp' => date('c'),
    'checks' => [],
];

try {
    Database::connect()->query('SELECT 1');
    $status['checks']['database'] = 'OK';
} catch (Throwable $e) {
    $status['status'] = 'ERROR';
    $status['checks']['database'] = 'FAIL: ' . $e->getMessage();
}

try {
    $ollama = OllamaClient::fromConfig();
    $tags = $ollama->listModels();
    $count = count($tags['models'] ?? []);
    $status['checks']['ollama'] = "OK ({$count} models)";
} catch (Throwable $e) {
    $status['status'] = 'ERROR';
    $status['checks']['ollama'] = 'FAIL: ' . $e->getMessage();
}

try {
    $status['checks']['queue_pending'] = (new AiQueue())->pendingCount();
} catch (Throwable $e) {
    $status['status'] = 'ERROR';
    $status['checks']['queue'] = 'FAIL: ' . $e->getMessage();
}

$cfgPath = dirname(__DIR__) . '/config/ai.php';
$status['checks']['config'] = is_file($cfgPath) ? require $cfgPath : null;

header_remove();
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($status['status'] === 'OK' ? 0 : 1);
