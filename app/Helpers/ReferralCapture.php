<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Единая точка захвата реферального кода (?ref=) в сессию.
 * Используется регистрацией (AuthController) и публичными страницами акций, чтобы
 * не создавать вторую реализацию реферальной логики. Начисление вознаграждений
 * по-прежнему выполняет только AuthController::applyReferralBonus → Bonus::awardReferral.
 */
final class ReferralCapture
{
    public const SESSION_KEY = 'referral_ref';

    public static function normalize(mixed $raw): ?string
    {
        $ref = trim((string) $raw);
        return ($ref !== '' && preg_match('/^[a-zA-Z0-9_]{1,50}$/', $ref)) ? $ref : null;
    }

    /** Сохранить код в сессию (последний переход перезаписывает предыдущий — как и раньше). */
    public static function capture(mixed $raw): ?string
    {
        $ref = self::normalize($raw);
        if ($ref !== null) {
            $_SESSION[self::SESSION_KEY] = $ref;
        }
        return $ref;
    }

    public static function captureFromRequest(): ?string
    {
        return self::capture($_POST['ref'] ?? $_GET['ref'] ?? '');
    }
}
