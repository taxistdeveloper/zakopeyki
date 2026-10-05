<?php

namespace App\Services\Cdek;

/**
 * Нормализованный ответ CDEK API для бизнес-слоя.
 *
 * HTTP 202 ACCEPTED ≠ окончательный SUCCESS:
 * internal_status = accepted|processing означает «запрос принят, результат ещё неизвестен».
 *
 * @see openapi_api_v2_integration.json RequestDto.state, ResponseDto*
 */
final class CdekApiResponse
{
    public const STATUS_SUCCESSFUL = 'successful';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_ERROR = 'error';

    /**
     * @param list<array{code: string|null, message: string}> $errors
     * @param list<array{code?: string|null, message?: string}> $warnings
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>|null $errorMapped
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $httpStatus,
        public readonly string $internalStatus,
        public readonly string $requestId,
        public readonly int $durationMs,
        public readonly ?string $cdekUuid = null,
        public readonly ?string $cdekNumber = null,
        public readonly ?string $requestUuid = null,
        public readonly ?string $requestState = null,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?array $data = null,
        public readonly ?array $errorMapped = null,
        public readonly ?string $message = null,
    ) {
    }

    public function isFinalSuccess(): bool
    {
        return $this->ok && $this->internalStatus === self::STATUS_SUCCESSFUL;
    }

    public function isAcceptedPending(): bool
    {
        return $this->ok && in_array($this->internalStatus, [self::STATUS_ACCEPTED, self::STATUS_PROCESSING], true);
    }

    public function isDuplicateOrder(): bool
    {
        $code = (string) ($this->errorMapped['internal_code'] ?? '');
        if ($code === CdekErrorMapper::INTERNAL_DUPLICATE) {
            return true;
        }
        foreach ($this->errors as $err) {
            if (($err['code'] ?? '') === 'v2_similar_order_exists') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'code' => $this->httpStatus,
            'http_status' => $this->httpStatus,
            'internal_status' => $this->internalStatus,
            'cdek_uuid' => $this->cdekUuid,
            'cdek_number' => $this->cdekNumber,
            'request_uuid' => $this->requestUuid,
            'request_state' => $this->requestState,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'error' => $this->message,
            'error_mapped' => $this->errorMapped,
            'data' => $this->data,
            'request_id' => $this->requestId,
            'duration_ms' => $this->durationMs,
            'async' => $this->isAcceptedPending(),
        ];
    }

    /**
     * @param array{
     *   ok: bool,
     *   code: int,
     *   data?: mixed,
     *   error?: string,
     *   error_mapped?: array,
     *   request_id?: string,
     *   duration_ms?: int,
     *   body?: string
     * } $raw Client::request() result
     */
    public static function fromClientResult(array $raw, ?CdekErrorMapper $mapper = null): self
    {
        $mapper = $mapper ?? new CdekErrorMapper();
        $http = (int) ($raw['code'] ?? 0);
        $requestId = (string) ($raw['request_id'] ?? '');
        $duration = (int) ($raw['duration_ms'] ?? 0);
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : null;

        if (!($raw['ok'] ?? false)) {
            $mapped = is_array($raw['error_mapped'] ?? null)
                ? $raw['error_mapped']
                : $mapper->map($data, $http > 0 ? $http : null, (string) ($raw['error'] ?? 'CDEK error'));

            return new self(
                ok: false,
                httpStatus: $http,
                internalStatus: self::STATUS_ERROR,
                requestId: $requestId,
                durationMs: $duration,
                cdekUuid: self::extractUuid($data),
                cdekNumber: self::extractCdekNumber($data),
                requestUuid: self::extractRequestUuid($data),
                requestState: self::extractRequestState($data),
                errors: $mapped['errors'] ?? [],
                warnings: self::extractWarnings($data),
                data: $data,
                errorMapped: $mapped,
                message: (string) ($mapped['message'] ?? $raw['error'] ?? 'CDEK error'),
            );
        }

        $requestState = self::extractRequestState($data);
        $uuid = self::extractUuid($data);
        $number = self::extractCdekNumber($data);
        $reqUuid = self::extractRequestUuid($data);
        $warnings = self::extractWarnings($data);
        $errors = self::extractRequestErrors($data);

        // Async accept: HTTP 202 or request.state ACCEPTED/WAITING.
        if ($http === 202 || in_array(strtoupper((string) $requestState), ['ACCEPTED', 'WAITING'], true)) {
            // If CDEK already reports INVALID in requests[] — treat as invalid even on 202.
            if (strtoupper((string) $requestState) === 'INVALID' || $errors !== []) {
                $mapped = $mapper->map($data, $http, 'CDEK request invalid');
                return new self(
                    ok: false,
                    httpStatus: $http,
                    internalStatus: self::STATUS_INVALID,
                    requestId: $requestId,
                    durationMs: $duration,
                    cdekUuid: $uuid,
                    cdekNumber: $number,
                    requestUuid: $reqUuid,
                    requestState: $requestState,
                    errors: $mapped['errors'] ?: $errors,
                    warnings: $warnings,
                    data: $data,
                    errorMapped: $mapped,
                    message: $mapped['message'],
                );
            }

            $status = strtoupper((string) $requestState) === 'WAITING'
                ? self::STATUS_PROCESSING
                : self::STATUS_ACCEPTED;

            return new self(
                ok: true,
                httpStatus: $http,
                internalStatus: $status,
                requestId: $requestId,
                durationMs: $duration,
                cdekUuid: $uuid,
                cdekNumber: $number,
                requestUuid: $reqUuid,
                requestState: $requestState,
                errors: [],
                warnings: $warnings,
                data: $data,
                errorMapped: null,
                message: null,
            );
        }

        if (strtoupper((string) $requestState) === 'INVALID' || ($http >= 200 && $http < 300 && $errors !== [])) {
            $mapped = $mapper->map($data, $http, 'CDEK request invalid');
            return new self(
                ok: false,
                httpStatus: $http,
                internalStatus: self::STATUS_INVALID,
                requestId: $requestId,
                durationMs: $duration,
                cdekUuid: $uuid,
                cdekNumber: $number,
                requestUuid: $reqUuid,
                requestState: $requestState,
                errors: $mapped['errors'] ?: $errors,
                warnings: $warnings,
                data: $data,
                errorMapped: $mapped,
                message: $mapped['message'],
            );
        }

        return new self(
            ok: true,
            httpStatus: $http,
            internalStatus: self::STATUS_SUCCESSFUL,
            requestId: $requestId,
            durationMs: $duration,
            cdekUuid: $uuid,
            cdekNumber: $number,
            requestUuid: $reqUuid,
            requestState: $requestState,
            errors: [],
            warnings: $warnings,
            data: $data,
            errorMapped: null,
            message: null,
        );
    }

    /** @param array<string, mixed>|null $data */
    private static function extractUuid(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : $data;
        $uuid = $entity['uuid'] ?? null;
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** @param array<string, mixed>|null $data */
    private static function extractCdekNumber(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        $entity = is_array($data['entity'] ?? null) ? $data['entity'] : $data;
        $num = $entity['cdek_number'] ?? null;
        if ($num === null || $num === '') {
            return null;
        }
        return (string) $num;
    }

    /** @param array<string, mixed>|null $data */
    private static function extractRequestUuid(?array $data): ?string
    {
        if ($data === null || empty($data['requests']) || !is_array($data['requests'])) {
            return null;
        }
        $first = $data['requests'][0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        $uuid = $first['request_uuid'] ?? null;
        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** @param array<string, mixed>|null $data */
    private static function extractRequestState(?array $data): ?string
    {
        if ($data === null || empty($data['requests']) || !is_array($data['requests'])) {
            return null;
        }
        $first = $data['requests'][0] ?? null;
        if (!is_array($first)) {
            return null;
        }
        $state = $first['state'] ?? null;
        return is_string($state) && $state !== '' ? $state : null;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return list<array{code: string|null, message: string}>
     */
    private static function extractRequestErrors(?array $data): array
    {
        if ($data === null || empty($data['requests']) || !is_array($data['requests'])) {
            return [];
        }
        $out = [];
        foreach ($data['requests'] as $req) {
            if (!is_array($req) || empty($req['errors']) || !is_array($req['errors'])) {
                continue;
            }
            foreach ($req['errors'] as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $out[] = [
                    'code' => isset($err['code']) ? (string) $err['code'] : null,
                    'message' => (string) ($err['message'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return list<array{code?: string|null, message?: string}>
     */
    private static function extractWarnings(?array $data): array
    {
        if ($data === null || empty($data['warnings']) || !is_array($data['warnings'])) {
            return [];
        }
        $out = [];
        foreach ($data['warnings'] as $w) {
            if (!is_array($w)) {
                continue;
            }
            $out[] = [
                'code' => isset($w['code']) ? (string) $w['code'] : null,
                'message' => (string) ($w['message'] ?? ''),
            ];
        }
        return $out;
    }
}
