<?php

declare(strict_types=1);

/**
 * AI Virtual Try-On (docs/tryon). Секреты — только переменные окружения.
 * Бизнес-настройки (вкл/выкл, лимиты, бюджет) — таблица settings, ключи tryon_*.
 */

$env = static function (string $name, string $default = ''): string {
    $v = getenv($name);
    return ($v === false || trim($v) === '') ? $default : trim($v);
};

return [
    // Каталог хранения фото/результатов (вне webroot, закрыт .htaccess).
    'storage_dir' => dirname(__DIR__) . '/storage/tryon',

    // HMAC-секрет для externalUserId (передаётся провайдеру вместо user_id).
    'user_hash_secret' => $env('TRYON_USER_HASH_SECRET'),

    // FakeTryOnProvider (тестовые результаты с плашкой «ТЕСТ — НЕ AI»). На проде не задавать.
    'allow_fake' => $env('TRYON_ALLOW_FAKE') === '1',

    'upload' => [
        'max_bytes' => 10 * 1024 * 1024,
        'min_side_px' => 512,
        'max_side_px' => 6000,
        'max_pixels' => 36_000_000,
        'store_max_side_px' => 2048,
        'jpeg_quality' => 90,
    ],

    'worker' => [
        'poll_interval_sec' => 2,
        'submit_max_attempts' => 3,
        'queue_timeout_sec' => 600,
        'processing_timeout_sec' => 300,
        'lock_ttl_sec' => 60,
        'cleanup_every_sec' => 600,
    ],

    'providers' => [
        'genlook' => [
            'api_key' => $env('TRYON_GENLOOK_API_KEY'),
            'base_url' => 'https://api.genlook.app/tryon/v1',
            // mock=true: бесплатный режим Genlook, возвращает исходное фото (is_test=1).
            'mock' => $env('TRYON_GENLOOK_MOCK') === '1',
            'cost_micros' => 40_000, // $0.04 PAYG, https://genlook.app/developers (2026-11-09)
            'keep_for_days' => 1,
            'types' => ['upper', 'lower', 'dress'],
            // Хосты, с которых разрешено скачивать resultImageUrl. Уточняется в PHASE 2 по факту.
            'result_hosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', $env('TRYON_GENLOOK_RESULT_HOSTS', 'genlook.app,api.genlook.app,storage.googleapis.com'))
            ))),
            'timeout' => 60,
        ],
    ],

    'result_fetch' => [
        'max_bytes' => 15 * 1024 * 1024,
        'timeout' => 20,
    ],
];
