<?php

declare(strict_types=1);

namespace {
    if (!function_exists('csrf_field')) {
        function csrf_field(): string
        {
            return '<input type="hidden" name="_csrf" value="test-token">';
        }
    }
    if (!function_exists('js_encode')) {
        function js_encode(mixed $data): string
        {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
        }
    }
}

namespace Tests\Unit\Services\Ads {

use App\Core\Lang;
use App\Services\Ads\AdPolicy;
use PHPUnit\Framework\TestCase;

final class AdUiTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['appConfig']['url'] = '';
        Lang::set('ru');
    }

    public function testCampaignFormShowsServerRatesAndDoesNotTreatDomAsAnImpression(): void
    {
        $html = $this->render([
            'hero' => true,
            'category' => true,
            'sidebar' => false,
            'exclusive' => true,
        ], []);

        $this->assertStringContainsString('1 200 ₸ за 1 000 показов', $html);
        $this->assertStringContainsString('800 ₸ за 1 000 показов', $html);
        $this->assertStringContainsString('19 900 ₸ за неделю', $html);
        $this->assertStringNotContainsString('600 ₸ за 1 000 показов', $html);
        $this->assertStringContainsString('name="format"', $html);
        $this->assertStringContainsString('id="ad-preview"', $html);
        $this->assertStringContainsString('На модерации', $this->render(['hero' => true], [[
            'id' => 4,
            'format' => AdPolicy::FORMAT_HERO,
            'title' => 'Весна',
            'status' => AdPolicy::STATUS_MODERATION,
            'amount' => 1200,
            'impressions_used' => 0,
            'impressions_paid' => 1000,
            'clicks' => 0,
            'target_url' => 'https://shop.example',
            'ends_at' => null,
            'reject_reason' => null,
            'redirect_url' => null,
        ]]));
    }

    public function testDisabledFormatHidesTheOrderForm(): void
    {
        $html = $this->render([
            'hero' => false,
            'category' => false,
            'sidebar' => false,
            'exclusive' => false,
        ], []);
        $this->assertStringContainsString('не принимает новые заказы', $html);
        $this->assertStringNotContainsString('name="format"', $html);
    }

    /**
     * @param array<string, bool> $flags
     * @param list<array<string, mixed>> $campaigns
     */
    private function render(array $flags, array $campaigns): string
    {
        $flash = null;
        $error = null;
        ob_start();
        include dirname(__DIR__, 4) . '/app/Views/ads/index.php';

        return (string) ob_get_clean();
    }
}
}
