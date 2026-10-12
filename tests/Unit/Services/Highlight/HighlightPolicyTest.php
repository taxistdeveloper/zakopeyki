<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Highlight;

use App\Services\Highlight\HighlightPolicy;
use PHPUnit\Framework\TestCase;

final class HighlightPolicyTest extends TestCase
{
    public function testPriceAndTermAreFixedOnTheServer(): void
    {
        $this->assertSame(790, HighlightPolicy::PRICE_KZT);
        $this->assertSame(7, HighlightPolicy::DURATION_DAYS);
        $this->assertSame(604800, HighlightPolicy::DURATION_SECONDS);
        $this->assertSame(790, HighlightPolicy::serverAmount(['amount' => 1, 'price' => '10', 'days' => 30, 'enabled' => '1']));
        $this->assertFalse(HighlightPolicy::affectsRanking());
    }

    public function testPeriodIsSevenDaysFromActivation(): void
    {
        $period = HighlightPolicy::period(1_700_000_000);
        $this->assertSame(1_700_000_000, $period['starts_at']);
        $this->assertSame(1_700_000_000 + 604800, $period['ends_at']);
    }

    public function testExpiredWindowDoesNotStayVisibleFromAStaleFlag(): void
    {
        $start = '2026-10-01 12:00:00';
        $end = '2026-10-08 12:00:00';
        $during = strtotime('2026-10-05 12:00:00');
        $after = strtotime('2026-10-08 12:00:01');
        $this->assertTrue(HighlightPolicy::windowActive($start, $end, $during));
        $this->assertFalse(HighlightPolicy::windowActive($start, $end, $after));
        $this->assertTrue(HighlightPolicy::displayActive(true, $start, $end, $during));
        $this->assertFalse(HighlightPolicy::displayActive(true, $start, $end, $after));
        $this->assertFalse(HighlightPolicy::windowActive(null, $end, $during));
        $this->assertFalse(HighlightPolicy::windowActive($start, $start, $during));
    }

    public function testPurchaseChecksOwnerStatusAndActiveWindow(): void
    {
        $product = $this->product();
        $this->assertSame('disabled', HighlightPolicy::purchaseBlock($product, 3, false, false));
        $this->assertSame('forbidden', HighlightPolicy::purchaseBlock($product, 9, true, false));
        $this->assertSame('not_found', HighlightPolicy::purchaseBlock(null, 3, true, false));
        $this->assertSame('bad_status', HighlightPolicy::purchaseBlock($this->product('sold'), 3, true, false));
        $this->assertSame('bad_status', HighlightPolicy::purchaseBlock($this->product('active', 'course'), 3, true, false));
        $this->assertSame('already_active', HighlightPolicy::purchaseBlock($product, 3, true, true));
        $this->assertNull(HighlightPolicy::purchaseBlock($product, 3, true, false));
    }

    public function testSuccessfulPaymentAppliesOnce(): void
    {
        $first = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_PENDING,
            790,
            HighlightPolicy::parseExactTenge('790.00'),
            true,
            true,
            true,
            true
        );
        $this->assertTrue($first['apply']);
        $this->assertSame(HighlightPolicy::STATUS_APPLIED, $first['next_status']);

        $repeat = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_APPLIED,
            790,
            790,
            true,
            true,
            true,
            true
        );
        $this->assertFalse($repeat['apply']);
        $this->assertTrue($repeat['idempotent']);

        $expired = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_EXPIRED,
            790,
            790,
            true,
            true,
            true,
            true
        );
        $this->assertFalse($expired['apply']);
        $this->assertTrue($expired['idempotent']);
    }

    public function testUnpaidAndRejectedPaymentsDoNotApply(): void
    {
        $failed = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_PENDING,
            790,
            790,
            false,
            true,
            true,
            true
        );
        $this->assertFalse($failed['apply']);
        $this->assertSame(HighlightPolicy::STATUS_FAILED, $failed['next_status']);

        $wrong = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_PENDING,
            790,
            390,
            true,
            true,
            true,
            true
        );
        $this->assertFalse($wrong['apply']);
        $this->assertSame('amount_mismatch', $wrong['failure_reason']);
        $this->assertSame('rejected', $wrong['gateway']);
        $this->assertNull(HighlightPolicy::parseExactTenge('789.50'));
    }

    public function testDisabledFeatureDoesNotStartANewWindow(): void
    {
        $reject = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_PENDING,
            790,
            790,
            true,
            false,
            true,
            true
        );
        $this->assertFalse($reject['apply']);
        $this->assertSame(HighlightPolicy::STATUS_FAILED, $reject['next_status']);
        $this->assertSame('disabled', $reject['failure_reason']);

        $captured = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_PENDING,
            790,
            790,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($captured['apply']);
        $this->assertSame(HighlightPolicy::STATUS_PAID, $captured['next_status']);

        $kept = HighlightPolicy::decideCallback(
            HighlightPolicy::STATUS_APPLIED,
            790,
            790,
            true,
            false,
            true,
            false
        );
        $this->assertTrue($kept['idempotent']);
        $this->assertSame(HighlightPolicy::STATUS_APPLIED, $kept['next_status']);
        $this->assertTrue(HighlightPolicy::ACTIVE_SURVIVES_DISABLE);
        $this->assertFalse(HighlightPolicy::REENABLE_REACTIVATES_EXPIRED);
    }

    public function testPrefixDoesNotCollideWithBoost(): void
    {
        $this->assertTrue(HighlightPolicy::isHighlightPgOrderId('zk-highlight-15-abcdef'));
        $this->assertFalse(HighlightPolicy::isHighlightPgOrderId('zk-boost-15-abcdef'));
        $this->assertFalse(HighlightPolicy::isHighlightPgOrderId('zk-15-abcdef'));
    }

    public function testServiceDoesNotTouchListingRank(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/Highlight/HighlightService.php');
        $this->assertStringNotContainsString('sort_at', $service);
        $this->assertStringNotContainsString('applyBoostSort', $service);
        $this->assertStringNotContainsString('catalogOrderSql', $service);
        $this->assertStringContainsString('HighlightPolicy::period', $service);
        $this->assertStringContainsString('PRICE_KZT', $service);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $status = 'active', string $type = 'used'): array
    {
        return [
            'id' => 15,
            'user_id' => 3,
            'status' => $status,
            'type' => $type,
        ];
    }
}
