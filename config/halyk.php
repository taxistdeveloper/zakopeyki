<?php

/**
 * Halyk ePay (тестовые ключи из документации).
 * @see https://epayment.kz/docs/cardverificationpaymentForm
 * @see https://epayment.kz/docs/platezhnaya-stranica
 *
 * В проде замените на ключи из ЛК и поставьте env=prod.
 */
return [
    // test | prod
    'env' => getenv('HALYK_ENV') ?: 'test',

    // Тестовые credentials (docs ePay)
    'client_id' => getenv('HALYK_CLIENT_ID') ?: 'test',
    'client_secret' => getenv('HALYK_CLIENT_SECRET') ?: 'yF587AV9Ms94qN2QShFzVR3vFnWkhjbAK3sG',
    'terminal' => getenv('HALYK_TERMINAL_ID') ?: '67e34d63-102f-4bd1-898e-370781d0074d',

    'oauth_url_test' => 'https://test-epay-oauth.epayment.kz/oauth2/token',
    'oauth_url_prod' => 'https://epay-oauth.homebank.kz/oauth2/token',
    'js_url_test' => 'https://test-epay.epayment.kz/payform/payment-api.js',
    'js_url_prod' => 'https://epay.homebank.kz/payform/payment-api.js',

    'currency' => 'KZT',
    'language' => 'rus',
];
