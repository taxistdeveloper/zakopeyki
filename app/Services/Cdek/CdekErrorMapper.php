<?php

namespace App\Services\Cdek;

/**
 * Нормализация ошибок CDEK → внутренние коды Zakapeiku.
 *
 * Сохраняет оригинальный CDEK code для аудита.
 * Не возвращает stack trace / секреты.
 *
 * Источник кодов: ErrorDto.code из openapi_api_v2_integration.json + известные runtime-коды.
 */
class CdekErrorMapper
{
    public const INTERNAL_UNKNOWN = 'CDEK_UNKNOWN_ERROR';
    public const INTERNAL_HTTP = 'CDEK_HTTP_ERROR';
    public const INTERNAL_NETWORK = 'CDEK_NETWORK_ERROR';
    public const INTERNAL_TIMEOUT = 'CDEK_TIMEOUT';
    public const INTERNAL_AUTH = 'CDEK_AUTH_FAILED';
    public const INTERNAL_AUTHORIZATION = 'CDEK_AUTHORIZATION_ERROR';
    public const INTERNAL_VALIDATION = 'CDEK_VALIDATION_ERROR';
    public const INTERNAL_BUSINESS = 'CDEK_BUSINESS_ERROR';
    public const INTERNAL_DUPLICATE = 'DUPLICATE_CDEK_ORDER';
    public const INTERNAL_RATE_LIMIT = 'CDEK_RATE_LIMIT';
    public const INTERNAL_SERVER = 'CDEK_SERVER_ERROR';
    public const INTERNAL_POINT_NOT_FOUND = 'DELIVERY_POINT_NOT_FOUND';
    public const INTERNAL_CITY_NOT_FOUND = 'CITY_NOT_FOUND';
    public const INTERNAL_TARIFF = 'TARIFF_UNAVAILABLE';
    public const INTERNAL_ORIGIN = 'INVALID_ORIGIN';
    public const INTERNAL_DESTINATION = 'INVALID_DESTINATION';
    public const INTERNAL_PACKAGE = 'INVALID_PACKAGE';

    /** @var array<string, string> */
    private const MAP = [
        'v2_office_by_delivery_point_not_found' => self::INTERNAL_POINT_NOT_FOUND,
        'v2_shipment_location_not_recognized' => self::INTERNAL_ORIGIN,
        'v2_delivery_location_not_recognized' => self::INTERNAL_DESTINATION,
        'v2_city_not_found' => self::INTERNAL_CITY_NOT_FOUND,
        'v2_tariff_code_is_empty' => self::INTERNAL_TARIFF,
        'v2_tariff_not_found' => self::INTERNAL_TARIFF,
        'v2_similar_order_exists' => self::INTERNAL_DUPLICATE,
        'v2_order_number_already_used' => self::INTERNAL_DUPLICATE,
        'v2_im_number_already_used' => self::INTERNAL_DUPLICATE,
        'v2_order_not_found' => 'CDEK_ORDER_NOT_FOUND',
        'v2_package_weight_is_empty' => self::INTERNAL_PACKAGE,
        'invalid_client' => self::INTERNAL_AUTH,
        'access_denied' => self::INTERNAL_AUTHORIZATION,
        'unauthorized' => self::INTERNAL_AUTH,
    ];

    /**
     * @param array<string, mixed>|null $cdekPayload decoded JSON body
     * @return array{
     *   internal_code: string,
     *   cdek_code: string|null,
     *   message: string,
     *   http_status: int|null,
     *   error_type: string,
     *   errors: list<array{code: string|null, message: string}>
     * }
     */
    public function map(?array $cdekPayload, ?int $httpStatus = null, ?string $fallbackMessage = null): array
    {
        $extracted = $this->extractErrors($cdekPayload);
        $first = $extracted[0] ?? null;
        $cdekCode = $first['code'] ?? null;
        $message = $first['message']
            ?? $fallbackMessage
            ?? ($httpStatus !== null ? ('CDEK HTTP ' . $httpStatus) : 'CDEK error');

        $internal = self::INTERNAL_UNKNOWN;
        if ($cdekCode !== null && isset(self::MAP[$cdekCode])) {
            $internal = self::MAP[$cdekCode];
        } elseif ($httpStatus === 401) {
            $internal = self::INTERNAL_AUTH;
        } elseif ($httpStatus === 403) {
            $internal = self::INTERNAL_AUTHORIZATION;
        } elseif ($httpStatus === 429) {
            $internal = self::INTERNAL_RATE_LIMIT;
        } elseif ($httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500) {
            $internal = self::INTERNAL_VALIDATION;
        } elseif ($httpStatus !== null && $httpStatus >= 500) {
            $internal = self::INTERNAL_SERVER;
        }

        // Нечёткий матч по тексту, если code пустой (некоторые ответы CDEK так делают).
        // Не перетираем уже определённые HTTP-классы (401/403/429/5xx).
        $lockedByHttp = in_array($internal, [
            self::INTERNAL_AUTH,
            self::INTERNAL_AUTHORIZATION,
            self::INTERNAL_RATE_LIMIT,
            self::INTERNAL_SERVER,
            self::INTERNAL_HTTP,
        ], true);

        if (!$lockedByHttp && ($cdekCode === null || $cdekCode === '')) {
            $lower = mb_strtolower($message);
            if (str_contains($lower, 'similar') || str_contains($lower, 'already') || str_contains($lower, 'im_number') || str_contains($lower, 'order number')) {
                $internal = self::INTERNAL_DUPLICATE;
            } elseif (str_contains($lower, 'delivery_point') || str_contains($lower, 'office')) {
                $internal = self::INTERNAL_POINT_NOT_FOUND;
            } elseif (str_contains($lower, 'city')) {
                $internal = self::INTERNAL_CITY_NOT_FOUND;
            } elseif ($httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500) {
                $internal = self::INTERNAL_BUSINESS;
            }
        }

        return [
            'internal_code' => $internal,
            'cdek_code' => $cdekCode,
            'message' => $this->sanitizeMessage($message),
            'http_status' => $httpStatus,
            'error_type' => $this->errorType($internal, $httpStatus),
            'errors' => $extracted,
        ];
    }

    /**
     * Map локальной validation-ошибки builder'а (до HTTP).
     *
     * @return array{internal_code: string, cdek_code: null, message: string, http_status: null, error_type: string, errors: list}
     */
    public function mapLocal(string $internalCode, string $message, ?int $httpStatus = null): array
    {
        return [
            'internal_code' => $internalCode,
            'cdek_code' => null,
            'message' => $this->sanitizeMessage($message),
            'http_status' => $httpStatus,
            'error_type' => $this->errorType($internalCode, $httpStatus),
            'errors' => [['code' => null, 'message' => $this->sanitizeMessage($message)]],
        ];
    }

    public function mapNetwork(?string $curlError, bool $isTimeout = false): array
    {
        $code = $isTimeout ? self::INTERNAL_TIMEOUT : self::INTERNAL_NETWORK;
        $message = $isTimeout
            ? 'CDEK request timeout'
            : ('CDEK network error' . ($curlError ? (': ' . $this->sanitizeMessage($curlError)) : ''));
        return $this->mapLocal($code, $message, 0);
    }

    private function errorType(string $internalCode, ?int $httpStatus): string
    {
        return match ($internalCode) {
            self::INTERNAL_NETWORK => 'network',
            self::INTERNAL_TIMEOUT => 'timeout',
            self::INTERNAL_AUTH => 'authentication',
            self::INTERNAL_AUTHORIZATION => 'authorization',
            self::INTERNAL_VALIDATION => 'validation',
            self::INTERNAL_DUPLICATE => 'duplicate_order',
            self::INTERNAL_RATE_LIMIT => 'rate_limit',
            self::INTERNAL_SERVER, self::INTERNAL_HTTP => 'server',
            self::INTERNAL_BUSINESS,
            self::INTERNAL_POINT_NOT_FOUND,
            self::INTERNAL_CITY_NOT_FOUND,
            self::INTERNAL_TARIFF,
            self::INTERNAL_ORIGIN,
            self::INTERNAL_DESTINATION,
            self::INTERNAL_PACKAGE => 'business',
            default => $httpStatus === 429 ? 'rate_limit' : 'unknown',
        };
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<array{code: string|null, message: string}>
     */
    private function extractErrors(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $out = [];

        if (!empty($payload['errors']) && is_array($payload['errors'])) {
            foreach ($payload['errors'] as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $out[] = [
                    'code' => isset($err['code']) ? (string) $err['code'] : null,
                    'message' => (string) ($err['message'] ?? ''),
                ];
            }
        }

        // Обёртка requests[].errors[] в ответах entity API.
        if (!empty($payload['requests']) && is_array($payload['requests'])) {
            foreach ($payload['requests'] as $req) {
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
        }

        if ($out === [] && !empty($payload['error'])) {
            $out[] = [
                'code' => is_string($payload['error']) ? (string) $payload['error'] : null,
                'message' => (string) ($payload['error_description'] ?? $payload['message'] ?? $payload['error']),
            ];
        }

        if ($out === [] && !empty($payload['message'])) {
            $out[] = [
                'code' => null,
                'message' => (string) $payload['message'],
            ];
        }

        return $out;
    }

    private function sanitizeMessage(string $message): string
    {
        $message = trim($message);
        // На всякий случай вырезаем bearer-подобные хвосты, если CDEK/прокси их отразил.
        $message = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/=]+/i', 'Bearer [redacted]', $message) ?? $message;
        return mb_substr($message, 0, 500);
    }
}
