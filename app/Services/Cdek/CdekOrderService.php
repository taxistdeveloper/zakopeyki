<?php

namespace App\Services\Cdek;

use App\Services\MicroTaskLock;

/**
 * Создание / чтение заказа CDEK (не calculator).
 *
 * Идемпотентность:
 * 1) локальный logistics_order_id (проверяет вызывающий DeliveryService);
 * 2) Redis/file lock на delivery_order_id на время create;
 * 3) developer_key + стабильный number в payload;
 * 4) при повторном вызове — GET по uuid / im_number, если уже создан.
 *
 * Race: два webhook/pay callback не должны создать два CDEK заказа.
 *
 * @see openapi_api_v2_integration.json POST /v2/orders, GET /v2/orders/{uuid}
 */
class CdekOrderService
{
    private const LOCK_TTL_SEC = 45;
    private const LOCK_PREFIX = 'cdek:order:lock:';

    private Client $client;
    private CdekOrderPayloadBuilder $payloadBuilder;
    private CdekErrorMapper $errorMapper;
    private ?object $redis;
    private MicroTaskLock $fileLock;

    public function __construct(
        ?Client $client = null,
        ?CdekOrderPayloadBuilder $payloadBuilder = null,
        ?CdekErrorMapper $errorMapper = null,
        ?object $redis = null,
        ?MicroTaskLock $fileLock = null
    ) {
        $this->client = $client ?? new Client();
        $this->errorMapper = $errorMapper ?? new CdekErrorMapper();
        $this->payloadBuilder = $payloadBuilder ?? new CdekOrderPayloadBuilder($this->errorMapper);
        $this->redis = $redis ?? self::makeRedis();
        $this->fileLock = $fileLock ?? new MicroTaskLock();
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function payloadBuilder(): CdekOrderPayloadBuilder
    {
        return $this->payloadBuilder;
    }

    /**
     * @param array<string, mixed> $avr
     * @param array{from_city_code?: int|null, to_city_code?: int|null, type?: int, existing_uuid?: string|null} $options
     * @return array{
     *   ok: bool,
     *   logistics_order_id?: string,
     *   tracking_number?: string|null,
     *   already_existed?: bool,
     *   request_id?: string,
     *   payload_hash?: string,
     *   error?: string,
     *   error_mapped?: array
     * }
     */
    public function create(array $avr, array $options = []): array
    {
        $deliveryOrderId = (int) ($avr['delivery_order_id'] ?? $avr['identifiers']['delivery_order_id'] ?? 0);
        if ($deliveryOrderId <= 0) {
            $mapped = $this->errorMapper->mapLocal(CdekErrorMapper::INTERNAL_VALIDATION, 'delivery_order_id required');
            return ['ok' => false, 'error' => $mapped['message'], 'error_mapped' => $mapped];
        }

        // Если uuid уже известен локально — не создаём повторно, при необходимости подтянем номер.
        $existingUuid = trim((string) ($options['existing_uuid'] ?? $avr['identifiers']['logistics_order_id'] ?? ''));
        if ($existingUuid !== '') {
            $fetched = $this->getByUuid($existingUuid);
            return [
                'ok' => true,
                'logistics_order_id' => $existingUuid,
                'tracking_number' => $fetched['cdek_number'] ?? null,
                'already_existed' => true,
                'request_id' => $fetched['request_id'] ?? null,
            ];
        }

        if (!$this->acquireLock($deliveryOrderId)) {
            // Другой воркер уже создаёт. Подождём и попробуем найти по im_number.
            usleep(400000);
            $byNumber = $this->findByImNumber((string) ($avr['order_number'] ?? ('DO-' . $deliveryOrderId)));
            if ($byNumber['ok'] && !empty($byNumber['uuid'])) {
                return [
                    'ok' => true,
                    'logistics_order_id' => (string) $byNumber['uuid'],
                    'tracking_number' => $byNumber['cdek_number'] ?? null,
                    'already_existed' => true,
                    'request_id' => $byNumber['request_id'] ?? null,
                ];
            }
            $mapped = $this->errorMapper->mapLocal(
                CdekErrorMapper::INTERNAL_VALIDATION,
                'CDEK order create already in progress'
            );
            return ['ok' => false, 'error' => $mapped['message'], 'error_mapped' => $mapped];
        }

        try {
            // Повторная проверка по im_number после захвата lock (двойной клик / двойной webhook).
            $orderNumber = (string) ($avr['order_number'] ?? ('DO-' . $deliveryOrderId));
            $existing = $this->findByImNumber($orderNumber);
            if ($existing['ok'] && !empty($existing['uuid'])) {
                return [
                    'ok' => true,
                    'logistics_order_id' => (string) $existing['uuid'],
                    'tracking_number' => $existing['cdek_number'] ?? null,
                    'already_existed' => true,
                    'request_id' => $existing['request_id'] ?? null,
                ];
            }

            $cfg = $this->client->config();
            $built = $this->payloadBuilder->buildFromAvr($avr, [
                'from_city_code' => $options['from_city_code'] ?? null,
                'to_city_code' => $options['to_city_code'] ?? null,
                'type' => (int) ($options['type'] ?? $cfg['order_type'] ?? 1),
                'developer_key' => 'zk-del-' . $deliveryOrderId,
            ]);

            if (!$built['ok']) {
                return [
                    'ok' => false,
                    'error' => $built['error']['message'] ?? 'payload build failed',
                    'error_mapped' => $built['error'],
                ];
            }

            $payload = $built['payload'];
            $extraHeaders = ['developer-key: ' . $built['developer_key']];

            $res = $this->client->post('/orders', $payload, $extraHeaders);
            if (!$res['ok']) {
                // v2_similar_request / duplicate — попробуем найти уже созданный.
                $cdekCode = $res['error_mapped']['cdek_code'] ?? '';
                if ($cdekCode === 'v2_similar_order_exists' || ($res['code'] ?? 0) === 400) {
                    $again = $this->findByImNumber($orderNumber);
                    if ($again['ok'] && !empty($again['uuid'])) {
                        return [
                            'ok' => true,
                            'logistics_order_id' => (string) $again['uuid'],
                            'tracking_number' => $again['cdek_number'] ?? null,
                            'already_existed' => true,
                            'request_id' => $res['request_id'] ?? null,
                            'payload_hash' => $built['payload_hash'],
                        ];
                    }
                }

                return [
                    'ok' => false,
                    'error' => $res['error'] ?? 'CDEK order create failed',
                    'error_mapped' => $res['error_mapped'] ?? null,
                    'request_id' => $res['request_id'] ?? null,
                    'payload_hash' => $built['payload_hash'],
                ];
            }

            $entity = is_array($res['data']['entity'] ?? null) ? $res['data']['entity'] : [];
            $uuid = (string) ($entity['uuid'] ?? '');
            if ($uuid === '') {
                $mapped = $this->errorMapper->mapLocal(
                    CdekErrorMapper::INTERNAL_UNKNOWN,
                    'CDEK order response without uuid'
                );
                return [
                    'ok' => false,
                    'error' => $mapped['message'],
                    'error_mapped' => $mapped,
                    'request_id' => $res['request_id'] ?? null,
                ];
            }

            $cdekNumber = isset($entity['cdek_number']) && $entity['cdek_number'] !== ''
                ? (string) $entity['cdek_number']
                : null;

            // Create асинхронный: номер часто появляется позже — один soft poll.
            if ($cdekNumber === null) {
                usleep(250000);
                $fetched = $this->getByUuid($uuid);
                if (!empty($fetched['cdek_number'])) {
                    $cdekNumber = (string) $fetched['cdek_number'];
                }
            }

            return [
                'ok' => true,
                'logistics_order_id' => $uuid,
                'tracking_number' => $cdekNumber,
                'already_existed' => false,
                'request_id' => $res['request_id'] ?? null,
                'payload_hash' => $built['payload_hash'],
            ];
        } finally {
            $this->releaseLock($deliveryOrderId);
        }
    }

    /**
     * GET /v2/orders/{uuid}
     *
     * @return array{ok: bool, uuid?: string, cdek_number?: string|null, statuses?: list, request_id?: string, error?: string, raw?: array}
     */
    public function getByUuid(string $uuid): array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return ['ok' => false, 'error' => 'uuid required'];
        }

        $res = $this->client->get('/orders/' . rawurlencode($uuid));
        if (!$res['ok']) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'get order failed',
                'request_id' => $res['request_id'] ?? null,
            ];
        }

        $entity = is_array($res['data']['entity'] ?? null) ? $res['data']['entity'] : [];
        return [
            'ok' => true,
            'uuid' => (string) ($entity['uuid'] ?? $uuid),
            'cdek_number' => isset($entity['cdek_number']) && $entity['cdek_number'] !== ''
                ? (string) $entity['cdek_number']
                : null,
            'statuses' => is_array($entity['statuses'] ?? null) ? $entity['statuses'] : [],
            'request_id' => $res['request_id'] ?? null,
            'raw' => $entity,
        ];
    }

    /**
     * GET /v2/orders?im_number=...
     *
     * @return array{ok: bool, uuid?: string|null, cdek_number?: string|null, request_id?: string, error?: string}
     */
    public function findByImNumber(string $imNumber): array
    {
        $imNumber = trim($imNumber);
        if ($imNumber === '') {
            return ['ok' => false, 'error' => 'im_number required'];
        }

        $res = $this->client->get('/orders', ['im_number' => $imNumber]);
        if (!$res['ok']) {
            return [
                'ok' => false,
                'error' => $res['error'] ?? 'find by im_number failed',
                'request_id' => $res['request_id'] ?? null,
            ];
        }

        $entity = is_array($res['data']['entity'] ?? null) ? $res['data']['entity'] : null;
        if ($entity === null && is_array($res['data'] ?? null) && isset($res['data'][0]) && is_array($res['data'][0])) {
            $entity = $res['data'][0];
        }
        if (!is_array($entity) || empty($entity['uuid'])) {
            return ['ok' => false, 'error' => 'not found', 'request_id' => $res['request_id'] ?? null];
        }

        return [
            'ok' => true,
            'uuid' => (string) $entity['uuid'],
            'cdek_number' => isset($entity['cdek_number']) && $entity['cdek_number'] !== ''
                ? (string) $entity['cdek_number']
                : null,
            'request_id' => $res['request_id'] ?? null,
        ];
    }

    private function acquireLock(int $deliveryOrderId): bool
    {
        $key = self::LOCK_PREFIX . $deliveryOrderId;
        $token = bin2hex(random_bytes(8));

        if ($this->redis !== null && method_exists($this->redis, 'set')) {
            try {
                // phpredis: set(key, value, ['nx', 'ex' => ttl])
                $ok = $this->redis->set($key, $token, ['nx', 'ex' => self::LOCK_TTL_SEC]);
                if ($ok) {
                    return true;
                }
                return false;
            } catch (\Throwable) {
                // fallback to file lock
            }
        }

        return $this->fileLock->set($key, $token, ['nx', 'ex' => self::LOCK_TTL_SEC]);
    }

    private function releaseLock(int $deliveryOrderId): void
    {
        $key = self::LOCK_PREFIX . $deliveryOrderId;
        if ($this->redis !== null && method_exists($this->redis, 'del')) {
            try {
                $this->redis->del($key);
            } catch (\Throwable) {
            }
        }

        // File-lock fallback: снимаем файл, иначе TTL 45s блокирует повторные create в том же процессе/тестах.
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zk_lock_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.lock';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static function makeRedis(): ?object
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }
        try {
            $redis = new \Redis();
            $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            if ($redis->connect($host, $port, 0.15)) {
                $password = getenv('REDIS_PASSWORD');
                if (is_string($password) && $password !== '') {
                    $redis->auth($password);
                }
                return $redis;
            }
        } catch (\Throwable) {
        }
        return null;
    }
}
