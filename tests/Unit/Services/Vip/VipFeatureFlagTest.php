<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vip;

use App\Services\Vip\VipPolicy;
use PHPUnit\Framework\TestCase;

final class VipFeatureFlagTest extends TestCase
{
    public function testOnlyAdminCanChangeTheFlag(): void
    {
        $this->assertTrue(VipPolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(VipPolicy::canToggle($role), (string) $role);
        }
    }

    public function testUnreadableSettingRefusesANewPurchase(): void
    {
        foreach ([null, false, 0, '', '0', 'off', 'false', [], 'maybe'] as $stored) {
            $this->assertFalse(VipPolicy::resolveEnabled($stored));
        }
        $this->assertFalse(VipPolicy::resolveEnabled('1', true));
        $this->assertFalse(VipPolicy::resolveEnabled(null, true));
        $this->assertTrue(VipPolicy::resolveEnabled(true));
        $this->assertTrue(VipPolicy::resolveEnabled(1));
        $this->assertTrue(VipPolicy::resolveEnabled('on'));
        $this->assertSame('disabled', VipPolicy::purchaseBlock($this->product(), 3, false));
    }

    public function testDisableKeepsHistoryAndDoesNotRefundOrTouchOtherServices(): void
    {
        $off = VipPolicy::toggleAudit(7, true, false, '2026-10-14T22:30:00+05:00');
        $this->assertSame('vip.feature_off', $off['action']);
        $this->assertSame(VipPolicy::SETTING_KEY, $off['context']['setting']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_active_window']);
        $this->assertFalse($off['context']['restarts_expired']);
        $this->assertFalse($off['context']['changes_existing_ends_at']);
        $this->assertFalse($off['context']['refunds']);
        $this->assertSame('no_apply', $off['context']['payment_after_disable']);
        $this->assertSame([], $off['context']['other_settings']);

        $on = VipPolicy::toggleAudit(7, false, true, '2026-10-14T22:40:00+05:00');
        $this->assertSame('vip.feature_on', $on['action']);
    }

    public function testActiveWindowSurvivesDisableInTheCardState(): void
    {
        $ends = date('Y-m-d H:i:s', time() + 86400);
        $ui = VipPolicy::uiState(false, $this->product(), 3, [
            'status' => VipPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => $ends,
            'category' => 'Дом и сад / Мебель',
            'lifts_remaining' => 2,
        ], true);
        $this->assertSame('active_off', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertSame($ends, $ui['ends_at']);
        $this->assertSame(2, $ui['lifts_remaining']);
    }

    public function testFlagStaysIndependentOfOtherPromotionControls(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/Vip/VipService.php');
        $toggle = $this->methodSource($service, 'setEnabled');
        $recover = $this->methodSource($service, 'recoverUnapplied');
        $enabled = $this->methodSource($service, 'isEnabled');
        $admin = (string) file_get_contents($root . '/app/Controllers/AdminController.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $boost = (string) file_get_contents($root . '/app/Services/Boost/BoostService.php');
        $highlight = (string) file_get_contents($root . '/app/Services/Highlight/HighlightService.php');
        $topFixed = (string) file_get_contents($root . '/app/Services/TopFixed/TopFixedService.php');
        $three = (string) file_get_contents($root . '/app/Services/ThreeLifts/ThreeLiftsService.php');

        $this->assertStringContainsString('VipPolicy::SETTING_KEY', $toggle);
        $this->assertStringContainsString('toggleAudit', $toggle);
        $this->assertStringContainsString('canToggle', $toggle);
        $this->assertStringContainsString('forget', $enabled);
        $this->assertStringContainsString('resolveEnabled', $enabled);
        $this->assertStringContainsString('canToggle', $recover);
        $this->assertStringContainsString('isEnabled', $recover);
        $this->assertStringContainsString('function requireVipAdmin', $admin);
        $this->assertStringContainsString("post('/admin/vip'", $routes);
        $this->assertStringContainsString("post('/admin/vip/recover'", $routes);
        $this->assertStringNotContainsString('vip_enabled', $boost);
        $this->assertStringNotContainsString('vip_enabled', $highlight);
        $this->assertStringNotContainsString('vip_enabled', $topFixed);
        $this->assertStringNotContainsString('vip_enabled', $three);
        foreach (['boost_enabled', 'highlight_enabled', 'three_lifts_enabled', 'top_fixed_enabled', 'top_fixed_xl_enabled', 'BoostService', 'HighlightService', 'TopFixedService', 'applyBoostSort', 'DELETE FROM'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $toggle, $foreign);
        }
        foreach (VipPolicy::UNRELATED_CONTROLS as $control) {
            $this->assertStringNotContainsString($control, $toggle);
        }
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
