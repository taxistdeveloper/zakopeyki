<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Bonus;

class BonusController extends Controller
{
    public function index(): void
    {
        $bonus = new Bonus();
        $uid = Auth::check() ? (int) Auth::id() : 0;
        $canUseGym = $uid > 0 && $bonus->canUseGym($uid);

        $this->view('bonuses/index', [
            'title' => t('bonuses.title'),
            'currentNav' => 'bonuses',
            'loggedIn' => $uid > 0,
            'balance' => $uid > 0 ? $bonus->balance($uid) : 0,
            'canUseGym' => $canUseGym,
            'gymPass' => $canUseGym ? $bonus->gymPass($uid) : null,
            'partnerGyms' => $bonus->partnerGyms(),
            'transactions' => $uid > 0 ? $bonus->transactions($uid, 40) : [],
            'earlyBird' => $bonus->earlyBirdStats(),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function verify(string $code): void
    {
        $bonus = new Bonus();
        $result = $bonus->verifyGymPass(rawurldecode($code));

        $this->view('bonuses/verify', [
            'title' => t('bonuses.verify_title'),
            'currentNav' => 'bonuses',
            'result' => $result,
            'partnerGyms' => $bonus->partnerGyms(),
        ]);
    }
}
