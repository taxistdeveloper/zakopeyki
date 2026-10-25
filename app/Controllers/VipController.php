<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\Vip\VipPolicy;
use App\Services\Vip\VipService;

class VipController extends Controller
{
    public function purchase(string $id): void
    {
        Auth::requireLogin();

        $result = (new VipService())->beginPurchase((int) Auth::id(), (int) $id, [
            'idempotency_key' => $_POST['idempotency_key'] ?? null,
            'amount' => $_POST['amount'] ?? null,
            'price' => $_POST['price'] ?? null,
            'enabled' => $_POST['enabled'] ?? null,
            'days' => $_POST['days'] ?? null,
            'category' => $_POST['category'] ?? null,
        ]);

        if (!empty($result['redirect_url'])) {
            $this->redirect((string) $result['redirect_url']);
        }

        if (!empty($result['already_applied'])) {
            $until = VipPolicy::formatUntil(isset($result['ends_at']) ? (string) $result['ends_at'] : null);
            $left = isset($result['lifts_remaining']) ? (string) $result['lifts_remaining'] : '';
            $_SESSION['flash'] = !empty($result['active'])
                ? t('vip.active', ['until' => $until, 'left' => $left])
                : t('vip.expired');
            $this->redirect('/profile/lots/' . (int) $id . '/promote');
        }

        $code = (string) ($result['error_code'] ?? 'generic');
        $key = match ($code) {
            'disabled' => 'vip.err_disabled',
            'forbidden' => 'vip.err_forbidden',
            'bad_status' => 'vip.err_bad_status',
            'not_found' => 'vip.err_not_found',
            'already_active' => 'vip.err_already_active',
            'gateway_not_configured', 'gateway_init_failed' => 'vip.err_gateway',
            'awaiting_apply' => 'vip.paid_waiting',
            'payment_pending' => 'vip.payment_pending',
            'payment_failed' => 'vip.payment_failed',
            'idempotency_conflict' => 'vip.err_generic',
            default => 'vip.err_generic',
        };
        $until = VipPolicy::formatUntil(isset($result['ends_at']) ? (string) $result['ends_at'] : null);
        $_SESSION['error'] = t($key, ['until' => $until]);
        $this->redirect('/profile/lots/' . (int) $id . '/promote');
    }

    public function status(): void
    {
        Auth::requireLogin();
        header('Cache-Control: private, no-store');

        $states = (new VipService())->statesForUser((int) Auth::id());
        $items = [];
        foreach ($states as $productId => $ui) {
            $code = (string) ($ui['code'] ?? 'hidden');
            if ($code === 'hidden') {
                continue;
            }
            $key = VipPolicy::uiMessageKey($code);
            $until = VipPolicy::formatUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null);
            $left = isset($ui['lifts_remaining']) && $ui['lifts_remaining'] !== null
                ? (string) $ui['lifts_remaining']
                : '';
            $message = $key ? t($key, ['until' => $until, 'left' => $left]) : '';
            if (($ui['notice'] ?? null) === 'gateway') {
                $extra = t('vip.err_gateway');
                $message = $message === '' ? $extra : $message . ' ' . $extra;
            }
            $items[] = [
                'id' => (int) $productId,
                'code' => $code,
                'can_purchase' => !empty($ui['can_purchase']),
                'continue_payment' => !empty($ui['continue_payment']),
                'message' => $message,
                'ends_at' => VipPolicy::isoUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null),
                'lifts_remaining' => $ui['lifts_remaining'],
            ];
        }

        $this->json(['ok' => true, 'items' => $items]);
    }
}
