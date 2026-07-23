<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Notification;
use App\Models\Order;
use App\Services\EscrowService;

class OrderController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        (new EscrowService())->processDeadlines();

        $n = new Notification();
        $this->view('orders/index', [
            'title' => t('escrow.deals_title'),
            'currentNav' => 'orders',
            'orders' => (new Order())->forUser(Auth::id()),
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $orderId = (int) $id;
        $escrow = new EscrowService();
        $escrow->processDeadlines($orderId);

        $order = (new Order())->findWithDetails($orderId);
        if (!$order) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('escrow.not_found')]);
            return;
        }

        $uid = Auth::id();
        $isBuyer = (int) $order['buyer_id'] === $uid;
        $isSeller = (int) $order['seller_id'] === $uid;
        if (!$isBuyer && !$isSeller && !Auth::isAdmin()) {
            http_response_code(403);
            $this->view('errors/404', ['title' => t('escrow.forbidden')]);
            return;
        }

        // Перечитать после auto-release
        $order = (new Order())->findWithDetails($orderId) ?: $order;

        $n = new Notification();
        $this->view('orders/show', [
            'title' => t('escrow.deal_title', ['id' => $orderId]),
            'currentNav' => 'orders',
            'order' => $order,
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'isAdmin' => Auth::isAdmin(),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function ship(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->addTracking(
            (int) $id,
            Auth::id(),
            (string) ($_POST['tracking_number'] ?? ''),
            (string) ($_POST['carrier'] ?? '')
        );
        $this->flashResult($result, (int) $id);
    }

    public function delivered(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->markDelivered((int) $id, Auth::id());
        $this->flashResult($result, (int) $id);
    }

    public function confirm(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->confirmReceived((int) $id, Auth::id());
        $this->flashResult($result, (int) $id);
    }

    public function dispute(string $id): void
    {
        Auth::requireLogin();
        $files = $this->uploadEvidence();
        if (!empty($files['error'])) {
            $_SESSION['error'] = $files['error'];
            $this->redirect('/orders/' . (int) $id);
            return;
        }

        $result = (new EscrowService())->openDispute(
            (int) $id,
            Auth::id(),
            (string) ($_POST['reason'] ?? ''),
            $files['files'] ?? []
        );
        $this->flashResult($result, (int) $id);
    }

    public function returnShip(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->addReturnTracking(
            (int) $id,
            Auth::id(),
            (string) ($_POST['return_tracking'] ?? '')
        );
        $this->flashResult($result, (int) $id);
    }

    public function returnReceived(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->confirmReturnReceived((int) $id, Auth::id());
        $this->flashResult($result, (int) $id);
    }

    public function approveReturn(string $id): void
    {
        Auth::requireAdmin();
        $result = (new EscrowService())->approveReturn((int) $id, Auth::id());
        $this->flashResult($result, (int) $id);
    }

    public function rejectDispute(string $id): void
    {
        Auth::requireAdmin();
        $result = (new EscrowService())->rejectDispute((int) $id, Auth::id());
        $this->flashResult($result, (int) $id);
    }

    /** @param array{ok: bool, error?: string} $result */
    private function flashResult(array $result, int $orderId): void
    {
        if ($result['ok']) {
            $_SESSION['flash'] = t('escrow.action_ok');
        } else {
            $_SESSION['error'] = $result['error'] ?? t('escrow.action_fail');
        }
        $this->redirect('/orders/' . $orderId);
    }

    /** @return array{files?: list<string>, error?: string} */
    private function uploadEvidence(): array
    {
        if (empty($_FILES['evidence']) || !is_array($_FILES['evidence']['name'] ?? null)) {
            if (!empty($_FILES['evidence']['name']) && is_string($_FILES['evidence']['name'])) {
                $_FILES['evidence'] = [
                    'name' => [$_FILES['evidence']['name']],
                    'type' => [$_FILES['evidence']['type'] ?? ''],
                    'tmp_name' => [$_FILES['evidence']['tmp_name'] ?? ''],
                    'error' => [$_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE],
                    'size' => [$_FILES['evidence']['size'] ?? 0],
                ];
            } else {
                return ['files' => []];
            }
        }

        $names = $_FILES['evidence']['name'];
        $tmps = $_FILES['evidence']['tmp_name'];
        $errors = $_FILES['evidence']['error'];
        $sizes = $_FILES['evidence']['size'];

        $dir = __DIR__ . '/../../public/uploads/disputes';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'webm'];
        $files = [];
        $count = min(count($names), 3);

        for ($i = 0; $i < $count; $i++) {
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($errors[$i] ?? 0) !== UPLOAD_ERR_OK) {
                return ['error' => t('flash.upload_error')];
            }
            if (($sizes[$i] ?? 0) > 8 * 1024 * 1024) {
                return ['error' => t('escrow.evidence_too_big')];
            }
            $ext = strtolower(pathinfo((string) $names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                return ['error' => t('escrow.evidence_type')];
            }
            $filename = 'd_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file((string) $tmps[$i], $dir . '/' . $filename)) {
                return ['error' => t('flash.upload_error')];
            }
            $files[] = $filename;
        }

        return ['files' => $files];
    }
}
