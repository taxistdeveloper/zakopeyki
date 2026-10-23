<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Core\Lang;
use App\Services\Finance\FinanceJournalPolicy;
use PHPUnit\Framework\TestCase;

/**
 * VAS-18.4: admin UI журнала — реальный backend, без mutate и секретов.
 */
final class FinanceJournalUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
        $GLOBALS['appConfig']['url'] = '';
        $GLOBALS['appConfig']['locale'] = 'ru';
        Lang::boot();
        Lang::set('ru');
    }

    public function testListViewUsesServerFiltersAndRealServiceContract(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/finance-journal.php');
        $this->assertStringContainsString('method="get"', $view);
        $this->assertStringContainsString('/admin/finance-journal', $view);
        $this->assertStringContainsString('name="stream_type"', $view);
        $this->assertStringContainsString('name="q"', $view);
        $this->assertStringContainsString('name="payment_confirmed"', $view);
        $this->assertStringContainsString('name="date_from"', $view);
        $this->assertStringContainsString('FinanceJournalPolicy', $view);
        $this->assertStringContainsString('payment_confirmed', $view);
        $this->assertStringContainsString('order_status', $view);
        $this->assertStringContainsString('payment_status', $view);
        $this->assertStringNotContainsString('demo_items', $view);
        $this->assertStringNotContainsString('mock', strtolower($view));
    }

    public function testSeparateStatusColumnsAndConfirmedBadge(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/finance-journal.php');
        $this->assertStringContainsString('finance_col_order_status', $view);
        $this->assertStringContainsString('finance_col_payment_status', $view);
        $this->assertStringContainsString('finance_col_confirmed', $view);
        $this->assertStringContainsString('payment_confirmed', $view);
    }

    public function testNoMutationActionsInUi(): void
    {
        foreach (['finance-journal.php', 'finance-journal-show.php'] as $file) {
            $view = (string) file_get_contents($this->root . '/app/Views/admin/' . $file);
            $this->assertStringNotContainsString('method="post"', $view);
            $this->assertStringNotContainsString('refund', strtolower($view));
            $this->assertStringNotContainsString('charge', strtolower($view));
            $this->assertStringNotContainsString('mark_paid', $view);
            $this->assertStringNotContainsString('completeFromGateway', $view);
            $this->assertStringNotContainsString('name="amount"', $view);
        }
    }

    public function testDetailShowsHistoryWithoutInventingAndNoSecrets(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/finance-journal-show.php');
        $this->assertStringContainsString('history', $view);
        $this->assertStringContainsString('finance_history_hint', $view);
        $this->assertStringContainsString('finance_history_empty', $view);
        $this->assertStringContainsString('pg_order_id', $view);
        $this->assertStringContainsString('pg_payment_id', $view);
        $this->assertStringContainsString('finance_status_hint', $view);
        foreach (['cvv', 'cvc', 'pan', 'secret_key', 'password', 'card_number'] as $secret) {
            $this->assertStringNotContainsString($secret, strtolower($view));
        }
    }

    public function testStreamLabelsCoverPolicyStreams(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/finance-journal.php');
        foreach ([
            'STREAM_VAS',
            'STREAM_ESCROW',
            'STREAM_ARBITRATION',
            'STREAM_COMMISSION_OTHER',
            'STREAM_DELIVERY',
            'STREAM_WALLET',
            'STREAM_OTHER',
        ] as $const) {
            $this->assertStringContainsString('FinanceJournalPolicy::' . $const, $view);
        }
        $this->assertSame(FinanceJournalPolicy::STREAMS, [
            FinanceJournalPolicy::STREAM_VAS,
            FinanceJournalPolicy::STREAM_ESCROW,
            FinanceJournalPolicy::STREAM_ARBITRATION,
            FinanceJournalPolicy::STREAM_COMMISSION_OTHER,
            FinanceJournalPolicy::STREAM_DELIVERY,
            FinanceJournalPolicy::STREAM_WALLET,
            FinanceJournalPolicy::STREAM_OTHER,
        ]);
        $this->assertStringContainsString('finance_stream_vas', $view);
        $this->assertStringContainsString('finance_stream_escrow', $view);
        $this->assertStringContainsString('finance_stream_arbitration', $view);
    }

    public function testControllerServesHtmlAndJsonFromSameService(): void
    {
        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $list = $this->methodSource($ctrl, 'financeJournalList');
        $this->assertStringContainsString("view('admin/finance-journal'", $list);
        $this->assertStringContainsString('application/json', $list);
        $this->assertStringContainsString('FinanceJournalService', $list);
        $this->assertStringContainsString('requireAdmin', $list);
        $this->assertStringContainsString('read_only', $list);

        $detail = $this->methodSource($ctrl, 'financeJournalDetail');
        $this->assertStringContainsString("view('admin/finance-journal-show'", $detail);
        $this->assertStringContainsString('detail(', $detail);
    }

    public function testIndexNavLinksToJournal(): void
    {
        $index = (string) file_get_contents($this->root . '/app/Views/admin/index.php');
        $this->assertStringContainsString('/admin/finance-journal', $index);
        $this->assertStringContainsString('finance_journal', $index);
        $this->assertStringContainsString('financeJournalEnabled', $index);
        $this->assertStringContainsString('/admin/finance-journal/module', $index);
    }

    public function testControllerHandlesModuleDisabled(): void
    {
        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $list = $this->methodSource($ctrl, 'financeJournalList');
        $this->assertStringContainsString('module_disabled', $list);
        $this->assertStringContainsString('finance_journal_module_off_flash', $list);
    }

    public function testLangKeysPresent(): void
    {
        $this->assertNotSame('', trim(t('admin.finance_journal')));
        $this->assertStringContainsString('просмотр', mb_strtolower(t('admin.finance_journal_readonly_note')));
        $this->assertNotSame('', trim(t('admin.finance_stream_vas')));
    }

    public function testPaginationUsesServerPageLinks(): void
    {
        $view = (string) file_get_contents($this->root . '/app/Views/admin/finance-journal.php');
        $this->assertStringContainsString("buildUrl(['page'", $view);
        $this->assertStringContainsString('finance_prev', $view);
        $this->assertStringContainsString('finance_next', $view);
        $this->assertStringNotContainsString('fetchAllWithoutLimit', $view);
    }

    private function methodSource(string $src, string $method): string
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*\S+\s*)?\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('method not found: ' . $method);
        }
        $start = (int) $m[0][1];
        $brace = strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        return substr($src, $start);
    }
}
