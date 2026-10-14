<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TopFixedXl;

use App\Services\TopFixedXl\TopFixedXlPolicy;
use PHPUnit\Framework\TestCase;

final class TopFixedXlFeatureFlagTest extends TestCase
{
    public function testOnlyAdminCanChangeTheFlag(): void
    {
        $this->assertTrue(TopFixedXlPolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(TopFixedXlPolicy::canToggle($role), (string) $role);
        }
    }

    public function testUnreadableSettingRefusesANewPurchase(): void
    {
        foreach ([null, false, 0, '', '0', 'off', 'false', [], 'maybe'] as $stored) {
            $this->assertFalse(TopFixedXlPolicy::resolveEnabled($stored));
        }
        $this->assertFalse(TopFixedXlPolicy::resolveEnabled('1', true));
        $this->assertFalse(TopFixedXlPolicy::resolveEnabled(null, true));
        $this->assertTrue(TopFixedXlPolicy::resolveEnabled(true));
        $this->assertTrue(TopFixedXlPolicy::resolveEnabled(1));
        $this->assertTrue(TopFixedXlPolicy::resolveEnabled('on'));
        $this->assertSame('disabled', TopFixedXlPolicy::purchaseBlock($this->product(), 3, false));
    }

    public function testDisableKeepsHistoryAndDoesNotRefundOrRestart(): void
    {
        $off = TopFixedXlPolicy::toggleAudit(7, true, false, '2026-10-12T22:30:00+05:00');
        $this->assertSame('top_fixed_xl.feature_off', $off['action']);
        $this->assertSame(TopFixedXlPolicy::SETTING_KEY, $off['context']['setting']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_active_window']);
        $this->assertFalse($off['context']['restarts_expired']);
        $this->assertFalse($off['context']['changes_existing_ends_at']);
        $this->assertFalse($off['context']['refunds']);
        $this->assertSame([], $off['context']['other_settings']);

        $on = TopFixedXlPolicy::toggleAudit(7, false, true, '2026-10-12T22:40:00+05:00');
        $this->assertSame('top_fixed_xl.feature_on', $on['action']);
    }

    public function testActiveWindowSurvivesDisableInTheCardState(): void
    {
        $ends = date('Y-m-d H:i:s', time() + 86400);
        $ui = TopFixedXlPolicy::uiState(false, $this->product(), 3, [
            'status' => TopFixedXlPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => $ends,
            'category' => 'Дом и сад / Мебель',
        ], true);
        $this->assertSame('active_off', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertSame($ends, $ui['ends_at']);
    }

    public function testFlagStaysIndependentOfOtherPromotionControls(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/TopFixedXl/TopFixedXlService.php');
        $toggle = $this->methodSource($service, 'setEnabled');
        $recover = $this->methodSource($service, 'recoverUnapplied');
        $enabled = $this->methodSource($service, 'isEnabled');
        $admin = (string) file_get_contents($root . '/app/Controllers/AdminController.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $boost = (string) file_get_contents($root . '/app/Services/Boost/BoostService.php');
        $highlight = (string) file_get_contents($root . '/app/Services/Highlight/HighlightService.php');
        $three = (string) file_get_contents($root . '/app/Services/ThreeLifts/ThreeLiftsService.php');

        $this->assertStringContainsString('TopFixedXlPolicy::SETTING_KEY', $toggle);
        $this->assertStringContainsString('toggleAudit', $toggle);
        $this->assertStringContainsString('canToggle', $toggle);
        $this->assertStringContainsString('forget', $enabled);
        $this->assertStringContainsString('resolveEnabled', $enabled);
        $this->assertStringContainsString('canToggle', $recover);
        $this->assertStringContainsString('isEnabled', $recover);
        $this->assertStringContainsString('function requireTopFixedXlAdmin', $admin);
        $this->assertStringContainsString("post('/admin/top-fixed-xl'", $routes);
        $this->assertStringContainsString("post('/admin/top-fixed-xl/recover'", $routes);
        $this->assertDoesNotMatchRegularExpression("/get\\('\\/admin\\/top-fixed-xl'/", $routes);
        $this->assertStringNotContainsString('top_fixed_xl_enabled', $boost);
        $this->assertStringNotContainsString('top_fixed_xl_enabled', $highlight);
        $this->assertStringNotContainsString('top_fixed_xl_enabled', $three);
        foreach (['boost_enabled', 'highlight_enabled', 'three_lifts_enabled', 'BoostService', 'HighlightService', 'ThreeLifts', 'applyBoostSort', 'DELETE FROM'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $toggle, $foreign);
        }
        foreach (TopFixedXlPolicy::UNRELATED_CONTROLS as $control) {
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
