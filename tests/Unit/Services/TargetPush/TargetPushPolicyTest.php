<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TargetPush;

use App\Services\TargetPush\TargetPushPolicy;
use PHPUnit\Framework\TestCase;

final class TargetPushPolicyTest extends TestCase
{
    public function testPriceIsFixedAndIgnoresTheClient(): void
    {
        $this->assertSame(5900, TargetPushPolicy::PRICE_KZT);
        $this->assertSame(5900, TargetPushPolicy::serverQuote([
            'amount' => 1,
            'price' => 10,
            'total' => 0,
        ]));
        $this->assertSame(10000, TargetPushPolicy::MAX_RECIPIENTS);
    }

    public function testSwitchIsIndependentAndOffByDefault(): void
    {
        $this->assertSame('target_push_enabled', TargetPushPolicy::SETTING_KEY);
        foreach (TargetPushPolicy::UNRELATED_CONTROLS as $other) {
            $this->assertNotSame(TargetPushPolicy::SETTING_KEY, $other);
        }
        $this->assertFalse(TargetPushPolicy::resolveEnabled(null));
        $this->assertFalse(TargetPushPolicy::resolveEnabled('0'));
        $this->assertTrue(TargetPushPolicy::resolveEnabled('1'));
        $this->assertTrue(TargetPushPolicy::canToggle('admin'));
        $this->assertFalse(TargetPushPolicy::canToggle('manager'));
        $this->assertFalse(TargetPushPolicy::canToggle('user'));
        $this->assertTrue(TargetPushPolicy::IN_PROGRESS_SURVIVES_DISABLE);
        $this->assertFalse(TargetPushPolicy::AUTO_LAUNCH_ON_ENABLE);
        $this->assertFalse(TargetPushPolicy::PAYMENT_STARTS_SEND);
        $this->assertFalse(TargetPushPolicy::REFUNDS);
    }

    public function testCalendarDayFollowsTheSiteTimezone(): void
    {
        $zone = new \DateTimeZone('Asia/Almaty');
        $midnight = (new \DateTimeImmutable('2026-01-02 00:00:00', $zone))->getTimestamp();
        $this->assertSame('2026-01-02', TargetPushPolicy::sendDay($midnight, 'Asia/Almaty'));
        $this->assertSame('2026-01-01', TargetPushPolicy::sendDay($midnight - 1, 'Asia/Almaty'));

        $day = '2026-01-02';
        $category = 'Электроника и бытовая техника';
        $child = $category . ' / Смартфоны и аксессуары';
        $this->assertSame(
            TargetPushPolicy::slotKey($category, $day),
            TargetPushPolicy::slotKey($category, $day)
        );
        $this->assertNotSame(
            TargetPushPolicy::slotKey($category, $day),
            TargetPushPolicy::slotKey($child, $day)
        );
        $this->assertNotSame(
            TargetPushPolicy::slotKey($category, $day),
            TargetPushPolicy::slotKey($category, '2026-01-03')
        );
    }

    public function testAudienceRequiresCategoryInterestAndHonorsUnsubscribe(): void
    {
        $parent = 'Электроника и бытовая техника';
        $child = $parent . ' / Смартфоны и аксессуары';
        $this->assertTrue(TargetPushPolicy::productInCategory($child, $parent));
        $this->assertTrue(TargetPushPolicy::productInCategory($child, $child));
        $this->assertFalse(TargetPushPolicy::productInCategory('Транспорт и запчасти', $parent));

        $this->assertTrue(TargetPushPolicy::recipientEligible(true, false, false));
        $this->assertFalse(TargetPushPolicy::recipientEligible(false, false, false));
        $this->assertFalse(TargetPushPolicy::recipientEligible(true, true, false));
        $this->assertFalse(TargetPushPolicy::recipientEligible(true, false, true));
    }

    public function testDisabledServiceBlocksPurchaseAndLaunch(): void
    {
        $this->assertSame('disabled', TargetPushPolicy::createBlock(false, true, null, null, 10));
        $this->assertSame('disabled', TargetPushPolicy::purchaseBlock(false, true, TargetPushPolicy::STATUS_APPROVED, 10));
        $this->assertSame('disabled', TargetPushPolicy::launchBlock(false, true, TargetPushPolicy::STATUS_PAID, 10, true));
        $this->assertNull(TargetPushPolicy::launchBlock(true, true, TargetPushPolicy::STATUS_PAID, 10, true));
    }

    public function testAudienceCapAndFrequencyAreServerDecisions(): void
    {
        $this->assertSame('audience_over', TargetPushPolicy::launchBlock(true, true, TargetPushPolicy::STATUS_PAID, 10001, true));
        $this->assertSame('audience_empty', TargetPushPolicy::launchBlock(true, true, TargetPushPolicy::STATUS_PAID, 0, true));
        $this->assertNull(TargetPushPolicy::launchBlock(true, true, TargetPushPolicy::STATUS_PAID, 10000, true));
        $this->assertSame('frequency', TargetPushPolicy::launchBlock(true, true, TargetPushPolicy::STATUS_PAID, 10, false));
        $this->assertSame('audience_over', TargetPushPolicy::purchaseBlock(true, true, TargetPushPolicy::STATUS_APPROVED, 10001));
        $this->assertSame('bad_category', TargetPushPolicy::createBlock(true, false, null, null, 1));
    }

    public function testPaymentDoesNotStartTheSendOrChargeTwice(): void
    {
        $paid = TargetPushPolicy::decideCallback(
            TargetPushPolicy::STATUS_PENDING,
            5900,
            5900,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($paid['record']);
        $this->assertFalse($paid['starts_send']);
        $this->assertSame(TargetPushPolicy::STATUS_PAID, $paid['next_status']);

        $again = TargetPushPolicy::decideCallback(
            TargetPushPolicy::STATUS_PAID,
            5900,
            5900,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($again['idempotent']);
        $this->assertFalse($again['record']);
        $this->assertFalse($again['starts_send']);

        $cheap = TargetPushPolicy::decideCallback(
            TargetPushPolicy::STATUS_PENDING,
            5900,
            1,
            true,
            true,
            true,
            true
        );
        $this->assertSame('rejected', $cheap['gateway']);
        $this->assertFalse($cheap['record']);

        $off = TargetPushPolicy::decideCallback(
            TargetPushPolicy::STATUS_PENDING,
            5900,
            5900,
            true,
            false,
            true,
            true
        );
        $this->assertSame('rejected', $off['gateway']);
        $this->assertSame(TargetPushPolicy::STATUS_FAILED, $off['next_status']);
        $this->assertFalse($off['starts_send']);
    }

    public function testCancelBeforeLaunchDoesNotConsumeTheDayAndRetrySkipsAccepted(): void
    {
        $this->assertTrue(TargetPushPolicy::mayCancel(TargetPushPolicy::STATUS_PAID));
        $this->assertFalse(TargetPushPolicy::consumesSlot(TargetPushPolicy::STATUS_PAID));
        $this->assertFalse(TargetPushPolicy::consumesSlot(TargetPushPolicy::STATUS_CANCELLED));
        $this->assertTrue(TargetPushPolicy::consumesSlot(TargetPushPolicy::STATUS_SENDING));
        $this->assertFalse(TargetPushPolicy::mayCancel(TargetPushPolicy::STATUS_SENDING));

        $accepted = TargetPushPolicy::decideRecipient(TargetPushPolicy::RECIPIENT_ACCEPTED);
        $this->assertFalse($accepted['write']);
        $this->assertTrue($accepted['idempotent']);

        $pending = TargetPushPolicy::decideRecipient(TargetPushPolicy::RECIPIENT_PENDING);
        $this->assertTrue($pending['write']);

        $failed = TargetPushPolicy::decideRecipient(TargetPushPolicy::RECIPIENT_FAILED);
        $this->assertFalse($failed['write']);
        $this->assertTrue(TargetPushPolicy::mayRetry(TargetPushPolicy::STATUS_PARTIAL));
        $this->assertFalse(TargetPushPolicy::mayRetry(TargetPushPolicy::STATUS_COMPLETED));
        $this->assertSame(TargetPushPolicy::STATUS_PARTIAL, TargetPushPolicy::finishStatus(0, 2));
        $this->assertSame(TargetPushPolicy::STATUS_COMPLETED, TargetPushPolicy::finishStatus(0, 0));
        $this->assertSame(TargetPushPolicy::STATUS_SENDING, TargetPushPolicy::finishStatus(3, 0));
    }

    public function testToggleAuditDoesNotTouchOtherServices(): void
    {
        $audit = TargetPushPolicy::toggleAudit(7, true, false, '2026-10-14T12:00:00+05:00');
        $this->assertSame('target_push.feature_off', $audit['action']);
        $this->assertSame([], $audit['context']['other_settings']);
        $this->assertTrue($audit['context']['keeps_history']);
        $this->assertFalse($audit['context']['auto_launch_on_enable']);
        $this->assertFalse($audit['context']['refunds']);
        $this->assertTrue($audit['context']['in_progress_survives']);
    }
}
