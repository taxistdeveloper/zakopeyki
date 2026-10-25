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

namespace Tests\Unit\Services\Vip {

use App\Core\Lang;
use App\Models\Wallet;
use App\Services\Vip\VipPolicy;
use PHPUnit\Framework\TestCase;

final class VipUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testReadyPurchaseShowsOnePriceTermAndIncludedEffects(): void
    {
        $ui = VipPolicy::uiState(true, $this->product(), 3, null, true);
        $this->assertSame('ready', $ui['code']);
        $this->assertTrue($ui['can_purchase']);

        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('VIP «Всё включено»', $html);
        $this->assertStringContainsString(Wallet::formatMoney(2290), $html);
        $this->assertStringContainsString('7 дней', $html);
        $this->assertStringContainsString('закрепление', $html);
        $this->assertStringContainsString('выделение', $html);
        $this->assertStringContainsString('два автоматических поднятия', $html);
        $this->assertStringContainsString('отдельно платить не нужно', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertMatchesRegularExpression('/name="idempotency_key" value="ui[a-f0-9]+"/', $html);
        $this->assertStringContainsString('profileVipSubmit', $html);
        $this->assertStringNotContainsString('name="amount"', $html);
        $this->assertStringNotContainsString('name="price"', $html);
        $this->assertStringNotContainsString('name="days"', $html);
        $this->assertStringNotContainsString('/top-fixed', $html);
        $this->assertStringNotContainsString('/highlight', $html);
    }

    public function testDisabledFeatureHidesTheEntry(): void
    {
        $ui = VipPolicy::uiState(false, $this->product(), 3, null, true);
        $this->assertSame('hidden', $ui['code']);
        $this->assertSame('', $this->render($this->product(), $ui));
    }

    public function testReturnFromPaymentIsNotActivation(): void
    {
        $ui = VipPolicy::uiState(true, $this->product(), 3, [
            'status' => VipPolicy::STATUS_PENDING,
            'redirect_url' => 'https://pay.example/session',
        ], true);
        $this->assertSame('pending_pay', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertTrue($ui['continue_payment']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('ещё не подтверждена', $html);
        $this->assertStringNotContainsString('Подключить за', $html);
    }

    public function testActivePackageShowsEndAndRemainingLiftsFromTheServer(): void
    {
        $ends = date('Y-m-d H:i:s', time() + 86400);
        $ui = VipPolicy::uiState(true, $this->product(), 3, [
            'status' => VipPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => $ends,
            'category' => 'Дом и сад / Мебель',
            'lifts_remaining' => 1,
        ], true);
        $this->assertSame('active', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertSame(1, $ui['lifts_remaining']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('Осталось автоматических поднятий: 1', $html);
        $this->assertStringNotContainsString('method="post"', $html);
    }

    public function testFailedPaymentCanBeRetriedWithoutASecondComponentPrice(): void
    {
        $ui = VipPolicy::uiState(true, $this->product(), 3, [
            'status' => VipPolicy::STATUS_FAILED,
        ], true);
        $this->assertSame('failed', $ui['code']);
        $this->assertTrue($ui['can_purchase']);
        $html = $this->render($this->product(), $ui);
        $this->assertStringContainsString('не прошла', $html);
        $this->assertStringContainsString(Wallet::formatMoney(2290), $html);
        $this->assertStringNotContainsString(Wallet::formatMoney(1490), $html);
        $this->assertStringNotContainsString(Wallet::formatMoney(790), $html);
        $this->assertStringNotContainsString(Wallet::formatMoney(390), $html);
    }

    public function testScreensShareTheServerPackage(): void
    {
        $root = dirname(__DIR__, 4);
        $card = (string) file_get_contents($root . '/app/Views/partials/product-card.php');
        $show = (string) file_get_contents($root . '/app/Views/products/show.php');
        $profile = (string) file_get_contents($root . '/app/Views/profile/index.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $admin = (string) file_get_contents($root . '/app/Views/admin/index.php');
        $js = (string) file_get_contents($root . '/public/assets/js/app.js');
        $this->assertStringContainsString('partials/vip-sticker', $card);
        $this->assertStringContainsString('partials/lot-promote-entry', $card);
        $this->assertStringNotContainsString('partials/lot-vip', $card);
        $this->assertStringContainsString('data-vip-until', $show);
        $this->assertStringContainsString('partials/lot-vip', $profile);
        $this->assertStringContainsString('vip-status', $profile);
        $this->assertStringContainsString("post('/profile/lots/{id}/vip'", $routes);
        $this->assertStringContainsString("get('/profile/lots/vip-status'", $routes);
        $this->assertStringContainsString('profileVipSubmit', $js);
        $this->assertStringContainsString('data-vip-locked', $js);
        $this->assertStringContainsString("ProductHelper::url('/admin/vip')", $admin);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $ui
     */
    private function render(array $product, array $ui): string
    {
        ob_start();
        try {
            require dirname(__DIR__, 4) . '/app/Views/partials/lot-vip.php';

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
            'category' => 'Дом и сад / Мебель',
        ];
    }
}
}
