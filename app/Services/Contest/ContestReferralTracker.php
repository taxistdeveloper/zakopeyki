<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Helpers\ActivityLogger;
use App\Helpers\ReferralCapture;
use App\Models\ContestTracking;
use App\Models\SiteVisit;
use App\Models\User;

/**
 * Атрибуция переходов по персональным ссылкам участников на акцию.
 *
 * Разделение механизмов (MASTERING-01, раздел 10):
 *  1. Распространение ссылки — фиксируем переход (contest_ref_visits).
 *  2. Привлечение нового пользователя — связываем регистрацию с переходом (один раз).
 *  3. Участие в акции — отдельная сущность (contest_entries), на награды не влияет.
 *  4–5. Квалификация и начисление реферального вознаграждения — ТОЛЬКО существующая система
 *       (AuthController::applyReferralBonus → Bonus::awardReferral). Модуль ничего не начисляет,
 *       а статусы вознаграждений лишь читает из bonus_transactions.
 *  Дополнительных шансов за приглашения нет.
 */
final class ContestReferralTracker
{
    public const SESSION_KEY = 'contest_ref';

    /** Обработать ?ref= на публичной странице акции. */
    public static function captureOnContestPage(array $contest): void
    {
        $code = ReferralCapture::captureFromRequest();
        if ($code === null) {
            return;
        }
        try {
            $refUser = (new User())->findByReferralCode($code);
            if (!$refUser || !empty($refUser['blocked_at'])) {
                return;
            }
            $refUserId = (int) $refUser['id'];
            $current = \App\Core\Auth::id();
            if ($current !== null && (int) $current === $refUserId) {
                return;
            }
            $visitorKey = SiteVisit::currentVisitorKey();
            $visitId = (new ContestTracking())->recordRefVisit((int) $contest['id'], $refUserId, $visitorKey);
            if ($visitId > 0) {
                $_SESSION[self::SESSION_KEY] = [
                    'contest_id' => (int) $contest['id'],
                    'ref_user_id' => $refUserId,
                    'visit_id' => $visitId,
                ];
            }
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'contest.ref_capture_failed');
        }
    }

    /** Вызывается сразу после создания нового аккаунта (email или Google). */
    public static function onRegistered(int $newUserId): void
    {
        $ref = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($ref) || $newUserId <= 0) {
            return;
        }
        try {
            if ((new ContestTracking())->linkRegistration((int) ($ref['visit_id'] ?? 0), $newUserId)) {
                ActivityLogger::info('contest.ref_registration', 'Регистрация по ссылке акции', 'contest', (int) $ref['contest_id'], [
                    'ref_user_id' => (int) $ref['ref_user_id'],
                    'new_user_id' => $newUserId,
                ]);
            }
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'contest.ref_link_failed');
        }
    }

    /** ID пригласившего для текущей сессии и данной акции (для записи в участие). */
    public static function refUserFor(int $contestId): int
    {
        $ref = $_SESSION[self::SESSION_KEY] ?? null;
        return (is_array($ref) && (int) ($ref['contest_id'] ?? 0) === $contestId) ? (int) ($ref['ref_user_id'] ?? 0) : 0;
    }

    /** Персональная ссылка участника на акцию (код = существующий реферальный код пользователя). */
    public static function personalPath(array $contest, array $user): string
    {
        $code = (new User())->referralCodeFor($user);
        return '/contests/' . rawurlencode((string) $contest['slug']) . '?ref=' . rawurlencode($code);
    }
}
