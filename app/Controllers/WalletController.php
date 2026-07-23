<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;
use App\Models\Wallet;

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
            'balance' => $wallet->balance($uid),
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

        $result = (new Wallet())->deposit(Auth::id(), $amount, $source);

        if ($result['ok']) {
            $_SESSION['flash'] = t('wallet.deposit_ok', [
                'amount' => Wallet::formatMoney($amount),
            ]);
        } else {
            $_SESSION['error'] = $result['error'] ?? t('wallet.op_failed');
        }
        $this->redirect('/wallet');
    }

    public function withdraw(): void
    {
        Auth::requireLogin();
        $amount = (int) preg_replace('/\D/', '', (string) ($_POST['amount'] ?? '0'));
        $dest = (string) ($_POST['dest'] ?? 'card');
        if (!in_array($dest, ['card', 'kaspi'], true)) {
            $dest = 'card';
        }

        $result = (new Wallet())->withdraw(Auth::id(), $amount, $dest);

        if ($result['ok']) {
            $_SESSION['flash'] = t('wallet.withdraw_ok', [
                'amount' => Wallet::formatMoney($amount),
            ]);
        } else {
            $_SESSION['error'] = $result['error'] ?? t('wallet.op_failed');
        }
        $this->redirect('/wallet');
    }
}
