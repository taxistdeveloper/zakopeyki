<?php

namespace App\Services\Cdek;

use App\Models\DeliveryCdekAction;
use App\Models\DeliveryOrder;
use App\Services\MicroTaskLock;

/**
 * Запрос refusal или clientReturn.
 * Пока OpenAPI не задаёт допустимые статусы, рабочий сценарий не вызывает CDEK.
 * Внутренний ключ не является exactly-once на стороне CDEK.
 */
class CdekDeliveryActionService
{
    /** @var DeliveryCdekAction|object */
    private object $actions;
    /** @var DeliveryOrder|object */
    private object $orders;
    /** @var CdekApi|object */
    private object $api;
    /** @var MicroTaskLock|object */
    private object $lock;

    public function __construct(
        ?object $actions = null,
        ?object $orders = null,
        ?object $api = null,
        ?object $lock = null
    ) {
        $this->actions = $actions ?? new DeliveryCdekAction();
        $this->orders = $orders ?? new DeliveryOrder();
        $this->api = $api ?? new CdekApi();
        $this->lock = $lock ?? new MicroTaskLock();
    }

    /**
     * @return array{ok: bool, actions?: list<array<string, mixed>>, available?: array<string, mixed>, error?: string, error_code?: string}
     */
    public function listForUser(int $deliveryOrderId, int $userId, bool $isAdmin): array
    {
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error' => 'not_found', 'error_code' => 'not_found'];
        }
        if (!CdekPrintFormService::canAccessDelivery($row, $userId, $isAdmin)) {
            return ['ok' => false, 'error' => 'forbidden', 'error_code' => 'forbidden'];
        }
        $actions = [];
        foreach ($this->actions->forDelivery($deliveryOrderId) as $action) {
            $actions[] = self::toPublic($action);
        }
        return [
            'ok' => true,
            'actions' => $actions,
            'available' => self::availableActions(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function request(int $deliveryOrderId, int $userId, bool $isAdmin, string $type, array $input): array
    {
        if (!in_array($type, [CdekDeliveryActionPolicy::TYPE_REFUSAL, CdekDeliveryActionPolicy::TYPE_CLIENT_RETURN], true)) {
            return ['ok' => false, 'error_code' => 'unknown_operation', 'cdek_called' => false];
        }
        $row = $this->orders->find($deliveryOrderId);
        if (!$row) {
            return ['ok' => false, 'error_code' => 'not_found', 'cdek_called' => false];
        }
        if (!CdekPrintFormService::canAccessDelivery($row, $userId, $isAdmin)) {
            return ['ok' => false, 'error_code' => 'forbidden', 'cdek_called' => false];
        }
        if ((string) ($input['confirm'] ?? '') !== '1') {
            return ['ok' => false, 'error_code' => 'needs_confirmation', 'cdek_called' => false];
        }

        $uuid = trim((string) ($row['logistics_order_id'] ?? $row['cdek_uuid'] ?? ''));
        if ($uuid === '') {
            return ['ok' => false, 'error_code' => 'missing_uuid', 'cdek_called' => false];
        }
        if (CdekDeliveryActionPolicy::isTerminalDelivery((string) ($row['status'] ?? ''))) {
            return ['ok' => false, 'error_code' => 'operation_not_allowed', 'cdek_called' => false];
        }

        $tariff = null;
        if ($type === CdekDeliveryActionPolicy::TYPE_CLIENT_RETURN) {
            $tariff = filter_var($input['tariff_code'] ?? null, FILTER_VALIDATE_INT);
            if ($tariff === false || (int) $tariff <= 0) {
                return ['ok' => false, 'error_code' => 'tariff_invalid', 'cdek_called' => false];
            }
        }

        $lockKey = 'cdek-action-' . $deliveryOrderId . '-' . $type;
        if (!$this->lock->set($lockKey, '1', ['NX', 'EX' => 30])) {
            return ['ok' => false, 'error_code' => 'in_progress', 'cdek_called' => false];
        }

        try {
            $key = CdekDeliveryActionPolicy::idempotencyKey($deliveryOrderId, $type, $userId);
            $existing = $this->actions->findByKey($key);
            if ($existing) {
                return [
                    'ok' => false,
                    'error_code' => 'duplicate',
                    'cdek_called' => false,
                    'action' => self::toPublic($existing),
                ];
            }

            $role = self::roleFor($row, $userId, $isAdmin);
            $reason = CdekDeliveryActionPolicy::sanitizeReason(isset($input['reason']) ? (string) $input['reason'] : null);

            if (!CdekDeliveryActionPolicy::mayCallCdek()) {
                $id = $this->actions->insert([
                    'delivery_order_id' => $deliveryOrderId,
                    'operation_type' => $type,
                    'initiator_user_id' => $userId,
                    'initiator_role' => $role,
                    'reason' => $reason,
                    'state' => DeliveryCdekAction::CANCELLED,
                    'cdek_order_uuid' => $uuid,
                    'idempotency_key' => $key,
                    'error_code' => 'right_unconfirmed',
                    'error_message' => 'eligibility_not_in_openapi',
                ]);
                $this->audit($deliveryOrderId, $userId, $role, 'cdek_action_blocked', $type);
                $saved = $this->actions->findById($id) ?? ['id' => $id, 'state' => DeliveryCdekAction::CANCELLED, 'operation_type' => $type];
                return [
                    'ok' => false,
                    'error_code' => 'right_unconfirmed',
                    'cdek_called' => false,
                    'marketplace_return' => false,
                    'action' => self::toPublic($saved),
                ];
            }

            return $this->submit($row, $userId, $role, $type, $uuid, $key, $reason, $tariff);
        } finally {
            $this->release($lockKey);
        }
    }

    /**
     * Проверка уже отправленного запроса по телу GET /v2/orders/{uuid}.
     * Не создаёт новый заказ и не двигает оплату.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $orderBody
     * @return array<string, mixed>
     */
    public function applyFollowUp(array $action, array $orderBody): array
    {
        $decision = CdekDeliveryActionPolicy::interpretFollowUp(
            $orderBody,
            isset($action['cdek_request_uuid']) ? (string) $action['cdek_request_uuid'] : null
        );
        $id = (int) ($action['id'] ?? 0);
        if ($id > 0 && method_exists($this->actions, 'updateState')) {
            $this->actions->updateState($id, [
                'state' => $decision['state'],
                'response_state' => $decision['response_state'],
                'related_uuid' => $decision['related_uuid'],
            ]);
        }
        $action['state'] = $decision['state'];
        $action['response_state'] = $decision['response_state'];
        $action['related_uuid'] = $decision['related_uuid'];
        return [
            'ok' => true,
            'marketplace_return' => false,
            'delivery_status_changed' => false,
            'cdek_called' => false,
            'action' => self::toPublic($action),
        ];
    }

    /**
     * @return array<string, array{available: bool, reason: string}>
     */
    public static function availableActions(): array
    {
        $reason = CdekDeliveryActionPolicy::mayCallCdek() ? 'allowed' : 'eligibility_not_in_openapi';
        $available = CdekDeliveryActionPolicy::mayCallCdek();
        return [
            CdekDeliveryActionPolicy::TYPE_REFUSAL => ['available' => $available, 'reason' => $reason],
            CdekDeliveryActionPolicy::TYPE_CLIENT_RETURN => ['available' => $available, 'reason' => $reason],
            'marketplace_return' => ['available' => false, 'reason' => 'not_this_stage'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublic(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'operation_type' => (string) ($row['operation_type'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'state_label' => self::stateLabel((string) ($row['state'] ?? '')),
            'error_code' => $row['error_code'] ?? null,
            'updated_at' => $row['updated_at'] ?? ($row['created_at'] ?? null),
        ];
    }

    public static function stateLabel(string $state): string
    {
        $key = 'delivery.cdek_action_' . strtolower($state);
        if (function_exists('t')) {
            $label = t($key);
            if ($label !== $key) {
                return $label;
            }
        }
        return match ($state) {
            DeliveryCdekAction::REQUESTED => 'Запрос создан',
            DeliveryCdekAction::VALIDATING => 'Проверяем',
            DeliveryCdekAction::SUBMITTED => 'CDEK принял запрос в обработку',
            DeliveryCdekAction::CONFIRMED => 'CDEK подтвердил операцию',
            DeliveryCdekAction::FAILED => 'CDEK не выполнил операцию',
            DeliveryCdekAction::CANCELLED => 'Операция не отправлена в CDEK',
            DeliveryCdekAction::UNKNOWN_RESULT => 'Результат запроса неизвестен',
            default => $state,
        };
    }

    /**
     * Вызывается только если mayCallCdek() станет true после подтверждения правил CDEK.
     * Сейчас этот путь недостижим.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function submit(
        array $row,
        int $userId,
        string $role,
        string $type,
        string $uuid,
        string $key,
        ?string $reason,
        ?int $tariff
    ): array {
        $id = $this->actions->insert([
            'delivery_order_id' => (int) $row['id'],
            'operation_type' => $type,
            'initiator_user_id' => $userId,
            'initiator_role' => $role,
            'reason' => $reason,
            'state' => DeliveryCdekAction::VALIDATING,
            'cdek_order_uuid' => $uuid,
            'idempotency_key' => $key,
        ]);
        $apiResult = $type === CdekDeliveryActionPolicy::TYPE_CLIENT_RETURN
            ? $this->api->clientReturn($uuid, ['tariff_code' => (int) $tariff])
            : $this->api->refuseOrder($uuid);
        $normalized = is_array($apiResult) ? $apiResult : (method_exists($apiResult, 'toArray') ? $apiResult->toArray() : []);
        $decision = CdekDeliveryActionPolicy::interpretApiResult($normalized);
        if (method_exists($this->actions, 'updateState')) {
            $this->actions->updateState($id, [
                'state' => $decision['state'],
                'response_state' => $decision['response_state'],
                'cdek_request_uuid' => $decision['request_uuid'],
                'related_uuid' => $decision['related_uuid'],
                'error_code' => $decision['error_code'],
            ]);
        }
        return [
            'ok' => $decision['state'] !== DeliveryCdekAction::FAILED,
            'error_code' => $decision['error_code'],
            'cdek_called' => true,
            'marketplace_return' => false,
            'action' => self::toPublic([
                'id' => $id,
                'operation_type' => $type,
                'state' => $decision['state'],
                'error_code' => $decision['error_code'],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function roleFor(array $row, int $userId, bool $isAdmin): string
    {
        if ((int) ($row['buyer_user_id'] ?? 0) === $userId) {
            return 'buyer';
        }
        if ((int) ($row['seller_user_id'] ?? 0) === $userId) {
            return 'seller';
        }
        return $isAdmin ? 'admin' : 'unknown';
    }

    private function audit(int $deliveryId, int $userId, string $role, string $event, string $type): void
    {
        if (!method_exists($this->orders, 'logEvent')) {
            return;
        }
        $this->orders->logEvent($deliveryId, $userId, $role, $event, null, null, [
            'operation_type' => $type,
            'cdek_called' => false,
        ]);
    }

    private function release(string $key): void
    {
        if (method_exists($this->lock, 'release')) {
            $this->lock->release($key);
            return;
        }
        $this->lock->set($key, '0', ['EX' => 1]);
    }
}
