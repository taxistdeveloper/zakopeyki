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

namespace Tests\Unit\Services\TopFixed {

use App\Core\Lang;
use App\Models\Wallet;
use App\Services\TopFixed\TopFixedPolicy;
use PHPUnit\Framework\TestCase;

final class TopFixedUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testReadyPurchaseShowsPriceTermAndEffect(): void
    {
        $ui = TopFixedPolicy::uiState(true, $this->product(), 3, null, true);
        $this->assertSame('ready', $ui['code']);
        $this->assertTrue($ui['can_purchase']);

        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Закрепление в категории (Top Fixed)', $html);
        $this->assertStringContainsString(Wallet::formatMoney(1490), $html);
        $this->assertStringContainsString('7 дней', $html);
        $this->assertStringContainsString('выше обычных', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="ui[a-f0-9]+"/', $html);
        $this->assertStringNotContainsString('name="amount"', $html);
        $this->assertStringNotContainsString('name="price"', $html);
        $this->assertStringNotContainsString('name="days"', $html);
        $this->assertStringNotContainsString('name="category"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('просмотр', $html);
        $this->assertStringNotContainsString('1-е место', $html);
    }

    public function testDisabledFeatureHidesTheEntry(): void
    {
        $ui = TopFixedPolicy::uiState(false, $this->product(), 3, null, true);
        $this->assertSame('hidden', $ui['code']);
        $this->assertSame('', $this->render($this->product(), $ui));
    }

    public function testReturnFromPaymentIsNotActivation(): void
    {
        $ui = TopFixedPolicy::uiState(true, $this->product(), 3, [
            'status' => TopFixedPolicy::STATUS_PENDING,
            'redirect_url' => 'https://pay.example/session',
            'returned_from_gateway' => true,
        ], true);
        $this->assertSame('pending_pay', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertTrue($ui['continue_payment']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('ещё не подтверждена', $html);
        $this->assertStringNotContainsString('Закрепить за', $html);
    }

    public function testActiveWindowShowsEndAndBlocksAnotherPurchase(): void
    {
        $ui = TopFixedPolicy::uiState(true, $this->product(), 3, [
            'status' => TopFixedPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => date('Y-m-d H:i:s', time() + 86400),
            'category' => 'Дом и сад / Мебель',
        ], true);
        $this->assertSame('active', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Дом и сад / Мебель', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function testMovedCategoryDoesNotPresentTheListingAsPinned(): void
    {
        $ui = TopFixedPolicy::uiState(true, $this->product('active', 'used', 'Авто / Легковые'), 3, [
            'status' => TopFixedPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => date('Y-m-d H:i:s', time() + 86400),
            'category' => 'Дом и сад / Мебель',
        ], true);
        $this->assertSame('moved', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('не поднимается', $html);
    }

    public function testExpiredWindowOffersANewPurchase(): void
    {
        $ui = TopFixedPolicy::uiState(true, $this->product(), 3, [
            'status' => TopFixedPolicy::STATUS_EXPIRED,
            'starts_at' => '2020-01-01 00:00:00',
            'ends_at' => '2020-01-08 00:00:00',
            'category' => 'Дом и сад / Мебель',
        ], true);
        $this->assertSame('expired', $ui['code']);
        $this->assertTrue($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Срок закрепления закончился', $html);
        $this->assertStringContainsString('Закрепить ещё раз', $html);
    }

    public function testUiCopyExistsInBothLocalesWithoutAPositionPromise(): void
    {
        $keys = [
            'top_fixed.ui_title',
            'top_fixed.ui_desc',
            'top_fixed.ui_term',
            'top_fixed.ui_available',
            'top_fixed.ui_buy',
            'top_fixed.sticker',
            'top_fixed.active',
            'top_fixed.moved',
            'top_fixed.expired',
            'top_fixed.err_disabled',
            'top_fixed.err_already_active',
            'top_fixed.payment_pending',
            'top_fixed.payment_failed',
            'top_fixed.paid_waiting',
            'top_fixed.paid_waiting_off',
            'admin.top_fixed_hint',
            'admin.top_fixed_disable_confirm',
        ];
        foreach (['ru', 'kk'] as $locale) {
            Lang::set($locale);
            foreach ($keys as $key) {
                $value = t($key);
                $this->assertNotSame($key, $value, $locale . ' ' . $key);
                $this->assertStringNotContainsString('1-е место', $value);
                $this->assertStringNotContainsString('просмотров', mb_strtolower($value));
            }
            $this->assertSame('TOP', t('top_fixed.sticker'));
            $this->assertStringContainsString('1 490', t('admin.top_fixed_hint'));
        }
    }

    public function testCardsAndProfileShareTheServerListing(): void
    {
        $root = dirname(__DIR__, 4);
        $card = (string) file_get_contents($root . '/app/Views/partials/product-card.php');
        $show = (string) file_get_contents($root . '/app/Views/products/show.php');
        $profile = (string) file_get_contents($root . '/app/Views/profile/index.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $admin = (string) file_get_contents($root . '/app/Views/admin/index.php');
        $this->assertStringContainsString('top-fixed-card', $card);
        $this->assertStringContainsString('partials/top-fixed-sticker', $card);
        $this->assertStringContainsString('partials/lot-promote-entry', $card);
        $this->assertStringNotContainsString('partials/lot-top-fixed', $card);
        $this->assertStringContainsString('top-fixed-card', $show);
        $this->assertStringContainsString('partials/top-fixed-sticker', $show);
        $this->assertStringContainsString('partials/lot-top-fixed', $profile);
        $this->assertStringContainsString("post('/profile/lots/{id}/top-fixed'", $routes);
        $this->assertStringContainsString("get('/profile/lots/top-fixed-status'", $routes);
        $this->assertStringContainsString('data-top-fixed-until', (string) file_get_contents($root . '/public/assets/js/app.js'));
        $this->assertStringContainsString("ProductHelper::url('/admin/top-fixed')", $admin);
        $this->assertStringContainsString('partials/lot-highlight', $profile);
        $this->assertStringContainsString('partials/lot-boost', $profile);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $ui
     */
    private function render(array $product, array $ui): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 4) . '/app/Views/partials/lot-top-fixed.php';

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
    private function product(string $status = 'active', string $type = 'used', string $category = 'Дом и сад / Мебель'): array
    {
        return [
            'id' => 15,
            'user_id' => 3,
            'title' => 'Велосипед «Алматы»',
            'status' => $status,
            'type' => $type,
            'category' => $category,
        ];
    }
}
}
