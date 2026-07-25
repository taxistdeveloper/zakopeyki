<?php

/**
 * Пример: скопируйте в config/mail.php
 * Ключ: https://resend.com/api-keys
 */
return [
    'driver' => 'resend',
    'from_address' => 'onboarding@resend.dev', // после DNS: noreply@zakopeyki.kz
    'from_name' => 'zakopeyki.kz',
    'allowed_hosts' => ['zakopeyki.kz', 'localhost', '127.0.0.1'],

    'resend' => [
        'api_key' => '', // ← re_...
    ],

    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'timeout' => 20,
    ],
];
