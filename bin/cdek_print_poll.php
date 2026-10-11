#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ограниченный poll печатных форм CDEK (после 202 / PRINT_FORM без скачивания в webhook).
 *
 * php bin/cdek_print_poll.php
 * php bin/cdek_print_poll.php 20
 */

require __DIR__ . '/bootstrap.php';

use App\Services\Cdek\CdekPrintFormService;

$limit = isset($argv[1]) ? (int) $argv[1] : 20;
$stats = (new CdekPrintFormService())->pollPending($limit);

echo json_encode(['timestamp' => date('c'), 'limit' => $limit] + $stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
