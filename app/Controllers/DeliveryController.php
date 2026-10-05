<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\CdekDeliveryPoint;
use App\Models\DeliveryOrder;
use App\Models\Notification;
use App\Models\Order;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\PackagingRecommendationService;
use App\Services\Listing\ListingShippingService;

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
        $shipment = $delivery['shipment'] ?? null;
        $packRecommendation = null;
        if ($isSeller && $shipment) {
            $packRecommendation = (new PackagingRecommendationService())->recommend(
                $packagings,
                isset($shipment['item_weight']) ? (float) $shipment['item_weight'] : null,
                isset($shipment['item_length']) ? (float) $shipment['item_length'] : null,
                isset($shipment['item_width']) ? (float) $shipment['item_width'] : null,
                isset($shipment['item_height']) ? (float) $shipment['item_height'] : null,
            );
        }
        $missingForQuotes = (new DeliveryService())->missingForQuotes($delivery);

        $n = new Notification();

        $this->view('delivery/show', [
            'title' => t('delivery.page_title', ['number' => $delivery['order_number']]),
            'currentNav' => 'orders',
            'delivery' => $delivery,
            'p2pOrder' => $p2pOrder,
            'packagings' => $packagings,
            'packRecommendation' => $packRecommendation,
            'missingForQuotes' => $missingForQuotes,
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
        $svc = new \App\Services\Delivery\BuyerPointBService();
        // Prefer domain service: validates PVZ via directory + invalidates quotes; no auto quote from checkout path.
        $result = $svc->updatePointB((int) $id, Auth::id(), $_POST, false);
        // If still in early data collection without product_id join, fall back.
        if (($result['error_code'] ?? '') === 'not_found' || (($result['ok'] ?? false) === false && ($result['error'] ?? '') === 'not_found')) {
            $result = (new DeliveryService())->saveBuyerData((int) $id, Auth::id(), $_POST, ['auto_quote' => false]);
        }
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('delivery.recipient_saved')
            : ($result['error'] ?? t('delivery.save_failed'));
        $this->redirect('/delivery/' . (int) $id);
    }

    /**
     * GET /delivery/{id}/recipient — сохранённый Point B (buyer/seller ownership).
     */
    public function getRecipient(string $id): void
    {
        Auth::requireLogin();
        $result = (new \App\Services\Delivery\BuyerPointBService())->getPointB((int) $id, Auth::id());
        if (!$result['ok']) {
            $code = ($result['error'] ?? '') === 'forbidden' ? 403 : 404;
            $this->json(['ok' => false, 'error' => $result['error'] ?? 'not_found'], $code);
        }
        $recipient = $result['recipient'];
        // Не отдаём лишнее; PII только владельцу заказа.
        $this->json([
            'ok' => true,
            'recipient' => $recipient ? [
                'name' => $recipient['name'] ?? null,
                'phone' => $recipient['phone'] ?? null,
                'email' => $recipient['email'] ?? null,
                'delivery_mode' => $recipient['delivery_mode'] ?? null,
                'country' => $recipient['country'] ?? null,
                'region' => $recipient['region'] ?? null,
                'city' => $recipient['city'] ?? null,
                'street' => $recipient['street'] ?? null,
                'building' => $recipient['building'] ?? null,
                'apartment' => $recipient['apartment'] ?? null,
                'postal_code' => $recipient['postal_code'] ?? null,
                'pvz_code' => $recipient['pvz_code'] ?? null,
                'pvz_name' => $recipient['pvz_name'] ?? null,
                'delivery_point' => $recipient['delivery_point'] ?? null,
                'cdek_city_code' => isset($recipient['cdek_city_code']) ? (int) $recipient['cdek_city_code'] : null,
                'notes' => $recipient['notes'] ?? null,
            ] : null,
        ]);
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

    /**
     * POST /delivery/{id}/quotes/calculate — серверный расчёт CDEK (Phase 6).
     * Тело: только CSRF; сумма и CalculatorRequestDto с frontend не принимаются.
     */
    public function calculateQuotes(string $id): void
    {
        Auth::requireLogin();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_POST['format'] ?? '') === 'json')
            || (($_GET['format'] ?? '') === 'json');

        $result = (new DeliveryService())->calculateQuotesForBuyer((int) $id, Auth::id());

        if ($wantsJson) {
            $code = 200;
            if (!($result['ok'] ?? false)) {
                $code = match ($result['error_code'] ?? '') {
                    'forbidden' => 403,
                    'not_found' => 404,
                    default => 422,
                };
            }
            $this->json([
                'ok' => (bool) ($result['ok'] ?? false),
                'error' => $result['error'] ?? null,
                'error_code' => $result['error_code'] ?? null,
                'quotes' => $result['quotes'] ?? [],
                'active_quote_ids' => $result['active_quote_ids'] ?? [],
                'reused' => !empty($result['reused']),
            ], $code);
            return;
        }

        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('delivery.quote_calculated')
            : ($result['error'] ?? t('delivery.quote_failed'));
        $this->redirect('/delivery/' . (int) $id);
    }

    public function pay(string $id): void
    {
        Auth::requireLogin();
        unset($_POST['amount'], $_POST['total_amount'], $_POST['currency'], $_POST['delivery_amount']);
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
     * GET /delivery/{id}/payment — статус оплаты (только backend truth).
     */
    public function paymentStatus(string $id): void
    {
        Auth::requireLogin();
        $result = (new \App\Services\Delivery\DeliveryPaymentService())->getPaymentStatus((int) $id, Auth::id());
        $code = ($result['ok'] ?? false) ? 200 : ((($result['error_code'] ?? '') === 'forbidden') ? 403 : 404);
        $this->json($result, $code);
    }

    /**
     * GET /delivery/{id}/cdek-ready — canCreateCdekOrder() без создания заказа.
     */
    public function cdekReady(string $id): void
    {
        Auth::requireLogin();
        $delivery = (new DeliveryOrder())->findWithDetails((int) $id);
        if (!$delivery) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        $uid = Auth::id();
        if ((int) $delivery['buyer_user_id'] !== $uid && (int) $delivery['seller_user_id'] !== $uid && !Auth::can('disputes')) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $gate = (new DeliveryService())->canCreateCdekOrder((int) $id);
        $this->json([
            'ok' => true,
            'ready' => (bool) ($gate['ok'] ?? false),
            'reason' => $gate['reason'] ?? null,
            'checks' => $gate['checks'] ?? [],
        ]);
    }

    /**
     * Локальный справочник ПВЗ (не прямой прокси в CDEK).
     * GET /delivery/cdek/points?city=&city_code=&q=&type=
     */
    public function cdekPoints(): void
    {
        Auth::requireLogin();

        $points = new CdekDeliveryPoint();
        $rows = $points->search([
            'city' => trim((string) ($_GET['city'] ?? '')),
            'city_code' => (int) ($_GET['city_code'] ?? 0) ?: null,
            'q' => trim((string) ($_GET['q'] ?? '')),
            'type' => trim((string) ($_GET['type'] ?? '')),
            'country_code' => strtoupper(trim((string) ($_GET['country_code'] ?? 'KZ'))) ?: 'KZ',
            'limit' => (int) ($_GET['limit'] ?? 30),
            'offset' => (int) ($_GET['offset'] ?? 0),
        ]);

        $public = array_map(static fn(array $r): array => CdekDeliveryPoint::toPublic($r), $rows);

        $this->json([
            'ok' => true,
            'points' => $public,
            'count' => count($public),
            'directory_total' => $points->countActive('KZ'),
        ]);
    }

    /**
     * Resolve city → CDEK city code for seller Point A (via CdekApi client).
     * GET /delivery/cdek/cities?city=&country_code=
     */
    public function cdekCities(): void
    {
        Auth::requireLogin();

        $city = trim((string) ($_GET['city'] ?? ''));
        $country = strtoupper(trim((string) ($_GET['country_code'] ?? 'KZ'))) ?: 'KZ';
        if ($city === '') {
            $this->json(['ok' => false, 'error' => 'city_required'], 422);
        }

        $found = (new ListingShippingService())->resolveCityCode($city, $country);
        if ($found === null) {
            $this->json(['ok' => false, 'error' => 'city_not_found', 'city' => $city], 404);
        }

        $this->json([
            'ok' => true,
            'code' => (int) $found['code'],
            'city' => (string) ($found['city'] ?? $city),
            'country_code' => (string) ($found['country_code'] ?? $country),
        ]);
    }

    /**
     * Webhook статусов от логистики (CDEK: shared token + idempotent event_hash).
     */
    public function logisticsWebhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = is_array($_POST) ? $_POST : [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_scalar($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        // Apache/CGI иногда отдаёт без HTTP_ префикса для кастомных.
        if (isset($_SERVER['HTTP_X_CDEK_WEBHOOK_TOKEN'])) {
            $headers['x-cdek-webhook-token'] = (string) $_SERVER['HTTP_X_CDEK_WEBHOOK_TOKEN'];
        }

        $result = (new DeliveryService())->handleLogisticsWebhook($payload, $headers, $_GET, $raw);
        $code = 200;
        if (empty($result['ok'])) {
            $err = (string) ($result['error'] ?? '');
            $code = in_array($err, ['unauthorized', 'webhook_token_not_configured'], true) ? 401 : 422;
        }
        $this->json($result, $code);
    }
}
