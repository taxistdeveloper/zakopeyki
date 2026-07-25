<?php

/**
 * Почта для сброса пароля.
 *
 * driver: log | mail | smtp
 *
 * Gmail: нужен «Пароль приложения» (не обычный пароль аккаунта):
 *   https://myaccount.google.com/apppasswords
 * Вставьте его в smtp.password или в переменную MAIL_PASSWORD.
 */
return [
    'driver' => getenv('MAIL_DRIVER') ?: 'smtp',
    'from_address' => getenv('MAIL_FROM') ?: 'official.zakopeyki@gmail.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'smtp' => [
        'host' => getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => (int) (getenv('MAIL_SMTP_PORT') ?: 587),
        // tls (порт 587) или ssl (порт 465)
        'encryption' => getenv('MAIL_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('MAIL_SMTP_USER') ?: 'official.zakopeyki@gmail.com',
        // Пароль приложения Gmail (16 символов без пробелов)
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'timeout' => 20,
    ],
];
