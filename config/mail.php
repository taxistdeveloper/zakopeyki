<?php

/**
 * Почта для сброса пароля.
 *
 * driver: log | mail | smtp
 *
 * Gmail: «Пароль приложения» (не обычный пароль):
 *   https://myaccount.google.com/apppasswords
 * Вставьте в smtp.password или MAIL_PASSWORD.
 */
return [
    'driver' => getenv('MAIL_DRIVER') ?: 'smtp',
    'from_address' => getenv('MAIL_FROM') ?: 'official.zakopeyki@gmail.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'smtp' => [
        'host' => getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => (int) (getenv('MAIL_SMTP_PORT') ?: 587),
        'encryption' => getenv('MAIL_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('MAIL_SMTP_USER') ?: 'official.zakopeyki@gmail.com',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'timeout' => 20,
    ],
];
