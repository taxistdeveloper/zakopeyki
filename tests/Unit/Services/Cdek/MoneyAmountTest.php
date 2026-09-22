<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cdek;

use App\Services\Cdek\MoneyAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyAmountTest extends TestCase
{
    public function testIntPassthrough(): void
    {
        $this->assertSame(1500, MoneyAmount::toTengeInt(1500));
    }

    public function testStringDecimalRoundsHalfUp(): void
    {
        $this->assertSame(1235, MoneyAmount::toTengeInt('1234.5'));
        $this->assertSame(1234, MoneyAmount::toTengeInt('1234.49'));
        $this->assertSame(100, MoneyAmount::toTengeInt('100.00'));
    }

    public function testFloatConvertedViaFixedScale(): void
    {
        // JSON number path: float → decimal string → int
        $this->assertSame(1990, MoneyAmount::toTengeInt(1989.6));
        $this->assertSame(10, MoneyAmount::toTengeInt(10.0));
    }

    public function testAuditStringPreservesScale(): void
    {
        $this->assertSame('1234.5600', MoneyAmount::toAuditString('1234.56'));
        $this->assertSame('10.0000', MoneyAmount::toAuditString(10));
    }

    public function testSumInt(): void
    {
        $this->assertSame(800, MoneyAmount::sumInt([500, 300, 0]));
    }

    public function testInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyAmount::toTengeInt('abc');
    }
}
