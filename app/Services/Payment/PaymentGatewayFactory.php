<?php

namespace App\Services\Payment;

/**
 * Фабрика gateway по конфигу (смена эквайера без правок CDEK delivery).
 */
final class PaymentGatewayFactory
{
    public static function forDelivery(?string $code = null): PaymentGatewayInterface
    {
        $code = strtolower(trim((string) ($code ?? ($GLOBALS['appConfig']['delivery_payment_gateway'] ?? 'halyk_epay'))));
        return match ($code) {
            'halyk', 'halyk_epay', 'epay', '' => new HalykEpayGateway(),
            'freedompay', 'fp' => new FreedomPayGateway(),
            default => new HalykEpayGateway(),
        };
    }

    public static function default(): PaymentGatewayInterface
    {
        $code = strtolower(trim((string) ($GLOBALS['appConfig']['payment_gateway'] ?? 'halyk_epay')));

        return self::forDelivery($code);
    }
}
