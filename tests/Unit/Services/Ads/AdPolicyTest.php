<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ads;

use App\Services\Ads\AdPolicy;
use PHPUnit\Framework\TestCase;

final class AdPolicyTest extends TestCase
{
    public function testTariffsIgnoreTheClient(): void
    {
        $this->assertSame(1200, AdPolicy::CPM_KZT[AdPolicy::FORMAT_HERO]);
        $this->assertSame(800, AdPolicy::CPM_KZT[AdPolicy::FORMAT_CATEGORY]);
        $this->assertSame(600, AdPolicy::CPM_KZT[AdPolicy::FORMAT_SIDEBAR]);
        $this->assertSame(19900, AdPolicy::EXCLUSIVE_WEEK_KZT);

        $hero = AdPolicy::serverQuote(AdPolicy::FORMAT_HERO, 3, ['amount' => 1, 'price' => 10, 'cpm' => 1]);
        $this->assertSame(3600, $hero['amount']);
        $this->assertSame(3000, $hero['impressions']);

        $side = AdPolicy::quote(AdPolicy::FORMAT_SIDEBAR, 1);
        $this->assertSame(600, $side['amount']);
        $this->assertSame(1000, $side['impressions']);

        $week = AdPolicy::quote(AdPolicy::FORMAT_EXCLUSIVE, 2);
        $this->assertSame(39800, $week['amount']);
        $this->assertSame(0, $week['impressions']);
        $this->assertSame(AdPolicy::WEEK_SECONDS * 2, $week['seconds']);

        $this->assertNull(AdPolicy::quote(AdPolicy::FORMAT_HERO, 0));
        $this->assertNull(AdPolicy::quote(AdPolicy::FORMAT_CATEGORY, AdPolicy::MAX_THOUSANDS + 1));
    }

    public function testSwitchesAreIndependent(): void
    {
        $keys = array_values(AdPolicy::SETTING_KEYS);
        $this->assertCount(4, array_unique($keys));
        foreach (AdPolicy::UNRELATED_CONTROLS as $other) {
            $this->assertNotContains($other, $keys);
        }
        $this->assertFalse(AdPolicy::resolveEnabled(null));
        $this->assertFalse(AdPolicy::resolveEnabled('0'));
        $this->assertTrue(AdPolicy::resolveEnabled('1'));
        $this->assertTrue(AdPolicy::ACTIVE_SURVIVES_DISABLE);
        $this->assertFalse(AdPolicy::REENABLE_REACTIVATES_COMPLETED);
        $this->assertSame('no_apply', AdPolicy::PAYMENT_AFTER_DISABLE);
    }

    public function testImpressionIsCountedOnceAndStopsAtThePaidVolume(): void
    {
        $first = AdPolicy::decideCount(AdPolicy::IMPRESSION_ISSUED, AdPolicy::STATUS_ACTIVE, 999, 1000, null, time());
        $this->assertTrue($first['count']);
        $this->assertSame(AdPolicy::STATUS_COMPLETED, $first['next_status']);

        $again = AdPolicy::decideCount(AdPolicy::IMPRESSION_COUNTED, AdPolicy::STATUS_ACTIVE, 1000, 1000, null, time());
        $this->assertFalse($again['count']);
        $this->assertTrue($again['idempotent']);

        $over = AdPolicy::decideCount(AdPolicy::IMPRESSION_ISSUED, AdPolicy::STATUS_ACTIVE, 1000, 1000, null, time());
        $this->assertFalse($over['count']);
        $this->assertSame('exhausted', $over['reason']);
        $this->assertSame(AdPolicy::STATUS_COMPLETED, $over['next_status']);

        $hidden = AdPolicy::decideCount(AdPolicy::IMPRESSION_ISSUED, AdPolicy::STATUS_PAUSED, 1, 1000, null, time());
        $this->assertFalse($hidden['count']);
        $this->assertSame(AdPolicy::IMPRESSION_SKIPPED, $hidden['next_impression']);
    }

    public function testUnapprovedAndForeignCategoryAreNotServed(): void
    {
        $this->assertFalse(AdPolicy::canShowCreative(AdPolicy::STATUS_MODERATION));
        $this->assertFalse(AdPolicy::canShowCreative(AdPolicy::STATUS_APPROVED));
        $this->assertFalse(AdPolicy::mayIssue(AdPolicy::STATUS_APPROVED, 0, 1000, null, time(), true));
        $this->assertTrue(AdPolicy::mayIssue(AdPolicy::STATUS_ACTIVE, 0, 1000, null, time(), true));

        $page = 'Электроника и бытовая техника / Смартфоны и аксессуары';
        $this->assertTrue(AdPolicy::categoryPageMatches($page, $page));
        $this->assertFalse(AdPolicy::categoryPageMatches('Электроника и бытовая техника', $page));
        $this->assertFalse(AdPolicy::categoryPageMatches($page, 'Электроника и бытовая техника'));
        $this->assertTrue(AdPolicy::sidebarMatches('Электроника и бытовая техника', $page));
        $this->assertFalse(AdPolicy::sidebarMatches('Транспорт и запчасти', $page));
        $this->assertTrue(AdPolicy::sidebarMatches(null, $page));
    }

    public function testPaymentDoesNotApplyTwiceOrAfterTheSwitchIsOff(): void
    {
        $ok = AdPolicy::decideCallback(
            AdPolicy::STATUS_PENDING,
            AdPolicy::FORMAT_HERO,
            1200,
            1,
            1200,
            true,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($ok['apply']);
        $this->assertSame(AdPolicy::STATUS_ACTIVE, $ok['next_status']);

        $dup = AdPolicy::decideCallback(
            AdPolicy::STATUS_ACTIVE,
            AdPolicy::FORMAT_HERO,
            1200,
            1,
            1200,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($dup['idempotent']);
        $this->assertFalse($dup['apply']);

        $off = AdPolicy::decideCallback(
            AdPolicy::STATUS_PENDING,
            AdPolicy::FORMAT_CATEGORY,
            800,
            1,
            800,
            true,
            false,
            true,
            true
        );
        $this->assertFalse($off['apply']);
        $this->assertSame('rejected', $off['gateway']);
        $this->assertSame(AdPolicy::STATUS_FAILED, $off['next_status']);

        $held = AdPolicy::decideCallback(
            AdPolicy::STATUS_PENDING,
            AdPolicy::FORMAT_SIDEBAR,
            600,
            1,
            600,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($held['apply']);
        $this->assertSame(AdPolicy::STATUS_PAID, $held['next_status']);

        $tamper = AdPolicy::decideCallback(
            AdPolicy::STATUS_PENDING,
            AdPolicy::FORMAT_HERO,
            1,
            1,
            1,
            true,
            true,
            true,
            true
        );
        $this->assertSame('amount_mismatch', $tamper['failure_reason']);
        $this->assertFalse($tamper['apply']);
    }

    public function testExclusiveWeekDoesNotOverlapAndRejectsATakenSlot(): void
    {
        $start = strtotime('2026-10-14 12:00:00');
        $period = AdPolicy::exclusivePeriod($start, 1);
        $this->assertSame(AdPolicy::WEEK_SECONDS, $period['ends_at'] - $period['starts_at']);
        $this->assertTrue(AdPolicy::periodsOverlap($start, $period['ends_at'], $start + 10, $period['ends_at'] + 10));
        $this->assertFalse(AdPolicy::periodsOverlap($start, $period['ends_at'], $period['ends_at'], $period['ends_at'] + 10));

        $taken = AdPolicy::decideCallback(
            AdPolicy::STATUS_PENDING,
            AdPolicy::FORMAT_EXCLUSIVE,
            19900,
            1,
            19900,
            true,
            true,
            false,
            true
        );
        $this->assertFalse($taken['apply']);
        $this->assertSame('slot_taken', $taken['failure_reason']);

        $block = AdPolicy::purchaseBlock(true, true, false, true);
        $this->assertSame('bad_status', $block);
        $this->assertSame('disabled', AdPolicy::purchaseBlock(false, true, true, true));
    }

    public function testLinksAndImagesAreChecked(): void
    {
        $this->assertNull(AdPolicy::targetUrlError('https://shop.example/sale'));
        $this->assertSame('bad_url', AdPolicy::targetUrlError('javascript:alert(1)'));
        $this->assertSame('bad_url', AdPolicy::targetUrlError('https://user:pass@shop.example/'));
        $this->assertNull(AdPolicy::imageError(1000, 'image/png', 1200, 400));
        $this->assertSame('bad_image', AdPolicy::imageError(1000, 'image/gif', 1200, 400));
        $this->assertSame('bad_image', AdPolicy::imageError(1000, 'image/jpeg', 10, 10));
    }

    public function testOrderPrefixesDoNotCollide(): void
    {
        $this->assertTrue(AdPolicy::isAdPgOrderId('zk-ad-abcdef'));
        $this->assertFalse(AdPolicy::isAdPgOrderId('zk-vip-abcdef'));
        $this->assertFalse(AdPolicy::isAdPgOrderId('zk-boost-abcdef'));
    }
}
