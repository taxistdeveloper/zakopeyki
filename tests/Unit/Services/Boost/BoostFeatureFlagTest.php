<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Boost;

use App\Services\Boost\BoostPolicy;
use PHPUnit\Framework\TestCase;

final class BoostFeatureFlagTest extends TestCase
{
    public function testOnlyAdminCanChangeTheFlag(): void
    {
        $this->assertTrue(BoostPolicy::canToggle('admin'));
        foreach (['manager', 'user', 'Admin', '', null] as $role) {
            $this->assertFalse(BoostPolicy::canToggle($role), (string) $role);
        }
    }

    public function testEnableAndDisableAreSeparateAuditRecords(): void
    {
        $on = BoostPolicy::toggleAudit(7, false, true, '2026-10-12T21:00:00+05:00');
        $this->assertSame('boost.feature_on', $on['action']);
        $this->assertSame(7, $on['context']['actor_id']);
        $this->assertSame('2026-10-12T21:00:00+05:00', $on['context']['at']);
        $this->assertSame(BoostPolicy::SETTING_KEY, $on['context']['setting']);
        $this->assertFalse($on['context']['before']);
        $this->assertTrue($on['context']['after']);
        $this->assertFalse($on['context']['refunds']);
        $this->assertSame([], $on['context']['other_settings']);

        $off = BoostPolicy::toggleAudit(7, true, false, '2026-10-12T21:05:00+05:00');
        $this->assertSame('boost.feature_off', $off['action']);
        $this->assertTrue($off['context']['before']);
        $this->assertFalse($off['context']['after']);
        $this->assertTrue($off['context']['keeps_listings']);
        $this->assertTrue($off['context']['keeps_orders']);
        $this->assertTrue($off['context']['keeps_created_at']);
        $this->assertTrue($off['context']['keeps_sort_at']);
    }

    public function testUnreadableSettingRefusesANewPurchase(): void
    {
        foreach ([null, false, 0, '', '0', 'off', 'false', [], 'maybe'] as $stored) {
            $this->assertFalse(BoostPolicy::resolveEnabled($stored));
        }
        $this->assertFalse(BoostPolicy::resolveEnabled('1', true));
        $this->assertFalse(BoostPolicy::resolveEnabled(null, true));
        $this->assertTrue(BoostPolicy::resolveEnabled(true));
        $this->assertTrue(BoostPolicy::resolveEnabled(1));
        $this->assertTrue(BoostPolicy::resolveEnabled('1'));
        $this->assertTrue(BoostPolicy::resolveEnabled('on'));

        $product = $this->product();
        $this->assertSame(
            'disabled',
            BoostPolicy::purchaseBlock($product, 3, BoostPolicy::resolveEnabled(null, true))
        );
        $this->assertSame('disabled', BoostPolicy::purchaseBlock($product, 3, false));
        $this->assertNull(BoostPolicy::purchaseBlock($product, 3, true));
    }

    public function testOldPageAndDirectApplyCannotPromoteWhileDisabled(): void
    {
        $product = $this->product();
        $this->assertSame('disabled', BoostPolicy::purchaseBlock($product, 3, false));

        $ui = BoostPolicy::uiState(false, $product, 3, [
            'status' => BoostPolicy::STATUS_PENDING,
            'redirect_url' => 'https://pay.example/old',
            'enabled' => '1',
        ], true);
        $this->assertSame('disabled_pending', $ui['code']);
        $this->assertFalse($ui['can_purchase']);
        $this->assertFalse($ui['continue_payment']);

        $rejectable = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            390,
            true,
            false,
            true,
            true
        );
        $this->assertFalse($rejectable['apply']);
        $this->assertSame(BoostPolicy::STATUS_FAILED, $rejectable['next_status']);
        $this->assertSame('disabled', $rejectable['failure_reason']);

        $captured = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PENDING,
            390,
            390,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($captured['apply']);
        $this->assertSame(BoostPolicy::STATUS_PAID, $captured['next_status']);
        $this->assertNotSame('refund', $captured['failure_reason']);

        $paid = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_PAID,
            390,
            390,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($paid['apply']);
        $this->assertSame(BoostPolicy::STATUS_PAID, $paid['next_status']);

        $waitingApply = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_APPLY_FAILED,
            390,
            390,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($waitingApply['apply']);
        $this->assertSame(BoostPolicy::STATUS_APPLY_FAILED, $waitingApply['next_status']);
    }

    public function testProcessedOperationDoesNotPromoteTwiceWhenDisabled(): void
    {
        $repeat = BoostPolicy::decideCallback(
            BoostPolicy::STATUS_APPLIED,
            390,
            390,
            true,
            false,
            true,
            false
        );
        $this->assertFalse($repeat['apply']);
        $this->assertTrue($repeat['idempotent']);
        $this->assertSame(BoostPolicy::STATUS_APPLIED, $repeat['next_status']);
    }

    public function testDisableKeepsOrderHistoryAndDoesNotRefund(): void
    {
        $cases = [
            [BoostPolicy::STATUS_PENDING, true, BoostPolicy::STATUS_FAILED],
            [BoostPolicy::STATUS_PENDING, false, BoostPolicy::STATUS_PAID],
            [BoostPolicy::STATUS_PAID, false, BoostPolicy::STATUS_PAID],
            [BoostPolicy::STATUS_APPLY_FAILED, false, BoostPolicy::STATUS_APPLY_FAILED],
            [BoostPolicy::STATUS_APPLIED, false, BoostPolicy::STATUS_APPLIED],
            [BoostPolicy::STATUS_FAILED, true, BoostPolicy::STATUS_FAILED],
        ];
        foreach ($cases as [$status, $canReject, $expected]) {
            $decision = BoostPolicy::decideCallback($status, 390, 390, true, false, true, $canReject);
            $this->assertFalse($decision['apply'], $status);
            $this->assertSame($expected, $decision['next_status'], $status);
            $this->assertNotSame('refund', (string) $decision['failure_reason']);
        }
    }

    public function testFlagStaysIndependentOfNeighboringControls(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/app/Services/Boost/BoostService.php');
        $toggle = $this->methodSource($service, 'setEnabled');
        $recover = $this->methodSource($service, 'recoverUnapplied');
        $enabled = $this->methodSource($service, 'isEnabled');
        $admin = (string) file_get_contents($root . '/app/Controllers/AdminController.php');
        $routes = (string) file_get_contents($root . '/config/routes.php');
        $package = (string) file_get_contents($root . '/app/Views/business/package.php');
        $business = (string) file_get_contents($root . '/app/Services/BusinessPackageService.php');

        $this->assertStringContainsString('BoostPolicy::SETTING_KEY', $toggle);
        $this->assertStringContainsString('toggleAudit', $toggle);
        $this->assertStringContainsString('canToggle', $toggle);
        foreach (['stub_mode', 'priority_boost', 'BusinessPackage', 'consumeQuota', 'DELETE FROM', 'sort_at', 'created_at'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $toggle, $foreign);
        }
        $this->assertStringContainsString('forget', $enabled);
        $this->assertStringContainsString('resolveEnabled', $enabled);
        $this->assertStringContainsString('canToggle', $recover);
        $this->assertStringContainsString('isEnabled', $recover);
        $this->assertStringContainsString('function requireBoostAdmin', $admin);
        $this->assertStringContainsString("post('/admin/boost'", $routes);
        $this->assertDoesNotMatchRegularExpression("/get\\('\\/admin\\/boost'/", $routes);
        $this->assertStringNotContainsString('boost_enabled', $package);
        $this->assertStringNotContainsString('boost_enabled', $business);
        $this->assertStringContainsString("t('business.lim_boosts')", $package);
        foreach (BoostPolicy::UNRELATED_CONTROLS as $control) {
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
