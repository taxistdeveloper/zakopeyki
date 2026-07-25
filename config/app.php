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
];
