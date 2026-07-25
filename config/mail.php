<?php

/**
 * Почта через Resend API (без хостинга и без Gmail).
 *
 * 1. Регистрация: https://resend.com
 * 2. API Keys → Create → вставьте ключ в resend.api_key ниже
 * 3. Для тестов From: onboarding@resend.dev
 *    Для прода: Domains → Add zakopeyki.kz → DNS (SPF/DKIM) → From: noreply@zakopeyki.kz
 *
 * driver: resend | log | smtp | mail
 */
$resendKey = getenv('RESEND_API_KEY') ?: ''; // или 're_xxxxxxxx'

return [
    'driver' => getenv('MAIL_DRIVER') ?: ($resendKey !== '' ? 'resend' : 'log'),
    // Пока домен не подтверждён в Resend — onboarding@resend.dev
    // После подтверждения zakopeyki.kz: noreply@zakopeyki.kz
    'from_address' => getenv('MAIL_FROM') ?: 'onboarding@resend.dev',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'resend' => [
        'api_key' => $resendKey,
    ],

    // запасной вариант, если понадобится SMTP
    'smtp' => [
        'host' => getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com',
        'port' => (int) (getenv('MAIL_SMTP_PORT') ?: 587),
        'encryption' => getenv('MAIL_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('MAIL_SMTP_USER') ?: '',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'timeout' => 20,
    ],
];
