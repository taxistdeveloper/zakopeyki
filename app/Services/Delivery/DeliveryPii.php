<?php

namespace App\Services\Delivery;

/**
 * Маскирование PII для логов/событий доставки.
 * Полные ФИО/телефоны/адреса не должны попадать в application logs.
 */
final class DeliveryPii
{
    public static function maskName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $len = mb_strlen($part);
            if ($len <= 1) {
                $out[] = '*';
                continue;
            }
            $out[] = mb_substr($part, 0, 1) . str_repeat('*', min(6, $len - 1));
        }
        return implode(' ', $out);
    }

    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        $len = strlen($digits);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', max(0, $len - 4)) . substr($digits, -4);
    }

    public static function maskEmail(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email === '' ? '' : '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $localMask = mb_substr($local, 0, 1) . '***';
        return $localMask . '@' . $domain;
    }

    public static function maskAddress(?string $address): string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return '';
        }
        return '[address_redacted len=' . mb_strlen($address) . ']';
    }

    /**
     * Убирает типичные PII-ключи из payload перед записью в delivery_events / logs.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function redactPayload(array $payload): array
    {
        $sensitive = [
            'name', 'phone', 'email', 'street', 'building', 'apartment',
            'address', 'notes', 'ship_contact_name', 'ship_phone',
            'ship_street', 'ship_building', 'ship_apartment',
        ];
        $out = [];
        foreach ($payload as $key => $value) {
            $k = strtolower((string) $key);
            if (in_array($k, $sensitive, true)) {
                if ($k === 'phone' || $k === 'ship_phone') {
                    $out[$key] = self::maskPhone(is_scalar($value) ? (string) $value : null);
                } elseif ($k === 'email') {
                    $out[$key] = self::maskEmail(is_scalar($value) ? (string) $value : null);
                } elseif ($k === 'name' || $k === 'ship_contact_name') {
                    $out[$key] = self::maskName(is_scalar($value) ? (string) $value : null);
                } else {
                    $out[$key] = self::maskAddress(is_scalar($value) ? (string) $value : null);
                }
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::redactPayload($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
