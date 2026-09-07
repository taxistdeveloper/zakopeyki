#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Проверка тестовой интеграции СДЭК (oauth + города + калькулятор).
 * php bin/cdek_healthcheck.php
 *
 * @see https://apidoc.cdek.ru/#tag/common/Vvedenie
 */

require __DIR__ . '/bootstrap.php';

use App\Services\Cdek\Client;

$client = new Client();
$out = [
    'status' => 'OK',
    'timestamp' => date('c'),
    'configured' => $client->isConfigured(),
    'test_mode' => $client->isTestMode(),
    'api_url' => $client->config()['api_url'] ?? null,
    'checks' => [],
];

if (!$client->isConfigured()) {
    $out['status'] = 'ERROR';
    $out['checks']['config'] = 'FAIL: скопируйте config/cdek.php.example → config/cdek.php';
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$auth = $client->authorize(true);
$out['checks']['oauth'] = $auth['ok']
    ? 'OK (token received)'
    : ('FAIL: ' . ($auth['error'] ?? 'unknown'));
if (!$auth['ok']) {
    $out['status'] = 'ERROR';
    $out['curl_error'] = $client->lastCurlError();
    $out['raw'] = $auth['raw'] ?? null;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$from = $client->findCityCode('Алматы', 'KZ');
$to = $client->findCityCode('Астана', 'KZ');
$out['checks']['city_almaty'] = $from ? ('OK code=' . $from['code']) : 'FAIL';
$out['checks']['city_astana'] = $to ? ('OK code=' . $to['code']) : 'FAIL';
if (!$from || !$to) {
    $out['status'] = 'ERROR';
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$calc = $client->post('/calculator/tarifflist', [
    'type' => 1,
    'currency' => (int) ($client->config()['currency'] ?? 2),
    'lang' => 'rus',
    'from_location' => ['code' => $from['code']],
    'to_location' => ['code' => $to['code']],
    'packages' => [[
        'weight' => 1000,
        'length' => 20,
        'width' => 15,
        'height' => 10,
    ]],
]);

if (!$calc['ok']) {
    $out['status'] = 'ERROR';
    $out['checks']['tarifflist'] = 'FAIL: ' . ($calc['error'] ?? 'unknown');
    $out['raw'] = $calc['data'] ?? $calc['body'] ?? null;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$tariffs = $calc['data']['tariff_codes'] ?? [];
$count = is_array($tariffs) ? count($tariffs) : 0;
$out['checks']['tarifflist'] = 'OK (' . $count . ' tariffs)';
if ($count > 0 && is_array($tariffs[0] ?? null)) {
    $sample = $tariffs[0];
    $out['sample_tariff'] = [
        'tariff_code' => $sample['tariff_code'] ?? null,
        'tariff_name' => $sample['tariff_name'] ?? null,
        'delivery_sum' => $sample['delivery_sum'] ?? null,
        'delivery_mode' => $sample['delivery_mode'] ?? null,
        'period_min' => $sample['period_min'] ?? null,
        'period_max' => $sample['period_max'] ?? null,
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($out['status'] === 'OK' ? 0 : 1);
