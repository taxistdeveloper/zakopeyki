<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Highlight;

use App\Services\Highlight\HighlightPolicy;
use PHPUnit\Framework\TestCase;

final class HighlightFeatureFlagTest extends TestCase
{
    public function testOnlyAdminCanChangeTheFlag(): void
    {
        $this->assertTrue(HighlightPolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(HighlightPolicy::canToggle($role), (string) $role);
        }
    }

    public function testUnreadableSettingRefusesANewPurchase(): void
    {
        foreach ([null, false, 0, '', '0', 'off', 'false', [], 'maybe'] as $stored) {
            $this->assertFalse(HighlightPolicy::resolveEnabled($stored));
        }
        $this->assertFalse(HighlightPolicy::resolveEnabled('1', true));
        $this->assertFalse(HighlightPolicy::resolveEnabled(null, true));
        $this->assertTrue(HighlightPolicy::resolveEnabled(true));
        $this->assertTrue(HighlightPolicy::resolveEnabled(1));
        $this->assertTrue(HighlightPolicy::resolveEnabled('on'));
        $this->assertSame('disabled', HighlightPolicy::purchaseBlock($this->product(), 3, false));
    }

    public function testDisableKeepsHistoryAndDoesNotRefundOrRestart(): void
    {
        $off = HighlightPolicy::toggleAudit(7, true, false, '2026-10-12T22:30:00+05:00');
        $this->assertSame('highlight.feature_off', $off['action']);
        $this->assertSame(HighlightPolicy::SETTING_KEY, $off['context']['setting']);
        $this->assertTrue($off['context']['before']);
        $this->assertFalse($off['context']['after']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_active_window']);
        $this->assertFalse($off['context']['restarts_expired']);
        $this->assertFalse($off['context']['changes_rank']);
        $this->assertFalse($off['context']['refunds']);
        $this->assertSame([], $off['context']['other_settings']);

        $on = HighlightPolicy::toggleAudit(7, false, true, '2026-10-12T22:40:00+05:00');
        $this->assertSame('highlight.feature_on', $on['action']);
        $this->assertFalse(HighlightPolicy::REENABLE_REACTIVATES_EXPIRED);
    }

    public function testActiveWindowSurvivesDisableInTheCardState(): void
    {
        $ends = date('Y-m-d H:i:s', time() + 86400);
        $ui = HighlightPolicy::uiState(false, $this->product(), 3, [
            'status' => HighlightPolicy::STATUS_APPLIED,
            'starts_at' => date('Y-m-d H:i:s', time() - 3600),
            'ends_at' => $ends,
        ], true);
        $this->assertSame('active_off', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertSame($ends, $ui['ends_at']);
    }

    public function testFlagStaysIndependentOfOtherPromotionControls(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/Highlight/HighlightService.php');
        $toggle = $this->methodSource($service, 'setEnabled');
        $recover = $this->methodSource($service, 'recoverUnapplied');
        $enabled = $this->methodSource($service, 'isEnabled');
        $admin = (string) file_get_contents($root . '/app/Controllers/AdminController.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $boost = (string) file_get_contents($root . '/app/Services/Boost/BoostService.php');
        $three = (string) file_get_contents($root . '/app/Services/ThreeLifts/ThreeLiftsService.php');

        $this->assertStringContainsString('HighlightPolicy::SETTING_KEY', $toggle);
        $this->assertStringContainsString('toggleAudit', $toggle);
        $this->assertStringContainsString('canToggle', $toggle);
        $this->assertStringContainsString('forget', $enabled);
        $this->assertStringContainsString('resolveEnabled', $enabled);
        $this->assertStringContainsString('canToggle', $recover);
        $this->assertStringContainsString('isEnabled', $recover);
        $this->assertStringContainsString('function requireHighlightAdmin', $admin);
        $this->assertStringContainsString("post('/admin/highlight'", $routes);
        $this->assertStringContainsString("post('/admin/highlight/recover'", $routes);
        $this->assertDoesNotMatchRegularExpression("/get\\('\\/admin\\/highlight'/", $routes);
        $this->assertStringNotContainsString('highlight_enabled', $boost);
        $this->assertStringNotContainsString('highlight_enabled', $three);
        foreach (['boost_enabled', 'three_lifts_enabled', 'BoostService', 'ThreeLifts', 'sort_at', 'DELETE FROM', 'created_at'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $toggle, $foreign);
        }
        foreach (HighlightPolicy::UNRELATED_CONTROLS as $control) {
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
        ];
    }
}
