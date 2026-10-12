<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ThreeLifts;

use App\Services\ThreeLifts\ThreeLiftsPolicy;
use PHPUnit\Framework\TestCase;

final class ThreeLiftsFeatureFlagTest extends TestCase
{
    public function testOnlyAdminCanChangeTheFlag(): void
    {
        $this->assertTrue(ThreeLiftsPolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(ThreeLiftsPolicy::canToggle($role), (string) $role);
        }
    }

    public function testUnreadableSettingRefusesANewPurchase(): void
    {
        foreach ([null, false, 0, '', '0', 'off', 'false', [], 'maybe'] as $stored) {
            $this->assertFalse(ThreeLiftsPolicy::resolveEnabled($stored));
        }
        $this->assertFalse(ThreeLiftsPolicy::resolveEnabled('1', true));
        $this->assertFalse(ThreeLiftsPolicy::resolveEnabled(null, true));
        $this->assertTrue(ThreeLiftsPolicy::resolveEnabled(true));
        $this->assertTrue(ThreeLiftsPolicy::resolveEnabled(1));
        $this->assertTrue(ThreeLiftsPolicy::resolveEnabled('1'));
        $this->assertTrue(ThreeLiftsPolicy::resolveEnabled('on'));

        $this->assertSame('disabled', ThreeLiftsPolicy::newPurchaseBlock(false, ['enabled' => '1', 'amount' => 1]));
        $this->assertNull(ThreeLiftsPolicy::newPurchaseBlock(true, ['enabled' => '0', 'price' => 1]));
    }

    public function testConflictAndRepeatDoNotWrite(): void
    {
        $conflict = ThreeLiftsPolicy::decideToggle(false, true, null);
        $this->assertFalse($conflict['write']);
        $this->assertSame('conflict', $conflict['error_code']);

        $stale = ThreeLiftsPolicy::decideToggle(true, false, false);
        $this->assertFalse($stale['write']);
        $this->assertSame('conflict', $stale['error_code']);

        $repeat = ThreeLiftsPolicy::decideToggle(true, true, true);
        $this->assertFalse($repeat['write']);
        $this->assertTrue($repeat['unchanged']);
        $this->assertNull($repeat['error_code']);

        $enable = ThreeLiftsPolicy::decideToggle(false, true, false);
        $this->assertTrue($enable['write']);
        $this->assertFalse($enable['unchanged']);

        $disable = ThreeLiftsPolicy::decideToggle(true, false, true);
        $this->assertTrue($disable['write']);
    }

    public function testDisableKeepsPaidWorkAndDoesNotTouchOtherServices(): void
    {
        $off = ThreeLiftsPolicy::toggleAudit(7, true, false, '2026-10-12T22:00:00+05:00');
        $this->assertSame('three_lifts.feature_off', $off['action']);
        $this->assertSame(ThreeLiftsPolicy::SETTING_KEY, $off['context']['setting']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_schedules']);
        $this->assertTrue($off['context']['keeps_sort_at']);
        $this->assertFalse($off['context']['cancels_paid']);
        $this->assertFalse($off['context']['refunds']);
        $this->assertSame([], $off['context']['other_settings']);
        $this->assertSame('undecided', $off['context']['unscheduled_paid']);
        $this->assertTrue(ThreeLiftsPolicy::scheduledLiftsSurviveDisable());
        $this->assertSame('keep', ThreeLiftsPolicy::PAID_SCHEDULE_ON_DISABLE);
        $this->assertSame('undecided', ThreeLiftsPolicy::UNSCHEDULED_PAID_ON_DISABLE);

        $on = ThreeLiftsPolicy::toggleAudit(7, false, true, '2026-10-12T22:05:00+05:00');
        $this->assertSame('three_lifts.feature_on', $on['action']);
        $this->assertSame(990, ThreeLiftsPolicy::PRICE_KZT);
    }

    public function testFlagStaysIndependentOfOtherPromotionControls(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/ThreeLifts/ThreeLiftsService.php');
        $policy = (string) file_get_contents($root . '/app/Services/ThreeLifts/ThreeLiftsPolicy.php');
        $toggle = $this->methodSource($service, 'setEnabled');
        $guard = $this->methodSource($service, 'guardNewPurchase');
        $enabled = $this->methodSource($service, 'isEnabled');
        $admin = (string) file_get_contents($root . '/app/Controllers/AdminController.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $view = (string) file_get_contents($root . '/app/Views/admin/index.php');
        $boost = (string) file_get_contents($root . '/app/Services/Boost/BoostService.php');

        $this->assertStringContainsString('ThreeLiftsPolicy::SETTING_KEY', $toggle);
        $this->assertStringContainsString('decideToggle', $toggle);
        $this->assertStringContainsString('toggleAudit', $toggle);
        $this->assertStringContainsString('canToggle', $toggle);
        $this->assertStringContainsString('newPurchaseBlock', $guard);
        $this->assertStringContainsString('isEnabled', $guard);
        $this->assertStringContainsString('forget', $enabled);
        $this->assertStringContainsString('resolveEnabled', $enabled);
        $this->assertStringContainsString('function requireThreeLiftsAdmin', $admin);
        $this->assertStringContainsString('function toggleThreeLifts', $admin);
        $this->assertStringContainsString("post('/admin/three-lifts'", $routes);
        $this->assertDoesNotMatchRegularExpression("/get\\('\\/admin\\/three-lifts'/", $routes);
        $this->assertStringContainsString("ProductHelper::url('/admin/three-lifts')", $view);
        $this->assertStringContainsString('name="expected"', $view);

        foreach (['boost_enabled', 'BoostService', 'highlight', 'top_fixed', 'vip', 'DELETE FROM', 'sort_at', 'created_at', 'boost_orders'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $toggle, $foreign);
            $this->assertStringNotContainsString($foreign, $guard, $foreign);
        }
        foreach (ThreeLiftsPolicy::UNRELATED_CONTROLS as $control) {
            $this->assertStringNotContainsString($control, $toggle);
        }
        $this->assertStringNotContainsString('three_lifts_enabled', $boost);
        $this->assertStringNotContainsString('ThreeLifts', $boost);
        $this->assertStringContainsString("'three_lifts_enabled'", $policy);
        $this->assertStringContainsString("'boost_enabled'", $policy);
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
}
