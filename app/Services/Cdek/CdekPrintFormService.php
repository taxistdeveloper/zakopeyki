<?php

namespace App\Services\Cdek;

use App\Models\DeliveryOrder;
use App\Models\DeliveryPrintForm;
use App\Services\Delivery\DeliveryPii;

/**
 * Запрос и выдача печатных форм CDEK для уже созданного заказа.
 * Не создаёт CDEK order и не меняет оплату.
 *
 * @see docs/cdek-print-forms.md
 * @see openapi WaybillRequestDto / WaybillPrintResponseDto / WaybillDto
 */
class CdekPrintFormService
{
    public const MAX_PDF_BYTES = 8 * 1024 * 1024;
    public const MAX_POLL_ATTEMPTS = 8;

    /** WaybillRequestDto.type enum (OpenAPI). tpl_invoice — инвойс, остальные — шаблоны накладной. */
    public const FORM_TYPES = [
        'tpl_china',
        'tpl_armenia',
        'tpl_russia',
        'tpl_english',
        'tpl_italian',
        'tpl_korean',
        'tpl_latvian',
        'tpl_lithuanian',
        'tpl_german',
        'tpl_turkish',
        'tpl_czech',
        'tpl_thailand',
        'tpl_invoice',
    ];

    /** Статусы доставки, при которых печать запрещена (заказа CDEK ещё нет или он отменён). */
    private const BLOCKED_DELIVERY_STATUSES = [
        DeliveryOrder::STATUS_DATA_COLLECTION,
        DeliveryOrder::STATUS_DATA_COMPLETE,
        DeliveryOrder::STATUS_QUOTE_REQUESTED,
        DeliveryOrder::STATUS_QUOTE_RECEIVED,
        DeliveryOrder::STATUS_READY_FOR_PAYMENT,
        DeliveryOrder::STATUS_PAYMENT_PENDING,
        DeliveryOrder::STATUS_PAID,
        DeliveryOrder::STATUS_CDEK_ORDER_FAILED,
        DeliveryOrder::STATUS_CANCELLED,
        DeliveryOrder::STATUS_REFUND_REQUIRED,
    ];

    private DeliveryPrintForm $forms;
    private DeliveryOrder $orders;
    private CdekApi $api;
    private string $storageRoot;

    public function __construct(
        ?DeliveryPrintForm $forms = null,
        ?DeliveryOrder $orders = null,
        ?CdekApi $api = null,
        ?string $storageRoot = null
    ) {
        $this->forms = $forms ?? new DeliveryPrintForm();
        $this->orders = $orders ?? new DeliveryOrder();
        $this->api = $api ?? new CdekApi();
        $this->storageRoot = $storageRoot ?? dirname(__DIR__, 3) . '/storage/cdek-prints';
    }

    /** @return list<string> */
    public static function allowedTypes(): array
    {
        return self::FORM_TYPES;
    }

    public static function defaultType(): string
    {
        $path = dirname(__DIR__, 3) . '/config/cdek.php';
        $cfg = is_file($path) ? (require $path) : [];
        $type = (string) ($cfg['print_default_type'] ?? 'tpl_russia');
        return in_array($type, self::FORM_TYPES, true) ? $type : 'tpl_russia';
    }

    /**
     * WaybillRequestDto — только поля OpenAPI.
     *
     * @return array{orders: list<array{order_uuid: string, cdek_number?: int}>, type: string}
     */
    public static function buildWaybillRequest(string $orderUuid, ?string $cdekNumber, string $type): array
    {
        $order = ['order_uuid' => $orderUuid];
        if ($cdekNumber !== null && $cdekNumber !== '' && ctype_digit($cdekNumber)) {
            $order['cdek_number'] = (int) $cdekNumber;
        }
        return [
            'orders' => [$order],
            'type' => $type,
        ];
    }

    /**
     * HTTP 202 / ACCEPTED ≠ готовый PDF.
     *
     * @param array<string, mixed>|null $data
     * @return array{status: string, print_uuid: ?string, file_url: ?string, error: ?string}
     */
    public static function interpretResponse(int $http, ?array $data, bool $timeout = false): array
    {
        if ($timeout || $http === 0) {
            return ['status' => DeliveryPrintForm::STATUS_PROCESSING, 'print_uuid' => null, 'file_url' => null, 'error' => 'timeout_or_network'];
        }
        if (in_array($http, [429], true) || $http >= 500) {
            return ['status' => DeliveryPrintForm::STATUS_PROCESSING, 'print_uuid' => self::entityUuid($data), 'file_url' => self::entityUrl($data), 'error' => 'http_' . $http];
        }
        if ($http === 401 || $http === 403 || $http === 400 || $http === 404 || $http === 409) {
            return ['status' => DeliveryPrintForm::STATUS_FAILED, 'print_uuid' => self::entityUuid($data), 'file_url' => null, 'error' => 'http_' . $http];
        }

        $state = strtoupper((string) (self::requestState($data) ?? ''));
        $url = self::entityUrl($data);
        $uuid = self::entityUuid($data);

        if ($state === 'INVALID') {
            return ['status' => DeliveryPrintForm::STATUS_FAILED, 'print_uuid' => $uuid, 'file_url' => null, 'error' => 'INVALID'];
        }

        // Готово только при url и не-202. HTTP 202 никогда не означает готовый PDF.
        if ($http !== 202 && $state !== 'ACCEPTED' && $state !== 'WAITING' && $url !== null) {
            return ['status' => DeliveryPrintForm::STATUS_READY, 'print_uuid' => $uuid, 'file_url' => $url, 'error' => null];
        }

        if ($http === 202 || $state === 'ACCEPTED' || $state === 'WAITING' || $state === 'SUCCESSFUL') {
            return ['status' => DeliveryPrintForm::STATUS_PROCESSING, 'print_uuid' => $uuid, 'file_url' => null, 'error' => null];
        }

        if ($http >= 400) {
            return ['status' => DeliveryPrintForm::STATUS_FAILED, 'print_uuid' => $uuid, 'file_url' => null, 'error' => 'http_' . $http];
        }

        return ['status' => DeliveryPrintForm::STATUS_PROCESSING, 'print_uuid' => $uuid, 'file_url' => $url, 'error' => null];
    }

    /**
     * @param array<string, mixed> $row delivery order
     */
    public static function canAccessDelivery(array $row, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        return (int) ($row['buyer_user_id'] ?? 0) === $userId
            || (int) ($row['seller_user_id'] ?? 0) === $userId;
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public static function toPublic(array $form, int $deliveryOrderId): array
    {
        $status = (string) ($form['status'] ?? '');
        $id = (int) ($form['id'] ?? 0);
        return [
            'id' => $id,
            'form_type' => (string) ($form['form_type'] ?? ''),
            'status' => $status,
            'format' => (string) ($form['format'] ?? 'pdf'),
            'created_at' => $form['created_at'] ?? null,
            'updated_at' => $form['updated_at'] ?? null,
            'received_at' => $form['received_at'] ?? null,
            'download_path' => $status === DeliveryPrintForm::STATUS_READY
                ? '/delivery/' . $deliveryOrderId . '/print-forms/' . $id . '/file'
                : null,
        ];
    }

    /**
     * @return array{ok: bool, forms?: list<array>, error?: string, error_code?: string}
     */
    public function listForUser(int $deliveryOrderId, int $userId, bool $isAdmin): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
        }
        if (!self::canAccessDelivery($row, $userId, $isAdmin)) {
            return ['ok' => false, 'error' => t('delivery.forbidden'), 'error_code' => 'forbidden'];
        }
        $forms = [];
        foreach ($this->forms->findForDelivery($deliveryOrderId) as $form) {
            $forms[] = self::toPublic($form, $deliveryOrderId);
        }
        return ['ok' => true, 'forms' => $forms];
    }

    /**
     * @return array{ok: bool, form?: array, error?: string, error_code?: string, async?: bool}
     */
    public function request(int $deliveryOrderId, int $userId, bool $isAdmin, ?string $formType = null): array
    {
        $type = $formType !== null && $formType !== '' ? $formType : self::defaultType();
        if (!in_array($type, self::FORM_TYPES, true)) {
            return ['ok' => false, 'error' => t('delivery.print_type_invalid'), 'error_code' => 'invalid_type'];
        }

        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
        }
        if (!self::canAccessDelivery($row, $userId, $isAdmin)) {
            return ['ok' => false, 'error' => t('delivery.forbidden'), 'error_code' => 'forbidden'];
        }

        $uuid = trim((string) ($row['cdek_uuid'] ?? $row['logistics_order_id'] ?? ''));
        if ($uuid === '') {
            return ['ok' => false, 'error' => t('delivery.print_no_cdek_order'), 'error_code' => 'cdek_uuid_missing'];
        }
        if (in_array((string) ($row['status'] ?? ''), self::BLOCKED_DELIVERY_STATUSES, true)) {
            return ['ok' => false, 'error' => t('delivery.print_not_allowed'), 'error_code' => 'status_blocked'];
        }

        $existing = $this->forms->findByDeliveryAndType($deliveryOrderId, $type);
        if ($existing && in_array($existing['status'], [
            DeliveryPrintForm::STATUS_REQUESTED,
            DeliveryPrintForm::STATUS_PROCESSING,
            DeliveryPrintForm::STATUS_READY,
        ], true)) {
            return [
                'ok' => true,
                'form' => self::toPublic($existing, $deliveryOrderId),
                'async' => $existing['status'] !== DeliveryPrintForm::STATUS_READY,
                'already_existed' => true,
            ];
        }

        $cdekNumber = isset($row['cdek_number']) ? (string) $row['cdek_number'] : null;
        if ($existing) {
            $formId = (int) $existing['id'];
            $this->forms->updateFields($formId, [
                'status' => DeliveryPrintForm::STATUS_REQUESTED,
                'cdek_order_uuid' => $uuid,
                'cdek_number' => $cdekNumber,
                'error_code' => null,
                'error_message' => null,
                'source' => DeliveryPrintForm::SOURCE_API,
                'poll_attempts' => 0,
                'requested_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $ins = $this->forms->insertRow([
                'delivery_order_id' => $deliveryOrderId,
                'cdek_order_uuid' => $uuid,
                'cdek_number' => $cdekNumber,
                'form_type' => $type,
                'status' => DeliveryPrintForm::STATUS_REQUESTED,
                'source' => DeliveryPrintForm::SOURCE_API,
            ]);
            if (!($ins['ok'] ?? false)) {
                $again = $this->forms->findByDeliveryAndType($deliveryOrderId, $type);
                if ($again) {
                    return [
                        'ok' => true,
                        'form' => self::toPublic($again, $deliveryOrderId),
                        'already_existed' => true,
                        'async' => ($again['status'] ?? '') !== DeliveryPrintForm::STATUS_READY,
                    ];
                }
                return ['ok' => false, 'error' => t('delivery.print_failed'), 'error_code' => 'insert_failed'];
            }
            $formId = (int) $ins['id'];
        }

        $body = self::buildWaybillRequest($uuid, $cdekNumber, $type);
        $res = $this->api->printOrders($body);
        $http = $res->httpStatus;
        $timeout = $http === 0;
        $interpreted = self::interpretResponse($http, $res->data, $timeout);

        $this->forms->updateFields($formId, [
            'status' => $interpreted['status'],
            'print_uuid' => $interpreted['print_uuid'],
            'file_url' => $interpreted['file_url'],
            'error_code' => $interpreted['error'],
            'error_message' => $interpreted['status'] === DeliveryPrintForm::STATUS_FAILED
                ? mb_substr((string) ($res->message ?? $interpreted['error'] ?? ''), 0, 255)
                : null,
            'received_at' => $interpreted['status'] === DeliveryPrintForm::STATUS_READY ? date('Y-m-d H:i:s') : null,
        ]);

        $this->orders->logEvent(
            $deliveryOrderId,
            $userId,
            $isAdmin ? 'admin' : 'user',
            'print_form_requested',
            null,
            null,
            DeliveryPii::redactPayload([
                'form_id' => $formId,
                'form_type' => $type,
                'status' => $interpreted['status'],
                'http' => $http,
            ])
        );

        $fresh = $this->forms->find($formId) ?? [];
        return [
            'ok' => $interpreted['status'] !== DeliveryPrintForm::STATUS_FAILED,
            'form' => self::toPublic($fresh, $deliveryOrderId),
            'async' => $interpreted['status'] === DeliveryPrintForm::STATUS_PROCESSING
                || $interpreted['status'] === DeliveryPrintForm::STATUS_REQUESTED,
            'error' => $interpreted['status'] === DeliveryPrintForm::STATUS_FAILED ? t('delivery.print_failed') : null,
            'error_code' => $interpreted['error'],
        ];
    }

    /**
     * PRINT_FORM webhook: metadata only, без скачивания PDF.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, delivery_order_id?: int, error?: string}
     */
    public function applyWebhook(array $payload): array
    {
        $attrs = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $printUuid = $this->firstString($attrs, ['print_uuid', 'uuid']);
        $orderUuid = $this->firstString($attrs, ['order_uuid']);
        $url = $this->safeHttpUrl($this->firstString($attrs, ['url']));

        $form = null;
        if ($printUuid !== '') {
            $form = $this->forms->findByPrintUuid($printUuid);
        }
        if (!$form && $orderUuid !== '') {
            $order = $this->orders->findByCdekUuid($orderUuid);
            if ($order) {
                $type = $this->firstString($attrs, ['form_type', 'type']);
                if (!in_array($type, self::FORM_TYPES, true)) {
                    $type = self::defaultType();
                }
                $form = $this->forms->findByDeliveryAndType((int) $order['id'], $type);
            }
        }

        if (!$form) {
            return ['ok' => false, 'error' => 'print_form_unresolved'];
        }

        $fields = [
            'source' => DeliveryPrintForm::SOURCE_WEBHOOK,
        ];
        if ($printUuid !== '' && empty($form['print_uuid'])) {
            $fields['print_uuid'] = $printUuid;
        }
        if ($url !== null) {
            $fields['file_url'] = $url;
            $fields['status'] = DeliveryPrintForm::STATUS_READY;
            $fields['received_at'] = date('Y-m-d H:i:s');
            $fields['error_code'] = null;
            $fields['error_message'] = null;
        }

        $this->forms->updateFields((int) $form['id'], $fields);

        return [
            'ok' => true,
            'delivery_order_id' => (int) $form['delivery_order_id'],
        ];
    }

    /**
     * @return array{processed: int, ready: int, failed: int, waiting: int}
     */
    public function pollPending(int $limit = 20): array
    {
        $stats = ['processed' => 0, 'ready' => 0, 'failed' => 0, 'waiting' => 0];
        foreach ($this->forms->findPendingPoll($limit) as $form) {
            $stats['processed']++;
            $out = $this->refreshOne((int) $form['id']);
            $st = (string) ($out['status'] ?? '');
            if ($st === DeliveryPrintForm::STATUS_READY) {
                $stats['ready']++;
            } elseif ($st === DeliveryPrintForm::STATUS_FAILED || $st === DeliveryPrintForm::STATUS_EXPIRED) {
                $stats['failed']++;
            } else {
                $stats['waiting']++;
            }
        }
        return $stats;
    }

    /** @return array{ok: bool, status?: string, error?: string} */
    public function refreshOne(int $formId): array
    {
        $form = $this->forms->find($formId);
        if (!$form) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $printUuid = trim((string) ($form['print_uuid'] ?? ''));
        $attempts = (int) ($form['poll_attempts'] ?? 0) + 1;
        if ($printUuid === '') {
            if ($attempts >= self::MAX_POLL_ATTEMPTS) {
                $this->forms->updateFields($formId, [
                    'status' => DeliveryPrintForm::STATUS_FAILED,
                    'poll_attempts' => $attempts,
                    'error_code' => 'print_uuid_missing',
                ]);
                return ['ok' => false, 'status' => DeliveryPrintForm::STATUS_FAILED];
            }
            $this->forms->updateFields($formId, ['poll_attempts' => $attempts]);
            return ['ok' => true, 'status' => (string) $form['status']];
        }

        $res = $this->api->getPrintOrder($printUuid);
        $interpreted = self::interpretResponse($res->httpStatus, $res->data, $res->httpStatus === 0);
        $status = $interpreted['status'];
        if ($status === DeliveryPrintForm::STATUS_PROCESSING && $attempts >= self::MAX_POLL_ATTEMPTS) {
            $status = DeliveryPrintForm::STATUS_FAILED;
            $interpreted['error'] = $interpreted['error'] ?: 'poll_exhausted';
        }

        $fields = [
            'status' => $status,
            'poll_attempts' => $attempts,
            'error_code' => $interpreted['error'],
            'file_url' => $interpreted['file_url'] ?? $form['file_url'],
        ];
        if ($status === DeliveryPrintForm::STATUS_READY) {
            $fields['received_at'] = date('Y-m-d H:i:s');
            $fields['error_message'] = null;
            $saved = $this->storePdf($formId, (int) $form['delivery_order_id'], $printUuid);
            if ($saved['ok'] && !empty($saved['path'])) {
                $fields['storage_path'] = $saved['path'];
                $fields['file_size'] = $saved['size'] ?? null;
            }
        } else {
            $fields['error_message'] = mb_substr((string) ($res->message ?? $interpreted['error'] ?? ''), 0, 255);
        }
        $this->forms->updateFields($formId, $fields);
        return ['ok' => $status !== DeliveryPrintForm::STATUS_FAILED, 'status' => $status];
    }

    /**
     * @return array{ok: bool, path?: string, body?: string, filename?: string, error?: string, error_code?: string}
     */
    public function downloadForUser(int $deliveryOrderId, int $formId, int $userId, bool $isAdmin): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => t('delivery.not_found'), 'error_code' => 'not_found'];
        }
        if (!self::canAccessDelivery($row, $userId, $isAdmin)) {
            return ['ok' => false, 'error' => t('delivery.forbidden'), 'error_code' => 'forbidden'];
        }
        $form = $this->forms->find($formId);
        if (!$form || (int) $form['delivery_order_id'] !== $deliveryOrderId) {
            return ['ok' => false, 'error' => t('delivery.print_not_found'), 'error_code' => 'not_found'];
        }
        if ((string) $form['status'] !== DeliveryPrintForm::STATUS_READY) {
            return ['ok' => false, 'error' => t('delivery.print_not_ready'), 'error_code' => 'not_ready'];
        }

        $relative = (string) ($form['storage_path'] ?? '');
        $absolute = $this->resolveStoragePath($relative);
        if ($absolute !== null && is_file($absolute)) {
            $body = (string) file_get_contents($absolute);
            if ($this->isPdf($body)) {
                return [
                    'ok' => true,
                    'body' => $body,
                    'filename' => $this->safeFilename($row, $form),
                ];
            }
        }

        $printUuid = trim((string) ($form['print_uuid'] ?? ''));
        if ($printUuid === '') {
            return ['ok' => false, 'error' => t('delivery.print_not_ready'), 'error_code' => 'print_uuid_missing'];
        }
        $saved = $this->storePdf($formId, $deliveryOrderId, $printUuid);
        if (!$saved['ok']) {
            if (($saved['error_code'] ?? '') === 'http_404') {
                $this->forms->updateFields($formId, [
                    'status' => DeliveryPrintForm::STATUS_EXPIRED,
                    'error_code' => 'http_404',
                ]);
            }
            return ['ok' => false, 'error' => t('delivery.print_unavailable'), 'error_code' => $saved['error_code'] ?? 'download_failed'];
        }
        $absolute = $this->resolveStoragePath((string) ($saved['path'] ?? ''));
        if ($absolute === null) {
            return ['ok' => false, 'error' => t('delivery.print_unavailable'), 'error_code' => 'storage'];
        }
        return [
            'ok' => true,
            'body' => (string) file_get_contents($absolute),
            'filename' => $this->safeFilename($row, $form),
        ];
    }

    /**
     * @return array{ok: bool, path?: string, size?: int, error_code?: string}
     */
    private function storePdf(int $formId, int $deliveryOrderId, string $printUuid): array
    {
        $res = $this->api->downloadPrintOrderPdf($printUuid);
        $code = (int) ($res['code'] ?? 0);
        $body = (string) ($res['body'] ?? '');
        if ($code === 0) {
            return ['ok' => false, 'error_code' => 'timeout_or_network'];
        }
        if ($code !== 200) {
            return ['ok' => false, 'error_code' => 'http_' . $code];
        }
        if (strlen($body) > self::MAX_PDF_BYTES || !$this->isPdf($body)) {
            return ['ok' => false, 'error_code' => 'invalid_pdf'];
        }
        $relative = $deliveryOrderId . '/' . $formId . '.pdf';
        $absolute = $this->resolveStoragePath($relative);
        if ($absolute === null) {
            return ['ok' => false, 'error_code' => 'storage'];
        }
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'error_code' => 'storage'];
        }
        if (file_put_contents($absolute, $body) === false) {
            return ['ok' => false, 'error_code' => 'storage'];
        }
        $this->forms->updateFields($formId, [
            'storage_path' => $relative,
            'file_size' => strlen($body),
            'format' => 'pdf',
        ]);
        return ['ok' => true, 'path' => $relative, 'size' => strlen($body)];
    }

    private function resolveStoragePath(string $relative): ?string
    {
        if (!preg_match('#^\d+/\d+\.pdf$#', $relative)) {
            return null;
        }
        $root = realpath($this->storageRoot) ?: $this->storageRoot;
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($path);
        if (is_dir($parent)) {
            $realParent = realpath($parent);
            $realRoot = realpath($this->storageRoot);
            if ($realParent === false || $realRoot === false || !str_starts_with($realParent, $realRoot)) {
                return null;
            }
        }
        return $path;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $form */
    private function safeFilename(array $order, array $form): string
    {
        $num = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($order['order_number'] ?? 'order')) ?: 'order';
        $type = preg_replace('/[^a-z0-9_]/', '', (string) ($form['form_type'] ?? 'waybill')) ?: 'waybill';
        return 'cdek-' . $type . '-' . $num . '.pdf';
    }

    private function isPdf(string $body): bool
    {
        return str_starts_with($body, '%PDF');
    }

    /** @param array<string, mixed> $attrs @param list<string> $keys */
    private function firstString(array $attrs, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($attrs[$key]) && is_scalar($attrs[$key])) {
                $v = trim((string) $attrs[$key]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    private function safeHttpUrl(string $url): ?string
    {
        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return null;
        }
        if (preg_match('#[\r\n]#', $url)) {
            return null;
        }
        return mb_substr($url, 0, 512);
    }

    /** @param array<string, mixed>|null $data */
    private static function entityUuid(?array $data): ?string
    {
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : [];
        $uuid = $entity['uuid'] ?? null;
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** @param array<string, mixed>|null $data */
    private static function entityUrl(?array $data): ?string
    {
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : [];
        $url = $entity['url'] ?? null;
        if (!is_string($url) || !preg_match('#^https://#i', $url)) {
            return null;
        }
        return mb_substr($url, 0, 512);
    }

    /** @param array<string, mixed>|null $data */
    private static function requestState(?array $data): ?string
    {
        $requests = $data['requests'] ?? null;
        if (!is_array($requests) || !is_array($requests[0] ?? null)) {
            return null;
        }
        $state = $requests[0]['state'] ?? null;
        return is_string($state) ? $state : null;
    }
}
