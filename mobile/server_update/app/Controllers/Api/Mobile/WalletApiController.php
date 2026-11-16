<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AMLService;
use App\Services\HalykEpay\Client as HalykClient;
use App\Services\MobileApi\Presenter;

/**
 * Кошелёк в приложении. Те же правила, что у сайта (WalletController):
 * пополнение картой — форма Halyk ePay; вывод — только в режиме симуляции платежей.
 */
final class WalletApiController extends BaseMobileController
{
    private const MIN_DEPOSIT = 100;

    /** GET /wallet */
    public function index(): void
    {
        $uid = $this->requireUserId();
        $wallet = new Wallet();
        $simulated = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false);
        $this->ok([
            'balance' => ['amount' => $wallet->balance($uid), 'currency' => 'KZT'],
            'held' => ['amount' => $wallet->heldBalance($uid), 'currency' => 'KZT'],
            'can_deposit' => $simulated || (new HalykClient())->isConfigured(),
            'can_withdraw' => $simulated,
            'min_deposit' => self::MIN_DEPOSIT,
            'transactions' => array_map(static fn (array $t): array => [
                'id' => (int) $t['id'],
                'type' => (string) $t['type'],
                'label' => Wallet::typeLabel((string) $t['type']),
                'amount' => (int) $t['amount'],
                'balance_after' => (int) $t['balance_after'],
                'order_id' => !empty($t['order_id']) ? (int) $t['order_id'] : null,
                'created_at' => Presenter::date($t['created_at'] ?? null),
            ], $wallet->transactions($uid, 100)),
        ]);
    }

    /** POST /wallet/deposit {amount} → {status: paid|pending, payment_url?} */
    public function deposit(): void
    {
        $uid = $this->requireUserId();
        $amount = (int) preg_replace('/\D/', '', $this->input('amount', '0'));
        if ($amount < self::MIN_DEPOSIT) {
            $this->fail('min_deposit', 422, t('wallet.min_deposit'), ['amount' => t('wallet.min_deposit')]);
        }

        if (!empty($GLOBALS['appConfig']['allow_simulated_payments'])) {
            $res = (new Wallet())->deposit($uid, $amount, 'card');
            if (empty($res['ok'])) {
                $this->fail('wallet_failed', 422, (string) ($res['error'] ?? t('wallet.op_failed')));
            }
            ActivityLogger::info('wallet.deposit', 'Пополнение (mobile) на ' . Wallet::formatMoney($amount), 'wallet', $uid, ['amount' => $amount]);
            $this->ok(['status' => 'paid', 'balance' => ['amount' => (new Wallet())->balance($uid), 'currency' => 'KZT']]);
        }

        $client = new HalykClient();
        if (!$client->isConfigured()) {
            $this->fail('payments_disabled', 503, t('wallet.payments_disabled'));
        }
        $user = (new User())->find($uid) ?? [];
        $intent = $client->initPayment([
            'order_id' => 'WALLET-' . $uid . '-' . time() . '-' . bin2hex(random_bytes(3)),
            'amount' => $amount,
            'description' => 'Пополнение баланса кошелька Zakopeyki.kz',
            'user_id' => (string) $uid,
            'user_email' => (string) ($user['email'] ?? ''),
            'user_phone' => (string) ($user['phone'] ?? ''),
        ]);
        if (empty($intent['ok']) || empty($intent['redirect_url'])) {
            $this->fail('payments_disabled', 503, t('wallet.payments_disabled'));
        }
        $url = (string) $intent['redirect_url'];
        if (preg_match('#/payments/halyk/sim/([a-f0-9]{32})#', $url, $m)) {
            $url = '/payments/halyk/app/' . $m[1];
        }
        $this->ok(['status' => 'pending', 'payment_url' => ProductHelper::absoluteUrl($url)]);
    }

    /** POST /wallet/withdraw {amount, dest: card|kaspi, iin?|bin?} */
    public function withdraw(): void
    {
        $uid = $this->requireUserId();
        if (empty($GLOBALS['appConfig']['allow_simulated_payments'])) {
            $this->fail('payments_disabled', 503, t('wallet.payments_disabled'));
        }
        $amount = (int) preg_replace('/\D/', '', $this->input('amount', '0'));
        $dest = in_array($this->input('dest'), ['card', 'kaspi'], true) ? $this->input('dest') : 'card';
        $aml = AMLService::make()->screenUser($uid, $this->input('bin', $this->input('iin')), 'withdraw');
        if (empty($aml['ok'])) {
            $this->fail('aml_blocked', 403, (string) ($aml['error'] ?? t('flash.aml_blocked')), ['iin' => (string) ($aml['error'] ?? '')]);
        }
        $res = (new Wallet())->withdraw($uid, $amount, $dest);
        if (empty($res['ok'])) {
            $this->fail('wallet_failed', 422, (string) ($res['error'] ?? t('wallet.op_failed')));
        }
        ActivityLogger::info('wallet.withdraw', 'Вывод (mobile) ' . Wallet::formatMoney($amount), 'wallet', $uid, ['amount' => $amount, 'dest' => $dest]);
        $this->ok(['balance' => ['amount' => (new Wallet())->balance($uid), 'currency' => 'KZT']]);
    }
}
