<?php

/**
 * Скопируйте в config/mail.php и вставьте пароль приложения Gmail.
 *
 * Пароль приложения: https://myaccount.google.com/apppasswords
 * (нужна 2FA на аккаунте Google)
 */
return [
    'driver' => 'smtp',
    'from_address' => 'official.zakopeyki@gmail.com',
    'from_name' => 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'official.zakopeyki@gmail.com',
        'password' => '', // ← сюда 16-символьный App Password
        'timeout' => 20,
    ],
];
