<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\LocalCheckout;
use PHPUnit\Framework\TestCase;

final class LocalCheckoutTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = $GLOBALS['appConfig'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['appConfig'] = $this->config;
    }

    public function testMissingGatewayIsReplacedOnlyWhenTheLocalFlagIsOn(): void
    {
        $GLOBALS['appConfig']['allow_simulated_payments'] = false;
        $this->assertFalse(LocalCheckout::substituteForGateway(false));
        $this->assertFalse(LocalCheckout::substituteForGateway(true));

        $GLOBALS['appConfig']['allow_simulated_payments'] = true;
        $this->assertTrue(LocalCheckout::substituteForGateway(false));
        $this->assertFalse(LocalCheckout::substituteForGateway(true));
    }
}
