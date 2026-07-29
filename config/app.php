<?php

return [
    'name' => 'zakopeyki.kz',
    // Путь подпапки. Переопределяется автоматически из SCRIPT_NAME в index.php.
    // Пишите только путь (/zakapeiku), НЕ http://localhost/...
    'url' => '/zakapeiku',
    'timezone' => 'Asia/Almaty',
    // В проде: false. На локали можно true для подробных ошибок БД.
    'debug' => false,
    // Симуляция карты/Kaspi. В проде обязательно false — иначе любой юзер «печатает» деньги.
    // Для локальной разработки можно true.
    'allow_simulated_payments' => true,
    'locale' => 'ru',
    'locales' => ['ru', 'kk'],
    // Заглушка «скоро открытие»: полный сайт только для админа.
    // Выключить: false. Дата открытия — для таймера (Asia/Almaty).
    'stub_mode' => true,
    'stub_opens_at' => '2026-08-30 00:00:00',
];
