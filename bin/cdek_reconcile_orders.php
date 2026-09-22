#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reconciliation открытых CDEK-доставок: GET /orders/{uuid} → локальный FSM.
 *
 * php bin/cdek_reconcile_orders.php
 * php bin/cdek_reconcile_orders.php 100
 *
 * Cron (пример): every 15 min — php /path/bin/cdek_reconcile_orders.php >> logs/cdek_reconcile.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use App\Services\Cdek\CdekReconciliationService;

$limit = isset($argv[1]) ? (int) $argv[1] : 50;

$service = new CdekReconciliationService();
$result = $service->reconcileOpen($limit);

$out = [
    'timestamp' => date('c'),
    'limit' => $limit,
] + $result;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
