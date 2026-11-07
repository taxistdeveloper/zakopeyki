<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Helpers\ActivityLogger;
use App\Models\ContestTracking;

/**
 * Адаптер событий платформы → журнал модуля (contest_platform_events).
 *
 * Пишет событие только если у цели (организатора) есть запланированные/активные акции —
 * глобальная архитектура и объём данных не меняются. Сбой записи не ломает основное действие.
 */
final class ContestEventRecorder
{
    public static function followChanged(int $followerId, int $followingId, bool $followed): void
    {
        try {
            $tracking = new ContestTracking();
            if (!$tracking->organizerHasLiveContests($followingId)) {
                return;
            }
            $tracking->recordEvent(
                $followed ? ContestTracking::EVENT_FOLLOW : ContestTracking::EVENT_UNFOLLOW,
                $followerId,
                'user',
                $followingId
            );
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'contest.event_record_failed');
        }
    }
}
