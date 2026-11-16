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
    // Пусто, пока не созданы OAuth-клиенты в Google Cloud Console — вход через Google в API выключен
    'google_client_ids' => $list('GOOGLE_MOBILE_CLIENT_IDS', []),
];
