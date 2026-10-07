#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Phase 8 — ограниченный poll результата CDEK order create (после 202 ACCEPTED).
 *
 * Не агрессивный inline-poll. Рекомендация CDEK: первый GET через 2–3 с;
 * findCdekPendingPoll пропускает записи, обновлённые менее 3 секунд назад.
 *
 * php bin/cdek_order_poll.php
 * php bin/cdek_order_poll.php 50
 *
 * Cron (пример): every 1–2 min — php /path/bin/cdek_order_poll.php >> logs/cdek_order_poll.log 2>&1
 *
 * Webhooks — следующий этап; этот job — безопасный fallback.
 */

require __DIR__ . '/bootstrap.php';

use App\Services\Cdek\CdekOrderRegistrationService;

$limit = isset($argv[1]) ? (int) $argv[1] : 30;

$service = new CdekOrderRegistrationService();
$stats = $service->pollPending($limit);

$out = [
    'timestamp' => date('c'),
    'limit' => $limit,
] + $stats;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
