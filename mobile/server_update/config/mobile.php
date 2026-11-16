<?php

/*
 * Мобильное приложение: публичные идентификаторы (НЕ секреты).
 * Могут быть переопределены через окружение.
 *
 *   APPLE_BUNDLE_IDS          bundle id iOS-приложения (aud в токене Sign in with Apple), через запятую
 *   GOOGLE_MOBILE_CLIENT_IDS  OAuth client id приложений Google Sign-In (iOS/Android/web), через запятую
 */

$list = static function (string $env, array $default): array {
    $raw = trim((string) (getenv($env) ?: ''));
    if ($raw === '') {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw))));
};

return [
    'apple_bundle_ids' => $list('APPLE_BUNDLE_IDS', ['kz.zakopeyki.zakopeyki']),
    // aud ID-токена из приложения: web-клиент сайта (serverClientId в приложении) и Android-клиент.
    // Публичные идентификаторы, не секреты. Пустой список выключает вход через Google в API.
    'google_client_ids' => $list('GOOGLE_MOBILE_CLIENT_IDS', [
        '341406337487-94kl58qqor20ksgm0gut87hpe477gmrv.apps.googleusercontent.com',
        '341406337487-pcrljs5ur0o1luaaopdn7kh8aiu0e08q.apps.googleusercontent.com',
    ]),
];
