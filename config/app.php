<?php

return [
    'name' => 'zakopeyki.kz',
    // Путь подпапки. Переопределяется автоматически из SCRIPT_NAME в index.php.
    // Пишите только путь (/zakapeiku), НЕ http://localhost/...
    'url' => '/zakopeyki',
    'timezone' => 'Asia/Almaty',
    // В проде: false. На локали можно true для подробных ошибок БД.
    'debug' => false,
    // Симуляция Halyk ePay / карта / Kaspi. В проде обязательно false — иначе любой юзер «печатает» деньги.
    // Для локальной разработки можно true.
    'allow_simulated_payments' => true,
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
