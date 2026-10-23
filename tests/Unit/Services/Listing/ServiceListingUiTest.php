<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Listing;

use App\Core\Lang;
use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Listing\ServiceListingFeePolicy;
use PHPUnit\Framework\TestCase;

/**
 * VAS-16.4: UI показывает 500 ₸, без бесплатной квоты, без Safe Deal для service.
 */
final class ServiceListingUiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['appConfig']['url'] = '';
        $GLOBALS['appConfig']['locale'] = 'ru';
        Lang::boot();
        Lang::set('ru');
    }

    public function testCatalogCtaShowsPaidAmountNotFreeQuota(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/catalog/index.php');
        $this->assertStringContainsString('catalog.publish_service', $src);
        $this->assertStringContainsString('serviceListingFee()', $src);
        $this->assertStringNotContainsString('publish_service_free', $src);
        $this->assertStringNotContainsString('isServiceListingFreePromo()', $src);

        $label = t('catalog.publish_service', [
            'amount' => Wallet::formatMoney(ServiceListingFeePolicy::FEE_KZT),
        ]);
        $this->assertStringContainsString('500', $label);
        $this->assertStringContainsString('₸', $label);
    }

    public function testProfileFormShowsFeeAndNoFreePromoBranch(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/profile/index.php');
        $this->assertStringContainsString('service_fee_label', $src);
        $this->assertStringContainsString('service_fee_per_listing', $src);
        $this->assertStringContainsString('service_board_note', $src);
        $this->assertStringContainsString('service_edit_note', $src);
        $this->assertStringContainsString('service_publishing', $src);
        $this->assertStringContainsString('dataset.submitting', $src);
        $this->assertStringContainsString('publish_service', $src);
        $this->assertStringNotContainsString('isServiceListingFreePromo()', $src);
        $this->assertStringNotContainsString('service_board_note_free', $src);

        $feeLine = t('profile.service_fee_per_listing', [
            'amount' => Wallet::formatMoney(500),
        ]);
        $this->assertStringContainsString('500', $feeLine);
        $this->assertSame(500, ProductHelper::serviceListingFee());
    }

    public function testServiceContactRejectsEscrowPromise(): void
    {
        $text = t('product.service_contact');
        $this->assertStringContainsString('напрямую', mb_strtolower($text));
        $this->assertMatchesRegularExpression('/эскроу|Safe Deal|арбитраж/iu', $text);
        $this->assertStringContainsString('не', mb_strtolower($text));
    }

    public function testCatalogGuideStatesPaidAndNoEscrow(): void
    {
        $note = t('catalog.service_note');
        $this->assertStringContainsString('500', $note);
        $this->assertMatchesRegularExpression('/эскроу|Safe Deal|арбитраж/iu', $note);
        $this->assertStringContainsString('квот', mb_strtolower($note));
    }

    public function testProductCardServiceHasNoBuyCartPair(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/partials/product-card.php');
        $this->assertStringContainsString("\$type === 'service'", $src);
        $this->assertStringContainsString("['used', 'new', 'course', 'gig']", $src);
        $this->assertStringNotContainsString("'service', 'gig'", $src);
    }

    public function testEditDoesNotShowPublishFeeButton(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/profile/index.php');
        $this->assertStringContainsString('service_edit_note', $src);
        $this->assertStringContainsString('profile.update', $src);
    }

    public function testWalletTopupCtaOnFeeError(): void
    {
        $ctrl = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Controllers/ProfileController.php');
        $this->assertStringContainsString("error_cta'] = 'wallet'", $ctrl);
        $view = (string) file_get_contents(dirname(__DIR__, 4) . '/app/Views/profile/index.php');
        $this->assertStringContainsString("errorCta", $view);
        $this->assertStringContainsString('/wallet', $view);
        $this->assertStringContainsString('service_wallet_topup', $view);
    }
}
