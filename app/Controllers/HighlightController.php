<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\Highlight\HighlightPolicy;
use App\Services\Highlight\HighlightService;

class HighlightController extends Controller
{
    public function purchase(string $id): void
    {
        Auth::requireLogin();

        $result = (new HighlightService())->beginPurchase((int) Auth::id(), (int) $id, [
            'idempotency_key' => $_POST['idempotency_key'] ?? null,
            'amount' => $_POST['amount'] ?? null,
            'price' => $_POST['price'] ?? null,
            'enabled' => $_POST['enabled'] ?? null,
            'days' => $_POST['days'] ?? null,
        ]);

        if (!empty($result['redirect_url'])) {
            $this->redirect((string) $result['redirect_url']);
        }

        if (!empty($result['already_applied'])) {
            $until = HighlightPolicy::formatUntil(isset($result['ends_at']) ? (string) $result['ends_at'] : null);
            $_SESSION['flash'] = !empty($result['active'])
                ? t('highlight.active', ['until' => $until])
                : t('highlight.expired');
            $this->redirect('/profile?tab=lots');
        }

        $code = (string) ($result['error_code'] ?? 'generic');
        $key = match ($code) {
            'disabled' => 'highlight.err_disabled',
            'forbidden' => 'highlight.err_forbidden',
            'bad_status' => 'highlight.err_bad_status',
            'not_found' => 'highlight.err_not_found',
            'already_active' => 'highlight.err_already_active',
            'gateway_not_configured', 'gateway_init_failed' => 'highlight.err_gateway',
            'awaiting_apply' => 'highlight.paid_waiting',
            'payment_pending' => 'highlight.payment_pending',
            'payment_failed' => 'highlight.payment_failed',
            'idempotency_conflict' => 'highlight.err_generic',
            default => 'highlight.err_generic',
        };
        $until = HighlightPolicy::formatUntil(isset($result['ends_at']) ? (string) $result['ends_at'] : null);
        $_SESSION['error'] = t($key, ['until' => $until]);
        $this->redirect('/profile?tab=lots');
    }

    public function status(): void
    {
        Auth::requireLogin();
        header('Cache-Control: private, no-store');

        $states = (new HighlightService())->statesForUser((int) Auth::id());
        $items = [];
        foreach ($states as $productId => $ui) {
            $code = (string) ($ui['code'] ?? 'hidden');
            if ($code === 'hidden') {
                continue;
            }
            $key = HighlightPolicy::uiMessageKey($code);
            $until = HighlightPolicy::formatUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null);
            $message = $key ? t($key, ['until' => $until]) : '';
            if (($ui['notice'] ?? null) === 'gateway') {
                $extra = t('highlight.err_gateway');
                $message = $message === '' ? $extra : $message . ' ' . $extra;
            }
            $items[] = [
                'id' => (int) $productId,
                'code' => $code,
                'can_purchase' => !empty($ui['can_purchase']),
                'continue_payment' => !empty($ui['continue_payment']),
                'message' => $message,
                'ends_at' => HighlightPolicy::isoUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null),
            ];
        }

        $this->json(['ok' => true, 'items' => $items]);
    }
}
