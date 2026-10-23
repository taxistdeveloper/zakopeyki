<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Models\Payment;
use App\Models\Wallet;
use App\Services\Boost\BoostPolicy;
use App\Services\Finance\FinanceJournalPolicy;
use App\Services\Finance\FinanceJournalService;
use PHPUnit\Framework\TestCase;

/**
 * VAS-18.3: read-only finance journal — classifier, confirmed, auth, no mutate.
 */
final class FinanceJournalTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        unset($_SESSION['user'], $_SESSION['user_id']);
    }

    public function testOnlyAdminCanView(): void
    {
        $this->assertTrue(FinanceJournalPolicy::canView('admin'));
        foreach (['manager', 'user', '', null, 'Admin'] as $role) {
            $this->assertFalse(FinanceJournalPolicy::canView($role), (string) $role);
        }
    }

    public function testClassifySeparatesStreamsByReliableIds(): void
    {
        $this->assertSame(
            FinanceJournalPolicy::STREAM_VAS,
            FinanceJournalPolicy::classify(['pg_order_id' => 'zk-boost-abc'])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_VAS,
            FinanceJournalPolicy::classify(['pg_order_id' => 'zk-vip-1'])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_DELIVERY,
            FinanceJournalPolicy::classify(['pg_order_id' => 'zk-del-9'])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_ESCROW,
            FinanceJournalPolicy::classify(['source_table' => 'payments'])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_VAS,
            FinanceJournalPolicy::classify([
                'source_table' => 'wallet_transactions',
                'wallet_type' => Wallet::TYPE_LISTING_FEE,
            ])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_ARBITRATION,
            FinanceJournalPolicy::classify([
                'source_table' => 'wallet_transactions',
                'wallet_type' => Wallet::TYPE_PLATFORM_COMMISSION,
            ])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_WALLET,
            FinanceJournalPolicy::classify([
                'source_table' => 'wallet_transactions',
                'wallet_type' => Wallet::TYPE_DEPOSIT,
            ])
        );
        $this->assertSame(
            FinanceJournalPolicy::STREAM_ESCROW,
            FinanceJournalPolicy::classify([
                'source_table' => 'wallet_transactions',
                'wallet_type' => Wallet::TYPE_ESCROW_HOLD,
            ])
        );
    }

    public function testPaymentConfirmedNotFromOrderCreationAlone(): void
    {
        $this->assertFalse(FinanceJournalPolicy::isPaymentConfirmed([
            'stream_type' => FinanceJournalPolicy::STREAM_VAS,
            'source_table' => 'boost_orders',
            'order_status' => BoostPolicy::STATUS_PENDING,
            'payment_status' => BoostPolicy::STATUS_PENDING,
            'paid_at' => null,
            'pg_payment_id' => null,
        ]));

        $this->assertTrue(FinanceJournalPolicy::isPaymentConfirmed([
            'stream_type' => FinanceJournalPolicy::STREAM_VAS,
            'source_table' => 'boost_orders',
            'order_status' => BoostPolicy::STATUS_APPLIED,
            'payment_status' => BoostPolicy::STATUS_APPLIED,
            'paid_at' => '2026-10-01 12:00:00',
            'pg_payment_id' => 'pg-1',
        ]));

        $this->assertFalse(FinanceJournalPolicy::isPaymentConfirmed([
            'stream_type' => FinanceJournalPolicy::STREAM_ESCROW,
            'source_table' => 'payments',
            'order_status' => 'escrowed',
            'payment_status' => Payment::STATUS_PENDING,
            'paid_at' => null,
            'pg_payment_id' => null,
        ]));

        $this->assertTrue(FinanceJournalPolicy::isPaymentConfirmed([
            'stream_type' => FinanceJournalPolicy::STREAM_ESCROW,
            'source_table' => 'payments',
            'order_status' => 'escrowed',
            'payment_status' => Payment::STATUS_PAID,
            'paid_at' => '2026-10-01 12:00:00',
            'pg_payment_id' => 'x',
        ]));
    }

    public function testSanitizeRejectsBadFiltersAndCapsPageSize(): void
    {
        $bad = FinanceJournalPolicy::sanitizeListFilters(['stream_type' => 'hack']);
        $this->assertFalse($bad['ok']);
        $this->assertSame('invalid_stream', $bad['error_code']);

        $range = FinanceJournalPolicy::sanitizeListFilters([
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-01',
        ]);
        $this->assertFalse($range['ok']);

        $ok = FinanceJournalPolicy::sanitizeListFilters(['per_page' => 9999, 'page' => 2]);
        $this->assertTrue($ok['ok']);
        $this->assertSame(FinanceJournalPolicy::MAX_PER_PAGE, $ok['filters']['per_page']);
        $this->assertSame(2, $ok['filters']['page']);
    }

    public function testEntryIdRoundTripWhitelist(): void
    {
        $id = FinanceJournalPolicy::makeEntryId('boost_orders', 42);
        $this->assertSame('boost_orders:42', $id);
        $parsed = FinanceJournalPolicy::parseEntryId($id);
        $this->assertNotNull($parsed);
        $this->assertSame('boost_orders', $parsed['source_table']);
        $this->assertSame(42, $parsed['source_id']);

        $this->assertNull(FinanceJournalPolicy::parseEntryId('users:1'));
        $this->assertNull(FinanceJournalPolicy::parseEntryId('../etc/passwd'));
        $this->assertNull(FinanceJournalPolicy::parseEntryId('boost_orders:0'));
    }

    public function testGuestListAndDetailForbidden(): void
    {
        $svc = new FinanceJournalService();
        $list = $svc->list([]);
        $this->assertFalse($list['ok']);
        $this->assertSame('forbidden', $list['error_code']);

        $detail = $svc->detail('boost_orders:1');
        $this->assertFalse($detail['ok']);
        $this->assertSame('forbidden', $detail['error_code']);
    }

    public function testRoutesAndControllerAreReadOnlyJson(): void
    {
        $routes = (string) file_get_contents($this->root . '/config/routes.php');
        $this->assertStringContainsString("get('/admin/finance-journal'", $routes);
        $this->assertStringContainsString("get('/admin/finance-journal/{entryId}'", $routes);
        $this->assertStringNotContainsString("post('/admin/finance-journal'", $routes);
        $this->assertStringNotContainsString("put('/admin/finance-journal'", $routes);
        $this->assertStringNotContainsString("delete('/admin/finance-journal'", $routes);

        $ctrl = (string) file_get_contents($this->root . '/app/Controllers/AdminController.php');
        $list = $this->methodSource($ctrl, 'financeJournalList');
        $detail = $this->methodSource($ctrl, 'financeJournalDetail');
        $this->assertStringContainsString('FinanceJournalService', $list);
        $this->assertStringContainsString('read_only', $list);
        $this->assertStringContainsString('requireLogin', $list);
        $this->assertStringNotContainsString('setEnabled', $list);
        $this->assertStringNotContainsString('completeFromGateway', $list);
        $this->assertStringNotContainsString('chargeListingFee', $detail);
        $this->assertStringNotContainsString('UPDATE ', $detail);
    }

    public function testServiceDoesNotTriggerPaymentsOrMutations(): void
    {
        $src = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        foreach ([
            'completeFromGateway',
            'createPaymentIntent',
            'initPayment',
            'chargeListingFee',
            'setEnabled',
            'arbiterFullRefund',
            'settle(',
            '->deposit(',
            '->withdraw(',
        ] as $banned) {
            $this->assertStringNotContainsString($banned, $src, $banned);
        }
        // Чтение escrow_refund как типа ledger допустимо; вызовов возврата быть не должно.
        $this->assertStringNotContainsString('->refund(', $src);
        $this->assertStringContainsString('read-only', strtolower($src));
        $this->assertStringContainsString('UNION ALL', $src);
    }

    public function testSecretsNotInPolicyPayloadKeys(): void
    {
        $keys = FinanceJournalPolicy::secretKeysForbiddenInPayload();
        $this->assertContains('cvv', $keys);
        $this->assertContains('pan', $keys);
        $this->assertContains('secret_key', $keys);
        $this->assertContains('pg_sig', $keys);

        $svcSrc = (string) file_get_contents($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $this->assertStringContainsString('stripSecrets', $svcSrc);
        $this->assertStringNotContainsString('secret_key', $this->methodSource($svcSrc, 'publicListItem'));
    }

    public function testListingFeeNotMixedWithEscrowInClassifier(): void
    {
        $listing = FinanceJournalPolicy::classify([
            'source_table' => 'wallet_transactions',
            'wallet_type' => Wallet::TYPE_LISTING_FEE,
        ]);
        $escrow = FinanceJournalPolicy::classify([
            'source_table' => 'payments',
        ]);
        $this->assertSame(FinanceJournalPolicy::STREAM_VAS, $listing);
        $this->assertSame(FinanceJournalPolicy::STREAM_ESCROW, $escrow);
        $this->assertNotSame($listing, $escrow);
    }

    public function testNoSchemaMigrationRequiredForJournal(): void
    {
        $migrations = glob($this->root . '/database/*finance*') ?: [];
        $this->assertSame([], $migrations);
        $this->assertFileExists($this->root . '/app/Services/Finance/FinanceJournalService.php');
        $this->assertFileExists($this->root . '/app/Services/Finance/FinanceJournalPolicy.php');
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
