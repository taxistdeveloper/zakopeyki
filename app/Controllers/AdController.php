<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\Ads\AdService;

class AdController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $service = new AdService();
        $this->view('ads/index', [
            'title' => t('ads.title'),
            'currentNav' => 'ads',
            'campaigns' => $service->mine((int) Auth::id()),
            'flags' => $service->flags(),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $result = (new AdService())->create((int) Auth::id(), $_POST, $_FILES['image'] ?? null);
        if (!empty($result['ok'])) {
            $_SESSION['flash'] = t('ads.created', ['amount' => (string) ($result['amount'] ?? '')]);
        } else {
            $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        }
        $this->redirect('/ads');
    }

    public function resubmit(string $id): void
    {
        Auth::requireLogin();
        $result = (new AdService())->resubmit((int) Auth::id(), (int) $id, $_POST, $_FILES['image'] ?? null);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t('ads.resubmitted');
        $this->redirect('/ads');
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();
        $result = (new AdService())->beginPayment((int) Auth::id(), (int) $id, [
            'idempotency_key' => $_POST['idempotency_key'] ?? null,
            'amount' => $_POST['amount'] ?? null,
            'price' => $_POST['price'] ?? null,
        ]);
        if (!empty($result['redirect_url'])) {
            $this->redirect((string) $result['redirect_url']);
        }
        if (!empty($result['already_applied'])) {
            $_SESSION['flash'] = t('ads.already_running');
            $this->redirect('/ads');
        }
        $_SESSION['error'] = t($this->errorKey((string) ($result['error_code'] ?? 'generic')));
        $this->redirect('/ads');
    }

    public function pause(string $id): void
    {
        $this->running($id, true);
    }

    public function resume(string $id): void
    {
        $this->running($id, false);
    }

    public function impression(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        $token = '';
        if (is_array($payload) && isset($payload['token'])) {
            $token = (string) $payload['token'];
        } elseif (isset($_POST['token'])) {
            $token = (string) $_POST['token'];
        }
        $result = (new AdService())->countImpression($token);
        $this->json([
            'ok' => !empty($result['ok']),
            'counted' => !empty($result['counted']),
        ], !empty($result['ok']) ? 200 : 404);
    }

    public function go(string $token): void
    {
        $target = (new AdService())->clickTarget($token);
        if ($target === null) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('ads.not_found')]);
            return;
        }
        header('Location: ' . $target['url']);
        exit;
    }

    private function running(string $id, bool $pause): void
    {
        Auth::requireLogin();
        $result = (new AdService())->setRunning((int) Auth::id(), (int) $id, $pause);
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t($this->errorKey((string) ($result['error_code'] ?? 'generic')))
            : t($pause ? 'ads.paused' : 'ads.resumed');
        $this->redirect('/ads');
    }

    private function errorKey(string $code): string
    {
        return match ($code) {
            'disabled' => 'ads.err_disabled',
            'forbidden' => 'ads.err_forbidden',
            'bad_format' => 'ads.err_format',
            'bad_units' => 'ads.err_units',
            'bad_category' => 'ads.err_category',
            'bad_url' => 'ads.err_url',
            'bad_image' => 'ads.err_image',
            'bad_title' => 'ads.err_title',
            'bad_status' => 'ads.err_status',
            'bad_reason' => 'ads.err_reason',
            'slot_taken' => 'ads.err_slot',
            'gateway_not_configured', 'gateway_init_failed' => 'ads.err_gateway',
            'not_found' => 'ads.err_not_found',
            default => 'ads.err_generic',
        };
    }
}
