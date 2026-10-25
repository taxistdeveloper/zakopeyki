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

namespace Tests\Unit\Services\Highlight {

use App\Core\Lang;
use App\Models\Wallet;
use App\Services\Highlight\HighlightPolicy;
use PHPUnit\Framework\TestCase;

final class HighlightUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testReadyPurchaseShowsPriceTermAndEffect(): void
    {
        $ui = HighlightPolicy::uiState(true, $this->product(), 3, null, true);
        $this->assertSame('ready', $ui['code']);
        $this->assertTrue($ui['can_purchase']);

        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Выделение объявления (Highlight)', $html);
        $this->assertStringContainsString(Wallet::formatMoney(790), $html);
        $this->assertStringContainsString('7 дней', $html);
        $this->assertStringContainsString('PREMIUM', $html);
        $this->assertStringContainsString('Можно выделить сейчас.', $html);
        $this->assertStringContainsString('Позиция в выдаче не меняется.', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="ui[a-f0-9]+"/', $html);
        $this->assertStringNotContainsString('name="amount"', $html);
        $this->assertStringNotContainsString('name="price"', $html);
        $this->assertStringNotContainsString('name="days"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('просмотр', $html);
    }

    public function testDisabledFeatureHidesTheEntry(): void
    {
        $ui = HighlightPolicy::uiState(false, $this->product(), 3, null, true);
        $this->assertSame('hidden', $ui['code']);
        $this->assertSame('', $this->render($this->product(), $ui));
    }

    public function testReturnFromPaymentIsNotActivation(): void
    {
        $ui = HighlightPolicy::uiState(true, $this->product(), 3, [
            'status' => HighlightPolicy::STATUS_PENDING,
            'redirect_url' => 'https://pay.example/session',
            'returned_from_gateway' => true,
        ], true);
        $this->assertSame('pending_pay', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertTrue($ui['continue_payment']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('ещё не подтверждена', $html);
        $this->assertStringNotContainsString('Выделить за', $html);
    }

    public function testActiveWindowShowsEndAndBlocksAnotherPurchase(): void
    {
        $ui = HighlightPolicy::uiState(true, $this->product(), 3, [
            'status' => HighlightPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => date('Y-m-d H:i:s', time() + 86400),
        ], true);
        $this->assertSame('active', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('PREMIUM', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function testExpiredWindowOffersANewPurchase(): void
    {
        $ui = HighlightPolicy::uiState(true, $this->product(), 3, [
            'status' => HighlightPolicy::STATUS_EXPIRED,
            'starts_at' => '2020-01-01 00:00:00',
            'ends_at' => '2020-01-08 00:00:00',
        ], true);
        $this->assertSame('expired', $ui['code']);
        $this->assertTrue($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Срок выделения закончился', $html);
        $this->assertStringContainsString('Выделить ещё раз', $html);
    }

    public function testUiCopyExistsInBothLocalesWithoutARankingPromise(): void
    {
        $keys = [
            'highlight.ui_title',
            'highlight.ui_desc',
            'highlight.ui_term',
            'highlight.ui_available',
            'highlight.ui_buy',
            'highlight.sticker',
            'highlight.active',
            'highlight.expired',
            'highlight.err_disabled',
            'highlight.err_already_active',
            'highlight.payment_pending',
            'highlight.payment_failed',
            'highlight.paid_waiting',
            'highlight.paid_waiting_off',
        ];
        foreach (['ru', 'kk'] as $locale) {
            Lang::set($locale);
            foreach ($keys as $key) {
                $value = t($key);
                $this->assertNotSame($key, $value, $locale . ' ' . $key);
                $this->assertStringNotContainsString('1-е место', $value);
                $this->assertStringNotContainsString('просмотров', mb_strtolower($value));
            }
            $this->assertSame('PREMIUM', t('highlight.sticker'));
        }
    }

    public function testCardsAndProfileShareOneVisualMark(): void
    {
        $root = dirname(__DIR__, 4);
        $card = (string) file_get_contents($root . '/app/Views/partials/product-card.php');
        $show = (string) file_get_contents($root . '/app/Views/products/show.php');
        $profile = (string) file_get_contents($root . '/app/Views/profile/index.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $this->assertStringContainsString('highlight-card', $card);
        $this->assertStringContainsString('partials/highlight-sticker', $card);
        $this->assertStringContainsString('partials/lot-promote-entry', $card);
        $this->assertStringNotContainsString('partials/lot-highlight', $card);
        $this->assertStringContainsString('highlight-card', $show);
        $this->assertStringContainsString('partials/highlight-sticker', $show);
        $this->assertStringContainsString('partials/lot-highlight', $profile);
        $this->assertStringContainsString("post('/profile/lots/{id}/highlight'", $routes);
        $this->assertStringContainsString("get('/profile/lots/highlight-status'", $routes);
        $this->assertStringContainsString('data-highlight-until', (string) file_get_contents($root . '/public/assets/js/app.js'));
    }

    /**
     * @param array<string, mixed> $product
     * @param array{code: string, can_purchase: bool, continue_payment: bool, notice: ?string, ends_at: ?string} $ui
     */
    private function render(array $product, array $ui): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 4) . '/app/Views/partials/lot-highlight.php';

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function product(): array
    {
        return [
            'id' => 15,
            'user_id' => 3,
            'title' => 'Велосипед «Алматы»',
            'status' => 'active',
            'type' => 'used',
        ];
    }
}
}
