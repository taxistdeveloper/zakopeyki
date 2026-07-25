<?php

/**
 * Почта для сброса пароля.
 *
 * Пока smtp.password пустой — driver=log (ссылка в storage/mail.log
 * и на экране на localhost).
 * Когда будет SMTP-пароль — вставьте его ниже, driver станет smtp.
 */
$smtpPassword = getenv('MAIL_PASSWORD') ?: ''; // или: 'ваш_пароль_сюда'

return [
    'driver' => getenv('MAIL_DRIVER') ?: ($smtpPassword !== '' ? 'smtp' : 'log'),
    'from_address' => getenv('MAIL_FROM') ?: 'official.zakopeyki@gmail.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'smtp' => [
        'host' => getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => (int) (getenv('MAIL_SMTP_PORT') ?: 587),
        'encryption' => getenv('MAIL_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('MAIL_SMTP_USER') ?: 'official.zakopeyki@gmail.com',
        'password' => $smtpPassword,
        'timeout' => 20,
    ],
];
