<?php

namespace App\Services\Cdek;

use App\Models\DeliveryCdekProblem;
use App\Models\DeliveryOrder;

/**
 * Сохраняет DELIV_PROBLEM и delivery_problem из GET заказа.
 * Не меняет FSM на возврат и не вызывает refusal/clientReturn.
 */
class CdekDeliveryProblemService
{
    /** @var DeliveryCdekProblem|object */
    private object $problems;
    /** @var DeliveryOrder|object */
    private object $orders;

    public function __construct(?object $problems = null, ?object $orders = null)
    {
        $this->orders = $orders ?? new DeliveryOrder();
        $this->problems = $problems ?? new DeliveryCdekProblem();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, delivery_order_id?: int, problem_id?: int, duplicate?: bool, error?: string}
     */
    public function applyWebhook(array $payload): array
    {
        $extracted = CdekDeliveryActionPolicy::extractWebhookProblem($payload);
        $delivery = $this->resolve($extracted['number'], $extracted['order_uuid'], $extracted['cdek_number']);
        if ($delivery === null) {
            return ['ok' => false, 'error' => 'unresolved_order'];
        }
        $deliveryId = (int) $delivery['id'];
        $eventUuid = trim((string) ($payload['uuid'] ?? ''));
        $saved = $this->store(
            $deliveryId,
            'webhook',
            $extracted['code'],
            $extracted['occurred_at'],
            $eventUuid
        );
        $this->audit($deliveryId, 'cdek_problem_received');
        return [
            'ok' => true,
            'delivery_order_id' => $deliveryId,
            'problem_id' => $saved['id'],
            'duplicate' => !$saved['inserted'],
        ];
    }

    /**
     * @param array<string, mixed> $entity OrderResponseDto
     */
    public function ingestFromEntity(int $deliveryOrderId, array $entity): void
    {
        $items = $entity['delivery_problem'] ?? [];
        if (!is_array($items) || $deliveryOrderId <= 0) {
            return;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $extracted = CdekDeliveryActionPolicy::extractOrderProblem($item);
            if ($extracted === null) {
                continue;
            }
            $this->store($deliveryOrderId, 'get', $extracted['code'], $extracted['occurred_at'], '');
        }
    }

    /**
     * @return array{ok: bool, problems?: list<array<string, mixed>>, error?: string}
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
        $problems = [];
        foreach ($this->problems->forDelivery($deliveryOrderId) as $problem) {
            $problems[] = self::toPublic($problem, $isAdmin);
        }
        return ['ok' => true, 'problems' => $problems, 'attention' => $problems !== []];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublic(array $row, bool $isAdmin): array
    {
        $public = [
            'id' => (int) ($row['id'] ?? 0),
            'summary' => function_exists('t') ? t('delivery.problem_neutral') : 'Требуется уточнение по доставке',
            'occurred_at' => $row['occurred_at'] ?? null,
            'source' => (string) ($row['source'] ?? ''),
        ];
        if ($isAdmin && !empty($row['problem_code'])) {
            $public['problem_code'] = (string) $row['problem_code'];
        }
        return $public;
    }

    private function resolve(string $number, string $uuid, string $cdekNumber): ?array
    {
        if ($number !== '' && method_exists($this->orders, 'findByOrderNumber')) {
            $found = $this->orders->findByOrderNumber($number);
            if ($found) {
                return $found;
            }
        }
        if ($uuid !== '' && method_exists($this->orders, 'findByLogisticsOrderId')) {
            $found = $this->orders->findByLogisticsOrderId($uuid);
            if ($found) {
                return $found;
            }
        }
        if ($cdekNumber !== '' && method_exists($this->orders, 'findByTrackingNumber')) {
            $found = $this->orders->findByTrackingNumber($cdekNumber);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    /**
     * @return array{inserted: bool, id: int}
     */
    private function store(int $deliveryId, string $source, ?string $code, ?string $occurredAt, string $eventUuid): array
    {
        $hash = CdekDeliveryActionPolicy::problemHash($deliveryId, $source, $code, $occurredAt, $eventUuid);
        return $this->problems->insertIgnore([
            'delivery_order_id' => $deliveryId,
            'source' => $source,
            'problem_code' => $code,
            'occurred_at' => $occurredAt,
            'event_hash' => $hash,
        ]);
    }

    private function audit(int $deliveryId, string $event): void
    {
        if (!method_exists($this->orders, 'logEvent')) {
            return;
        }
        $this->orders->logEvent($deliveryId, null, 'system', $event, null, null, [
            'kind' => 'delivery_problem',
        ]);
    }
}
