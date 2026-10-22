<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MassPush;

use App\Services\MassPush\MassPushPolicy;
use PHPUnit\Framework\TestCase;

final class MassPushPolicyTest extends TestCase
{
    public function testPriceIsFixedAndIgnoresTheClient(): void
    {
        $this->assertSame(14900, MassPushPolicy::PRICE_KZT);
        $this->assertSame(14900, MassPushPolicy::serverQuote([
            'amount' => 1,
            'price' => 10,
            'total' => 0,
        ]));
    }

    public function testSwitchIsIndependentAndOffByDefault(): void
    {
        $this->assertSame('mass_push_enabled', MassPushPolicy::SETTING_KEY);
        foreach (MassPushPolicy::UNRELATED_CONTROLS as $other) {
            $this->assertNotSame(MassPushPolicy::SETTING_KEY, $other);
        }
        $this->assertContains('target_push_enabled', MassPushPolicy::UNRELATED_CONTROLS);
        $this->assertFalse(MassPushPolicy::resolveEnabled(null));
        $this->assertFalse(MassPushPolicy::resolveEnabled('0'));
        $this->assertTrue(MassPushPolicy::resolveEnabled('1'));
        $this->assertTrue(MassPushPolicy::canToggle('admin'));
        $this->assertFalse(MassPushPolicy::canToggle('manager'));
        $this->assertFalse(MassPushPolicy::canToggle('user'));
        $this->assertTrue(MassPushPolicy::IN_PROGRESS_SURVIVES_DISABLE);
        $this->assertFalse(MassPushPolicy::AUTO_LAUNCH_ON_ENABLE);
        $this->assertFalse(MassPushPolicy::PAYMENT_STARTS_SEND);
        $this->assertFalse(MassPushPolicy::REFUNDS);
    }

    public function testAudienceHonorsUnsubscribeAndExcludesAdvertiser(): void
    {
        $this->assertTrue(MassPushPolicy::recipientEligible(false, false));
        $this->assertFalse(MassPushPolicy::recipientEligible(true, false));
        $this->assertFalse(MassPushPolicy::recipientEligible(false, true));
    }

    public function testDisabledServiceBlocksPurchaseAndLaunch(): void
    {
        $this->assertSame('disabled', MassPushPolicy::createBlock(false, null, null));
        $this->assertSame('disabled', MassPushPolicy::purchaseBlock(false, true, MassPushPolicy::STATUS_APPROVED, 10));
        $this->assertSame('disabled', MassPushPolicy::launchBlock(false, true, MassPushPolicy::STATUS_PAID, 10));
        $this->assertNull(MassPushPolicy::launchBlock(true, true, MassPushPolicy::STATUS_PAID, 10));
        $this->assertSame('audience_empty', MassPushPolicy::launchBlock(true, true, MassPushPolicy::STATUS_PAID, 0));
        $this->assertSame('bad_status', MassPushPolicy::launchBlock(true, true, MassPushPolicy::STATUS_APPROVED, 10));
        $this->assertSame('bad_status', MassPushPolicy::purchaseBlock(true, true, MassPushPolicy::STATUS_MODERATION, 10));
    }

    public function testModerationIsRequiredBeforePaymentAndSend(): void
    {
        $this->assertSame('bad_status', MassPushPolicy::purchaseBlock(true, true, MassPushPolicy::STATUS_MODERATION, 5));
        $this->assertNull(MassPushPolicy::purchaseBlock(true, true, MassPushPolicy::STATUS_APPROVED, 5));
        $this->assertSame('bad_status', MassPushPolicy::launchBlock(true, true, MassPushPolicy::STATUS_APPROVED, 5));
        $this->assertSame('bad_status', MassPushPolicy::launchBlock(true, true, MassPushPolicy::STATUS_PENDING, 5));
    }

    public function testPaymentDoesNotStartTheSendOrChargeTwice(): void
    {
        $paid = MassPushPolicy::decideCallback(
            MassPushPolicy::STATUS_PENDING,
            14900,
            14900,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($paid['record']);
        $this->assertFalse($paid['starts_send']);
        $this->assertSame(MassPushPolicy::STATUS_PAID, $paid['next_status']);

        $again = MassPushPolicy::decideCallback(
            MassPushPolicy::STATUS_PAID,
            14900,
            14900,
            true,
            true,
            true,
            true
        );
        $this->assertTrue($again['idempotent']);
        $this->assertFalse($again['record']);
        $this->assertFalse($again['starts_send']);

        $cheap = MassPushPolicy::decideCallback(
            MassPushPolicy::STATUS_PENDING,
            14900,
            1,
            true,
            true,
            true,
            true
        );
        $this->assertSame('rejected', $cheap['gateway']);
        $this->assertFalse($cheap['record']);

        $off = MassPushPolicy::decideCallback(
            MassPushPolicy::STATUS_PENDING,
            14900,
            14900,
            true,
            false,
            true,
            true
        );
        $this->assertSame('rejected', $off['gateway']);
        $this->assertSame(MassPushPolicy::STATUS_FAILED, $off['next_status']);
        $this->assertFalse($off['starts_send']);
    }

    public function testCancelBeforeLaunchAndRetrySkipsAccepted(): void
    {
        $this->assertTrue(MassPushPolicy::mayCancel(MassPushPolicy::STATUS_PAID));
        $this->assertFalse(MassPushPolicy::isStarted(MassPushPolicy::STATUS_PAID));
        $this->assertFalse(MassPushPolicy::isStarted(MassPushPolicy::STATUS_CANCELLED));
        $this->assertTrue(MassPushPolicy::isStarted(MassPushPolicy::STATUS_SENDING));
        $this->assertFalse(MassPushPolicy::mayCancel(MassPushPolicy::STATUS_SENDING));

        $accepted = MassPushPolicy::decideRecipient(MassPushPolicy::RECIPIENT_ACCEPTED);
        $this->assertFalse($accepted['write']);
        $this->assertTrue($accepted['idempotent']);

        $pending = MassPushPolicy::decideRecipient(MassPushPolicy::RECIPIENT_PENDING);
        $this->assertTrue($pending['write']);

        $failed = MassPushPolicy::decideRecipient(MassPushPolicy::RECIPIENT_FAILED);
        $this->assertFalse($failed['write']);
        $this->assertTrue(MassPushPolicy::mayRetry(MassPushPolicy::STATUS_PARTIAL));
        $this->assertFalse(MassPushPolicy::mayRetry(MassPushPolicy::STATUS_COMPLETED));
        $this->assertSame(MassPushPolicy::STATUS_PARTIAL, MassPushPolicy::finishStatus(0, 2));
        $this->assertSame(MassPushPolicy::STATUS_COMPLETED, MassPushPolicy::finishStatus(0, 0));
        $this->assertSame(MassPushPolicy::STATUS_SENDING, MassPushPolicy::finishStatus(3, 0));
    }

    public function testToggleAuditDoesNotTouchOtherServices(): void
    {
        $audit = MassPushPolicy::toggleAudit(7, true, false, '2026-10-23T12:00:00+05:00');
        $this->assertSame('mass_push.feature_off', $audit['action']);
        $this->assertSame([], $audit['context']['other_settings']);
        $this->assertTrue($audit['context']['keeps_history']);
        $this->assertFalse($audit['context']['auto_launch_on_enable']);
        $this->assertFalse($audit['context']['refunds']);
        $this->assertTrue($audit['context']['in_progress_survives']);
    }

    public function testMessageValidationMatchesNotificationLimits(): void
    {
        $this->assertSame('bad_title', MassPushPolicy::messageError('a', 'ok body'));
        $this->assertSame('bad_body', MassPushPolicy::messageError('ok title', 'a'));
        $this->assertNull(MassPushPolicy::messageError('Акция', 'Скидка на всё'));
        $this->assertNull(MassPushPolicy::targetUrlError(''));
        $this->assertNull(MassPushPolicy::targetUrlError('https://zakopeyki.kz/x'));
        $this->assertSame('bad_url', MassPushPolicy::targetUrlError('javascript:alert(1)'));
    }
}
