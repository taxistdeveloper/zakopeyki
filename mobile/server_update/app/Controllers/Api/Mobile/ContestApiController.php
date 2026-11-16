<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\ContestReport;
use App\Models\ContestResult;
use App\Models\Follow;
use App\Models\User;
use App\Services\Contest\ContestConditionVerifier;
use App\Services\Contest\ContestEntryService;
use App\Services\Contest\ContestPolicy;
use App\Services\Contest\ContestReferralTracker;
use App\Services\Contest\ContestReportService;
use App\Services\Contest\ContestResultService;
use App\Services\Contest\ContestService;
use App\Services\MobileApi\Presenter;

/**
 * Конкурсы и розыгрыши в приложении — сторона участника (то же, что ContestController сайта):
 * каталог, карточка с правилами и условиями, участие, выход, жалоба, подтверждение / спор по призу, «мои конкурсы».
 */
final class ContestApiController extends BaseMobileController
{
    /** GET /contests?status=active|upcoming|finished&q=&page= */
    public function index(): void
    {
        $this->guardSiteOpen();
        $policy = new ContestPolicy();
        if (!$policy->publicVisible()) {
            $this->ok(['enabled' => false, 'items' => [], 'total' => 0, 'has_more' => false]);
        }
        $status = $this->queryString('status', 12);
        $page = $this->queryInt('page', 1, 1, 1000);
        $res = (new Contest())->catalog([
            'status' => in_array($status, ['active', 'upcoming', 'finished'], true) ? $status : '',
            'q' => $this->queryString('q', 80),
        ], $page, 20);
        $this->ok([
            'enabled' => true,
            'items' => array_map(fn (array $c): array => $this->card($c), $res['items']),
            'total' => (int) $res['total'],
            'has_more' => $page * 20 < (int) $res['total'],
        ]);
    }

    /** GET /contests/{slug} */
    public function show(string $slug): void
    {
        $this->guardSiteOpen();
        $uid = $this->optionalUserId();
        $user = $uid !== null ? (new User())->find($uid) : null;
        $contest = $this->publicContest($slug);
        $model = new Contest();
        $policy = new ContestPolicy();
        $rules = $model->currentRules($contest);
        $entries = new ContestEntry();
        $entry = $user ? $entries->findForUser((int) $contest['id'], $uid) : null;
        $results = new ContestResult();
        $winners = $results->forContest((int) $contest['id']) ? $results->winners((int) $contest['id']) : [];
        $verifier = new ContestConditionVerifier($policy);
        $conditions = $rules ? $verifier->effectiveConditions((array) $rules['conditions']) : [];

        $preview = [];
        if ($user && !$entry && $rules && $contest['status'] === ContestPolicy::S_ACTIVE) {
            $preview = $verifier->checkAtJoin($contest, (array) $rules['conditions'], $user, 'preview');
        }
        $needsSubmission = false;
        $condOut = [];
        foreach ($conditions as $c) {
            $key = (string) $c['key'];
            if ($key === ContestPolicy::COND_SUBMISSION) {
                $needsSubmission = true;
            }
            $condOut[] = [
                'key' => $key,
                'label' => t('contests.cond.' . $key, ['days' => (string) ($c['days'] ?? '')]),
                'met' => $key === ContestPolicy::COND_SUBMISSION ? null
                    : (isset($preview[$key]) ? ($preview[$key]['status'] ?? '') === 'met' : null),
            ];
        }

        $card = $this->card($contest);
        $this->ok(['contest' => $card + [
            'description' => (string) ($contest['description'] ?? ''),
            'rules_text' => (string) ($rules['rules_text'] ?? ''),
            'criteria_text' => ($rules['criteria_text'] ?? '') !== '' ? (string) $rules['criteria_text'] : null,
            'mechanic_hint' => t('contests.mechanic_hint.' . $contest['mechanic'], [], ''),
            'conditions' => $condOut,
            'requires_submission' => $needsSubmission,
            'prizes' => array_map(static fn (array $p): array => [
                'place' => (int) ($p['place'] ?? 0),
                'title' => (string) ($p['title'] ?? ''),
            ], (array) ($rules['prizes'] ?? [])),
            'can_join' => $contest['status'] === ContestPolicy::S_ACTIVE && $entry === null
                && $policy->mechanicEnabled((string) $contest['mechanic']),
            'my_entry' => $entry ? [
                'status' => (string) $entry['status'],
                'status_label' => t('contests.entry_status.' . $entry['status'], [], (string) $entry['status']),
                'submission' => ($entry['submission_text'] ?? '') !== '' ? (string) $entry['submission_text'] : null,
                'can_withdraw' => $contest['status'] === ContestPolicy::S_ACTIVE,
            ] : null,
            'winners' => array_map(static fn (array $w): array => [
                'id' => (int) $w['id'],
                'place' => (int) $w['place'],
                'name' => (string) ($w['user_name'] ?? ''),
                'prize' => ($w['prize_title'] ?? '') !== '' ? (string) $w['prize_title'] : null,
                'is_me' => $uid !== null && (int) $w['user_id'] === $uid,
                'fulfilment' => (string) ($w['fulfilment_status'] ?? 'pending'),
                'fulfilment_label' => t('contests.fulfilment.' . ($w['fulfilment_status'] ?? 'pending'), [], ''),
            ], $winners),
            'is_following_organizer' => $uid !== null && (new Follow())->isFollowing($uid, (int) $contest['organizer_id']),
            'public_url' => ProductHelper::absoluteUrl('/contests/' . rawurlencode((string) $contest['slug'])),
            'personal_url' => ($user && $entry) ? ProductHelper::absoluteUrl(ContestReferralTracker::personalPath($contest, $user)) : null,
            'has_open_report' => $uid !== null && (new ContestReport())->hasOpen((int) $contest['id'], $uid),
            'report_reasons' => array_map(
                static fn (string $r): array => ['code' => $r, 'label' => t('contests.report_reason.' . $r, [], $r)],
                ContestPolicy::REPORT_REASONS
            ),
        ]]);
    }

    /** POST /contests/{slug}/join {submission?} */
    public function join(string $slug): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $contest = $this->publicContest($slug);
        $user = (new User())->find($uid) ?? [];
        $submission = $this->body()['submission'] ?? null;
        $res = (new ContestEntryService())->join(
            $contest,
            $user,
            is_string($submission) ? $submission : null,
            ContestEntryService::requestContext() + ['ref_user_id' => null]
        );
        $this->result($res, t('contests.flash.joined'));
    }

    /** POST /contests/{slug}/withdraw */
    public function withdraw(string $slug): void
    {
        $uid = $this->requireUserId();
        $contest = $this->publicContest($slug);
        $res = (new ContestEntryService())->withdraw($contest, (new User())->find($uid) ?? []);
        $this->result($res, t('contests.flash.withdrawn'));
    }

    /** POST /contests/{slug}/report {reason, details} */
    public function report(string $slug): void
    {
        $uid = $this->requireUserId();
        $contest = $this->publicContest($slug);
        $res = (new ContestReportService())->submit($contest, (new User())->find($uid) ?? [], $this->input('reason'), mb_substr($this->input('details'), 0, 2000));
        $this->result($res, t('contests.flash.reported'));
    }

    /** POST /contests/{slug}/prize/{winnerId}/confirm */
    public function confirmPrize(string $slug, string $winnerId): void
    {
        $uid = $this->requireUserId();
        $contest = $this->publicContest($slug);
        $res = (new ContestResultService())->confirmReceived($contest, (int) $winnerId, (new User())->find($uid) ?? []);
        $this->result($res, t('contests.flash.prize_confirmed'));
    }

    /** POST /contests/{slug}/prize/{winnerId}/dispute {note} */
    public function disputePrize(string $slug, string $winnerId): void
    {
        $uid = $this->requireUserId();
        $contest = $this->publicContest($slug);
        $res = (new ContestResultService())->disputePrize($contest, (int) $winnerId, mb_substr($this->input('note'), 0, 1000), (new User())->find($uid) ?? []);
        $this->result($res, t('contests.flash.dispute_opened'));
    }

    /** GET /me/contests — участие, выигрыши, свои акции */
    public function mine(): void
    {
        $uid = $this->requireUserId();
        $this->ok([
            'participations' => array_map(static fn (array $e): array => [
                'slug' => (string) $e['slug'],
                'title' => (string) $e['title'],
                'contest_status' => (string) $e['contest_status'],
                'contest_status_label' => t('contests.status.' . $e['contest_status'], [], (string) $e['contest_status']),
                'entry_status_label' => t('contests.entry_status.' . $e['status'], [], (string) $e['status']),
                'ends_at' => Presenter::date($e['ends_at'] ?? null),
            ], (new ContestEntry())->byUser($uid)),
            'wins' => array_map(static fn (array $w): array => [
                'id' => (int) $w['id'],
                'slug' => (string) $w['slug'],
                'title' => (string) $w['title'],
                'place' => (int) $w['place'],
                'prize' => ($w['prize_title'] ?? '') !== '' ? (string) $w['prize_title'] : null,
                'fulfilment' => (string) ($w['fulfilment_status'] ?? 'pending'),
                'fulfilment_label' => t('contests.fulfilment.' . ($w['fulfilment_status'] ?? 'pending'), [], ''),
            ], (new ContestResult())->byUser($uid)),
            'organized' => array_map(fn (array $c): array => $this->card($c), (new Contest())->byOrganizer($uid)),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function publicContest(string $slug): array
    {
        $model = new Contest();
        $policy = new ContestPolicy();
        $contest = $model->findBySlug($slug);
        if (!$contest || !ContestPolicy::isPublicStatus((string) $contest['status']) || !$policy->publicVisible()) {
            $this->fail('not_found', 404, t('contests.not_found'));
        }

        return (new ContestService($model, $policy))->syncTime($contest) + $contest;
    }

    /** @param array<string, mixed> $c */
    private function card(array $c): array
    {
        $organizer = isset($c['organizer_name'])
            ? ['name' => (string) (($c['organizer_business_name'] ?? '') !== '' ? $c['organizer_business_name'] : $c['organizer_name'])]
            : ['name' => (string) ((new User())->find((int) $c['organizer_id'])['name'] ?? '')];
        $status = (string) $c['status'];

        return [
            'id' => (int) $c['id'],
            'slug' => (string) $c['slug'],
            'title' => (string) $c['title'],
            'summary' => ($c['summary'] ?? '') !== '' ? (string) $c['summary'] : null,
            'prize_summary' => ($c['prize_summary'] ?? '') !== '' ? (string) $c['prize_summary'] : null,
            'cover_url' => Presenter::media(ContestView::coverUrl($c)),
            'status' => $status,
            'status_label' => t('contests.status.' . $status, [], $status),
            'mechanic' => (string) $c['mechanic'],
            'mechanic_label' => t('contests.mechanic.' . $c['mechanic'], [], (string) $c['mechanic']),
            'starts_at' => Presenter::date($c['starts_at'] ?? null),
            'ends_at' => Presenter::date($c['ends_at'] ?? null),
            'entries_count' => (int) ($c['entries_count'] ?? 0),
            'winners_count' => (int) ($c['winners_count'] ?? 0),
            'organizer' => ['id' => (int) $c['organizer_id']] + $organizer,
        ];
    }

    /** @param array<string, mixed> $res */
    private function result(array $res, string $okMessage): never
    {
        if (!empty($res['ok'])) {
            $this->ok(['message' => $okMessage]);
        }
        $code = (string) ($res['error'] ?? 'error');
        $message = t('contests.errors.' . $code, [], t('contests.errors.generic'));
        if ($code === 'conditions' && !empty($res['conditions'])) {
            $failed = [];
            foreach ((array) $res['conditions'] as $key => $r) {
                if (($r['status'] ?? '') !== 'met') {
                    $failed[] = t('contests.cond_fail.' . ($r['detail'] ?? $key), [], (string) $key);
                }
            }
            $message = t('contests.err.conditions') . ' ' . implode('; ', $failed);
        }
        $this->fail('contest_' . $code, 422, $message);
    }
}
