<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Models\Notification;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AMLService;

class WalletController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $wallet = new Wallet();
        $uid = Auth::id();
        $n = new Notification();

        $this->view('wallet/index', [
            'title' => t('wallet.title'),
            'currentNav' => 'wallet',
            'user' => Auth::user(),
            'balance' => $wallet->balance($uid),
            'heldBalance' => $wallet->heldBalance($uid),
            'transactions' => $wallet->transactions($uid, 40),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function deposit(): void
    {
        Auth::requireLogin();

        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $source = (string) ($_POST['source'] ?? 'card');
        if (!in_array($source, ['card', 'kaspi'], true)) {
            $source = 'card';
        }

        if ($amount < 100) {
            $_SESSION['error'] = t('wallet.min_deposit');
            $this->redirect('/wallet');
            return;
        }

        // Если разрешена симуляция — быстрое зачисление (локальная среда)
        if (!empty($GLOBALS['appConfig']['allow_simulated_payments'])) {
            $result = (new Wallet())->deposit(Auth::id(), $amount, $source);

            if ($result['ok']) {
                ActivityLogger::info('wallet.deposit', 'Пополнение на ' . Wallet::formatMoney($amount), 'wallet', Auth::id(), [
                    'amount' => $amount,
                    'source' => $source,
                ]);
                $_SESSION['flash'] = t('wallet.deposit_ok', [
                    'amount' => Wallet::formatMoney($amount),
                ]);
            } else {
                ActivityLogger::warning('wallet.deposit', $result['error'] ?? 'Ошибка пополнения', 'wallet', Auth::id(), [
                    'amount' => $amount,
                ]);
                $_SESSION['error'] = $result['error'] ?? t('wallet.op_failed');
            }
            $this->redirect('/wallet');
            return;
        }

        // Боевой режим: инициация платежа через Halyk ePay (QA-0004)
        $client = new \App\Services\HalykEpay\Client();
        if ($client->isConfigured()) {
            $user = Auth::user();
            $pgOrderId = 'WALLET-' . Auth::id() . '-' . time() . '-' . bin2hex(random_bytes(3));
            // M-01 / B0.3: у HalykEpay\Client нет createPaymentIntent — вызов падал fatal error
            $intent = $client->initPayment([
                'order_id' => $pgOrderId,
                'amount' => $amount,
                'description' => 'Пополнение баланса кошелька Zakopeyki.kz',
                'user_id' => (string) Auth::id(),
                'user_email' => (string) ($user['email'] ?? ''),
                'user_phone' => (string) ($user['phone'] ?? ''),
            ]);
            if (!empty($intent['ok']) && !empty($intent['redirect_url'])) {
                header('Location: ' . $intent['redirect_url']);
                exit;
            }
        }

        $_SESSION['error'] = t('wallet.payments_disabled');
        $this->redirect('/wallet');
    }

    public function withdraw(): void
    {
        Auth::requireLogin();
        if (!($GLOBALS['appConfig']['allow_simulated_payments'] ?? false)) {
            $_SESSION['error'] = t('wallet.payments_disabled');
            $this->redirect('/wallet');
        }

        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $dest = (string) ($_POST['dest'] ?? 'card');
        if (!in_array($dest, ['card', 'kaspi'], true)) {
            $dest = 'card';
        }

        $aml = AMLService::make()->screenUser(
            Auth::id(),
            (string) ($_POST['bin'] ?? $_POST['iin'] ?? ''),
            'withdraw'
        );
        if (empty($aml['ok'])) {
            $_SESSION['error'] = $aml['error'] ?? t('flash.aml_blocked');
            $this->redirect('/wallet');
        }
        $fresh = (new User())->find(Auth::id());
        if ($fresh) {
            Auth::refresh($fresh);
        }

        $result = (new Wallet())->withdraw(Auth::id(), $amount, $dest);

        if ($result['ok']) {
            ActivityLogger::info('wallet.withdraw', 'Вывод ' . Wallet::formatMoney($amount), 'wallet', Auth::id(), [
                'amount' => $amount,
                'dest' => $dest,
            ]);
            $_SESSION['flash'] = t('wallet.withdraw_ok', [
                'amount' => Wallet::formatMoney($amount),
            ]);
        } else {
            ActivityLogger::warning('wallet.withdraw', $result['error'] ?? 'Ошибка вывода', 'wallet', Auth::id(), [
                'amount' => $amount,
            ]);
            $_SESSION['error'] = $result['error'] ?? t('wallet.op_failed');
        }
        $this->redirect('/wallet');
    }
}
