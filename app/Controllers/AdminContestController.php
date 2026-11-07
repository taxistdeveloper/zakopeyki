<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\ContestReport;
use App\Models\ContestResult;
use App\Models\Setting;
use App\Models\User;
use App\Services\Contest\ContestPolicy;
use App\Services\Contest\ContestReportService;
use App\Services\Contest\ContestResultService;
use App\Services\Contest\ContestService;

/**
 * Админка модуля: управление разрешёнными механиками (только admin), модерация акций,
 * жалобы и споры (право contests).
 */
class AdminContestController extends Controller
{
    public function index(): void
    {
        Auth::requirePermission('contests');
        $policy = new ContestPolicy();
        $status = (string) ($_GET['status'] ?? '');
        $contests = new Contest();
        $this->view('admin/contests/index', [
            'title' => t('contests.admin.title'),
            'currentNav' => 'admin',
            'items' => $contests->adminList($status),
            'statusFilter' => $status,
            'counts' => $contests->countByStatus(),
            'reports' => (new ContestReport())->openQueue(),
            'isAdmin' => Auth::isAdmin(),
            'settings' => [
                'enabled' => $policy->moduleEnabled(),
                'organizer_mode' => $policy->organizerMode(),
                'require_verified_email' => $policy->requireVerifiedEmail(),
                'mechanics' => array_combine(
                    ContestPolicy::MECHANICS,
                    array_map(fn (string $m): bool => $policy->mechanicEnabled($m), ContestPolicy::MECHANICS)
                ),
            ],
            'flash' => $this->pull('flash'),
            'error' => $this->pull('error'),
        ]);
    }

    /** Флаги модуля и механик — только администратор, каждое изменение в журнале. */
    public function saveSettings(): void
    {
        Auth::requireAdmin();
        $s = new Setting();
        $policy = new ContestPolicy($s);
        $before = [
            'enabled' => $policy->moduleEnabled(),
            'organizer_mode' => $policy->organizerMode(),
            'require_verified_email' => $policy->requireVerifiedEmail(),
        ];
        foreach (ContestPolicy::MECHANICS as $m) {
            $before['method_' . $m] = $policy->mechanicEnabled($m);
        }

        $legalAck = !empty($_POST['legal_ack']);
        $wantMechanics = [];
        foreach (ContestPolicy::MECHANICS as $m) {
            $wantMechanics[$m] = !empty($_POST['method_' . $m]);
            if ($wantMechanics[$m] && !$before['method_' . $m] && !$legalAck) {
                $_SESSION['error'] = t('contests.admin.legal_ack_required');
                $this->redirect('/admin/contests');
            }
        }

        $s->setBool(ContestPolicy::SETTING_ENABLED, !empty($_POST['enabled']));
        $mode = (string) ($_POST['organizer_mode'] ?? '');
        $s->set(ContestPolicy::SETTING_ORGANIZER_MODE, in_array($mode, ContestPolicy::ORGANIZER_MODES, true) ? $mode : ContestPolicy::ORGANIZER_BUSINESS_VERIFIED);
        $s->setBool(ContestPolicy::SETTING_REQUIRE_VERIFIED_EMAIL, !empty($_POST['require_verified_email']));
        foreach ($wantMechanics as $m => $on) {
            $s->setBool(ContestPolicy::SETTING_METHOD_PREFIX . $m . '_enabled', $on);
        }

        $after = [
            'enabled' => !empty($_POST['enabled']),
            'organizer_mode' => (string) $s->get(ContestPolicy::SETTING_ORGANIZER_MODE),
            'require_verified_email' => !empty($_POST['require_verified_email']),
        ];
        foreach ($wantMechanics as $m => $on) {
            $after['method_' . $m] = $on;
        }
        ActivityLogger::warning('admin.contests_settings', 'Изменены настройки модуля конкурсов', 'settings', 0, [
            'before' => $before,
            'after' => $after,
            'legal_ack' => $legalAck,
        ]);
        $_SESSION['flash'] = t('contests.admin.saved');
        $this->redirect('/admin/contests');
    }

    public function show(string $id): void
    {
        Auth::requirePermission('contests');
        $contests = new Contest();
        $contest = $contests->findWithOrganizer((int) $id);
        if (!$contest) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('contests.not_found')]);
            return;
        }
        $contest = (new ContestService($contests))->syncTime($contest) + $contest;
        $results = new ContestResult();
        $this->view('admin/contests/show', [
            'title' => t('contests.admin.title') . ' #' . (int) $id,
            'currentNav' => 'admin',
            'contest' => $contest,
            'organizer' => (new User())->find((int) $contest['organizer_id']),
            'rules' => $contests->currentRules($contest),
            'versions' => $contests->ruleVersions((int) $contest['id']),
            'counts' => (new ContestEntry())->statusCounts((int) $contest['id']),
            'execution' => $results->forContest((int) $contest['id']),
            'winners' => $results->winners((int) $contest['id']),
            'reports' => (new ContestReport())->forContest((int) $contest['id']),
            'flash' => $this->pull('flash'),
            'error' => $this->pull('error'),
        ]);
    }

    public function approve(string $id): void
    {
        $this->moderate((int) $id, fn (array $c, array $u) => (new ContestService())->approve($c, $u, (string) ($_POST['note'] ?? '')), 'approved');
    }

    public function reject(string $id): void
    {
        $this->moderate((int) $id, fn (array $c, array $u) => (new ContestService())->reject($c, $u, (string) ($_POST['note'] ?? '')), 'rejected');
    }

    public function suspend(string $id): void
    {
        $this->moderate((int) $id, fn (array $c, array $u) => (new ContestService())->suspend($c, $u, (string) ($_POST['note'] ?? '')), 'suspended');
    }

    public function resume(string $id): void
    {
        $this->moderate((int) $id, fn (array $c, array $u) => (new ContestService())->resume($c, $u), 'resumed');
    }

    public function cancel(string $id): void
    {
        $this->moderate((int) $id, fn (array $c, array $u) => (new ContestService())->cancel($c, $u, (string) ($_POST['note'] ?? '')), 'cancelled');
    }

    public function resolveDispute(string $id, string $winnerId): void
    {
        $this->moderate(
            (int) $id,
            fn (array $c, array $u) => (new ContestResultService())->resolveDispute($c, (int) $winnerId, (string) ($_POST['outcome'] ?? ''), (string) ($_POST['note'] ?? ''), $u),
            'dispute_resolved'
        );
    }

    public function resolveReport(string $reportId): void
    {
        Auth::requirePermission('contests');
        $res = (new ContestReportService())->resolve(
            (int) $reportId,
            (string) ($_POST['status'] ?? ''),
            (string) ($_POST['note'] ?? ''),
            $this->actor()
        );
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.admin.report_done') : t('contests.errors.' . ($res['error'] ?? 'generic'), [], t('contests.errors.generic'));
        $back = (int) ($_POST['contest_id'] ?? 0);
        $this->redirect($back > 0 ? '/admin/contests/' . $back : '/admin/contests');
    }

    private function moderate(int $id, callable $action, string $flashKey): void
    {
        Auth::requirePermission('contests');
        $contest = (new Contest())->findWithOrganizer($id);
        if (!$contest) {
            $_SESSION['error'] = t('contests.not_found');
            $this->redirect('/admin/contests');
        }
        $res = $action($contest, $this->actor());
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok']
            ? t('contests.admin.done_' . $flashKey)
            : t('contests.errors.' . ($res['error'] ?? 'generic'), [], t('contests.errors.generic'));
        $this->redirect('/admin/contests/' . $id);
    }

    private function actor(): array
    {
        $u = (new User())->find((int) Auth::id()) ?? [];
        $u['permissions'] = Auth::normalizePermissions($u['permissions'] ?? null, (string) ($u['role'] ?? 'user'));
        return $u;
    }

    private function pull(string $key): ?string
    {
        $v = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_string($v) ? $v : null;
    }
}
