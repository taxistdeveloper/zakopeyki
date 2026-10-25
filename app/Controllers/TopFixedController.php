<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixed\TopFixedService;

class TopFixedController extends Controller
{
    public function purchase(string $id): void
    {
        Auth::requireLogin();

        $result = (new TopFixedService())->beginPurchase((int) Auth::id(), (int) $id, [
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
            $until = TopFixedPolicy::formatUntil(isset($result['ends_at']) ? (string) $result['ends_at'] : null);
            $category = (string) ($result['category'] ?? '');
            $_SESSION['flash'] = !empty($result['active'])
                ? t('top_fixed.active', ['until' => $until, 'category' => $category])
                : t('top_fixed.expired');
            $this->redirect('/profile/lots/' . (int) $id . '/promote');
        }

        $code = (string) ($result['error_code'] ?? 'generic');
        $key = match ($code) {
            'disabled' => 'top_fixed.err_disabled',
            'forbidden' => 'top_fixed.err_forbidden',
            'bad_status' => 'top_fixed.err_bad_status',
            'not_found' => 'top_fixed.err_not_found',
            'already_active' => 'top_fixed.err_already_active',
            'gateway_not_configured', 'gateway_init_failed' => 'top_fixed.err_gateway',
            'awaiting_apply' => 'top_fixed.paid_waiting',
            'payment_pending' => 'top_fixed.payment_pending',
            'payment_failed' => 'top_fixed.payment_failed',
            'idempotency_conflict' => 'top_fixed.err_generic',
            default => 'top_fixed.err_generic',
        };
        $until = TopFixedPolicy::formatUntil(isset($result['ends_at']) ? (string) $result['ends_at'] : null);
        $_SESSION['error'] = t($key, ['until' => $until]);
        $this->redirect('/profile/lots/' . (int) $id . '/promote');
    }

    public function status(): void
    {
        Auth::requireLogin();
        header('Cache-Control: private, no-store');

        $states = (new TopFixedService())->statesForUser((int) Auth::id());
        $items = [];
        foreach ($states as $productId => $ui) {
            $code = (string) ($ui['code'] ?? 'hidden');
            if ($code === 'hidden') {
                continue;
            }
            $key = TopFixedPolicy::uiMessageKey($code);
            $until = TopFixedPolicy::formatUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null);
            $category = (string) ($ui['category'] ?? '');
            $message = $key ? t($key, ['until' => $until, 'category' => $category]) : '';
            if (($ui['notice'] ?? null) === 'gateway') {
                $extra = t('top_fixed.err_gateway');
                $message = $message === '' ? $extra : $message . ' ' . $extra;
            }
            $items[] = [
                'id' => (int) $productId,
                'code' => $code,
                'can_purchase' => !empty($ui['can_purchase']),
                'continue_payment' => !empty($ui['continue_payment']),
                'message' => $message,
                'ends_at' => TopFixedPolicy::isoUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null),
                'category' => $category,
            ];
        }

        $this->json(['ok' => true, 'items' => $items]);
    }
}
