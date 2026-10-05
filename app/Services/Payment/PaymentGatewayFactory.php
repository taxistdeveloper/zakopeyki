<?php

namespace App\Services\Payment;

/**
 * Фабрика gateway по конфигу (смена эквайера без правок CDEK delivery).
 */
final class PaymentGatewayFactory
{
    public static function forDelivery(?string $code = null): PaymentGatewayInterface
    {
        $code = strtolower(trim((string) ($code ?? ($GLOBALS['appConfig']['delivery_payment_gateway'] ?? 'freedompay'))));
        return match ($code) {
            'freedompay', 'fp', '' => new FreedomPayGateway(),
            default => new FreedomPayGateway(), // неизвестный код → дефолт; GAP: другие эквайеры
        };
    }
}
