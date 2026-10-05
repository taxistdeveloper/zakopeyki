<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Delivery;

use App\Models\DeliveryPayment;
use App\Services\Delivery\DeliveryPaymentService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 — payment gate / canCreateCdekOrder (no live gateway).
 * Full suite: php tools/run_delivery_payment_phase7_tests.php
 */
final class DeliveryPaymentPhase7Test extends TestCase
{
    public function testAssertQuotePayableUsesServerAmount(): void
    {
        $svc = new DeliveryPaymentService();
        $r = $svc->assertQuotePayable([
            'id' => 1,
            'shipping_version' => 1,
            'sender' => ['city' => 'Алматы'],
            'recipient' => ['name' => 'B', 'phone' => '+77001112233', 'city' => 'Астана'],
            'shipment' => ['billed_gross_weight' => 1],
            'selected_quote' => [
                'id' => 9,
                'total_amount' => 3330,
                'delivery_amount_to_pay' => 3330,
                'currency' => 'KZT',
                'quote_status' => 'active',
                'is_selected' => 1,
                'valid_until' => date('Y-m-d H:i:s', time() + 600),
                'shipping_version' => 1,
            ],
        ]);
        $this->assertTrue($r['ok'] ?? false);
        $this->assertSame(3330, $r['amount'] ?? 0);
        $this->assertSame('KZT', $r['currency'] ?? null);
    }

    public function testExpiredQuoteRejected(): void
    {
        $svc = new DeliveryPaymentService();
        $r = $svc->assertQuotePayable([
            'id' => 1,
            'shipping_version' => 1,
            'sender' => ['city' => 'Алматы'],
            'recipient' => ['name' => 'B', 'phone' => '+77001112233', 'city' => 'Астана'],
            'shipment' => ['billed_gross_weight' => 1],
            'selected_quote' => [
                'id' => 9,
                'total_amount' => 1000,
                'currency' => 'KZT',
                'quote_status' => 'active',
                'valid_until' => date('Y-m-d H:i:s', time() - 10),
                'shipping_version' => 1,
            ],
        ]);
        $this->assertFalse($r['ok'] ?? true);
        $this->assertSame('quote_expired', $r['error_code'] ?? null);
    }

    public function testCanCreateCdekOrderFalseWithoutDelivery(): void
    {
        $svc = new DeliveryPaymentService();
        $r = $svc->canCreateCdekOrder(0);
        $this->assertFalse($r['ok'] ?? true);
    }

    public function testPaymentStatusConstants(): void
    {
        $this->assertSame('paid', DeliveryPayment::STATUS_PAID);
        $this->assertSame('pending', DeliveryPayment::STATUS_PENDING);
        $this->assertSame('refunded', DeliveryPayment::STATUS_REFUNDED);
        $this->assertSame('cancelled', DeliveryPayment::STATUS_CANCELLED);
    }
}
