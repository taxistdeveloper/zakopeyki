<?php

namespace App\Services\Cdek;

use App\Models\DeliveryCdekAction;
use App\Models\DeliveryOrder;

/**
 * Решения по проблемам, отказу и clientReturn без вызова CDEK.
 * OpenAPI не задаёт, при каком статусе заказа refusal/clientReturn допустимы.
 */
final class CdekDeliveryActionPolicy
{
    public const TYPE_REFUSAL = 'refusal';
    public const TYPE_CLIENT_RETURN = 'client_return';

    /**
     * Внутренний ключ не передаётся в CDEK: у refusal нет тела, у clientReturn в схеме нет idempotency key.
     * Он не гарантирует exactly-once на стороне CDEK.
     */
    public static function mayCallCdek(): bool
    {
        return false;
    }

    public static function isTerminalDelivery(string $status): bool
    {
        return in_array($status, [
            DeliveryOrder::STATUS_DELIVERED,
            DeliveryOrder::STATUS_CANCELLED,
            DeliveryOrder::STATUS_REFUND_REQUIRED,
        ], true);
    }

    public static function idempotencyKey(int $deliveryOrderId, string $type, int $userId): string
    {
        return hash('sha256', $deliveryOrderId . '|' . $type . '|' . $userId);
    }

    public static function sanitizeReason(?string $reason): ?string
    {
        $reason = trim(strip_tags((string) $reason));
        $reason = preg_replace('/\s+/u', ' ', $reason) ?? '';
        if ($reason === '') {
            return null;
        }
        return mb_substr($reason, 0, 200);
    }

    public static function problemCode(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || preg_match('/^[A-Z0-9_\-]{1,64}$/', $code) !== 1) {
            return null;
        }
        return $code;
    }

    public static function problemHash(int $deliveryOrderId, string $source, ?string $code, ?string $occurredAt, string $eventUuid): string
    {
        return hash('sha256', implode('|', [
            (string) $deliveryOrderId,
            $source,
            (string) $code,
            (string) $occurredAt,
            $eventUuid,
        ]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{code: ?string, occurred_at: ?string, order_uuid: string, number: string, cdek_number: string}
     */
    public static function extractWebhookProblem(array $payload): array
    {
        $attrs = $payload['attributes'] ?? [];
        if (!is_array($attrs)) {
            $attrs = [];
        }
        return [
            'code' => self::problemCode(isset($attrs['code']) ? (string) $attrs['code'] : null),
            'occurred_at' => CdekTrackingPolicy::normalizeEventAt(
                (string) ($attrs['create_date'] ?? $attrs['status_date_time'] ?? $payload['date_time'] ?? '')
            ),
            'order_uuid' => trim((string) ($attrs['uuid'] ?? $attrs['order_uuid'] ?? '')),
            'number' => trim((string) ($attrs['number'] ?? '')),
            'cdek_number' => trim((string) ($attrs['cdek_number'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $item DeliveryProblemResponseDto
     * @return array{code: ?string, occurred_at: ?string}|null
     */
    public static function extractOrderProblem(array $item): ?array
    {
        $code = self::problemCode(isset($item['code']) ? (string) $item['code'] : null);
        $at = CdekTrackingPolicy::normalizeEventAt(isset($item['create_date']) ? (string) $item['create_date'] : null);
        if ($code === null && $at === null) {
            return null;
        }
        return ['code' => $code, 'occurred_at' => $at];
    }

    /**
     * Разбор документированного ответа refusal/clientReturn.
     * 202 / ACCEPTED — запрос принят, операция не подтверждена.
     * SUCCESSFUL — единственное слово, после которого внутренний статус CONFIRMED.
     * Timeout не разрешает повторный POST.
     *
     * @param array<string, mixed> $result нормализованный ответ клиента
     * @return array{
     *   state: string,
     *   response_state: ?string,
     *   request_uuid: ?string,
     *   related_uuid: ?string,
     *   error_code: ?string,
     *   retry_post: bool,
     *   confirmed: bool
     * }
     */
    public static function interpretApiResult(array $result): array
    {
        $http = (int) ($result['http_status'] ?? $result['code'] ?? 0);
        $error = strtolower((string) ($result['error'] ?? ''));
        $errorType = strtolower((string) ($result['error_type'] ?? ''));
        $timeout = str_contains($errorType, 'timeout')
            || str_contains($error, 'timeout')
            || str_contains($error, 'timed out');
        if ($timeout) {
            return self::apiDecision(DeliveryCdekAction::UNKNOWN_RESULT, null, null, null, 'timeout', false);
        }
        if ($http === 0 || str_contains($errorType, 'network')) {
            return self::apiDecision(DeliveryCdekAction::UNKNOWN_RESULT, null, null, null, 'network', false);
        }

        $request = self::firstRequest($result);
        $requestState = strtoupper(trim((string) ($request['state'] ?? '')));
        $requestUuid = trim((string) ($request['request_uuid'] ?? ''));
        $related = self::relatedReturnUuid($result);

        if (in_array($requestState, ['INVALID'], true) || $http === 400) {
            return self::apiDecision(
                DeliveryCdekAction::FAILED,
                $requestState !== '' ? $requestState : 'INVALID',
                $requestUuid !== '' ? $requestUuid : null,
                $related,
                'invalid',
                false
            );
        }

        if ($http === 202 || $requestState === 'ACCEPTED') {
            return self::apiDecision(
                DeliveryCdekAction::SUBMITTED,
                $requestState !== '' ? $requestState : 'ACCEPTED',
                $requestUuid !== '' ? $requestUuid : null,
                $related,
                null,
                false
            );
        }

        if ($requestState === 'SUCCESSFUL') {
            return self::apiDecision(
                DeliveryCdekAction::CONFIRMED,
                'SUCCESSFUL',
                $requestUuid !== '' ? $requestUuid : null,
                $related,
                null,
                false
            );
        }

        return self::apiDecision(DeliveryCdekAction::FAILED, $requestState !== '' ? $requestState : null, null, $related, 'error', false);
    }

    /**
     * Сверка уже отправленного запроса через GET /v2/orders/{uuid}.
     * Не создаёт заказ. Неизвестный state не считается подтверждением.
     *
     * @param array<string, mixed> $orderBody
     * @return array{state: string, response_state: ?string, related_uuid: ?string, marketplace_return: false}
     */
    public static function interpretFollowUp(array $orderBody, ?string $requestUuid): array
    {
        $requests = $orderBody['requests'] ?? [];
        if (!is_array($requests)) {
            $requests = [];
        }
        $matched = null;
        foreach ($requests as $request) {
            if (!is_array($request)) {
                continue;
            }
            $uuid = trim((string) ($request['request_uuid'] ?? ''));
            if ($requestUuid !== null && $requestUuid !== '' && strcasecmp($uuid, $requestUuid) === 0) {
                $matched = $request;
                break;
            }
        }
        $stateWord = strtoupper(trim((string) ($matched['state'] ?? '')));
        $internal = match ($stateWord) {
            'SUCCESSFUL' => DeliveryCdekAction::CONFIRMED,
            'INVALID' => DeliveryCdekAction::FAILED,
            'ACCEPTED' => DeliveryCdekAction::SUBMITTED,
            default => DeliveryCdekAction::SUBMITTED,
        };
        if ($matched === null) {
            $internal = DeliveryCdekAction::UNKNOWN_RESULT;
            $stateWord = '';
        }
        return [
            'state' => $internal,
            'response_state' => $stateWord !== '' ? $stateWord : null,
            'related_uuid' => self::relatedReturnUuid($orderBody),
            'marketplace_return' => false,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function firstRequest(array $result): array
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
        $requests = $data['requests'] ?? [];
        if (!is_array($requests) || !is_array($requests[0] ?? null)) {
            return [];
        }
        return $requests[0];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function relatedReturnUuid(array $result): ?string
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : $result;
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : [];
        $lists = [];
        if (is_array($data['related_entities'] ?? null)) {
            $lists[] = $data['related_entities'];
        }
        if (is_array($entity['related_entities'] ?? null)) {
            $lists[] = $entity['related_entities'];
        }
        foreach ($lists as $list) {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = (string) ($item['type'] ?? '');
                if (!in_array($type, ['client_return_order', 'return_order'], true)) {
                    continue;
                }
                $uuid = trim((string) ($item['uuid'] ?? ''));
                if ($uuid !== '') {
                    return $uuid;
                }
            }
        }
        return null;
    }

    /**
     * @return array{state: string, response_state: ?string, request_uuid: ?string, related_uuid: ?string, error_code: ?string, retry_post: bool, confirmed: bool}
     */
    private static function apiDecision(
        string $state,
        ?string $responseState,
        ?string $requestUuid,
        ?string $related,
        ?string $errorCode,
        bool $retry
    ): array {
        return [
            'state' => $state,
            'response_state' => $responseState,
            'request_uuid' => $requestUuid,
            'related_uuid' => $related,
            'error_code' => $errorCode,
            'retry_post' => $retry,
            'confirmed' => $state === DeliveryCdekAction::CONFIRMED,
        ];
    }
}
