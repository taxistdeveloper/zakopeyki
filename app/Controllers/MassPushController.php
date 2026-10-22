<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\MassPush\MassPushService;

class MassPushController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $service = new MassPushService();
        $this->view('mass-push/index', [
            'title' => t('mass_push.title'),
            'currentNav' => 'mass_push',
            'enabled' => $service->isEnabled(),
            'campaigns' => $service->mine((int) Auth::id()),
            'unsubscribed' => $service->isUnsubscribed((int) Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->create((int) Auth::id(), $_POST);
        if (!empty($result['ok'])) {
            $_SESSION['flash'] = t('mass_push.created', ['amount' => (string) ($result['amount'] ?? '')]);
        } else {
            $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        }
        $this->redirect('/mass-push');
    }

    public function audience(): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->previewAudience((int) Auth::id());
        $this->json([
            'ok' => !empty($result['ok']),
            'count' => (int) ($result['count'] ?? 0),
            'error' => empty($result['ok']) ? t($this->errorKey((string) ($result['error_code'] ?? 'generic'))) : null,
        ], !empty($result['ok']) ? 200 : 422);
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->update((int) Auth::id(), (int) $id, $_POST);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('mass_push.updated');
        $this->redirect('/mass-push');
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->beginPayment((int) Auth::id(), (int) $id, [
            'idempotency_key' => $_POST['idempotency_key'] ?? null,
            'amount' => $_POST['amount'] ?? null,
            'price' => $_POST['price'] ?? null,
        ]);
        if (!empty($result['redirect_url'])) {
            $this->redirect((string) $result['redirect_url']);
        }
        if (!empty($result['already_paid'])) {
            $_SESSION['flash'] = t('mass_push.already_paid');
            $this->redirect('/mass-push');
        }
        $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        $this->redirect('/mass-push');
    }

    public function launch(string $id): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->launch((int) Auth::id(), (int) $id);
        if (!empty($result['already_started'])) {
            $_SESSION['flash'] = t('mass_push.already_started');
        } elseif (!empty($result['ok'])) {
            $_SESSION['flash'] = t('mass_push.launched', ['n' => (string) ($result['count'] ?? 0)]);
        } else {
            $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        }
        $this->redirect('/mass-push');
    }

    public function retry(string $id): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->retry((int) Auth::id(), (int) $id);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('mass_push.retried');
        $this->redirect('/mass-push');
    }

    public function cancel(string $id): void
    {
        Auth::requireLogin();
        $result = (new MassPushService())->cancel((int) Auth::id(), (int) $id);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('mass_push.cancelled');
        $this->redirect('/mass-push');
    }

    public function notice(string $publicId): void
    {
        Auth::requireLogin();
        $service = new MassPushService();
        $row = $service->notice($publicId);
        if ($row === null) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('mass_push.not_found')]);
            return;
        }
        $this->view('mass-push/notice', [
            'title' => t('mass_push.notice_title'),
            'currentNav' => 'mass_push',
            'campaign' => $row,
            'unsubscribed' => $service->isUnsubscribed((int) Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function unsubscribe(): void
    {
        Auth::requireLogin();
        $ok = (new MassPushService())->unsubscribe((int) Auth::id());
        $_SESSION[$ok ? 'flash' : 'error'] = $ok ? t('mass_push.unsubscribed') : t('mass_push.err_generic');
        $back = (string) ($_POST['back'] ?? '/mass-push');
        if (!str_starts_with($back, '/mass-push')) {
            $back = '/mass-push';
        }
        $this->redirect($back);
    }

    private function errorKey(string $code): string
    {
        return match ($code) {
            'disabled' => 'mass_push.err_disabled',
            'forbidden' => 'mass_push.err_forbidden',
            'bad_title' => 'mass_push.err_title',
            'bad_body' => 'mass_push.err_body',
            'bad_message' => 'mass_push.err_message',
            'bad_url' => 'mass_push.err_url',
            'bad_status' => 'mass_push.err_status',
            'bad_reason' => 'mass_push.err_reason',
            'bad_amount' => 'mass_push.err_amount',
            'audience_empty' => 'mass_push.err_audience_empty',
            'idempotency_conflict' => 'mass_push.err_idempotency',
            'gateway_not_configured', 'gateway_init_failed' => 'mass_push.err_gateway',
            default => 'mass_push.err_generic',
        };
    }
}
