<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\TargetPush\TargetPushService;

class TargetPushController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $service = new TargetPushService();
        $this->view('target-push/index', [
            'title' => t('target_push.title'),
            'currentNav' => 'target_push',
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
        $result = (new TargetPushService())->create((int) Auth::id(), $_POST);
        if (!empty($result['ok'])) {
            $_SESSION['flash'] = t('target_push.created', ['amount' => (string) ($result['amount'] ?? '')]);
        } else {
            $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        }
        $this->redirect('/target-push');
    }

    public function audience(): void
    {
        Auth::requireLogin();
        $result = (new TargetPushService())->previewAudience((int) Auth::id(), (string) ($_POST['category'] ?? ''));
        $this->json([
            'ok' => !empty($result['ok']),
            'count' => (int) ($result['count'] ?? 0),
            'limit' => (int) ($result['limit'] ?? 0),
            'over' => !empty($result['over']),
            'error' => empty($result['ok']) ? t($this->errorKey((string) ($result['error_code'] ?? 'generic'))) : null,
        ], !empty($result['ok']) ? 200 : 422);
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        $result = (new TargetPushService())->update((int) Auth::id(), (int) $id, $_POST);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('target_push.updated');
        $this->redirect('/target-push');
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();
        $result = (new TargetPushService())->beginPayment((int) Auth::id(), (int) $id, [
            'idempotency_key' => $_POST['idempotency_key'] ?? null,
            'amount' => $_POST['amount'] ?? null,
            'price' => $_POST['price'] ?? null,
        ]);
        if (!empty($result['redirect_url'])) {
            $this->redirect((string) $result['redirect_url']);
        }
        if (!empty($result['already_paid'])) {
            $_SESSION['flash'] = t('target_push.already_paid');
            $this->redirect('/target-push');
        }
        $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        $this->redirect('/target-push');
    }

    public function launch(string $id): void
    {
        Auth::requireLogin();
        $result = (new TargetPushService())->launch((int) Auth::id(), (int) $id);
        if (!empty($result['already_started'])) {
            $_SESSION['flash'] = t('target_push.already_started');
        } elseif (!empty($result['ok'])) {
            $_SESSION['flash'] = t('target_push.launched', ['n' => (string) ($result['count'] ?? 0)]);
        } else {
            $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        }
        $this->redirect('/target-push');
    }

    public function retry(string $id): void
    {
        Auth::requireLogin();
        $result = (new TargetPushService())->retry((int) Auth::id(), (int) $id);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('target_push.retried');
        $this->redirect('/target-push');
    }

    public function cancel(string $id): void
    {
        Auth::requireLogin();
        $result = (new TargetPushService())->cancel((int) Auth::id(), (int) $id);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('target_push.cancelled');
        $this->redirect('/target-push');
    }

    public function notice(string $publicId): void
    {
        Auth::requireLogin();
        $service = new TargetPushService();
        $row = $service->notice($publicId);
        if ($row === null) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('target_push.not_found')]);
            return;
        }
        $this->view('target-push/notice', [
            'title' => t('target_push.notice_title'),
            'currentNav' => 'target_push',
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
        $ok = (new TargetPushService())->unsubscribe((int) Auth::id());
        $_SESSION[$ok ? 'flash' : 'error'] = $ok ? t('target_push.unsubscribed') : t('target_push.err_generic');
        $back = (string) ($_POST['back'] ?? '/target-push');
        if (!str_starts_with($back, '/target-push')) {
            $back = '/target-push';
        }
        $this->redirect($back);
    }

    private function errorKey(string $code): string
    {
        return match ($code) {
            'disabled' => 'target_push.err_disabled',
            'forbidden' => 'target_push.err_forbidden',
            'bad_category' => 'target_push.err_category',
            'bad_title' => 'target_push.err_title',
            'bad_body' => 'target_push.err_body',
            'bad_message' => 'target_push.err_message',
            'bad_url' => 'target_push.err_url',
            'bad_status' => 'target_push.err_status',
            'bad_reason' => 'target_push.err_reason',
            'bad_amount' => 'target_push.err_amount',
            'audience_over' => 'target_push.err_audience_over',
            'audience_empty' => 'target_push.err_audience_empty',
            'frequency' => 'target_push.err_frequency',
            'idempotency_conflict' => 'target_push.err_idempotency',
            'gateway_not_configured', 'gateway_init_failed' => 'target_push.err_gateway',
            default => 'target_push.err_generic',
        };
    }
}
