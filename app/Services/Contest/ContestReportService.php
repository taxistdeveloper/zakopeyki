<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Helpers\ActivityLogger;
use App\Models\Contest;
use App\Models\ContestReport;
use App\Services\Security\AuthThrottle;

/**
 * Жалобы на акции: подача пользователями и разбор модераторами.
 */
final class ContestReportService
{
    public function __construct(
        private ?ContestReport $reports = null,
        private ?ContestNotifier $notifier = null,
    ) {
        $this->reports ??= new ContestReport();
        $this->notifier ??= new ContestNotifier();
    }

    /** @return array{ok:bool, error?:string} */
    public function submit(array $contest, array $user, string $reason, string $details): array
    {
        if (!in_array($reason, ContestPolicy::REPORT_REASONS, true)) {
            return ['ok' => false, 'error' => 'reason'];
        }
        if ((int) $user['id'] === (int) $contest['organizer_id']) {
            return ['ok' => false, 'error' => 'own_contest'];
        }
        if (!ContestPolicy::isPublicStatus((string) $contest['status'])) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($this->reports->hasOpen((int) $contest['id'], (int) $user['id'])) {
            return ['ok' => false, 'error' => 'duplicate'];
        }
        if (!(new AuthThrottle())->hit('contest_report', 'u' . (int) $user['id'])['allowed']) {
            return ['ok' => false, 'error' => 'throttled'];
        }
        $details = mb_substr(trim(str_replace("\0", '', $details)), 0, 1000);
        $id = $this->reports->create((int) $contest['id'], (int) $user['id'], $reason, $details);
        ActivityLogger::warning('contest.report', 'Жалоба на акцию', 'contest', (int) $contest['id'], [
            'report_id' => $id,
            'reason' => $reason,
        ]);
        $this->notifier->notifyModerators('report_received', $contest, ['reason' => t('contests.report_reason.' . $reason)]);
        return ['ok' => true];
    }

    /** @return array{ok:bool, error?:string} */
    public function resolve(int $reportId, string $status, string $note, array $actor): array
    {
        $report = $this->reports->find($reportId);
        if ($report === null) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $contest = (new Contest())->find((int) $report['contest_id']);
        if (!$contest || !in_array('moderator', ContestService::actorRoles($contest, $actor), true)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        if (mb_strlen(trim($note)) < 3) {
            return ['ok' => false, 'error' => 'note_required'];
        }
        if (!$this->reports->resolve($reportId, $status, trim($note), (int) $actor['id'])) {
            return ['ok' => false, 'error' => 'invalid_state'];
        }
        ActivityLogger::info('contest.report_resolve', 'Жалоба рассмотрена', 'contest', (int) $contest['id'], [
            'report_id' => $reportId,
            'status' => $status,
        ]);
        $this->notifier->notify((int) ($report['reporter_id'] ?? 0), 'report_resolved', $contest, ['note' => trim($note)]);
        return ['ok' => true];
    }
}
