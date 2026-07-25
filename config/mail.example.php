<?php

/**
 * Пример: скопируйте в config/mail.php
 * SMTP Яндекса (по DNS домена MX → mx.yandex.net).
 */
return [
    'driver' => 'smtp',
    'from_address' => 'info@zakopeyki.kz',
    'from_name' => 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'smtp' => [
        'host' => 'smtp.yandex.ru',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'info@zakopeyki.kz',
        'password' => '', // пароль ящика или пароль приложения
        'timeout' => 20,
    ],

    'resend' => [
        'api_key' => '',
    ],
];
