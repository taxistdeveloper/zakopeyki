<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Локальная оплата без эквайера. Включается только флагом allow_simulated_payments
 * и только пока FreedomPay не настроен. В проде флаг должен быть false.
 */
final class LocalCheckout
{
    public static function allowed(): bool
    {
        return (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);
    }

    public static function substituteForGateway(bool $gatewayConfigured): bool
    {
        return !$gatewayConfigured && self::allowed();
    }
}
