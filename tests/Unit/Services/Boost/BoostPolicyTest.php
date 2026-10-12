<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Boost;

use App\Services\Boost\BoostPolicy;
use PHPUnit\Framework\TestCase;

final class BoostPolicyTest extends TestCase
{
    public function testServerPriceIgnoresClientAmount(): void
    {
        $this->assertSame(390, BoostPolicy::PRICE_KZT);
        $this->assertSame(390, BoostPolicy::serverAmount(['amount' => 1, 'price' => '10']));
        $this->assertSame(390, BoostPolicy::serverAmount([]));
    }

    public function testDisabledBlocksPurchase(): void
    {
        $product = ['id' => 5, 'user_id' => 3, 'status' => 'active', 'type' => 'used'];
        $this->assertSame('disabled', BoostPolicy::purchaseBlock($product, 3, false));
    }

    public function testForeignListingIsRejected(): void
    {
        $product = ['id' => 5, 'user_id' => 9, 'status' => 'active', 'type' => 'used'];
        $this->assertSame('forbidden', BoostPolicy::purchaseBlock($product, 3, true));
    }

    public function testUnavailableStatusesAndTypesAreRejected(): void
    {
        $this->assertSame('not_found', BoostPolicy::purchaseBlock(null, 3, true));
        foreach (['sold', 'reserved', 'archived', 'out_of_stock'] as $status) {
            $this->assertSame('bad_status', BoostPolicy::purchaseBlock([
                'user_id' => 3,
                'status' => $status,
                'type' => 'new',
            ], 3, true));
        }
        $this->assertSame('bad_status', BoostPolicy::purchaseBlock([
            'user_id' => 3,
            'status' => 'active',
            'type' => 'course',
        ], 3, true));
        $this->assertSame('bad_status', BoostPolicy::purchaseBlock([
            'user_id' => 3,
            'status' => 'active',
            'type' => 'gig',
        ], 3, true));
        $this->assertNull(BoostPolicy::purchaseBlock([
            'user_id' => 3,
            'status' => 'active',
            'type' => 'service',
        ], 3, true));
    }

    public function testUnconfirmedPaymentDoesNotApply(): void
    {
        $decision = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            390,
            false,
            true,
            true,
            true
        );
        $this->assertFalse($decision['apply']);
        $this->assertSame(BoostPolicy::STATUS_FAILED, $decision['next_status']);
    }

    public function testAmountMismatchDoesNotApply(): void
    {
        $decision = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            1,
            true,
            true,
            true,
            true
        );
        $this->assertFalse($decision['apply']);
        $this->assertSame('rejected', $decision['gateway']);
        $this->assertSame(BoostPolicy::STATUS_PENDING, $decision['next_status']);
        $this->assertNull(BoostPolicy::parseExactTenge('389.50'));
    }

    public function testStoredPriceOtherThan390DoesNotApply(): void
    {
        $decision = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            100,
            100,
            true,
            true,
            true,
            false
        );
        $this->assertFalse($decision['apply']);
        $this->assertSame('error', $decision['gateway']);
    }

    public function testRepeatCallbackOnAppliedDoesNotApplyAgain(): void
    {
        $first = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            390,
            true,
            true,
            true,
            false
        );
        $this->assertTrue($first['apply']);
        $this->assertSame(BoostPolicy::STATUS_APPLIED, $first['next_status']);

        $repeat = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_APPLIED,
            390,
            390,
            true,
            true,
            true,
            false
        );
        $this->assertFalse($repeat['apply']);
        $this->assertTrue($repeat['idempotent']);
        $this->assertSame(BoostPolicy::STATUS_APPLIED, $repeat['next_status']);
    }

    public function testDisabledFeatureDoesNotApply(): void
    {
        $reject = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            390,
            true,
            false,
            true,
            true
        );
        $this->assertFalse($reject['apply']);
        $this->assertSame(BoostPolicy::STATUS_FAILED, $reject['next_status']);
        $this->assertSame('rejected', $reject['gateway']);

        $captured = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            390,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($captured['apply']);
        $this->assertSame(BoostPolicy::STATUS_PAID, $captured['next_status']);
        $this->assertTrue(BoostPolicy::keepsOpenLock(BoostPolicy::STATUS_PAID));
    }

    public function testIneligibleListingDoesNotApply(): void
    {
        $decision = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PAID,
            390,
            390,
            true,
            true,
            false,
            false
        );
        $this->assertFalse($decision['apply']);
        $this->assertSame(BoostPolicy::STATUS_APPLY_FAILED, $decision['next_status']);
        $this->assertTrue(BoostPolicy::keepsOpenLock(BoostPolicy::STATUS_APPLY_FAILED));
        $this->assertFalse(BoostPolicy::keepsOpenLock(BoostPolicy::STATUS_APPLIED));
    }

    public function testPaidOrderCanBeAppliedOnceOnRecovery(): void
    {
        $decision = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_APPLY_FAILED,
            390,
            BoostPolicy::parseExactTenge('390.00'),
            true,
            true,
            true,
            false,
            true
        );
        $this->assertTrue($decision['apply']);
        $this->assertSame(BoostPolicy::STATUS_APPLIED, $decision['next_status']);
    }

    public function testCatalogOrderUsesSortAtAndDoesNotRewriteCreatedAt(): void
    {
        $sql = BoostPolicy::catalogOrderSql();
        $this->assertStringContainsString('p.sort_at', $sql);
        $this->assertStringContainsString('p.id DESC', $sql);
        $this->assertStringNotContainsString('created_at =', $sql);
    }

    public function testPgPrefixDoesNotCollideWithExistingPayments(): void
    {
        $this->assertTrue(BoostPolicy::isBoostPgOrderId('zk-boost-15-abcdef'));
        $this->assertFalse(BoostPolicy::isBoostPgOrderId('zk-del-15-abcdef'));
        $this->assertFalse(BoostPolicy::isBoostPgOrderId('zk-15-abcdef'));
    }

    public function testSchemaKeepsOneOpenOrderAndNoCardData(): void
    {
        $sql = \App\Models\BoostOrder::schemaSql();
        $this->assertStringContainsString('uq_boost_open', $sql);
        $this->assertStringContainsString('amount INT UNSIGNED NOT NULL', $sql);
        $this->assertStringNotContainsString('created_at DATETIME', $sql);
        $this->assertDoesNotMatchRegularExpression('/\b(pan|cvv|card_number)\b/i', $sql);
        $this->assertSame('p15', BoostPolicy::openKey(15));
    }
}
