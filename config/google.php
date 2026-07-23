<?php

/**
 * Google OAuth 2.0
 *
 * 1. Создайте OAuth-клиент: https://console.cloud.google.com/apis/credentials
 * 2. Тип: «Веб-приложение»
 * 3. Authorized redirect URI (локально):
 *    http://localhost/zakapeiku/auth/google/callback
 * 4. Вставьте Client ID и Client Secret ниже.
 */
return [
    // Замените плейсхолдеры на реальные значения из Google Cloud Console
    'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
    // Если пусто — собирается автоматически: http(s)://{host}/zakapeiku/auth/google/callback
    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: '',
];
