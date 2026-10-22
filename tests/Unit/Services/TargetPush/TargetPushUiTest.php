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

namespace Tests\Unit\Services\TargetPush {

use App\Core\Lang;
use App\Services\TargetPush\TargetPushPolicy;
use PHPUnit\Framework\TestCase;

final class TargetPushUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testOfferShowsTheTariffAndHidesTheFormWhenDisabled(): void
    {
        $html = $this->render(true, [], false);
        $this->assertStringContainsString('5 900 ₸ за одну отправку', $html);
        $this->assertStringContainsString('10 000', $html);
        $this->assertStringContainsString('name="category"', $html);
        $this->assertStringContainsString('id="tp-preview"', $html);
        $this->assertStringContainsString('Предпросмотр ничего не отправляет', $html);
        $this->assertStringContainsString('не подтверждение доставки', $html);

        $off = $this->render(false, [], false);
        $this->assertStringContainsString('не принимает новые заказы', $off);
        $this->assertStringNotContainsString('name="category"', $off);
    }

    public function testStatusesAndResultsDoNotClaimDeviceDelivery(): void
    {
        $html = $this->render(true, [[
            'id' => 3,
            'title' => 'Весна',
            'body' => 'Скидка на смартфоны',
            'status' => TargetPushPolicy::STATUS_PAID,
            'category_key' => 'Электроника и бытовая техника',
            'amount' => 5900,
            'audience_count' => 12,
            'accepted_count' => 0,
            'failed_count' => 0,
            'send_day' => null,
            'reject_reason' => null,
            'redirect_url' => null,
            'target_url' => null,
        ]], false);

        $this->assertStringContainsString('Оплачено, не запущено', $html);
        $this->assertStringContainsString('Запустить отправку', $html);
        $this->assertStringContainsString('Принято в уведомления: 0', $html);
        $this->assertStringNotContainsString('Доставлено', $html);
    }

    public function testAdminQueueShowsAudienceWithoutGivingItToAGuestForm(): void
    {
        $rows = [[
            'id' => 8,
            'title' => 'Проверка',
            'body' => 'Текст кампании',
            'status' => TargetPushPolicy::STATUS_MODERATION,
            'category_key' => 'Электроника и бытовая техника',
            'amount' => 5900,
            'audience_count' => 4,
            'accepted_count' => 0,
            'failed_count' => 0,
            'started_at' => null,
            'failure_reason' => null,
        ]];
        $enabled = true;
        $flash = null;
        $error = null;
        ob_start();
        include dirname(__DIR__, 4) . '/app/Views/admin/target-push.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Аудитория: 4', $html);
        $this->assertStringContainsString('/admin/target-push/8/moderate', $html);
        $this->assertStringContainsString('name="decision"', $html);
        $this->assertStringNotContainsString('name="launch"', $html);
    }

    /**
     * @param list<array<string, mixed>> $campaigns
     */
    private function render(bool $enabled, array $campaigns, bool $unsubscribed): string
    {
        $flash = null;
        $error = null;
        ob_start();
        include dirname(__DIR__, 4) . '/app/Views/target-push/index.php';

        return (string) ob_get_clean();
    }
}
}
