<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TopFixedXl;

use App\Services\TopFixedXl\TopFixedXlPolicy;
use PHPUnit\Framework\TestCase;

final class TopFixedXlPolicyTest extends TestCase
{
    public function testPriceAndTermAreFixedOnTheServer(): void
    {
        $this->assertSame(2690, TopFixedXlPolicy::PRICE_KZT);
        $this->assertSame(14, TopFixedXlPolicy::DURATION_DAYS);
        $this->assertSame(1209600, TopFixedXlPolicy::DURATION_SECONDS);
        $this->assertSame(2690, TopFixedXlPolicy::serverAmount([
            'amount' => 1,
            'price' => '10',
            'days' => 30,
            'enabled' => '1',
            'category' => 'Другая',
        ]));
        $this->assertTrue(TopFixedXlPolicy::affectsRanking());
    }

    public function testPeriodIsSevenDaysFromActivation(): void
    {
        $period = TopFixedXlPolicy::period(1_700_000_000);
        $this->assertSame(1_700_000_000, $period['starts_at']);
        $this->assertSame(1_700_000_000 + 1209600, $period['ends_at']);
    }

    public function testExpiredWindowDoesNotStayPinnedFromAStaleFlag(): void
    {
        $start = '2026-10-01 12:00:00';
        $end = '2026-10-08 12:00:00';
        $during = strtotime('2026-10-05 12:00:00');
        $after = strtotime('2026-10-08 12:00:01');
        $this->assertTrue(TopFixedXlPolicy::windowActive($start, $end, $during));
        $this->assertFalse(TopFixedXlPolicy::windowActive($start, $end, $after));
        $this->assertTrue(TopFixedXlPolicy::displayActive(true, $start, $end, $during));
        $this->assertFalse(TopFixedXlPolicy::displayActive(true, $start, $end, $after));
    }

    public function testPurchaseChecksOwnerCategoryAndActiveWindow(): void
    {
        $product = $this->product();
        $this->assertSame('disabled', TopFixedXlPolicy::purchaseBlock($product, 3, false, false));
        $this->assertSame('forbidden', TopFixedXlPolicy::purchaseBlock($product, 9, true, false));
        $this->assertSame('not_found', TopFixedXlPolicy::purchaseBlock(null, 3, true, false));
        $this->assertSame('bad_status', TopFixedXlPolicy::purchaseBlock($this->product('sold'), 3, true, false));
        $this->assertSame('bad_status', TopFixedXlPolicy::purchaseBlock($this->product('active', 'course'), 3, true, false));
        $this->assertSame('bad_status', TopFixedXlPolicy::purchaseBlock($this->product('active', 'used', ''), 3, true, false));
        $this->assertSame('already_active', TopFixedXlPolicy::purchaseBlock($product, 3, true, true));
        $this->assertNull(TopFixedXlPolicy::purchaseBlock($product, 3, true, false));
    }

    public function testSuccessfulPaymentAppliesOnceAndRejectsAWrongAmount(): void
    {
        $first = TopFixedXlPolicy::decideCallback(
            TopFixedXlPolicy::STATUS_PENDING,
            2690,
            TopFixedXlPolicy::parseExactTenge('2690.00'),
            true,
            true,
            true,
            true
        );
        $this->assertTrue($first['apply']);
        $this->assertSame(TopFixedXlPolicy::STATUS_APPLIED, $first['next_status']);

        $repeat = TopFixedXlPolicy::decideCallback(TopFixedXlPolicy::STATUS_APPLIED, 2690, 2690, true, true, true, true);
        $this->assertFalse($repeat['apply']);
        $this->assertTrue($repeat['idempotent']);

        $expired = TopFixedXlPolicy::decideCallback(TopFixedXlPolicy::STATUS_EXPIRED, 2690, 2690, true, true, true, true);
        $this->assertTrue($expired['idempotent']);
        $this->assertFalse($expired['apply']);

        $failed = TopFixedXlPolicy::decideCallback(TopFixedXlPolicy::STATUS_PENDING, 2690, 2690, false, true, true, true);
        $this->assertFalse($failed['apply']);
        $this->assertSame(TopFixedXlPolicy::STATUS_FAILED, $failed['next_status']);

        $wrong = TopFixedXlPolicy::decideCallback(TopFixedXlPolicy::STATUS_PENDING, 2690, 790, true, true, true, true);
        $this->assertFalse($wrong['apply']);
        $this->assertSame('amount_mismatch', $wrong['failure_reason']);
        $this->assertSame('rejected', $wrong['gateway']);
    }

    public function testDisabledFeatureBlocksANewActivationWithoutTouchingAnAppliedWindow(): void
    {
        $pending = TopFixedXlPolicy::decideCallback(TopFixedXlPolicy::STATUS_PENDING, 2690, 2690, true, false, true, true);
        $this->assertFalse($pending['apply']);
        $this->assertSame(TopFixedXlPolicy::STATUS_FAILED, $pending['next_status']);

        $applied = TopFixedXlPolicy::decideCallback(TopFixedXlPolicy::STATUS_APPLIED, 2690, 2690, true, false, true, true);
        $this->assertTrue($applied['idempotent']);
        $this->assertSame(TopFixedXlPolicy::STATUS_APPLIED, $applied['next_status']);
        $this->assertTrue(TopFixedXlPolicy::ACTIVE_SURVIVES_DISABLE);
        $this->assertFalse(TopFixedXlPolicy::REENABLE_REACTIVATES_EXPIRED);
    }

    public function testHomeFeedPutsXlAboveOrdinaryListingsWithoutANewBlock(): void
    {
        $now = strtotime('2026-10-12 12:00:00');
        $rows = [
            $this->row(1, 'Дом и сад / Мебель', '2026-10-12 10:00:00'),
            $this->row(2, 'Авто / Легковые', '2026-10-01 10:00:00', 'Авто / Легковые'),
            $this->row(3, 'Дом и сад / Мебель', '2026-10-11 10:00:00', 'Дом и сад / Мебель'),
        ];

        $this->assertSame([3, 2, 1], TopFixedXlPolicy::orderedIds($rows, null, $now));
        $this->assertSame([3, 2, 1], TopFixedXlPolicy::orderedIds($rows, '', $now));
        $this->assertTrue(TopFixedXlPolicy::ranksOnFeed(null));
        $home = TopFixedXlPolicy::homeOrderSql();
        $this->assertStringNotContainsString('UNION', $home);
        $this->assertStringNotContainsString('?', $home);
        $this->assertStringContainsString('top_fixed_xl_orders', $home);
        $this->assertStringNotContainsString('top_fixed_orders', $home);
    }

    public function testCategoryFeedRanksXlAboveTopFixedAboveOrdinary(): void
    {
        $now = strtotime('2026-10-12 12:00:00');
        $rows = [
            $this->row(1, 'Дом и сад / Мебель', '2026-10-12 10:00:00'),
            $this->xlAndPin(2, 'Дом и сад / Мебель', '2026-10-02 10:00:00'),
            $this->row(3, 'Дом и сад / Мебель', '2026-10-11 10:00:00', 'Дом и сад / Мебель'),
            $this->pinOnly(4, 'Дом и сад / Мебель', '2026-10-09 10:00:00'),
            $this->row(5, 'Авто / Легковые', '2026-10-12 11:00:00', 'Авто / Легковые'),
        ];

        $this->assertSame([3, 2, 4, 1], TopFixedXlPolicy::orderedIds(array_slice($rows, 0, 4), 'Дом и сад / Мебель', $now));
        $this->assertSame([3, 2, 4, 1], TopFixedXlPolicy::orderedIds(array_slice($rows, 0, 4), 'Дом и сад', $now));
        $this->assertSame([5], TopFixedXlPolicy::orderedIds([$rows[4]], 'Авто / Легковые', $now));
        $home = TopFixedXlPolicy::orderedIds(array_slice($rows, 0, 4), null, $now);
        $this->assertSame([3, 2, 1, 4], $home);
        $this->assertSame($home, array_values(array_unique($home)));

        $sql = TopFixedXlPolicy::categoryOrderSql();
        $this->assertStringNotContainsString('UNION', $sql);
        $this->assertStringContainsString('top_fixed_xl_orders xl', $sql);
        $this->assertStringContainsString('top_fixed_orders tf', $sql);
        $this->assertStringContainsString('COALESCE(p.sort_at, p.created_at) DESC', $sql);
        $this->assertStringNotContainsString('top_fixed_xl_enabled', $sql);
        $this->assertSame('100!%!_off / %', TopFixedXlPolicy::filterLike('100%_off'));
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
        $filtered = TopFixedXlPolicy::orderedIds($kept, 'Дом и сад / Мебель', $now);
        $this->assertSame([11, 10, 12], $filtered);
        $this->assertNotContains(13, $filtered);
        $withMoved = TopFixedXlPolicy::orderedIds(array_merge($kept, [$moved]), 'Дом и сад / Мебель', $now);
        $this->assertSame([11, 13, 10, 12], $withMoved);
        $this->assertSame([11, 13, 10, 12], TopFixedXlPolicy::orderedIds(array_merge($kept, [$moved]), null, $now));
        $this->assertSame(1, count(array_keys($withMoved, 13, true)));

        $pages = array_chunk($filtered, 2);
        $flat = array_merge(...$pages);
        $this->assertSame($filtered, $flat);
        $this->assertSame($flat, array_values(array_unique($flat)));
    }

    public function testCatalogQueryKeepsBothFeedsAndTheOrdinaryFallback(): void
    {
        $root = dirname(__DIR__, 4);
        $product = (string) file_get_contents($root . '/app/Models/Product.php');
        $start = strpos($product, 'function allActive');
        $this->assertNotFalse($start);
        $next = strpos($product, "\n    public function ", $start + 10);
        $method = substr($product, (int) $start, ($next === false ? strlen($product) : $next) - (int) $start);
        $this->assertStringContainsString('TopFixedXlPolicy::homeOrderSql', $method);
        $this->assertStringContainsString('TopFixedXlPolicy::categoryOrderSql', $method);
        $this->assertStringContainsString('TopFixedPolicy::catalogOrderSql', $method);
        $this->assertStringContainsString('BoostPolicy::catalogOrderSql', $method);
    }

    public function testPrefixDoesNotCollideWithOtherPayments(): void
    {
        $this->assertTrue(TopFixedXlPolicy::isTopFixedXlPgOrderId('zk-topfixedxl-15-abcdef'));
        $this->assertFalse(TopFixedXlPolicy::isTopFixedXlPgOrderId('zk-topfixed-15-abcdef'));
        $this->assertFalse(TopFixedXlPolicy::isTopFixedXlPgOrderId('zk-highlight-15-abcdef'));
        $this->assertFalse(TopFixedXlPolicy::isTopFixedXlPgOrderId('zk-boost-15-abcdef'));
        $this->assertFalse(\App\Services\TopFixed\TopFixedPolicy::isTopFixedPgOrderId('zk-topfixedxl-15-abcdef'));
    }

    public function testServiceDoesNotRewriteBoostOrHighlight(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/TopFixedXl/TopFixedXlService.php');
        $this->assertStringNotContainsString('applyBoostSort', $service);
        $this->assertStringNotContainsString('HighlightService', $service);
        $this->assertStringNotContainsString('BoostService', $service);
        $this->assertStringNotContainsString('TopFixedService', $service);
        $this->assertStringNotContainsString('top_fixed_orders', $service);
        $this->assertStringContainsString('TopFixedXlPolicy::period', $service);
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
            $row['xl_category'] = $pinCategory;
            $row['xl_starts_at'] = '2026-10-10 12:00:00';
            $row['xl_ends_at'] = $pinEnds;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function pinOnly(int $id, string $category, string $sortAt): array
    {
        return [
            'id' => $id,
            'category' => $category,
            'sort_at' => $sortAt,
            'pin_category' => $category,
            'pin_starts_at' => '2026-10-10 12:00:00',
            'pin_ends_at' => '2026-10-20 12:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function xlAndPin(int $id, string $category, string $sortAt): array
    {
        $row = $this->row($id, $category, $sortAt, $category);

        return $row + [
            'pin_category' => $category,
            'pin_starts_at' => '2026-10-10 12:00:00',
            'pin_ends_at' => '2026-10-20 12:00:00',
        ];
    }
}
