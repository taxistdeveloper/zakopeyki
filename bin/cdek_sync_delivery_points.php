#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Синхронизация справочника ПВЗ CDEK → cdek_delivery_points.
 *
 * php bin/cdek_sync_delivery_points.php
 * php bin/cdek_sync_delivery_points.php KZ
 * php bin/cdek_sync_delivery_points.php KZ PVZ
 *
 * Cron (пример): 0 3 * * * php /path/bin/cdek_sync_delivery_points.php >> logs/cdek_points_sync.log 2>&1
 */

require __DIR__ . '/bootstrap.php';

use App\Services\Cdek\CdekDeliveryPointsSyncService;

$country = strtoupper((string) ($argv[1] ?? 'KZ'));
$type = strtoupper((string) ($argv[2] ?? 'ALL'));

$service = new CdekDeliveryPointsSyncService();
$result = $service->syncCountry($country, $type);

$out = [
    'timestamp' => date('c'),
    'country' => $country,
    'type' => $type,
] + $result;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
