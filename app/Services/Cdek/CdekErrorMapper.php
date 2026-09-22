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
    public const INTERNAL_AUTH = 'CDEK_AUTH_FAILED';
    public const INTERNAL_VALIDATION = 'CDEK_VALIDATION_ERROR';
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
        'v2_similar_order_exists' => 'DUPLICATE_CDEK_ORDER',
        'v2_order_not_found' => 'CDEK_ORDER_NOT_FOUND',
        'v2_package_weight_is_empty' => self::INTERNAL_PACKAGE,
        'invalid_client' => self::INTERNAL_AUTH,
        'access_denied' => self::INTERNAL_AUTH,
    ];

    /**
     * @param array<string, mixed>|null $cdekPayload decoded JSON body
     * @return array{
     *   internal_code: string,
     *   cdek_code: string|null,
     *   message: string,
     *   http_status: int|null,
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
        } elseif ($httpStatus === 401 || $httpStatus === 403) {
            $internal = self::INTERNAL_AUTH;
        } elseif ($httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500) {
            $internal = self::INTERNAL_VALIDATION;
        } elseif ($httpStatus !== null && $httpStatus >= 500) {
            $internal = self::INTERNAL_HTTP;
        }

        // Нечёткий матч по тексту, если code пустой (некоторые ответы CDEK так делают).
        if ($cdekCode === null || $cdekCode === '') {
            $lower = mb_strtolower($message);
            if (str_contains($lower, 'delivery_point') || str_contains($lower, 'office')) {
                $internal = self::INTERNAL_POINT_NOT_FOUND;
            } elseif (str_contains($lower, 'city')) {
                $internal = self::INTERNAL_CITY_NOT_FOUND;
            }
        }

        return [
            'internal_code' => $internal,
            'cdek_code' => $cdekCode,
            'message' => $this->sanitizeMessage($message),
            'http_status' => $httpStatus,
            'errors' => $extracted,
        ];
    }

    /**
     * Map локальной validation-ошибки builder'а (до HTTP).
     *
     * @return array{internal_code: string, cdek_code: null, message: string, http_status: null, errors: list}
     */
    public function mapLocal(string $internalCode, string $message): array
    {
        return [
            'internal_code' => $internalCode,
            'cdek_code' => null,
            'message' => $this->sanitizeMessage($message),
            'http_status' => null,
            'errors' => [['code' => null, 'message' => $this->sanitizeMessage($message)]],
        ];
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
