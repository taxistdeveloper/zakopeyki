<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Models\ContestEntry;
use App\Models\ContestTracking;

/**
 * Воронка эффективности акции без двойного учёта: каждый этап — отдельный счётчик со своим
 * ключом уникальности. «Подтверждённая регистрация» и «квалифицированный реферал» в смысле
 * реферальной программы НЕ вычисляются модулем (правила программы не утверждены, D-1).
 */
final class ContestAnalyticsService
{
    public function __construct(
        private ?ContestTracking $tracking = null,
        private ?ContestEntry $entries = null,
    ) {
        $this->tracking ??= new ContestTracking();
        $this->entries ??= new ContestEntry();
    }

    public function funnel(array $contest): array
    {
        $id = (int) $contest['id'];
        $ref = $this->tracking->refTotals($id);
        $counts = $this->entries->statusCounts($id);
        $from = (string) ($contest['published_at'] ?? $contest['starts_at']);
        $to = (string) ($contest['closed_at'] ?? date('Y-m-d H:i:s'));

        return [
            'page_views' => (int) $contest['views_count'],
            'unique_visitors' => $this->tracking->uniqueViews($id),
            'ref_visits' => $ref['visits'],
            'ref_registrations' => $ref['registrations'],
            'referred_participants' => $this->tracking->referredParticipants($id),
            'entries_total' => array_sum($counts),
            'entries' => $counts,
            'new_followers' => $this->tracking->countEvents(ContestTracking::EVENT_FOLLOW, 'user', (int) $contest['organizer_id'], $from, $to),
            'unfollows' => $this->tracking->countEvents(ContestTracking::EVENT_UNFOLLOW, 'user', (int) $contest['organizer_id'], $from, $to),
            'top_referrers' => $this->tracking->topReferrers($id),
        ];
    }
}
