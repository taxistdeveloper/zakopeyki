<?php

/*
 * M-01 / B0.2: опасные флаги берутся из окружения с БЕЗОПАСНЫМИ значениями по умолчанию.
 *
 * Переменные окружения (необязательны):
 *   APP_ENV                       production | staging | local   (по умолчанию production)
 *   APP_DEBUG                     1/0                            (по умолчанию 0)
 *   APP_ALLOW_SIMULATED_PAYMENTS  1/0                            (по умолчанию 0; в production всегда 0)
 *
 * Для локальной разработки без env можно создать config/app.local.php (в .gitignore),
 * который возвращает массив переопределений, например:
 *   <?php return ['env' => 'local', 'allow_simulated_payments' => true];
 */

$envBool = static function (string $name, bool $default): bool {
    $v = getenv($name);
    if ($v === false || $v === '') {
        return $default;
    }

    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
};

$config = [
    'name' => 'zakopeyki.kz',
    // Путь подпапки. Переопределяется автоматически из SCRIPT_NAME в index.php.
    // Пишите только путь (/zakapeiku), НЕ http://localhost/...
    'url' => '/zakopeyki',
    'timezone' => 'Asia/Almaty',
    // production | staging | local
    'env' => strtolower((string) (getenv('APP_ENV') ?: 'production')),
    // В проде: false. На локали можно true для подробных ошибок БД.
    'debug' => $envBool('APP_DEBUG', false),
    // Симуляция Halyk ePay / карта / Kaspi. В проде обязательно false — иначе любой юзер «печатает» деньги.
    'allow_simulated_payments' => $envBool('APP_ALLOW_SIMULATED_PAYMENTS', false),
    // Эквайер по умолчанию: имитация Halyk ePay (не FreedomPay).
    'payment_gateway' => 'halyk_epay',
    'delivery_payment_gateway' => 'halyk_epay',
    'locale' => 'ru',
    'locales' => ['ru', 'kk'],
    // Заглушка «скоро открытие»: полный сайт только для админа.
    // Можно переключить в админке (/admin) — значение пишется в таблицу settings.
    // Fallback, если в БД ещё нет ключа stub_mode: true = закрыт.
    'stub_mode' => true,
    'stub_opens_at' => '2026-11-30 00:00:00',
];

$localOverrides = __DIR__ . '/app.local.php';
if (is_file($localOverrides)) {
    $overrides = require $localOverrides;
    if (is_array($overrides)) {
        $config = array_replace($config, $overrides);
    }
}
// Явно заданный APP_ENV важнее локального файла
if ((string) (getenv('APP_ENV') ?: '') !== '') {
    $config['env'] = strtolower((string) getenv('APP_ENV'));
}

// Жёсткий запрет симуляции платежей и debug в production, даже если переопределено.
if (($config['env'] ?? 'production') === 'production') {
    if (!empty($config['allow_simulated_payments'])) {
        error_log('[security] allow_simulated_payments forced to false in production');
    }
    $config['allow_simulated_payments'] = false;
    $config['debug'] = false;
}

return $config;
