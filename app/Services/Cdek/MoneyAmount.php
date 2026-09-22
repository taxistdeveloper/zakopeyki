<?php

namespace App\Services\Cdek;

/**
 * Безопасное представление денежных сумм CDEK → внутренний INT (тенге).
 *
 * Почему так:
 * - JSON CDEK отдаёт delivery_sum как number/float — точность уже частично потеряна на wire.
 * - Мы немедленно переводим значение в строку с фиксированной шкалой и дальше считаем
 *   только через строковую арифметику / целые, без цепочки float → calculation → DB.
 * - В delivery_quotes суммы хранятся INT (целые тенге) — округление half-up до целого.
 */
final class MoneyAmount
{
    /**
     * Преобразует значение CDEK (int|float|string|numeric-string) в неотрицательный INT тенге.
     *
     * @throws \InvalidArgumentException при нечисловом вводе
     */
    public static function toTengeInt(mixed $value): int
    {
        $normalized = self::toDecimalString($value, 2);
        return self::roundHalfUpToInt($normalized);
    }

    /**
     * Сохраняет исходное CDEK-значение как decimal-строку для аудита (без float-операций).
     */
    public static function toAuditString(mixed $value): string
    {
        return self::toDecimalString($value, 4);
    }

    /**
     * Складывает целые суммы (тенге) без float.
     *
     * @param list<int> $parts
     */
    public static function sumInt(array $parts): int
    {
        $total = 0;
        foreach ($parts as $part) {
            $total += (int) $part;
        }
        return max(0, $total);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function toDecimalString(mixed $value, int $scale): string
    {
        if ($value === null || $value === '') {
            return '0.' . str_repeat('0', $scale);
        }

        if (is_int($value)) {
            return (string) $value . '.' . str_repeat('0', $scale);
        }

        if (is_float($value)) {
            // Единственная точка касания с float: сериализация уже декодированного JSON number.
            // Фиксируем шкалу через number_format (не через sprintf %F — locale-зависимо).
            return number_format($value, $scale, '.', '');
        }

        if (is_string($value)) {
            $trimmed = trim(str_replace(',', '.', $value));
            if ($trimmed === '' || !preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
                throw new \InvalidArgumentException('Invalid money amount: ' . $value);
            }
            if (!str_contains($trimmed, '.')) {
                return $trimmed . '.' . str_repeat('0', $scale);
            }
            [$intPart, $frac] = explode('.', $trimmed, 2);
            $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);
            $sign = str_starts_with($intPart, '-') ? '-' : '';
            $intPart = ltrim($intPart, '+-');
            $intPart = $intPart === '' ? '0' : $intPart;
            return $sign . $intPart . '.' . $frac;
        }

        throw new \InvalidArgumentException('Unsupported money type: ' . get_debug_type($value));
    }

    /**
     * Half-up округление decimal-строки до целого.
     */
    private static function roundHalfUpToInt(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');
        if (!str_contains($decimal, '.')) {
            $n = (int) $decimal;
            return $negative ? -$n : $n;
        }

        [$intPart, $frac] = explode('.', $decimal, 2);
        $intPart = $intPart === '' ? '0' : $intPart;
        $firstFrac = (int) ($frac[0] ?? '0');
        $n = (int) $intPart;
        if ($firstFrac >= 5) {
            $n++;
        }
        $n = max(0, $n);
        return $negative ? -$n : $n;
    }
}
