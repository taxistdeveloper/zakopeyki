<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TopFixed;

use App\Services\TopFixed\TopFixedPolicy;
use PHPUnit\Framework\TestCase;

final class TopFixedPolicyTest extends TestCase
{
    public function testPriceAndTermAreFixedOnTheServer(): void
    {
        $this->assertSame(1490, TopFixedPolicy::PRICE_KZT);
        $this->assertSame(7, TopFixedPolicy::DURATION_DAYS);
        $this->assertSame(604800, TopFixedPolicy::DURATION_SECONDS);
        $this->assertSame(1490, TopFixedPolicy::serverAmount([
            'amount' => 1,
            'price' => '10',
            'days' => 30,
            'enabled' => '1',
            'category' => 'Другая',
        ]));
        $this->assertTrue(TopFixedPolicy::affectsRanking());
    }

    public function testPeriodIsSevenDaysFromActivation(): void
    {
        $period = TopFixedPolicy::period(1_700_000_000);
        $this->assertSame(1_700_000_000, $period['starts_at']);
        $this->assertSame(1_700_000_000 + 604800, $period['ends_at']);
    }

    public function testExpiredWindowDoesNotStayPinnedFromAStaleFlag(): void
    {
        $start = '2026-10-01 12:00:00';
        $end = '2026-10-08 12:00:00';
        $during = strtotime('2026-10-05 12:00:00');
        $after = strtotime('2026-10-08 12:00:01');
        $this->assertTrue(TopFixedPolicy::windowActive($start, $end, $during));
        $this->assertFalse(TopFixedPolicy::windowActive($start, $end, $after));
        $this->assertTrue(TopFixedPolicy::displayActive(true, $start, $end, $during));
        $this->assertFalse(TopFixedPolicy::displayActive(true, $start, $end, $after));
    }

    public function testPurchaseChecksOwnerCategoryAndActiveWindow(): void
    {
        $product = $this->product();
        $this->assertSame('disabled', TopFixedPolicy::purchaseBlock($product, 3, false, false));
        $this->assertSame('forbidden', TopFixedPolicy::purchaseBlock($product, 9, true, false));
        $this->assertSame('not_found', TopFixedPolicy::purchaseBlock(null, 3, true, false));
        $this->assertSame('bad_status', TopFixedPolicy::purchaseBlock($this->product('sold'), 3, true, false));
        $this->assertSame('bad_status', TopFixedPolicy::purchaseBlock($this->product('active', 'course'), 3, true, false));
        $this->assertSame('bad_status', TopFixedPolicy::purchaseBlock($this->product('active', 'used', ''), 3, true, false));
        $this->assertSame('already_active', TopFixedPolicy::purchaseBlock($product, 3, true, true));
        $this->assertNull(TopFixedPolicy::purchaseBlock($product, 3, true, false));
    }

    public function testSuccessfulPaymentAppliesOnceAndRejectsAWrongAmount(): void
    {
        $first = TopFixedPolicy::decideCallback(
            TopFixedPolicy::STATUS_PENDING,
            1490,
            TopFixedPolicy::parseExactTenge('1490.00'),
            true,
            true,
            true,
            true
        );
        $this->assertTrue($first['apply']);
        $this->assertSame(TopFixedPolicy::STATUS_APPLIED, $first['next_status']);

        $repeat = TopFixedPolicy::decideCallback(TopFixedPolicy::STATUS_APPLIED, 1490, 1490, true, true, true, true);
        $this->assertFalse($repeat['apply']);
        $this->assertTrue($repeat['idempotent']);

        $expired = TopFixedPolicy::decideCallback(TopFixedPolicy::STATUS_EXPIRED, 1490, 1490, true, true, true, true);
        $this->assertTrue($expired['idempotent']);
        $this->assertFalse($expired['apply']);

        $failed = TopFixedPolicy::decideCallback(TopFixedPolicy::STATUS_PENDING, 1490, 1490, false, true, true, true);
        $this->assertFalse($failed['apply']);
        $this->assertSame(TopFixedPolicy::STATUS_FAILED, $failed['next_status']);

        $wrong = TopFixedPolicy::decideCallback(TopFixedPolicy::STATUS_PENDING, 1490, 790, true, true, true, true);
        $this->assertFalse($wrong['apply']);
        $this->assertSame('amount_mismatch', $wrong['failure_reason']);
        $this->assertSame('rejected', $wrong['gateway']);
    }

    public function testDisabledFeatureBlocksANewActivationWithoutTouchingAnAppliedWindow(): void
    {
        $pending = TopFixedPolicy::decideCallback(TopFixedPolicy::STATUS_PENDING, 1490, 1490, true, false, true, true);
        $this->assertFalse($pending['apply']);
        $this->assertSame(TopFixedPolicy::STATUS_FAILED, $pending['next_status']);

        $applied = TopFixedPolicy::decideCallback(TopFixedPolicy::STATUS_APPLIED, 1490, 1490, true, false, true, true);
        $this->assertTrue($applied['idempotent']);
        $this->assertSame(TopFixedPolicy::STATUS_APPLIED, $applied['next_status']);
        $this->assertTrue(TopFixedPolicy::ACTIVE_SURVIVES_DISABLE);
        $this->assertFalse(TopFixedPolicy::REENABLE_REACTIVATES_EXPIRED);
    }

    public function testPinsSitAboveOrdinaryListingsOnlyInsideTheTargetCategory(): void
    {
        $now = strtotime('2026-10-12 12:00:00');
        $rows = [
            $this->row(1, 'Дом и сад / Мебель', '2026-10-12 10:00:00'),
            $this->row(2, 'Дом и сад / Мебель', '2026-10-01 10:00:00', 'Дом и сад / Мебель'),
            $this->row(3, 'Дом и сад / Мебель', '2026-10-11 10:00:00', 'Дом и сад / Мебель'),
            $this->row(4, 'Авто / Легковые', '2026-10-12 11:00:00', 'Авто / Легковые'),
        ];

        $this->assertSame([3, 2, 1], TopFixedPolicy::orderedIds(array_slice($rows, 0, 3), 'Дом и сад / Мебель', $now));
        $this->assertSame([3, 2, 1], TopFixedPolicy::orderedIds(array_slice($rows, 0, 3), 'Дом и сад', $now));
        $this->assertSame([1, 3, 2], TopFixedPolicy::orderedIds(array_slice($rows, 0, 3), null, $now));
        $this->assertSame([1, 3, 2], TopFixedPolicy::orderedIds(array_slice($rows, 0, 3), '', $now));
        $this->assertSame([4], TopFixedPolicy::orderedIds([$rows[3]], 'Авто / Легковые', $now));
        $this->assertFalse(TopFixedPolicy::ranksInCategory(null));
    }

    public function testFiltersSortPaginationAndCategoryChangeDoNotDuplicateOrBypass(): void
    {
        $now = strtotime('2026-10-12 12:00:00');
        $kept = [
            $this->row(10, 'Дом и сад / Мебель', '2026-10-09 10:00:00'),
            $this->row(11, 'Дом и сад / Мебель', '2026-10-08 10:00:00', 'Дом и сад / Мебель'),
            $this->row(12, 'Дом и сад / Мебель', '2026-10-07 10:00:00', 'Дом и сад / Мебель', '2026-10-01 00:00:00'),
        ];
        $moved = $this->row(13, 'Авто / Легковые', '2026-10-12 09:00:00', 'Дом и сад / Мебель');
        $filtered = TopFixedPolicy::orderedIds($kept, 'Дом и сад / Мебель', $now);
        $this->assertSame([11, 10, 12], $filtered);
        $this->assertNotContains(13, $filtered);
        $this->assertSame([11, 13, 10, 12], TopFixedPolicy::orderedIds(array_merge($kept, [$moved]), 'Дом и сад / Мебель', $now));
        $this->assertSame([13], TopFixedPolicy::orderedIds([$moved], 'Авто / Легковые', $now));

        $pages = array_chunk($filtered, 2);
        $flat = array_merge(...$pages);
        $this->assertSame($filtered, $flat);
        $this->assertSame($flat, array_values(array_unique($flat)));

        $sql = TopFixedPolicy::catalogOrderSql();
        $this->assertStringNotContainsString('UNION', $sql);
        $this->assertStringContainsString("tf.status = 'applied'", $sql);
        $this->assertStringContainsString('tf.ends_at > NOW()', $sql);
        $this->assertStringContainsString('tf.category = p.category', $sql);
        $this->assertStringContainsString('COALESCE(p.sort_at, p.created_at) DESC', $sql);
        $this->assertStringNotContainsString('top_fixed_enabled', $sql);
        $this->assertSame('100!%!_off / %', TopFixedPolicy::filterLike('100%_off'));
    }

    public function testCatalogQueryUsesThePinOnlyWhenACategoryIsSelected(): void
    {
        $root = dirname(__DIR__, 4);
        $product = (string) file_get_contents($root . '/app/Models/Product.php');
        $start = strpos($product, 'function allActive');
        $this->assertNotFalse($start);
        $next = strpos($product, "\n    public function ", $start + 10);
        $method = substr($product, (int) $start, ($next === false ? strlen($product) : $next) - (int) $start);
        $this->assertStringContainsString('TopFixedPolicy::ranksInCategory', $method);
        $this->assertStringContainsString('TopFixedPolicy::catalogOrderSql', $method);
        $this->assertStringContainsString('BoostPolicy::catalogOrderSql', $method);
    }

    public function testPrefixDoesNotCollideWithOtherPayments(): void
    {
        $this->assertTrue(TopFixedPolicy::isTopFixedPgOrderId('zk-topfixed-15-abcdef'));
        $this->assertFalse(TopFixedPolicy::isTopFixedPgOrderId('zk-highlight-15-abcdef'));
        $this->assertFalse(TopFixedPolicy::isTopFixedPgOrderId('zk-boost-15-abcdef'));
    }

    public function testServiceDoesNotRewriteBoostOrHighlight(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/TopFixed/TopFixedService.php');
        $this->assertStringNotContainsString('applyBoostSort', $service);
        $this->assertStringNotContainsString('HighlightService', $service);
        $this->assertStringNotContainsString('BoostService', $service);
        $this->assertStringContainsString('TopFixedPolicy::period', $service);
        $this->assertStringContainsString('PRICE_KZT', $service);
        $this->assertStringContainsString("['category']", $service);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $status = 'active', string $type = 'used', string $category = 'Дом и сад / Мебель'): array
    {
        return [
            'id' => 15,
            'user_id' => 3,
            'status' => $status,
            'type' => $type,
            'category' => $category,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $category, string $sortAt, ?string $pinCategory = null, string $pinEnds = '2026-10-17 12:00:00'): array
    {
        $row = [
            'id' => $id,
            'category' => $category,
            'sort_at' => $sortAt,
        ];
        if ($pinCategory !== null) {
            $row['pin_category'] = $pinCategory;
            $row['pin_starts_at'] = '2026-10-10 12:00:00';
            $row['pin_ends_at'] = $pinEnds;
        }

        return $row;
    }
}
