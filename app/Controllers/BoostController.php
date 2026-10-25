<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\Boost\BoostPolicy;
use App\Services\Boost\BoostService;

class BoostController extends Controller
{
    public function purchase(string $id): void
    {
        Auth::requireLogin();

        $result = (new BoostService())->beginPurchase((int) Auth::id(), (int) $id, [
            'idempotency_key' => $_POST['idempotency_key'] ?? null,
            'amount' => $_POST['amount'] ?? null,
            'price' => $_POST['price'] ?? null,
        ]);

        if (!empty($result['redirect_url'])) {
            $this->redirect((string) $result['redirect_url']);
        }

        if (!empty($result['already_applied'])) {
            $_SESSION['flash'] = t('boost.applied');
            $this->redirect('/profile/lots/' . (int) $id . '/promote');
        }

        $code = (string) ($result['error_code'] ?? 'generic');
        $key = match ($code) {
            'disabled' => 'boost.err_disabled',
            'forbidden' => 'boost.err_forbidden',
            'bad_status' => 'boost.err_bad_status',
            'not_found' => 'boost.err_not_found',
            'gateway_not_configured', 'gateway_init_failed' => 'boost.err_gateway',
            'awaiting_apply' => 'boost.paid_waiting',
            'payment_pending' => 'boost.payment_pending',
            'payment_failed' => 'boost.payment_failed',
            'idempotency_conflict' => 'boost.err_generic',
            default => 'boost.err_generic',
        };
        $_SESSION['error'] = t($key);
        $this->redirect('/profile/lots/' . (int) $id . '/promote');
    }

    public function status(): void
    {
        Auth::requireLogin();
        header('Cache-Control: private, no-store');

        $states = (new BoostService())->statesForUser((int) Auth::id());
        $items = [];
        foreach ($states as $productId => $ui) {
            $code = (string) ($ui['code'] ?? 'hidden');
            if ($code === 'hidden') {
                continue;
            }
            $key = BoostPolicy::uiMessageKey($code);
            $message = $key ? t($key) : '';
            if (($ui['notice'] ?? null) === 'gateway') {
                $extra = t('boost.err_gateway');
                $message = $message === '' ? $extra : $message . ' ' . $extra;
            }
            $items[] = [
                'id' => (int) $productId,
                'code' => $code,
                'can_purchase' => !empty($ui['can_purchase']),
                'continue_payment' => !empty($ui['continue_payment']),
                'message' => $message,
            ];
        }

        $this->json(['ok' => true, 'items' => $items]);
    }
}
