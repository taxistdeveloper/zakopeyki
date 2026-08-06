<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Review;
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
        $canModerate = Auth::can('disputes');
        if (!$isBuyer && !$isSeller && !$canModerate) {
            http_response_code(403);
            $this->view('errors/404', ['title' => t('escrow.forbidden')]);
            return;
        }

        // Перечитать после auto-release
        $order = (new Order())->findWithDetails($orderId) ?: $order;

        $myReview = null;
        $counterpartReview = null;
        if ($isBuyer || $isSeller) {
            $reviews = new Review();
            $myReview = $reviews->findByOrderAndAuthor($orderId, $uid);
            $counterpartId = $isBuyer ? (int) $order['seller_id'] : (int) $order['buyer_id'];
            $counterpartReview = $reviews->findByOrderAndAuthor($orderId, $counterpartId);
        }

        $n = new Notification();
        $this->view('orders/show', [
            'title' => t('escrow.deal_title', ['id' => $orderId]),
            'currentNav' => 'orders',
            'order' => $order,
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'isAdmin' => $canModerate,
            'myReview' => $myReview,
            'counterpartReview' => $counterpartReview,
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function review(string $id): void
    {
        Auth::requireLogin();
        $orderId = (int) $id;
        $rating = (int) ($_POST['rating'] ?? 0);
        $body = (string) ($_POST['body'] ?? '');

        $result = (new Review())->createForOrder($orderId, Auth::id(), $rating, $body);
        if ($result['ok']) {
            ActivityLogger::info('order.review', 'Отзыв по сделке #' . $orderId, 'order', $orderId);
            $_SESSION['flash'] = t('reviews.saved');
        } else {
            ActivityLogger::warning('order.review', $result['error'] ?? 'Ошибка отзыва', 'order', $orderId);
            $_SESSION['error'] = $result['error'] ?? t('reviews.save_fail');
        }
        $this->redirect('/orders/' . $orderId);
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
        $this->flashResult($result, (int) $id, 'order.ship');
    }

    public function delivered(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->markDelivered((int) $id, Auth::id());
        $this->flashResult($result, (int) $id, 'order.delivered');
    }

    public function confirm(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->confirmReceived((int) $id, Auth::id());
        $this->flashResult($result, (int) $id, 'order.confirm');
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
        $this->flashResult($result, (int) $id, 'order.dispute');
    }

    public function returnShip(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->addReturnTracking(
            (int) $id,
            Auth::id(),
            (string) ($_POST['return_tracking'] ?? '')
        );
        $this->flashResult($result, (int) $id, 'order.return_ship');
    }

    public function returnReceived(string $id): void
    {
        Auth::requireLogin();
        $result = (new EscrowService())->confirmReturnReceived((int) $id, Auth::id());
        $this->flashResult($result, (int) $id, 'order.return_received');
    }

    public function approveReturn(string $id): void
    {
        Auth::requirePermission('disputes');
        $result = (new EscrowService())->approveReturn((int) $id, Auth::id());
        $this->flashResult($result, (int) $id, 'order.approve_return');
    }

    public function rejectDispute(string $id): void
    {
        Auth::requirePermission('disputes');
        $result = (new EscrowService())->rejectDispute((int) $id, Auth::id());
        $this->flashResult($result, (int) $id, 'order.reject_dispute');
    }

    public function evidence(string $id, string $file): void
    {
        Auth::requireLogin();
        $orderId = (int) $id;
        $order = (new Order())->find($orderId);
        if (!$order) {
            http_response_code(404);
            exit;
        }

        $uid = Auth::id();
        $isParty = (int) $order['buyer_id'] === $uid || (int) $order['seller_id'] === $uid;
        if (!$isParty && !Auth::can('disputes')) {
            http_response_code(403);
            exit;
        }

        $safe = basename($file);
        if ($safe === '' || $safe !== $file || !preg_match('/^d_\d{14}_[a-f0-9]+\.(jpe?g|png|webp|gif|mp4|webm)$/i', $safe)) {
            http_response_code(404);
            exit;
        }

        $evidence = [];
        if (!empty($order['dispute_evidence'])) {
            $decoded = json_decode((string) $order['dispute_evidence'], true);
            if (is_array($decoded)) {
                $evidence = $decoded;
            }
        }
        if (!in_array($safe, $evidence, true)) {
            http_response_code(404);
            exit;
        }

        $path = __DIR__ . '/../../public/uploads/disputes/' . $safe;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }

        $mime = \App\Helpers\UploadHelper::detectMime($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . $safe . '"');
        readfile($path);
        exit;
    }

    /** @param array{ok: bool, error?: string} $result */
    private function flashResult(array $result, int $orderId, string $action = 'order.action'): void
    {
        if ($result['ok']) {
            ActivityLogger::info($action, 'Действие по сделке #' . $orderId, 'order', $orderId);
            $_SESSION['flash'] = t('escrow.action_ok');
        } else {
            ActivityLogger::warning($action, $result['error'] ?? 'Ошибка по сделке #' . $orderId, 'order', $orderId);
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
            if (!\App\Helpers\UploadHelper::isAllowedUpload((string) $tmps[$i], (string) $names[$i], $allowed)) {
                return ['error' => t('escrow.evidence_type')];
            }
            $ext = \App\Helpers\UploadHelper::normalizeExt((string) $names[$i]);
            $filename = 'd_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file((string) $tmps[$i], $dir . '/' . $filename)) {
                return ['error' => t('flash.upload_error')];
            }
            $files[] = $filename;
        }

        return ['files' => $files];
    }
}
