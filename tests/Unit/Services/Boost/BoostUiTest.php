<?php

declare(strict_types=1);

namespace {
    if (!function_exists('csrf_field')) {
        function csrf_field(): string
        {
            return '<input type="hidden" name="_csrf" value="test-token">';
        }
    }
}

namespace Tests\Unit\Services\Boost {

use App\Core\Lang;
use App\Models\Wallet;
use App\Services\Boost\BoostPolicy;
use PHPUnit\Framework\TestCase;

final class BoostUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testReadyPurchaseShowsPriceAndRequiresExplicitConfirm(): void
    {
        $ui = $this->state(true, $this->product(), null, true);
        $this->assertSame('ready', $ui['code']);
        $this->assertTrue($ui['can_purchase']);
        $this->assertFalse($ui['continue_payment']);

        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Платное поднятие в Топ (Boost)', $html);
        $this->assertStringContainsString(Wallet::formatMoney(390), $html);
        $this->assertStringContainsString('каталоге и поиске', $html);
        $this->assertStringContainsString('Можно поднять сейчас.', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="ui[a-f0-9]+"/', $html);
        $this->assertStringNotContainsString('name="amount"', $html);
        $this->assertStringNotContainsString('name="price"', $html);
        $this->assertStringNotContainsString('disabled', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('.submit(', $html);
        $this->assertStringNotContainsString('1-е', $html);
        $this->assertStringNotContainsString('позицию №', $html);
    }

    public function testDisabledFeatureHidesTheEntry(): void
    {
        $ui = $this->state(false, $this->product(), null, true);
        $this->assertSame('hidden', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertSame('', $this->render($this->product(), $ui));
    }

    public function testIneligibleListingAndForeignOwnerHaveNoBuyButton(): void
    {
        foreach (['sold', 'reserved', 'archived', 'out_of_stock'] as $status) {
            $ui = $this->state(true, $this->product($status, 'used'), null, true);
            $this->assertSame('hidden', $ui['code']);
            $this->assertFalse($ui['can_purchase']);
        }
        $course = $this->state(true, $this->product('active', 'course'), null, true);
        $this->assertSame('hidden', $course['code']);
        $foreign = $this->state(true, $this->product(), null, true, 9);
        $this->assertSame('hidden', $foreign['code']);
        $this->assertSame('', $this->render($this->product('sold'), $this->state(true, $this->product('sold'), null, true)));
    }

    public function testReturnFromPaymentIsNotSuccess(): void
    {
        $ui = $this->state(true, $this->product(), [
            'status' => BoostPolicy::STATUS_PENDING,
            'redirect_url' => 'https://pay.example/session',
            'returned_from_gateway' => true,
        ], true);
        $this->assertSame('pending_pay', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertTrue($ui['continue_payment']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Оплата Boost ещё не подтверждена', $html);
        $this->assertStringNotContainsString('Объявление поднято', $html);
        $this->assertStringNotContainsString('idempotency_key', $html);
        $this->assertStringNotContainsString('https://pay.example/session', $html);
        $this->assertStringContainsString('Новый заказ не создаётся', $html);
    }

    public function testOpenPaymentDoesNotStartAnotherPurchase(): void
    {
        $waiting = $this->state(true, $this->product(), [
            'status' => BoostPolicy::STATUS_PENDING,
        ], true);
        $this->assertSame('pending_wait', $waiting['code']);
        $this->assertFalse($waiting['can_purchase']);
        $this->assertFalse($waiting['continue_payment']);
        $html = $this->render($this->product(), $waiting);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);

        $paid = $this->state(true, $this->product(), ['status' => BoostPolicy::STATUS_PAID], true);
        $this->assertSame('processing', $paid['code']);
        $this->assertFalse($paid['can_purchase']);
        $paidHtml = $this->render($this->product(), $paid);
        $this->assertStringContainsString('Оплата получена', $paidHtml);
        $this->assertStringNotContainsString('Объявление поднято', $paidHtml);
        $this->assertStringNotContainsString('<form', $paidHtml);

        $applyFailed = $this->state(true, $this->product(), ['status' => BoostPolicy::STATUS_APPLY_FAILED], true);
        $this->assertSame('processing', $applyFailed['code']);
        $this->assertFalse($applyFailed['can_purchase']);
    }

    public function testAppliedStateUsesServerStatusAndAllowsOnlyANewExplicitPurchase(): void
    {
        $ui = $this->state(true, $this->product(), ['status' => BoostPolicy::STATUS_APPLIED], true);
        $this->assertSame('applied', $ui['code']);
        $this->assertTrue($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Объявление поднято', $html);
        $this->assertStringContainsString('Это новая оплата', $html);
        $this->assertStringContainsString('name="idempotency_key"', $html);
        $this->assertStringNotContainsString('.submit(', $html);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*method="get"/i', $html);
    }

    public function testPaymentErrorShowsFailureAndRetryIsExplicit(): void
    {
        $ui = $this->state(true, $this->product(), ['status' => BoostPolicy::STATUS_FAILED], true);
        $this->assertSame('failed', $ui['code']);
        $this->assertTrue($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Оплата Boost не прошла', $html);
        $this->assertStringContainsString('Повторить оплату', $html);
        $this->assertStringNotContainsString('Объявление поднято', $html);
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="ui[a-f0-9]+"/', $html);
    }

    public function testDisabledFeatureKeepsResultButBlocksANewPurchase(): void
    {
        $pending = $this->state(false, $this->product(), [
            'status' => BoostPolicy::STATUS_PENDING,
            'redirect_url' => 'https://pay.example/session',
        ], true);
        $this->assertSame('disabled_pending', $pending['code']);
        $this->assertFalse($pending['can_purchase']);
        $this->assertFalse($pending['continue_payment']);
        $pendingHtml = $this->render($this->product(), $pending);
        $this->assertStringContainsString('выключено', $pendingHtml);
        $this->assertStringNotContainsString('<form', $pendingHtml);

        $paid = $this->state(false, $this->product(), ['status' => BoostPolicy::STATUS_PAID], true);
        $this->assertSame('processing_off', $paid['code']);
        $this->assertFalse($paid['can_purchase']);
        $this->assertStringNotContainsString('<form', $this->render($this->product(), $paid));

        $applied = $this->state(false, $this->product(), ['status' => BoostPolicy::STATUS_APPLIED], true);
        $this->assertSame('applied_off', $applied['code']);
        $this->assertFalse($applied['can_purchase']);
        $appliedHtml = $this->render($this->product(), $applied);
        $this->assertStringContainsString('Объявление поднято', $appliedHtml);
        $this->assertStringNotContainsString('<button', $appliedHtml);

        $failed = $this->state(false, $this->product(), ['status' => BoostPolicy::STATUS_FAILED], true);
        $this->assertSame('failed_off', $failed['code']);
        $this->assertFalse($failed['can_purchase']);
    }

    public function testGatewayOutageIsNotAnAvailablePurchase(): void
    {
        $ui = $this->state(true, $this->product(), null, false);
        $this->assertSame('gateway', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Платёжный шлюз не принял заказ', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function testUnknownOrderDoesNotOfferPurchase(): void
    {
        $ui = $this->state(true, $this->product(), ['status' => 'mystery'], true);
        $this->assertSame('pending_wait', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertFalse($ui['continue_payment']);
    }

    public function testUiCopyExistsInBothLocalesWithoutAGuaranteedPlace(): void
    {
        $keys = [
            'boost.ui_title',
            'boost.ui_desc',
            'boost.ui_available',
            'boost.ui_buy',
            'boost.ui_overlay',
            'boost.ui_again',
            'boost.ui_retry',
            'boost.ui_continue',
            'boost.ui_wait',
            'boost.ui_confirm',
            'boost.ui_confirm_again',
            'boost.ui_confirm_retry',
            'boost.ui_confirm_continue',
            'boost.ui_refresh',
            'boost.ui_offline',
            'boost.paid_waiting_off',
            'boost.failed_off',
            'boost.disabled_pending',
            'boost.ineligible_open',
            'boost.applied',
            'boost.payment_failed',
            'boost.err_disabled',
        ];
        foreach (['ru', 'kk'] as $locale) {
            Lang::set($locale);
            foreach ($keys as $key) {
                $value = t($key);
                $this->assertNotSame($key, $value, $locale . ' ' . $key);
                $this->assertStringNotContainsString('1-е место', $value);
                $this->assertStringNotContainsString('позицию №', $value);
            }
        }
        Lang::set('ru');
        $this->assertNotSame(t('boost.ui_desc'), $this->kk('boost.ui_desc'));
    }

    public function testProfileEntryAndOtherPromotionsStaySeparate(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Views/profile/index.php');
        $card = (string) file_get_contents($root . '/app/Views/partials/product-card.php');
        $partial = (string) file_get_contents($root . '/app/Views/partials/lot-boost.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $package = (string) file_get_contents($root . '/app/Views/business/package.php');
        $boostJs = strstr($profile, 'function profileBoostSubmit');
        $this->assertNotFalse($boostJs);
        $this->assertStringContainsString("partials/lot-boost", $profile);
        $this->assertStringContainsString('lot-promote-', $profile);
        $this->assertStringContainsString('partials/lot-promote-entry', $card);
        $this->assertStringNotContainsString('partials/lot-boost', $card);
        $this->assertStringNotContainsString('.submit(', $partial);
        $this->assertStringNotContainsString('.submit(', (string) $boostJs);
        $this->assertStringContainsString("get('/profile/lots/boost-status'", $routes);
        $this->assertStringContainsString("post('/profile/lots/{id}/boost'", $routes);
        $this->assertStringContainsString("t('business.lim_boosts')", $package);
    }

    /**
     * @param array<string, mixed>|null $order
     * @return array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string}
     */
    private function state(bool $enabled, array $product, ?array $order, bool $gatewayReady, int $userId = 3): array
    {
        return BoostPolicy::uiState($enabled, $product, $userId, $order, $gatewayReady);
    }

    /**
     * @param array<string, mixed> $product
     * @param array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string} $ui
     */
    private function render(array $product, array $ui): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 4) . '/app/Views/partials/lot-boost.php';

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private function kk(string $key): string
    {
        Lang::set('kk');
        $value = t($key);
        Lang::set('ru');

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $status = 'active', string $type = 'used'): array
    {
        return [
            'id' => 15,
            'user_id' => 3,
            'title' => 'Велосипед «Алматы»',
            'status' => $status,
            'type' => $type,
        ];
    }
}
}
