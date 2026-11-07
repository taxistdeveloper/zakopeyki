<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Helpers\QrCode;
use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\ContestReport;
use App\Models\ContestResult;
use App\Models\ContestTracking;
use App\Models\Follow;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\Contest\ContestAnalyticsService;
use App\Services\Contest\ContestEntryService;
use App\Services\Contest\ContestPolicy;
use App\Services\Contest\ContestReferralTracker;
use App\Services\Contest\ContestReportService;
use App\Services\Contest\ContestResultService;
use App\Services\Contest\ContestService;

/**
 * «Конкурсы и розыгрыши»: каталог, страница акции, участие, кабинет организатора.
 */
class ContestController extends Controller
{
    private ?Contest $contests = null;
    private ?ContestPolicy $policy = null;

    // ── Публичная часть ───────────────────────────────────────────────────────

    public function index(): void
    {
        if (!$this->policy()->publicVisible()) {
            $this->notFound();
        }
        $filters = [
            'status' => in_array($_GET['status'] ?? '', ['active', 'upcoming', 'finished'], true) ? (string) $_GET['status'] : '',
            'mechanic' => (string) ($_GET['mechanic'] ?? ''),
            'region' => (string) ($_GET['region'] ?? ''),
            'category' => mb_substr((string) ($_GET['category'] ?? ''), 0, 120),
            'organizer' => (int) ($_GET['organizer'] ?? 0),
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $this->contests()->catalog($filters, $page, 12);

        $this->view('contests/index', [
            'title' => t('contests.title'),
            'currentNav' => 'contests',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 12,
            'filters' => $filters,
            'organizers' => $this->contests()->publicOrganizers(),
            'categories' => $this->contests()->publicCategories(),
            'canCreate' => Auth::check() && $this->policy()->organizerDenyReason($this->freshUser()) === null,
            'moduleEnabled' => $this->policy()->moduleEnabled(),
        ]);
    }

    public function show(string $slug): void
    {
        $contest = $this->contests()->findBySlug($slug);
        if (!$contest) {
            $this->notFound();
        }
        $contest = (new ContestService($this->contests(), $this->policy()))->syncTime($contest) + $contest;
        $user = $this->freshUser();
        $canManage = $user !== null && ContestService::canManage($contest, $user);
        if (!$canManage && (!ContestPolicy::isPublicStatus((string) $contest['status']) || !$this->policy()->publicVisible())) {
            $this->notFound();
        }

        if (ContestPolicy::isPublicStatus((string) $contest['status'])) {
            $tracking = new ContestTracking();
            $visitor = SiteVisit::currentVisitorKey();
            if ($visitor !== '' && $tracking->recordView((int) $contest['id'], $visitor)) {
                $this->contests()->incrementViews((int) $contest['id']);
            }
            ContestReferralTracker::captureOnContestPage($contest);
        }

        $rules = $this->contests()->currentRules($contest);
        $entries = new ContestEntry();
        $entry = $user ? $entries->findForUser((int) $contest['id'], (int) $user['id']) : null;
        $results = new ContestResult();
        $execution = $results->forContest((int) $contest['id']);
        $winners = $execution ? $results->winners((int) $contest['id']) : [];

        $conditionPreview = [];
        if ($user && !$entry && $rules && $contest['status'] === ContestPolicy::S_ACTIVE) {
            $conditionPreview = (new \App\Services\Contest\ContestConditionVerifier($this->policy()))
                ->checkAtJoin($contest, (array) $rules['conditions'], $user, 'preview');
            unset($conditionPreview[ContestPolicy::COND_SUBMISSION]);
        }

        $personalPath = ($user && $entry) ? ContestReferralTracker::personalPath($contest, $user) : null;
        $tracking = $tracking ?? new ContestTracking();

        $this->view('contests/show', [
            'title' => (string) $contest['title'],
            'currentNav' => 'contests',
            'contest' => $contest,
            'rules' => $rules,
            'user' => $user,
            'entry' => $entry,
            'entryConditions' => $entry ? $entries->conditions((int) $entry['id']) : [],
            'conditionPreview' => $conditionPreview,
            'effectiveConditions' => $rules ? (new \App\Services\Contest\ContestConditionVerifier($this->policy()))->effectiveConditions((array) $rules['conditions']) : [],
            'execution' => $execution,
            'winners' => $winners,
            'canManage' => $canManage,
            'isFollowing' => $user ? (new Follow())->isFollowing((int) $user['id'], (int) $contest['organizer_id']) : false,
            'personalUrl' => $personalPath ? ProductHelper::absoluteUrl($personalPath) : null,
            'publicUrl' => ProductHelper::absoluteUrl('/contests/' . rawurlencode((string) $contest['slug'])),
            'myReferrals' => ($user && $entry) ? $tracking->referralsWithRewardStatus((int) $contest['id'], (int) $user['id']) : [],
            'myRefTotals' => ($user && $entry) ? $tracking->refTotals((int) $contest['id'], (int) $user['id']) : null,
            'hasOpenReport' => $user ? (new ContestReport())->hasOpen((int) $contest['id'], (int) $user['id']) : false,
            'mechanicEnabled' => $this->policy()->mechanicEnabled((string) $contest['mechanic']),
            'flash' => $this->pull('flash'),
            'error' => $this->pull('error'),
        ]);
    }

    /** SVG QR-код публичной (или персональной, ?personal=1) ссылки на акцию. */
    public function qr(string $id): void
    {
        $contest = $this->contests()->find((int) $id);
        if (!$contest || !ContestPolicy::isPublicStatus((string) $contest['status'])) {
            $this->notFound();
        }
        $path = '/contests/' . rawurlencode((string) $contest['slug']);
        $user = $this->freshUser();
        if (!empty($_GET['personal']) && $user && (new ContestEntry())->findForUser((int) $contest['id'], (int) $user['id'])) {
            $path = ContestReferralTracker::personalPath($contest, $user);
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        echo QrCode::svg(ProductHelper::absoluteUrl($path), 280);
        exit;
    }

    // ── Участник ──────────────────────────────────────────────────────────────

    public function join(string $slug): void
    {
        Auth::requireLogin();
        $contest = $this->publicContestOr404($slug);
        $res = (new ContestEntryService())->join(
            $contest,
            $this->freshUser() ?? [],
            isset($_POST['submission']) ? (string) $_POST['submission'] : null,
            ContestEntryService::requestContext() + ['ref_user_id' => ContestReferralTracker::refUserFor((int) $contest['id'])]
        );
        if ($res['ok']) {
            $_SESSION['flash'] = t('contests.flash.joined');
        } else {
            $_SESSION['error'] = $this->errorText($res['error'] ?? 'error', $res['conditions'] ?? []);
        }
        $this->redirect('/contests/' . rawurlencode($slug));
    }

    public function withdraw(string $slug): void
    {
        Auth::requireLogin();
        $contest = $this->publicContestOr404($slug);
        $res = (new ContestEntryService())->withdraw($contest, $this->freshUser() ?? []);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.withdrawn') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . rawurlencode($slug));
    }

    public function report(string $slug): void
    {
        Auth::requireLogin();
        $contest = $this->publicContestOr404($slug);
        $res = (new ContestReportService())->submit(
            $contest,
            $this->freshUser() ?? [],
            (string) ($_POST['reason'] ?? ''),
            (string) ($_POST['details'] ?? '')
        );
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.reported') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . rawurlencode($slug));
    }

    public function confirmPrize(string $slug, string $winnerId): void
    {
        Auth::requireLogin();
        $contest = $this->publicContestOr404($slug);
        $res = (new ContestResultService())->confirmReceived($contest, (int) $winnerId, $this->freshUser() ?? []);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.prize_confirmed') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . rawurlencode($slug));
    }

    public function disputePrize(string $slug, string $winnerId): void
    {
        Auth::requireLogin();
        $contest = $this->publicContestOr404($slug);
        $res = (new ContestResultService())->disputePrize($contest, (int) $winnerId, (string) ($_POST['note'] ?? ''), $this->freshUser() ?? []);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.dispute_opened') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . rawurlencode($slug));
    }

    // ── Кабинет организатора ──────────────────────────────────────────────────

    public function my(): void
    {
        Auth::requireLogin();
        $user = $this->freshUser();
        $uid = (int) Auth::id();
        $this->view('contests/my', [
            'title' => t('contests.my_title'),
            'currentNav' => 'contests',
            'organized' => $this->contests()->byOrganizer($uid),
            'participations' => (new ContestEntry())->byUser($uid),
            'wins' => (new ContestResult())->byUser($uid),
            'denyReason' => $this->policy()->organizerDenyReason($user),
            'flash' => $this->pull('flash'),
            'error' => $this->pull('error'),
        ]);
    }

    public function createForm(): void
    {
        Auth::requireLogin();
        $deny = $this->policy()->organizerDenyReason($this->freshUser());
        if ($deny !== null) {
            $_SESSION['error'] = t('contests.deny.' . $deny);
            $this->redirect('/contests/my');
        }
        $this->renderForm(null, $this->defaultInput(), []);
    }

    public function store(): void
    {
        Auth::requireLogin();
        $res = (new ContestService())->createDraft(
            $this->freshUser() ?? [],
            $_POST,
            $_FILES['cover'] ?? null,
            (string) ($_POST['idempotency_key'] ?? '')
        );
        if (!$res['ok']) {
            if (isset($res['error'])) {
                $_SESSION['error'] = t('contests.deny.' . $res['error'], [], $this->errorText($res['error']));
                $this->redirect('/contests/my');
            }
            $this->renderForm(null, $_POST, $res['errors'] ?? []);
            return;
        }
        $id = (int) $res['id'];
        if (!empty($_POST['submit_for_moderation'])) {
            $contest = $this->contests()->find($id);
            $sub = (new ContestService())->submit($contest, $this->freshUser() ?? [], !empty($_POST['organizer_terms']));
            if (!$sub['ok']) {
                $_SESSION['error'] = isset($sub['errors']) ? implode(' ', $sub['errors']) : $this->errorText($sub['error'] ?? 'error');
                $this->redirect('/contests/' . $id . '/edit');
            }
            $_SESSION['flash'] = t('contests.flash.submitted');
        } else {
            $_SESSION['flash'] = t('contests.flash.draft_saved');
        }
        $this->redirect('/contests/' . $id . '/manage');
    }

    public function editForm(string $id): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, true);
        if (!ContestPolicy::isEditable((string) $contest['status'])) {
            $_SESSION['error'] = t('contests.err.not_editable');
            $this->redirect('/contests/' . (int) $id . '/manage');
        }
        $service = new ContestService();
        $this->renderForm($contest, $service->inputFromContest($contest, $this->contests()->currentRules($contest)), [], $this->pull('error'));
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, true);
        $service = new ContestService();
        $res = $service->updateDraft($contest, $this->freshUser() ?? [], $_POST, $_FILES['cover'] ?? null);
        if (!$res['ok']) {
            if (isset($res['errors'])) {
                $this->renderForm($contest, $_POST, $res['errors']);
                return;
            }
            $_SESSION['error'] = $this->errorText($res['error'] ?? 'error');
            $this->redirect('/contests/' . (int) $id . '/manage');
        }
        if (!empty($_POST['submit_for_moderation'])) {
            $fresh = $this->contests()->find((int) $id);
            $sub = $service->submit($fresh, $this->freshUser() ?? [], !empty($_POST['organizer_terms']));
            if (!$sub['ok']) {
                $_SESSION['error'] = isset($sub['errors']) ? implode(' ', $sub['errors']) : $this->errorText($sub['error'] ?? 'error');
                $this->redirect('/contests/' . (int) $id . '/edit');
            }
            $_SESSION['flash'] = t('contests.flash.submitted');
        } else {
            $_SESSION['flash'] = t('contests.flash.draft_saved');
        }
        $this->redirect('/contests/' . (int) $id . '/manage');
    }

    public function submit(string $id): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, true);
        $res = (new ContestService())->submit($contest, $this->freshUser() ?? [], !empty($_POST['organizer_terms']));
        if ($res['ok']) {
            $_SESSION['flash'] = t('contests.flash.submitted');
        } else {
            $_SESSION['error'] = isset($res['errors']) ? implode(' ', $res['errors']) : $this->errorText($res['error'] ?? 'error');
        }
        $this->redirect('/contests/' . (int) $id . '/manage');
    }

    public function cancel(string $id): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, true);
        $res = (new ContestService())->cancel($contest, $this->freshUser() ?? [], (string) ($_POST['reason'] ?? ''));
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.cancelled') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . (int) $id . '/manage');
    }

    public function manage(string $id): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, false);
        $contest = (new ContestService())->syncTime($contest) + $contest;
        $entries = new ContestEntry();
        $results = new ContestResult();
        $statusFilter = (string) ($_GET['entries'] ?? '');
        $this->view('contests/manage', [
            'title' => t('contests.manage_title') . ': ' . $contest['title'],
            'currentNav' => 'contests',
            'contest' => $contest,
            'rules' => $this->contests()->currentRules($contest),
            'versions' => $this->contests()->ruleVersions((int) $contest['id']),
            'roles' => ContestService::actorRoles($contest, $this->freshUser()),
            'entries' => $entries->listForContest((int) $contest['id'], $statusFilter),
            'entryFilter' => $statusFilter,
            'counts' => $entries->statusCounts((int) $contest['id']),
            'execution' => $results->forContest((int) $contest['id']),
            'winners' => $results->winners((int) $contest['id']),
            'funnel' => (new ContestAnalyticsService())->funnel($contest),
            'reports' => (new ContestReport())->forContest((int) $contest['id']),
            'publicUrl' => ProductHelper::absoluteUrl('/contests/' . rawurlencode((string) $contest['slug'])),
            'mechanicEnabled' => $this->policy()->mechanicEnabled((string) $contest['mechanic']),
            'flash' => $this->pull('flash'),
            'error' => $this->pull('error'),
        ]);
    }

    public function reviewEntry(string $id, string $entryId): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, false);
        $res = (new ContestEntryService())->review(
            $contest,
            (int) $entryId,
            (string) ($_POST['action'] ?? ''),
            (string) ($_POST['reason'] ?? ''),
            $this->freshUser() ?? []
        );
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.entry_updated') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . (int) $id . '/manage#entries');
    }

    public function results(string $id): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, false);
        $picks = [];
        foreach ((array) ($_POST['place'] ?? []) as $place => $entryId) {
            $picks[(int) $place] = (int) $entryId;
        }
        $notes = [];
        foreach ((array) ($_POST['note'] ?? []) as $place => $note) {
            $notes[(int) $place] = (string) $note;
        }
        $res = (new ContestResultService())->execute($contest, $this->freshUser() ?? [], $picks, $notes);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.results_published') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . (int) $id . '/manage');
    }

    public function handedOver(string $id, string $winnerId): void
    {
        Auth::requireLogin();
        $contest = $this->managedOr404((int) $id, false);
        $res = (new ContestResultService())->markHandedOver($contest, (int) $winnerId, (string) ($_POST['note'] ?? ''), $this->freshUser() ?? []);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('contests.flash.handed_over') : $this->errorText($res['error'] ?? 'error');
        $this->redirect('/contests/' . (int) $id . '/manage#winners');
    }

    // ── Вспомогательное ───────────────────────────────────────────────────────

    private function renderForm(?array $contest, array $input, array $errors, ?string $error = null): void
    {
        $this->view('contests/form', [
            'title' => $contest ? t('contests.edit_title') : t('contests.create_title'),
            'currentNav' => 'contests',
            'contest' => $contest,
            'input' => $input,
            'errors' => $errors,
            'error' => $error,
            'mechanics' => $this->policy()->enabledMechanics(),
            'categories' => array_keys(ProductHelper::PRODUCT_CATEGORY_TREE),
            'idempotencyKey' => bin2hex(random_bytes(16)),
        ]);
    }

    private function defaultInput(): array
    {
        $mech = $this->policy()->enabledMechanics();
        return [
            'mechanic' => $mech[0] ?? ContestPolicy::MECHANIC_JURY,
            'winners_count' => 1,
            'region' => 'kz',
            'fulfilment_days' => ContestPolicy::DEFAULT_FULFILMENT_DAYS,
            'starts_at' => date('Y-m-d\TH:i', time() + 86400),
            'ends_at' => date('Y-m-d\TH:i', time() + 8 * 86400),
            'prizes' => [''],
            'cond_follow_organizer' => '',
            'cond_account_age_days' => 0,
        ];
    }

    /** Акция, которой текущий пользователь может управлять (организатор или модератор). */
    private function managedOr404(int $id, bool $organizerOnly): array
    {
        $contest = $this->contests()->findWithOrganizer($id);
        $user = $this->freshUser();
        if (!$contest || $user === null) {
            $this->notFound();
        }
        $roles = ContestService::actorRoles($contest, $user);
        $ok = $organizerOnly
            ? (in_array('organizer', $roles, true) || in_array('admin', $roles, true))
            : ContestService::canManage($contest, $user);
        if (!$ok) {
            $this->notFound(); // не раскрываем существование чужих акций
        }
        return $contest;
    }

    private function publicContestOr404(string $slug): array
    {
        $contest = $this->contests()->findBySlug($slug);
        if (!$contest || !ContestPolicy::isPublicStatus((string) $contest['status'])) {
            $this->notFound();
        }
        return $contest;
    }

    /** Актуальная запись пользователя из БД (статусы блокировки/email не берём из сессии). */
    private function freshUser(): ?array
    {
        static $cache = [];
        $id = Auth::id();
        if ($id === null) {
            return null;
        }
        if (!array_key_exists($id, $cache)) {
            $row = (new User())->find((int) $id);
            if ($row) {
                $row['permissions'] = Auth::normalizePermissions($row['permissions'] ?? null, (string) ($row['role'] ?? 'user'));
            }
            $cache[$id] = $row ?: null;
        }
        return $cache[$id];
    }

    private function errorText(string $code, array $conditions = []): string
    {
        if ($code === 'conditions' && $conditions !== []) {
            $failed = [];
            foreach ($conditions as $key => $r) {
                if (($r['status'] ?? '') !== 'met') {
                    $failed[] = t('contests.cond_fail.' . ($r['detail'] ?? $key), [], (string) $key);
                }
            }
            return t('contests.err.conditions') . ' ' . implode('; ', $failed);
        }
        return t('contests.errors.' . $code, [], t('contests.errors.generic'));
    }

    private function pull(string $key): ?string
    {
        $v = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_string($v) ? $v : null;
    }

    private function notFound(): never
    {
        http_response_code(404);
        $this->view('errors/404', ['title' => t('contests.not_found')]);
        exit;
    }

    private function contests(): Contest
    {
        return $this->contests ??= new Contest();
    }

    private function policy(): ContestPolicy
    {
        return $this->policy ??= new ContestPolicy();
    }
}
