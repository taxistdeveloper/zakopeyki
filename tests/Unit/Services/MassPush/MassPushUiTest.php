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

namespace Tests\Unit\Services\MassPush {

use App\Core\Lang;
use App\Services\MassPush\MassPushPolicy;
use PHPUnit\Framework\TestCase;

final class MassPushUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testOfferShowsTheTariffAndHidesTheFormWhenDisabled(): void
    {
        $html = $this->render(true, [], false);
        $this->assertStringContainsString('14 900 ₸ за одну отправку', $html);
        $this->assertStringNotContainsString('name="category"', $html);
        $this->assertStringContainsString('id="mp-preview"', $html);
        $this->assertStringContainsString('Предпросмотр ничего не отправляет', $html);
        $this->assertStringContainsString('не подтверждение доставки', $html);
        $this->assertStringContainsString('обязательна', $html);

        $off = $this->render(false, [], false);
        $this->assertStringContainsString('не принимает новые заказы', $off);
        $this->assertStringNotContainsString('name="title"', $off);
    }

    public function testStatusesAndResultsDoNotClaimDeviceDelivery(): void
    {
        $html = $this->render(true, [[
            'id' => 3,
            'title' => 'Весна',
            'body' => 'Скидка на всё',
            'status' => MassPushPolicy::STATUS_PAID,
            'amount' => 14900,
            'audience_count' => 12,
            'accepted_count' => 0,
            'failed_count' => 0,
            'started_at' => null,
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
            'status' => MassPushPolicy::STATUS_MODERATION,
            'amount' => 14900,
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
        include dirname(__DIR__, 4) . '/app/Views/admin/mass-push.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Аудитория: 4', $html);
        $this->assertStringContainsString('/admin/mass-push/8/moderate', $html);
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
        include dirname(__DIR__, 4) . '/app/Views/mass-push/index.php';

        return (string) ob_get_clean();
    }
}
}
