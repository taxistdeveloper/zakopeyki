<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vip;

use App\Services\Boost\BoostPolicy;
use App\Services\Highlight\HighlightPolicy;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\Vip\VipPolicy;
use PHPUnit\Framework\TestCase;

final class VipPolicyTest extends TestCase
{
    public function testServerPriceAndTermIgnoreTheClient(): void
    {
        $this->assertSame(2290, VipPolicy::PRICE_KZT);
        $this->assertSame(7, VipPolicy::DURATION_DAYS);
        $this->assertSame(604800, VipPolicy::DURATION_SECONDS);
        $this->assertSame(0, VipPolicy::COMPONENT_AMOUNT_KZT);
        $this->assertSame(2290, VipPolicy::serverAmount([
            'amount' => 1,
            'price' => 390,
            'days' => 30,
            'enabled' => '1',
        ]));
    }

    public function testComponentsStayInsideThePackageAndLiftsAreExactlyTwo(): void
    {
        $start = strtotime('2026-10-14 12:00:00');
        $period = VipPolicy::period($start);
        $component = VipPolicy::componentPeriod($start);
        $this->assertSame($period, $component);
        $this->assertSame(VipPolicy::DURATION_SECONDS, $period['ends_at'] - $period['starts_at']);

        $slots = VipPolicy::liftSchedule($start);
        $this->assertCount(VipPolicy::LIFT_COUNT, $slots);
        $seen = [];
        foreach ($slots as $slot) {
            $this->assertGreaterThan($start, $slot['at']);
            $this->assertLessThan($period['ends_at'], $slot['at']);
            $seen[] = $slot['slot'];
        }
        $this->assertSame([1, 2], $seen);
        $this->assertSame(259200, $slots[0]['at'] - $start);
        $this->assertSame(518400, $slots[1]['at'] - $start);
    }

    public function testLiftDoesNotRunTwiceAndStopsAtThePackageEnd(): void
    {
        $start = strtotime('2026-10-14 12:00:00');
        $end = $start + VipPolicy::DURATION_SECONDS;
        $due = $start + 259200;

        $first = VipPolicy::decideLift(VipPolicy::LIFT_PENDING, $due, $end, $due, true);
        $this->assertTrue($first['run']);
        $this->assertSame(VipPolicy::LIFT_DONE, $first['next']);

        $again = VipPolicy::decideLift(VipPolicy::LIFT_DONE, $due, $end, $due + 10, true);
        $this->assertFalse($again['run']);
        $this->assertTrue($again['idempotent']);

        $early = VipPolicy::decideLift(VipPolicy::LIFT_PENDING, $due, $end, $due - 60, true);
        $this->assertFalse($early['run']);
        $this->assertSame(VipPolicy::LIFT_PENDING, $early['next']);

        $late = VipPolicy::decideLift(VipPolicy::LIFT_PENDING, $due, $end, $end, true);
        $this->assertFalse($late['run']);
        $this->assertSame(VipPolicy::LIFT_SKIPPED, $late['next']);

        $ineligible = VipPolicy::decideLift(VipPolicy::LIFT_PENDING, $due, $end, $due, false);
        $this->assertFalse($ineligible['run']);
        $this->assertSame(VipPolicy::LIFT_PENDING, $ineligible['next']);
    }

    public function testSuccessfulPaymentAppliesOnceAndADuplicateDoesNot(): void
    {
        $ok = VipPolicy::decideCallback(
            VipPolicy::STATUS_PENDING,
            2290,
            2290,
            true,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($ok['apply']);
        $this->assertSame(VipPolicy::STATUS_APPLIED, $ok['next_status']);
        $this->assertFalse($ok['idempotent']);

        $again = VipPolicy::decideCallback(
            VipPolicy::STATUS_APPLIED,
            2290,
            2290,
            true,
            true,
            true,
            true
        );
        $this->assertFalse($again['apply']);
        $this->assertTrue($again['idempotent']);
    }

    public function testFailedPaymentAndWrongAmountDoNotApply(): void
    {
        $failed = VipPolicy::decideCallback(
            VipPolicy::STATUS_PENDING,
            2290,
            2290,
            false,
            true,
            true,
            true
        );
        $this->assertFalse($failed['apply']);
        $this->assertSame(VipPolicy::STATUS_FAILED, $failed['next_status']);

        $cheap = VipPolicy::decideCallback(
            VipPolicy::STATUS_PENDING,
            2290,
            1490,
            true,
            true,
            true,
            true
        );
        $this->assertFalse($cheap['apply']);
        $this->assertSame('rejected', $cheap['gateway']);
        $this->assertSame('amount_mismatch', $cheap['failure_reason']);
    }

    public function testDisabledFlagBlocksANewActivationWithoutCancellingAPaidOne(): void
    {
        $this->assertSame('disabled', VipPolicy::purchaseBlock($this->product(), 3, false));

        $rejected = VipPolicy::decideCallback(
            VipPolicy::STATUS_PENDING,
            2290,
            2290,
            true,
            false,
            true,
            true
        );
        $this->assertFalse($rejected['apply']);
        $this->assertSame(VipPolicy::STATUS_FAILED, $rejected['next_status']);
        $this->assertSame('rejected', $rejected['gateway']);
        $this->assertSame('disabled', $rejected['failure_reason']);

        $held = VipPolicy::decideCallback(
            VipPolicy::STATUS_PENDING,
            2290,
            2290,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($held['apply']);
        $this->assertSame(VipPolicy::STATUS_PAID, $held['next_status']);

        $kept = VipPolicy::decideCallback(
            VipPolicy::STATUS_APPLIED,
            2290,
            2290,
            true,
            false,
            true,
            true
        );
        $this->assertTrue($kept['idempotent']);
        $this->assertFalse($kept['apply']);
        $this->assertTrue(VipPolicy::ACTIVE_SURVIVES_DISABLE);
        $this->assertSame('no_apply', VipPolicy::PAYMENT_AFTER_DISABLE);
    }

    public function testPrefixDoesNotCollideAndGrantsAreNotPaidOrders(): void
    {
        $this->assertTrue(VipPolicy::isVipPgOrderId('zk-vip-15-abcdef'));
        $this->assertFalse(VipPolicy::isVipPgOrderId('zk-vipcomp-tf-15'));
        $this->assertFalse(VipPolicy::isVipPgOrderId('zk-topfixed-15-abcdef'));
        $this->assertFalse(TopFixedPolicy::isTopFixedPgOrderId('zk-vip-15-abcdef'));
        $this->assertFalse(HighlightPolicy::isHighlightPgOrderId('zk-vip-15-abcdef'));
        $this->assertFalse(BoostPolicy::isBoostPgOrderId('zk-vip-15-abcdef'));
        $this->assertFalse(TopFixedXlPolicy::isTopFixedXlPgOrderId('zk-vip-15-abcdef'));
    }

    public function testGrantsDoNotRewriteIndependentServices(): void
    {
        $root = dirname(__DIR__, 4);
        $pin = (string) file_get_contents($root . '/app/Models/TopFixedOrder.php');
        $highlight = (string) file_get_contents($root . '/app/Models/HighlightOrder.php');
        $pinGrant = $this->methodSource($pin, 'insertVipGrant');
        $highlightGrant = $this->methodSource($highlight, 'insertVipGrant');
        $this->assertStringContainsString('INSERT INTO top_fixed_orders', $pinGrant);
        $this->assertStringContainsString(', 0,', $pinGrant);
        $this->assertStringNotContainsString('UPDATE top_fixed_orders', $pinGrant);
        $this->assertStringContainsString('INSERT INTO highlight_orders', $highlightGrant);
        $this->assertStringContainsString(', 0,', $highlightGrant);
        $this->assertStringNotContainsString('UPDATE highlight_orders', $highlightGrant);

        $pinActive = $this->methodSource($pin, 'findActiveByProduct');
        $pinLatest = $this->methodSource($pin, 'latestByUser');
        $pinPins = $this->methodSource($pin, 'activePins');
        $this->assertStringContainsString('vip_order_id IS NULL', $pinActive);
        $this->assertStringContainsString('vip_order_id IS NULL', $pinLatest);
        $this->assertStringNotContainsString('vip_order_id IS NULL', $pinPins);

        $highlightActive = $this->methodSource($highlight, 'findActiveByProduct');
        $highlightEnds = $this->methodSource($highlight, 'activeEnds');
        $this->assertStringContainsString('vip_order_id IS NULL', $highlightActive);
        $this->assertStringNotContainsString('vip_order_id IS NULL', $highlightEnds);
    }

    public function testBundleUsesExistingEffectsWithoutASecondPurchase(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/Vip/VipService.php');
        $this->assertStringContainsString('insertVipGrant', $service);
        $this->assertStringContainsString('applyBoostSort', $service);
        $this->assertStringContainsString('VipPolicy::componentPeriod', $service);
        $this->assertStringContainsString('COMPONENT_AMOUNT_KZT', $service);
        $this->assertStringNotContainsString('->beginPurchase', $service);
        $this->assertStringNotContainsString('HighlightService', $service);
        $this->assertStringNotContainsString('TopFixedService', $service);
        $this->assertStringNotContainsString('BoostService', $service);
        $this->assertStringNotContainsString('PRICE_KZT', $this->methodSource($service, 'writeBundle'));
    }

    private function methodSource(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method);
        $this->assertNotFalse($start);
        $next = strpos($source, "\n    public function ", $start + 10);
        if ($next === false) {
            $next = strpos($source, "\n    private function ", $start + 10);
        }

        return $next === false ? substr($source, (int) $start) : substr($source, (int) $start, $next - (int) $start);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(): array
    {
        return [
            'id' => 15,
            'user_id' => 3,
            'status' => 'active',
            'type' => 'used',
            'category' => 'Дом и сад / Мебель',
        ];
    }
}
