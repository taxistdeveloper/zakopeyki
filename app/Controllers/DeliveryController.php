<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\DeliveryOrder;
use App\Models\Notification;
use App\Models\Order;
use App\Services\Delivery\DeliveryService;

class DeliveryController extends Controller
{
    public function show(string $id): void
    {
        Auth::requireLogin();
        $deliveryOrderId = (int) $id;
        $delivery = (new DeliveryOrder())->findWithDetails($deliveryOrderId);

        if (!$delivery) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('delivery.not_found')]);
            return;
        }

        $uid = Auth::id();
        $isBuyer = (int) $delivery['buyer_user_id'] === $uid;
        $isSeller = (int) $delivery['seller_user_id'] === $uid;
        $isAdmin = Auth::can('disputes');

        if (!$isBuyer && !$isSeller && !$isAdmin) {
            http_response_code(403);
            $this->view('errors/404', ['title' => t('delivery.forbidden')]);
            return;
        }

        $p2pOrder = (new Order())->findWithDetails((int) $delivery['order_id']);
        $packagings = (new DeliveryOrder())->packagingsForProvider((int) $delivery['logistics_provider_id']);
        $n = new Notification();

        $this->view('delivery/show', [
            'title' => t('delivery.page_title', ['number' => $delivery['order_number']]),
            'currentNav' => 'orders',
            'delivery' => $delivery,
            'p2pOrder' => $p2pOrder,
            'packagings' => $packagings,
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'isAdmin' => $isAdmin,
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function byOrder(string $orderId): void
    {
        Auth::requireLogin();
        $delivery = (new DeliveryOrder())->findByP2pOrderId((int) $orderId);
        if (!$delivery) {
            $_SESSION['error'] = t('delivery.not_started');
            $this->redirect('/orders/' . (int) $orderId);
            return;
        }
        $this->redirect('/delivery/' . (int) $delivery['id']);
    }

    public function saveSender(string $id): void
    {
        Auth::requireLogin();
        $result = (new DeliveryService())->saveSellerData((int) $id, Auth::id(), $_POST);
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('delivery.sender_saved')
            : ($result['error'] ?? t('delivery.save_failed'));
        $this->redirect('/delivery/' . (int) $id);
    }

    public function saveRecipient(string $id): void
    {
        Auth::requireLogin();
        $result = (new DeliveryService())->saveBuyerData((int) $id, Auth::id(), $_POST);
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('delivery.recipient_saved')
            : ($result['error'] ?? t('delivery.save_failed'));
        $this->redirect('/delivery/' . (int) $id);
    }

    public function selectQuote(string $id): void
    {
        Auth::requireLogin();
        $quoteId = (int) ($_POST['quote_id'] ?? 0);
        $result = (new DeliveryService())->selectQuote((int) $id, Auth::id(), $quoteId);
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('delivery.quote_selected')
            : ($result['error'] ?? t('delivery.save_failed'));
        $this->redirect('/delivery/' . (int) $id);
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();
        $method = ($_POST['payment_method'] ?? 'card') === 'card' ? 'card' : 'card';
        $result = (new DeliveryService())->initiatePayment((int) $id, Auth::id(), $method);
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('delivery.payment_failed');
            $this->redirect('/delivery/' . (int) $id);
            return;
        }
        if (!empty($result['redirect_url'])) {
            $this->redirect($result['redirect_url']);
        }
        $_SESSION['flash'] = t('delivery.payment_success');
        $this->redirect('/delivery/' . (int) $id);
    }

    /**
     * Webhook статусов от логистики (подпись — в следующей итерации интеграции).
     */
    public function logisticsWebhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $result = (new DeliveryService())->handleLogisticsWebhook($payload);
        $this->json($result, $result['ok'] ? 200 : 422);
    }
}
