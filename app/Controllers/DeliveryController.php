<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\DeliveryOrder;
use App\Models\DeliveryPrintForm;
use App\Models\Notification;
use App\Services\Cdek\CdekDeliveryActionService;
use App\Services\Cdek\CdekDeliveryProblemService;
use App\Models\Order;
use App\Services\Cdek\CdekPrintFormService;
use App\Services\Cdek\CdekTrackingService;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\PackagingRecommendationService;

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
            'printForms' => (new CdekPrintFormService())->listForUser($deliveryOrderId, $uid, $isAdmin)['forms'] ?? [],
            'printTypes' => CdekPrintFormService::allowedTypes(),
            'trackingSummary' => CdekTrackingService::summaryForUser(
                $delivery,
                $uid,
                $isAdmin,
                (new DeliveryPrintForm())->findForDelivery($deliveryOrderId)
            )['summary'] ?? null,
            'deliveryProblems' => (new CdekDeliveryProblemService())->listForUser($deliveryOrderId, $uid, $isAdmin)['problems'] ?? [],
            'cdekActions' => (new CdekDeliveryActionService())->listForUser($deliveryOrderId, $uid, $isAdmin),
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
     * POST /delivery/{id}/cdek/register — регистрация CDEK после PAID.
     * Frontend не передаёт payload/amount/uuid/im_number — всё с backend.
     */
    public function registerCdek(string $id): void
    {
        Auth::requireLogin();
        unset(
            $_POST['cdek_uuid'],
            $_POST['logistics_order_id'],
            $_POST['order_number'],
            $_POST['im_number'],
            $_POST['amount'],
            $_POST['total_amount'],
            $_POST['quote_id'],
            $_POST['payment_id'],
            $_POST['shipment_point'],
            $_POST['delivery_point'],
            $_POST['tariff_code']
        );

        $result = (new DeliveryService())->createLogisticsOrder((int) $id, Auth::id());
        if (!($result['ok'] ?? false)) {
            $_SESSION['error'] = $result['error'] ?? t('delivery.cdek_create_failed');
            $this->redirect('/delivery/' . (int) $id);
            return;
        }

        if (!empty($result['async'])) {
            $_SESSION['flash'] = t('delivery.cdek_register_accepted');
        } else {
            $_SESSION['flash'] = t('delivery.cdek_register_success');
        }
        $this->redirect('/delivery/' . (int) $id);
    }

    /**
     * GET /delivery/cdek/points — живой список CDEK, фильтр по city_code и shipment заказа/товара.
     */
    public function cdekPoints(): void
    {
        Auth::requireLogin();
        $filters = $this->serverPointFilters();
        if (($filters['city_code'] ?? 0) <= 0 && trim((string) ($_GET['code'] ?? '')) === '') {
            $this->json(['ok' => false, 'error' => t('delivery.city_code_required'), 'error_code' => 'city_code_required'], 422);
            return;
        }
        $result = (new \App\Services\Cdek\CdekLocationService())->searchDeliveryPoints($filters, true);
        $code = ($result['ok'] ?? false) ? 200 : 422;
        $this->json($result, $code);
    }

    /** GET /delivery/cdek/points/{code} */
    public function cdekPoint(string $code): void
    {
        Auth::requireLogin();
        $filters = $this->serverPointFilters();
        $result = (new \App\Services\Cdek\CdekLocationService())->confirmDeliveryPoint($code, $filters);
        $this->json($result, ($result['ok'] ?? false) ? 200 : 404);
    }

    /**
     * GET /delivery/cdek/cities?q= or ?city=
     */
    public function cdekCities(): void
    {
        Auth::requireLogin();
        $city = trim((string) ($_GET['q'] ?? $_GET['city'] ?? $_GET['name'] ?? ''));
        $country = strtoupper(trim((string) ($_GET['country_code'] ?? 'KZ'))) ?: 'KZ';
        $result = (new \App\Services\Cdek\CdekLocationService())->suggestCities($city, $country);
        if (!($result['ok'] ?? false)) {
            $this->json($result, 422);
            return;
        }
        $cities = $result['cities'] ?? [];
        $payload = $result;
        if (count($cities) === 1) {
            $payload['code'] = (int) $cities[0]['code'];
            $payload['city'] = (string) $cities[0]['full_name'];
            $payload['country_code'] = (string) $cities[0]['country_code'];
        }
        $this->json($payload);
    }

    /** GET /delivery/cdek/cities/{code} */
    public function cdekCity(string $code): void
    {
        Auth::requireLogin();
        $country = strtoupper(trim((string) ($_GET['country_code'] ?? 'KZ'))) ?: 'KZ';
        $result = (new \App\Services\Cdek\CdekLocationService())->confirmCity((int) $code, $country);
        $this->json($result, ($result['ok'] ?? false) ? 200 : 404);
    }

    /** GET /delivery/cdek/regions */
    public function cdekRegions(): void
    {
        Auth::requireLogin();
        $result = (new \App\Services\Cdek\CdekLocationService())->searchRegions(
            strtoupper(trim((string) ($_GET['country_code'] ?? 'KZ'))) ?: 'KZ',
            (string) ($_GET['lang'] ?? 'rus'),
            trim((string) ($_GET['q'] ?? ''))
        );
        $this->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** GET /delivery/cdek/postal-codes?city_code= */
    public function cdekPostalCodes(): void
    {
        Auth::requireLogin();
        $result = (new \App\Services\Cdek\CdekLocationService())->postalCodes((int) ($_GET['city_code'] ?? 0));
        $this->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** GET /delivery/cdek/coordinates?latitude=&longitude= */
    public function cdekCoordinates(): void
    {
        Auth::requireLogin();
        if (!isset($_GET['latitude'], $_GET['longitude']) || $_GET['latitude'] === '' || $_GET['longitude'] === '') {
            $this->json(['ok' => false, 'error_code' => 'coordinates_required'], 422);
            return;
        }
        $result = (new \App\Services\Cdek\CdekLocationService())->cityByCoordinates(
            (float) $_GET['latitude'],
            (float) $_GET['longitude']
        );
        $this->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /** POST /delivery/{id}/point-b/validate */
    public function validatePointB(string $id): void
    {
        Auth::requireLogin();
        unset($_POST['amount'], $_POST['total_amount'], $_POST['quote_id']);
        $row = (new DeliveryOrder())->findWithDetails((int) $id);
        if (!$row) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        if ((int) ($row['buyer_user_id'] ?? 0) !== Auth::id() && !Auth::can('disputes')) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $productId = (int) ($row['product_id'] ?? 0);
        $result = (new \App\Services\Delivery\BuyerPointBService())->validateForCheckout(
            $productId,
            (int) $row['buyer_user_id'],
            'cdek',
            $_POST
        );
        $safe = [
            'ok' => (bool) ($result['ok'] ?? false),
            'error' => $result['error'] ?? null,
            'error_code' => $result['error_code'] ?? null,
        ];
        if (!empty($result['snapshot']['point_b']) && is_array($result['snapshot']['point_b'])) {
            $b = $result['snapshot']['point_b'];
            $safe['point_b'] = [
                'delivery_mode' => $b['delivery_mode'] ?? null,
                'city' => $b['city'] ?? null,
                'cdek_city_code' => $b['cdek_city_code'] ?? null,
                'region' => $b['region'] ?? null,
                'pvz_code' => $b['pvz_code'] ?? null,
                'pvz_name' => $b['pvz_name'] ?? null,
                'postal_code' => $b['postal_code'] ?? null,
                'address_verified' => $b['address_verified'] ?? null,
            ];
        }
        $this->json($safe, ($safe['ok'] ?? false) ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private function serverPointFilters(): array
    {
        $filters = [
            'city_code' => (int) ($_GET['city_code'] ?? 0),
            'country_code' => strtoupper(trim((string) ($_GET['country_code'] ?? 'KZ'))) ?: 'KZ',
            'type' => strtoupper(trim((string) ($_GET['type'] ?? 'PVZ'))),
            'code' => trim((string) ($_GET['code'] ?? '')),
            'limit' => (int) ($_GET['limit'] ?? 30),
            'lang' => (string) ($_GET['lang'] ?? 'rus'),
        ];
        $deliveryId = (int) ($_GET['delivery_id'] ?? 0);
        $productId = (int) ($_GET['product_id'] ?? 0);
        $shipment = [];
        if ($deliveryId > 0) {
            $row = (new DeliveryOrder())->findWithDetails($deliveryId);
            $uid = Auth::id();
            if ($row && ((int) $row['buyer_user_id'] === $uid || (int) $row['seller_user_id'] === $uid || Auth::can('disputes'))) {
                $shipment = is_array($row['shipment'] ?? null) ? $row['shipment'] : [];
            }
        } elseif ($productId > 0) {
            $shipping = (new \App\Services\Listing\ListingShippingService())->findForProduct($productId);
            if (is_array($shipping)) {
                $shipment = $shipping;
            }
        }
        if ($shipment !== []) {
            $filters['weight_kg'] = (float) ($shipment['billed_gross_weight'] ?? $shipment['gross_weight'] ?? $shipment['item_weight'] ?? 0);
            $filters['length_cm'] = (int) round((float) ($shipment['billed_length'] ?? $shipment['package_length'] ?? $shipment['item_length'] ?? 0));
            $filters['width_cm'] = (int) round((float) ($shipment['billed_width'] ?? $shipment['package_width'] ?? $shipment['item_width'] ?? 0));
            $filters['height_cm'] = (int) round((float) ($shipment['billed_height'] ?? $shipment['package_height'] ?? $shipment['item_height'] ?? 0));
        }
        return $filters;
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

    /** GET /delivery/{id}/tracking — сохранённая сводка, без запроса в CDEK. */
    public function tracking(string $id): void
    {
        Auth::requireLogin();
        $deliveryOrderId = (int) $id;
        $delivery = (new DeliveryOrder())->findWithDetails($deliveryOrderId);
        if (!$delivery) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        $forms = (new DeliveryPrintForm())->findForDelivery($deliveryOrderId);
        $result = CdekTrackingService::summaryForUser($delivery, Auth::id(), Auth::can('disputes'), $forms);
        if (empty($result['ok'])) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $this->json($result);
    }

    /** POST /delivery/{id}/tracking/refresh — только ставит сверку в очередь, CDEK не вызывается. */
    public function refreshTracking(string $id): void
    {
        Auth::requireLogin();
        $deliveryOrderId = (int) $id;
        $orders = new DeliveryOrder();
        $delivery = $orders->findWithDetails($deliveryOrderId);
        if (!$delivery) {
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        $forms = (new DeliveryPrintForm())->findForDelivery($deliveryOrderId);
        $result = CdekTrackingService::summaryForUser($delivery, Auth::id(), Auth::can('disputes'), $forms);
        if (empty($result['ok'])) {
            $this->json(['ok' => false, 'error' => 'forbidden'], 403);
            return;
        }
        $uuid = trim((string) ($delivery['logistics_order_id'] ?? $delivery['cdek_uuid'] ?? ''));
        $scheduled = false;
        if ($uuid === '') {
            $result['refresh_scheduled'] = false;
            $result['error'] = 'missing_uuid';
        } else {
            $scheduled = $orders->requestTrackingRefresh($deliveryOrderId);
            $result['refresh_scheduled'] = $scheduled;
        }
        if ($this->wantsJson()) {
            $this->json($result);
            return;
        }
        $_SESSION['flash'] = $scheduled
            ? t('delivery.track_refresh_scheduled')
            : t('delivery.track_refresh_idle');
        $this->redirect('/delivery/' . $deliveryOrderId);
    }

    /** GET /delivery/{id}/problems */
    public function deliveryProblems(string $id): void
    {
        Auth::requireLogin();
        $result = (new CdekDeliveryProblemService())->listForUser((int) $id, Auth::id(), Auth::can('disputes'));
        $this->json($result, $this->actionHttpCode($result));
    }

    /** GET /delivery/{id}/cdek-actions */
    public function cdekActions(string $id): void
    {
        Auth::requireLogin();
        $result = (new CdekDeliveryActionService())->listForUser((int) $id, Auth::id(), Auth::can('disputes'));
        $this->json($result, $this->actionHttpCode($result));
    }

    /** POST /delivery/{id}/cdek-actions/refusal */
    public function requestRefusal(string $id): void
    {
        $this->requestCdekAction((int) $id, 'refusal');
    }

    /** POST /delivery/{id}/cdek-actions/client-return */
    public function requestClientReturn(string $id): void
    {
        $this->requestCdekAction((int) $id, 'client_return');
    }

    /** GET /delivery/{id}/cdek-actions/{actionId} */
    public function cdekActionStatus(string $id, string $actionId): void
    {
        Auth::requireLogin();
        $service = new CdekDeliveryActionService();
        $list = $service->listForUser((int) $id, Auth::id(), Auth::can('disputes'));
        if (empty($list['ok'])) {
            $this->json($list, $this->actionHttpCode($list));
            return;
        }
        $found = null;
        foreach ($list['actions'] ?? [] as $action) {
            if ((int) ($action['id'] ?? 0) === (int) $actionId) {
                $found = $action;
                break;
            }
        }
        if ($found === null) {
            $this->json(['ok' => false, 'error_code' => 'not_found'], 404);
            return;
        }
        $this->json(['ok' => true, 'action' => $found]);
    }

    private function requestCdekAction(int $deliveryOrderId, string $type): void
    {
        Auth::requireLogin();
        unset($_POST['cdek_uuid'], $_POST['access_token'], $_POST['client_secret']);
        $result = (new CdekDeliveryActionService())->request(
            $deliveryOrderId,
            Auth::id(),
            Auth::can('disputes'),
            $type,
            [
                'confirm' => (string) ($_POST['confirm'] ?? ''),
                'reason' => (string) ($_POST['reason'] ?? ''),
                'tariff_code' => $_POST['tariff_code'] ?? null,
            ]
        );
        if ($this->wantsJson()) {
            $this->json($result, $this->actionHttpCode($result));
            return;
        }
        $_SESSION[($result['ok'] ?? false) ? 'flash' : 'error'] = t(
            'delivery.cdek_action_error_' . (string) ($result['error_code'] ?? 'failed')
        );
        $this->redirect('/delivery/' . $deliveryOrderId);
    }

    /** @param array{ok?: bool, error_code?: string} $result */
    private function actionHttpCode(array $result): int
    {
        if ($result['ok'] ?? false) {
            return 200;
        }
        return match ((string) ($result['error_code'] ?? '')) {
            'forbidden' => 403,
            'not_found' => 404,
            default => 422,
        };
    }

    /** GET /delivery/{id}/print-forms */
    public function printForms(string $id): void
    {
        Auth::requireLogin();
        $result = (new CdekPrintFormService())->listForUser((int) $id, Auth::id(), Auth::can('disputes'));
        $this->json($result, $this->printHttpCode($result));
    }

    /** POST /delivery/{id}/print-forms — тип формы с whitelist, без CDEK credentials. */
    public function requestPrintForm(string $id): void
    {
        Auth::requireLogin();
        unset($_POST['cdek_uuid'], $_POST['print_uuid'], $_POST['file_url'], $_POST['url']);
        $type = trim((string) ($_POST['form_type'] ?? ''));
        $result = (new CdekPrintFormService())->request((int) $id, Auth::id(), Auth::can('disputes'), $type !== '' ? $type : null);
        if ($this->wantsJson()) {
            $this->json($result, $this->printHttpCode($result));
            return;
        }
        $_SESSION[$result['ok'] ?? false ? 'flash' : 'error'] = ($result['ok'] ?? false)
            ? (!empty($result['async']) ? t('delivery.print_accepted') : t('delivery.print_ready'))
            : ($result['error'] ?? t('delivery.print_failed'));
        $this->redirect('/delivery/' . (int) $id);
    }

    /** GET /delivery/{id}/print-forms/{formId} */
    public function printFormStatus(string $id, string $formId): void
    {
        Auth::requireLogin();
        $list = (new CdekPrintFormService())->listForUser((int) $id, Auth::id(), Auth::can('disputes'));
        if (!($list['ok'] ?? false)) {
            $this->json($list, $this->printHttpCode($list));
            return;
        }
        foreach ($list['forms'] as $form) {
            if ((int) ($form['id'] ?? 0) === (int) $formId) {
                $this->json(['ok' => true, 'form' => $form]);
                return;
            }
        }
        $this->json(['ok' => false, 'error' => t('delivery.print_not_found'), 'error_code' => 'not_found'], 404);
    }

    /** GET /delivery/{id}/print-forms/{formId}/file */
    public function downloadPrintForm(string $id, string $formId): void
    {
        Auth::requireLogin();
        $result = (new CdekPrintFormService())->downloadForUser(
            (int) $id,
            (int) $formId,
            Auth::id(),
            Auth::can('disputes')
        );
        if (!($result['ok'] ?? false)) {
            $this->json([
                'ok' => false,
                'error' => $result['error'] ?? t('delivery.print_unavailable'),
                'error_code' => $result['error_code'] ?? 'download_failed',
            ], $this->printHttpCode($result));
            return;
        }
        $filename = (string) ($result['filename'] ?? 'cdek-waybill.pdf');
        $body = (string) ($result['body'] ?? '');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $body;
        exit;
    }

    /** @param array{ok?: bool, error_code?: string} $result */
    private function printHttpCode(array $result): int
    {
        if ($result['ok'] ?? false) {
            return 200;
        }
        return match ((string) ($result['error_code'] ?? '')) {
            'forbidden' => 403,
            'not_found' => 404,
            'invalid_type' => 422,
            default => 422,
        };
    }

    private function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json') || (($_GET['format'] ?? '') === 'json');
    }
}
