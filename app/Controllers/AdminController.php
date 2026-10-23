<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Helpers\Mail;
use App\Helpers\ProductHelper;
use App\Models\ActivityLog;
use App\Models\AiSupport;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SiteVisit;
use App\Models\SupportTicket;
use App\Models\BusinessUpgradeRequest;
use App\Models\MicroTask;
use App\Models\User;
use App\Services\AMLService;
use App\Services\Business\BusinessPackagePolicy;
use App\Services\BusinessPackageService;
use App\Services\BusinessUpgradeService;
use App\Services\Finance\FinanceJournalService;
use App\Services\PaidServices\PaidServicesAdminService;
use App\Services\PersonalLimitService;
use App\Services\UnskilledTaskValidator;
use App\Core\Csrf;
use App\Services\AI\SelfLearningService;
use App\Services\EscrowService;

class AdminController extends Controller
{
    public function index(): void
    {
        Auth::requireStaff();
        if (Auth::can('products') || Auth::can('disputes')) {
            (new EscrowService())->processDeadlines();
        }

        $productModel = new Product();
        $userModel = new User();
        $orderModel = new Order();
        $support = new SupportTicket();
        $aiSupport = new AiSupport();

        $canProducts = Auth::can('products');
        $canTickets = Auth::can('tickets');
        $canAi = Auth::can('ai_chats');
        $canDisputes = Auth::can('disputes');
        $isAdmin = Auth::isAdmin();
        $libraryCount = 0;
        $openTasks = 0;
        try {
            $libraryCount = (new \App\Models\Library())->countDocuments();
            $openTasks = (new \App\Models\OpsTask())->countOpen();
        } catch (\Throwable) {
            // tables may not exist yet
        }

        $items = $canProducts ? $productModel->all('created_at DESC') : [];
        $counts = $canProducts ? $productModel->countByType() : [];
        $userStats = [
            'total' => 0,
            'today' => 0,
            'week' => 0,
            'site_access' => 0,
            'logins_today' => 0,
            'logins_week' => 0,
        ];
        $userCount = 0;
        $visitStats = [
            'visitors_today' => 0,
            'visitors_week' => 0,
            'visitors_total' => 0,
            'hits_today' => 0,
            'hits_week' => 0,
        ];
        $recentVisitors = [];
        $disputes = $canDisputes ? $orderModel->findReturnQueue() : [];

        $n = new Notification();
        $notifications = $n->forUser(Auth::id());
        $unread = $n->unreadCount(Auth::id());

        $recentErrors = 0;
        $amlBlockedCount = 0;
        $stubMode = !empty($GLOBALS['appConfig']['stub_mode']);
        if ($isAdmin) {
            try {
                $userStats = $userModel->registrationStats();
                $userCount = $userStats['total'];
                $log = new ActivityLog();
                $recentErrors = $log->recentErrorCount(24);
                $userStats['logins_today'] = $log->countUniqueLoginsSince('CURDATE()');
                $userStats['logins_week'] = $log->countUniqueLoginsSince('(CURDATE() - INTERVAL 7 DAY)');
                $amlBlockedCount = $userModel->amlStats()['blocked'];
            } catch (\Throwable) {
                $userCount = $userModel->countAll();
                $userStats['total'] = $userCount;
            }
            try {
                $visits = new SiteVisit();
                $visitStats = $visits->stats();
                $recentVisitors = $visits->recent(25);
            } catch (\Throwable) {
                // ignore
            }
        }

        $this->view('admin/index', [
            'title' => t('admin.title'),
            'currentNav' => 'admin',
            'items' => $items,
            'counts' => $counts,
            'userCount' => $userCount,
            'userStats' => $userStats,
            'visitStats' => $visitStats,
            'recentVisitors' => $recentVisitors,
            'disputes' => $disputes,
            'openTickets' => $canTickets ? $support->openCount() : 0,
            'ticketUnread' => $canTickets ? $support->unreadCountForAdmin() : 0,
            'aiEscalated' => $canAi ? $aiSupport->countEscalated() : 0,
            'recentErrors' => $recentErrors,
            'amlBlockedCount' => $amlBlockedCount,
            'stubMode' => $stubMode,
            'boostEnabled' => $isAdmin && (new \App\Services\Boost\BoostService())->isEnabled(),
            'paidServicesAdminEnabled' => $isAdmin
                && (new PaidServicesAdminService())->isModuleEnabled(),
            'financeJournalEnabled' => $isAdmin
                && (new FinanceJournalService())->isModuleEnabled(),
            'serviceListingPublishEnabled' => $isAdmin
                && (new \App\Services\Listing\ServiceListingFeeService())->isPublishEnabled(),
            'threeLiftsEnabled' => $isAdmin && (new \App\Services\ThreeLifts\ThreeLiftsService())->isEnabled(),
            'highlightEnabled' => $isAdmin && (new \App\Services\Highlight\HighlightService())->isEnabled(),
            'topFixedEnabled' => $isAdmin && (new \App\Services\TopFixed\TopFixedService())->isEnabled(),
            'topFixedXlEnabled' => $isAdmin && (new \App\Services\TopFixedXl\TopFixedXlService())->isEnabled(),
            'vipEnabled' => $isAdmin && (new \App\Services\Vip\VipService())->isEnabled(),
            'adsFlags' => $isAdmin ? (new \App\Services\Ads\AdService())->flags() : [],
            'targetPushEnabled' => $isAdmin && (new \App\Services\TargetPush\TargetPushService())->isEnabled(),
            'massPushEnabled' => $isAdmin && (new \App\Services\MassPush\MassPushService())->isEnabled(),
            'canProducts' => $canProducts,
            'canTickets' => $canTickets,
            'canAi' => $canAi,
            'canDisputes' => $canDisputes,
            'isAdmin' => $isAdmin,
            'libraryCount' => $libraryCount,
            'openTasks' => $openTasks,
            'types' => ProductHelper::TYPES,
            'notifications' => $notifications,
            'unread' => $unread,
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function toggleSiteStatus(): void
    {
        Auth::requireAdmin();

        $open = isset($_POST['open']) && (string) $_POST['open'] === '1';
        $stubMode = !$open;

        try {
            (new Setting())->setBool('stub_mode', $stubMode);
            $GLOBALS['appConfig']['stub_mode'] = $stubMode;

            ActivityLogger::info(
                'admin.site_toggle',
                $open ? 'Сайт открыт для пользователей' : 'Сайт закрыт (заглушка)',
                'settings',
                null,
                ['stub_mode' => $stubMode]
            );

            $_SESSION['flash'] = $open
                ? t('admin.site_opened_flash')
                : t('admin.site_closed_flash');
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'admin.site_toggle');
            $_SESSION['error'] = t('admin.site_toggle_failed');
        }

        $this->redirect('/admin');
    }

    private function requireBoostAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\Boost\BoostPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleBoost(): void
    {
        $this->requireBoostAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\Boost\BoostService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.boost_enabled_flash') : t('admin.boost_disabled_flash'))
            : t('admin.boost_toggle_failed');
        $this->redirect('/admin');
    }

    private function requireServiceListingAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\Listing\ServiceListingFeePolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleServiceListingPublish(): void
    {
        $this->requireServiceListingAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\Listing\ServiceListingFeeService())->setPublishEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.service_listing_enabled_flash') : t('admin.service_listing_disabled_flash'))
            : t('admin.service_listing_toggle_failed');
        $this->redirect('/admin');
    }

    /**
     * VAS-18: журнал заказов/платежей — HTML админки; JSON при Accept/format=json.
     */
    /**
     * VAS-18: флаг модуля админ-журнала (не VAS-17 и не продажи VAS-01…16).
     */
    public function toggleFinanceJournalModule(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\Finance\FinanceJournalPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $result = (new FinanceJournalService())->setModuleEnabled($enabled);
        $_SESSION[!empty($result['ok']) ? 'flash' : 'error'] = !empty($result['ok'])
            ? ($enabled ? t('admin.finance_journal_module_enabled_flash') : t('admin.finance_journal_module_disabled_flash'))
            : t('admin.finance_journal_module_toggle_failed');
        $this->redirect('/admin');
    }

    public function financeJournalList(): void
    {
        Auth::requireLogin();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_GET['format'] ?? '') === 'json');

        if (!$wantsJson) {
            Auth::requireAdmin();
        }

        $svc = new FinanceJournalService();
        $result = $svc->list($_GET);
        if (empty($result['ok'])) {
            $code = (string) ($result['error_code'] ?? 'forbidden');
            if ($wantsJson) {
                $http = match ($code) {
                    'forbidden', 'module_disabled' => 403,
                    'query_failed' => 500,
                    default => 400,
                };
                $this->json(['ok' => false, 'error_code' => $code], $http);
            }
            $_SESSION['error'] = match ($code) {
                'forbidden' => t('admin.finance_journal_forbidden'),
                'module_disabled' => t('admin.finance_journal_module_off_flash'),
                'invalid_stream', 'invalid_date_range', 'invalid_query', 'invalid_filters',
                'invalid_service', 'invalid_status', 'invalid_sort', 'invalid_confirmed',
                'invalid_user', 'invalid_date_from', 'invalid_date_to' => t('admin.finance_journal_bad_filters'),
                default => t('admin.finance_journal_error'),
            };
            if ($code === 'forbidden' || $code === 'module_disabled') {
                $this->redirect('/admin');
            }
            $this->redirect('/admin/finance-journal');
        }

        if ($wantsJson) {
            $this->json([
                'ok' => true,
                'items' => $result['items'] ?? [],
                'page' => $result['page'] ?? 1,
                'pages' => $result['pages'] ?? 1,
                'total' => $result['total'] ?? 0,
                'per_page' => $result['per_page'] ?? 40,
                'filters' => $result['filters'] ?? [],
                'read_only' => true,
            ]);
        }

        $n = new Notification();
        $this->view('admin/finance-journal', [
            'title' => t('admin.finance_journal'),
            'currentNav' => 'admin',
            'items' => $result['items'] ?? [],
            'page' => (int) ($result['page'] ?? 1),
            'pages' => (int) ($result['pages'] ?? 1),
            'total' => (int) ($result['total'] ?? 0),
            'perPage' => (int) ($result['per_page'] ?? 40),
            'filters' => is_array($result['filters'] ?? null) ? $result['filters'] : [],
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    /**
     * VAS-18: детали записи журнала — HTML / JSON.
     */
    public function financeJournalDetail(string $entryId): void
    {
        Auth::requireLogin();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_GET['format'] ?? '') === 'json');

        if (!$wantsJson) {
            Auth::requireAdmin();
        }

        $svc = new FinanceJournalService();
        $result = $svc->detail(rawurldecode($entryId));
        if (empty($result['ok'])) {
            $code = (string) ($result['error_code'] ?? 'forbidden');
            if ($wantsJson) {
                $http = match ($code) {
                    'forbidden', 'module_disabled' => 403,
                    'not_found' => 404,
                    'query_failed' => 500,
                    default => 400,
                };
                $this->json(['ok' => false, 'error_code' => $code], $http);
            }
            $_SESSION['error'] = match ($code) {
                'forbidden' => t('admin.finance_journal_forbidden'),
                'module_disabled' => t('admin.finance_journal_module_off_flash'),
                'not_found' => t('admin.finance_journal_not_found'),
                default => t('admin.finance_journal_error'),
            };
            $this->redirect(($code === 'forbidden' || $code === 'module_disabled') ? '/admin' : '/admin/finance-journal');
        }

        if ($wantsJson) {
            $this->json([
                'ok' => true,
                'entry' => $result['entry'] ?? null,
                'read_only' => true,
            ]);
        }

        $n = new Notification();
        $this->view('admin/finance-journal-show', [
            'title' => t('admin.finance_journal_detail'),
            'currentNav' => 'admin',
            'entry' => $result['entry'] ?? [],
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    /**
     * VAS-17: флаг модуля админ-каталога (не флаги продаж отдельных услуг).
     */
    public function togglePaidServicesModule(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\PaidServices\PaidServicesAdminPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $result = (new PaidServicesAdminService())->setModuleEnabled($enabled);
        $_SESSION[!empty($result['ok']) ? 'flash' : 'error'] = !empty($result['ok'])
            ? ($enabled ? t('admin.paid_services_module_enabled_flash') : t('admin.paid_services_module_disabled_flash'))
            : t('admin.paid_services_module_toggle_failed');
        $this->redirect('/admin');
    }

    /**
     * VAS-17: каталог платных услуг — HTML для админки; JSON при Accept/format=json.
     */
    public function paidServicesCatalog(): void
    {
        Auth::requireLogin();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_GET['format'] ?? '') === 'json');

        if (!$wantsJson) {
            Auth::requireAdmin();
        }

        $svc = new PaidServicesAdminService();
        $result = $svc->catalog();
        if (empty($result['ok'])) {
            $err = (string) ($result['error_code'] ?? 'forbidden');
            if ($wantsJson) {
                $this->json([
                    'ok' => false,
                    'error_code' => $err,
                ], 403);
            }
            $_SESSION['error'] = $err === 'module_disabled'
                ? t('admin.paid_services_module_off_flash')
                : t('admin.paid_services_forbidden');
            $this->redirect('/admin');
        }

        if ($wantsJson) {
            $this->json([
                'ok' => true,
                'services' => $result['services'] ?? [],
                'price_editable' => false,
            ]);
        }

        $n = new Notification();
        $this->view('admin/paid-services', [
            'title' => t('admin.paid_services'),
            'currentNav' => 'admin',
            'services' => $result['services'] ?? [],
            'priceEditable' => false,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    /**
     * VAS-17: изменить доступность одной услуги. Цены через этот endpoint запрещены.
     */
    public function togglePaidServiceAvailability(string $code): void
    {
        Auth::requireLogin();
        Csrf::enforce();

        $input = array_merge($_POST, []);
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }

        $enabled = isset($input['enabled']) && (
            $input['enabled'] === true
            || $input['enabled'] === 1
            || (string) $input['enabled'] === '1'
        );

        $svc = new PaidServicesAdminService();
        $result = $svc->setAvailability($code, $enabled, $input);
        if (empty($result['ok'])) {
            $codeErr = (string) ($result['error_code'] ?? 'toggle_failed');
            $http = match ($codeErr) {
                'forbidden', 'module_disabled' => 403,
                'not_found' => 404,
                'price_edit_forbidden', 'not_toggleable', 'conflict' => 400,
                default => 400,
            };
            $this->json([
                'ok' => false,
                'error_code' => $codeErr,
                'code' => $result['code'] ?? $code,
            ], $http);
        }

        $this->json([
            'ok' => true,
            'code' => $result['code'] ?? $code,
            'enabled' => !empty($result['enabled']),
            'before' => $result['before'] ?? null,
            'after' => $result['after'] ?? null,
            'unchanged' => !empty($result['unchanged']),
        ]);
    }

    public function recoverBoost(): void
    {
        $this->requireBoostAdmin();

        $result = (new \App\Services\Boost\BoostService())->recoverUnapplied();
        if (empty($result['ok'])) {
            $_SESSION['error'] = t('admin.boost_recover_disabled');
            $this->redirect('/admin');
            return;
        }
        $_SESSION['flash'] = t('admin.boost_recovered', ['n' => (string) ($result['applied'] ?? 0)]);
        $this->redirect('/admin');
    }

    private function requireThreeLiftsAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\ThreeLifts\ThreeLiftsPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleThreeLifts(): void
    {
        $this->requireThreeLiftsAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $expected = null;
        if (isset($_POST['expected']) && ((string) $_POST['expected'] === '1' || (string) $_POST['expected'] === '0')) {
            $expected = (string) $_POST['expected'] === '1';
        }
        $result = (new \App\Services\ThreeLifts\ThreeLiftsService())->setEnabled($enabled, $expected);
        if (!empty($result['ok']) && !empty($result['unchanged'])) {
            $_SESSION['flash'] = $enabled
                ? t('admin.three_lifts_already_on')
                : t('admin.three_lifts_already_off');
        } elseif (!empty($result['ok'])) {
            $_SESSION['flash'] = $enabled
                ? t('admin.three_lifts_enabled_flash')
                : t('admin.three_lifts_disabled_flash');
        } elseif (($result['error_code'] ?? '') === 'conflict') {
            $_SESSION['error'] = t('admin.three_lifts_conflict');
        } else {
            $_SESSION['error'] = t('admin.three_lifts_toggle_failed');
        }
        $this->redirect('/admin');
    }

    private function requireHighlightAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\Highlight\HighlightPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleHighlight(): void
    {
        $this->requireHighlightAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\Highlight\HighlightService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.highlight_enabled_flash') : t('admin.highlight_disabled_flash'))
            : t('admin.highlight_toggle_failed');
        $this->redirect('/admin');
    }

    public function recoverHighlight(): void
    {
        $this->requireHighlightAdmin();

        $result = (new \App\Services\Highlight\HighlightService())->recoverUnapplied();
        if (empty($result['ok'])) {
            $_SESSION['error'] = t('admin.highlight_recover_disabled');
            $this->redirect('/admin');
            return;
        }
        $_SESSION['flash'] = t('admin.highlight_recovered', ['n' => (string) ($result['applied'] ?? 0)]);
        $this->redirect('/admin');
    }

    private function requireTopFixedAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\TopFixed\TopFixedPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleTopFixed(): void
    {
        $this->requireTopFixedAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\TopFixed\TopFixedService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.top_fixed_enabled_flash') : t('admin.top_fixed_disabled_flash'))
            : t('admin.top_fixed_toggle_failed');
        $this->redirect('/admin');
    }

    public function recoverTopFixed(): void
    {
        $this->requireTopFixedAdmin();

        $result = (new \App\Services\TopFixed\TopFixedService())->recoverUnapplied();
        if (empty($result['ok'])) {
            $_SESSION['error'] = t('admin.top_fixed_recover_disabled');
            $this->redirect('/admin');
            return;
        }
        $_SESSION['flash'] = t('admin.top_fixed_recovered', ['n' => (string) ($result['applied'] ?? 0)]);
        $this->redirect('/admin');
    }

    private function requireTopFixedXlAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\TopFixedXl\TopFixedXlPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleTopFixedXl(): void
    {
        $this->requireTopFixedXlAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\TopFixedXl\TopFixedXlService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.top_fixed_xl_enabled_flash') : t('admin.top_fixed_xl_disabled_flash'))
            : t('admin.top_fixed_xl_toggle_failed');
        $this->redirect('/admin');
    }

    public function recoverTopFixedXl(): void
    {
        $this->requireTopFixedXlAdmin();

        $result = (new \App\Services\TopFixedXl\TopFixedXlService())->recoverUnapplied();
        if (empty($result['ok'])) {
            $_SESSION['error'] = t('admin.top_fixed_xl_recover_disabled');
            $this->redirect('/admin');
            return;
        }
        $_SESSION['flash'] = t('admin.top_fixed_xl_recovered', ['n' => (string) ($result['applied'] ?? 0)]);
        $this->redirect('/admin');
    }

    private function requireVipAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\Vip\VipPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function toggleVip(): void
    {
        $this->requireVipAdmin();

        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\Vip\VipService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.vip_enabled_flash') : t('admin.vip_disabled_flash'))
            : t('admin.vip_toggle_failed');
        $this->redirect('/admin');
    }

    public function recoverVip(): void
    {
        $this->requireVipAdmin();

        $result = (new \App\Services\Vip\VipService())->recoverUnapplied();
        if (empty($result['ok'])) {
            $_SESSION['error'] = t('admin.vip_recover_disabled');
            $this->redirect('/admin');
            return;
        }
        $_SESSION['flash'] = t('admin.vip_recovered', ['n' => (string) ($result['applied'] ?? 0)]);
        $this->redirect('/admin');
    }

    private function requireAdsAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\Ads\AdPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function ads(): void
    {
        $this->requireAdsAdmin();
        $n = new Notification();
        $this->view('admin/ads', [
            'title' => t('admin.ads_queue'),
            'currentNav' => 'admin',
            'rows' => (new \App\Services\Ads\AdService())->pendingModeration(),
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function moderateAd(string $id): void
    {
        $this->requireAdsAdmin();
        $approve = (string) ($_POST['decision'] ?? '') === 'approve';
        $result = (new \App\Services\Ads\AdService())->moderate((int) $id, $approve, (string) ($_POST['reason'] ?? ''));
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t('admin.ads_moderate_failed')
            : ($approve ? t('admin.ads_approved') : t('admin.ads_rejected'));
        $this->redirect('/admin/ads');
    }

    public function toggleAd(string $format): void
    {
        $this->requireAdsAdmin();
        if (!\App\Services\Ads\AdPolicy::isFormat($format)) {
            http_response_code(404);
            echo 'Не найдено';
            exit;
        }
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\Ads\AdService())->setEnabled($format, $enabled);
        $name = t('ads.format_' . $format);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.ads_enabled_flash', ['name' => $name]) : t('admin.ads_disabled_flash', ['name' => $name]))
            : t('admin.ads_toggle_failed');
        $this->redirect('/admin');
    }

    public function recoverAd(string $format): void
    {
        $this->requireAdsAdmin();
        $result = (new \App\Services\Ads\AdService())->recoverUnapplied($format);
        if (empty($result['ok'])) {
            $_SESSION['error'] = t('admin.ads_recover_disabled');
            $this->redirect('/admin');
            return;
        }
        $_SESSION['flash'] = t('admin.ads_recovered', ['n' => (string) ($result['applied'] ?? 0)]);
        $this->redirect('/admin');
    }

    private function requireTargetPushAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\TargetPush\TargetPushPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function targetPush(): void
    {
        $this->requireTargetPushAdmin();
        $service = new \App\Services\TargetPush\TargetPushService();
        $n = new Notification();
        $this->view('admin/target-push', [
            'title' => t('admin.target_push_queue'),
            'currentNav' => 'admin',
            'rows' => $service->adminList(),
            'enabled' => $service->isEnabled(),
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function moderateTargetPush(string $id): void
    {
        $this->requireTargetPushAdmin();
        $approve = (string) ($_POST['decision'] ?? '') === 'approve';
        $result = (new \App\Services\TargetPush\TargetPushService())->moderate((int) $id, $approve, (string) ($_POST['reason'] ?? ''));
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t('admin.target_push_moderate_failed')
            : ($approve ? t('admin.target_push_approved') : t('admin.target_push_rejected'));
        $this->redirect('/admin/target-push');
    }

    public function toggleTargetPush(): void
    {
        $this->requireTargetPushAdmin();
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\TargetPush\TargetPushService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.target_push_enabled_flash') : t('admin.target_push_disabled_flash'))
            : t('admin.target_push_toggle_failed');
        $this->redirect('/admin');
    }

    private function requireMassPushAdmin(): void
    {
        Auth::requireLogin();
        $role = (string) (Auth::user()['role'] ?? '');
        if (!\App\Services\MassPush\MassPushPolicy::canToggle($role)) {
            http_response_code(403);
            echo 'Доступ запрещён';
            exit;
        }
    }

    public function massPush(): void
    {
        $this->requireMassPushAdmin();
        $service = new \App\Services\MassPush\MassPushService();
        $n = new Notification();
        $this->view('admin/mass-push', [
            'title' => t('admin.mass_push_queue'),
            'currentNav' => 'admin',
            'rows' => $service->adminList(),
            'enabled' => $service->isEnabled(),
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function moderateMassPush(string $id): void
    {
        $this->requireMassPushAdmin();
        $approve = (string) ($_POST['decision'] ?? '') === 'approve';
        $result = (new \App\Services\MassPush\MassPushService())->moderate((int) $id, $approve, (string) ($_POST['reason'] ?? ''));
        $_SESSION[empty($result['ok']) ? 'error' : 'flash'] = empty($result['ok'])
            ? t('admin.mass_push_moderate_failed')
            : ($approve ? t('admin.mass_push_approved') : t('admin.mass_push_rejected'));
        $this->redirect('/admin/mass-push');
    }

    public function toggleMassPush(): void
    {
        $this->requireMassPushAdmin();
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new \App\Services\MassPush\MassPushService())->setEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.mass_push_enabled_flash') : t('admin.mass_push_disabled_flash'))
            : t('admin.mass_push_toggle_failed');
        $this->redirect('/admin');
    }

    public function gigCategories(): void
    {
        Auth::requireAdmin();
        $model = new MicroTask();
        $n = new Notification();
        $all = $model->listCategoriesWithCounts();
        $parentChoices = [];
        foreach ($all as $row) {
            if ((int) ($row['depth'] ?? 0) < 2) {
                $parentChoices[] = $row;
            }
        }

        $perPage = 20;
        $total = count($all);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pages = max(1, (int) ceil(max($total, 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $categories = array_slice($all, ($page - 1) * $perPage, $perPage);

        $this->view('admin/gig-categories', [
            'title' => t('admin.gig_categories'),
            'currentNav' => 'admin',
            'categories' => $categories,
            'parentChoices' => $parentChoices,
            'page' => $page,
            'pages' => $pages,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function gigCategoryCreate(): void
    {
        Auth::requireAdmin();
        $name = trim((string) ($_POST['name'] ?? ''));
        $unskilled = isset($_POST['is_unskilled_only']);
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $error = $this->validateGigCategoryName($name);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/admin/gig-categories');
            return;
        }

        $model = new MicroTask();
        $parent = null;
        if ($parentId > 0) {
            $parent = $model->findCategory($parentId);
            if (!$parent) {
                $_SESSION['error'] = t('admin.gig_cat_not_found');
                $this->redirect('/admin/gig-categories');
                return;
            }
            if ($model->categoryDepth($parentId) >= 2) {
                $_SESSION['error'] = t('admin.gig_cat_max_depth');
                $this->redirect('/admin/gig-categories');
                return;
            }
        }

        $resolvedParent = $parentId > 0 ? $parentId : null;
        if ($model->categoryNameExists($name, null, $resolvedParent)) {
            $_SESSION['error'] = t('admin.gig_cat_exists');
            $this->redirect('/admin/gig-categories');
            return;
        }

        $id = $model->createCategory($name, $unskilled, $resolvedParent);
        ActivityLogger::info('admin.gig_category_create', 'Добавлена категория биржи: ' . $name, 'micro_category', $id);
        $_SESSION['flash'] = t('admin.gig_cat_created');
        $this->redirect('/admin/gig-categories');
    }

    public function gigCategoryUpdate(string $id): void
    {
        Auth::requireAdmin();
        $catId = (int) $id;
        $model = new MicroTask();
        $category = $model->findCategory($catId);
        if (!$category) {
            $_SESSION['error'] = t('admin.gig_cat_not_found');
            $this->redirect('/admin/gig-categories');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $unskilled = isset($_POST['is_unskilled_only']);
        $parentId = (int) ($_POST['parent_id'] ?? 0);
        $error = $this->validateGigCategoryName($name);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/admin/gig-categories');
            return;
        }

        if ($parentId === $catId) {
            $_SESSION['error'] = t('admin.gig_cat_parent_self');
            $this->redirect('/admin/gig-categories');
            return;
        }

        $resolvedParent = null;
        if ($parentId > 0) {
            $parent = $model->findCategory($parentId);
            if (!$parent) {
                $_SESSION['error'] = t('admin.gig_cat_not_found');
                $this->redirect('/admin/gig-categories');
                return;
            }
            if (in_array($parentId, $model->descendantIds($catId), true)) {
                $_SESSION['error'] = t('admin.gig_cat_parent_self');
                $this->redirect('/admin/gig-categories');
                return;
            }
            if ($model->categoryDepth($parentId) >= 2) {
                $_SESSION['error'] = t('admin.gig_cat_max_depth');
                $this->redirect('/admin/gig-categories');
                return;
            }
            $resolvedParent = $parentId;
        }

        if ($model->categoryNameExists($name, $catId, $resolvedParent)) {
            $_SESSION['error'] = t('admin.gig_cat_exists');
            $this->redirect('/admin/gig-categories');
            return;
        }

        $model->updateCategory($catId, $name, $unskilled, $resolvedParent);
        ActivityLogger::info('admin.gig_category_update', 'Обновлена категория биржи: ' . $name, 'micro_category', $catId);
        $_SESSION['flash'] = t('admin.gig_cat_updated');
        $this->redirect('/admin/gig-categories');
    }

    public function gigCategoryDelete(string $id): void
    {
        Auth::requireAdmin();
        $catId = (int) $id;
        $model = new MicroTask();
        $category = $model->findCategory($catId);
        if (!$category) {
            $_SESSION['error'] = t('admin.gig_cat_not_found');
            $this->redirect('/admin/gig-categories');
            return;
        }

        if ($model->categoryTaskCount($catId) > 0 || $model->categoryHasChildren($catId)) {
            $_SESSION['error'] = t('admin.gig_cat_in_use');
            $this->redirect('/admin/gig-categories');
            return;
        }

        if ($model->deleteCategory($catId)) {
            ActivityLogger::info(
                'admin.gig_category_delete',
                'Удалена категория биржи: ' . ($category['name'] ?? ''),
                'micro_category',
                $catId
            );
            $_SESSION['flash'] = t('admin.gig_cat_deleted');
        } else {
            $_SESSION['error'] = t('admin.gig_cat_delete_failed');
        }
        $this->redirect('/admin/gig-categories');
    }

    private function validateGigCategoryName(string $name): ?string
    {
        $len = mb_strlen($name, 'UTF-8');
        if ($len < 2 || $len > 180) {
            return t('admin.gig_cat_name_len');
        }

        $word = (new UnskilledTaskValidator(Database::connect()))->findStopWord($name);
        if ($word !== null) {
            return t('gigs.err_stopword', ['word' => $word]);
        }

        return null;
    }

    public function logs(): void
    {
        Auth::requireAdmin();

        $level = isset($_GET['level']) ? strtolower(trim((string) $_GET['level'])) : '';
        if ($level === 'all') {
            $level = '';
        }
        $action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $logModel = new ActivityLog();
        $result = $logModel->search([
            'level' => $level,
            'action' => $action,
            'q' => $q,
            'user_id' => $userId > 0 ? $userId : null,
        ], $page, 40);

        $n = new Notification();
        $this->view('admin/logs', [
            'title' => t('admin.logs'),
            'currentNav' => 'admin',
            'logs' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'levelCounts' => $logModel->countByLevel(),
            'actionPrefixes' => $logModel->distinctActionPrefixes(),
            'filterLevel' => $level !== '' ? $level : null,
            'filterAction' => $action,
            'searchQuery' => $q,
            'filterUserId' => $userId > 0 ? $userId : null,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'search' => '',
        ]);
    }

    public function tickets(): void
    {
        Auth::requirePermission('tickets');
        $status = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : null;
        if ($status === 'all' || $status === '') {
            $status = null;
        }

        $support = new SupportTicket();
        $n = new Notification();
        $uid = Auth::id();

        $this->view('admin/tickets', [
            'title' => t('admin.tickets'),
            'currentNav' => 'admin',
            'tickets' => $support->listAll($status),
            'filterStatus' => $status,
            'ticketUnread' => $support->unreadCountForAdmin(),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function ticketShow(string $id): void
    {
        Auth::requirePermission('tickets');
        $support = new SupportTicket();
        $ticketId = (int) $id;
        $ticket = $support->findForAdmin($ticketId);

        if (!$ticket) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('support.not_found')]);
            return;
        }

        $support->markReadByAdmin($ticketId);
        $n = new Notification();
        $uid = Auth::id();

        $this->view('admin/ticket-show', [
            'title' => t('support.ticket_title', ['number' => $ticket['ticket_number']]),
            'currentNav' => 'admin',
            'ticket' => $ticket,
            'messages' => $support->messages($ticketId),
            'ticketUnread' => $support->unreadCountForAdmin(),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function ticketReply(string $id): void
    {
        Auth::requirePermission('tickets');
        $ticketId = (int) $id;
        $body = (string) ($_POST['body'] ?? '');
        $support = new SupportTicket();

        $result = $support->replyAsAdmin($ticketId, Auth::id(), $body);
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('support.send_failed');
            $this->redirect('/admin/tickets/' . $ticketId);
            return;
        }

        $ticket = $support->findForAdmin($ticketId);
        if ($ticket) {
            $notify = new Notification();
            $notify->createFor(
                (int) $ticket['user_id'],
                t('support.notify_reply', ['number' => $ticket['ticket_number']])
            );
            $this->sendReplyEmail($ticket, (string) ($result['message']['body'] ?? $body));
        }

        $_SESSION['flash'] = t('admin.ticket_replied');
        $this->redirect('/admin/tickets/' . $ticketId);
    }

    public function ticketClose(string $id): void
    {
        Auth::requirePermission('tickets');
        $ticketId = (int) $id;
        $support = new SupportTicket();
        $ticket = $support->findForAdmin($ticketId);

        if ($ticket) {
            $support->close($ticketId);
            (new Notification())->createFor(
                (int) $ticket['user_id'],
                t('support.notify_closed', ['number' => $ticket['ticket_number']])
            );
            $_SESSION['flash'] = t('admin.ticket_closed');
        }

        $this->redirect('/admin/tickets/' . $ticketId);
    }

    public function ticketReopen(string $id): void
    {
        Auth::requirePermission('tickets');
        $ticketId = (int) $id;
        $support = new SupportTicket();
        if ($support->reopen($ticketId)) {
            $_SESSION['flash'] = t('admin.ticket_reopened');
        }
        $this->redirect('/admin/tickets/' . $ticketId);
    }

    public function aiChats(): void
    {
        Auth::requirePermission('ai_chats');
        $status = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'human_escalated';
        if ($status === 'all' || $status === '') {
            $status = null;
        }

        $ai = new AiSupport();
        $n = new Notification();
        $uid = Auth::id();

        $this->view('admin/ai-chats', [
            'title' => t('admin.ai_chats'),
            'currentNav' => 'admin',
            'conversations' => $ai->listForAdmin($status),
            'filterStatus' => $status,
            'aiEscalated' => $ai->countEscalated(),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function aiChatShow(string $id): void
    {
        Auth::requirePermission('ai_chats');
        $ai = new AiSupport();
        $conversationId = (int) $id;
        $conversation = $ai->getConversationById($conversationId);

        if (!$conversation) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('admin.ai_chat_not_found')]);
            return;
        }

        if (empty($conversation['assigned_agent_id']) && ($conversation['status'] ?? '') === 'human_escalated') {
            $ai->assignAgent($conversationId, Auth::id());
            $conversation = $ai->getConversationById($conversationId);
        }

        $messages = $ai->getMessages($conversationId, 200);
        $n = new Notification();

        $userName = null;
        $userEmail = null;
        if (!empty($conversation['user_id'])) {
            $user = (new User())->find((int) $conversation['user_id']);
            $userName = $user['name'] ?? null;
            $userEmail = $user['email'] ?? null;
        }

        $this->view('admin/ai-chat-show', [
            'title' => t('admin.ai_chat') . ' #' . $conversationId,
            'currentNav' => 'admin',
            'conversation' => $conversation,
            'messages' => $messages,
            'userName' => $userName,
            'userEmail' => $userEmail,
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function aiChatReply(string $id): void
    {
        Auth::requirePermission('ai_chats');
        $conversationId = (int) $id;
        $body = trim((string) ($_POST['body'] ?? ''));
        $close = !empty($_POST['close']);

        if ($body === '') {
            $_SESSION['error'] = t('admin.ai_reply_required');
            $this->redirect('/admin/ai-chats/' . $conversationId);
            return;
        }

        if (mb_strlen($body, 'UTF-8') > 4000) {
            $_SESSION['error'] = t('admin.ai_reply_too_long');
            $this->redirect('/admin/ai-chats/' . $conversationId);
            return;
        }

        $ai = new AiSupport();
        $conversation = $ai->getConversationById($conversationId);
        if (!$conversation) {
            $_SESSION['error'] = t('admin.ai_chat_not_found');
            $this->redirect('/admin/ai-chats');
            return;
        }

        $ai->assignAgent($conversationId, Auth::id());
        $ai->addMessage($conversationId, 'agent', $body, 1.0, Auth::id());

        if ($close) {
            $ai->updateStatus($conversationId, 'closed', Auth::id());
            (new SelfLearningService())->learnFromOperatorResolution($conversationId);
            $_SESSION['flash'] = t('admin.ai_closed_learned');
        } else {
            $_SESSION['flash'] = t('admin.ai_replied');
        }

        $this->redirect('/admin/ai-chats/' . $conversationId);
    }

    public function aiChatClose(string $id): void
    {
        Auth::requirePermission('ai_chats');
        $conversationId = (int) $id;
        $ai = new AiSupport();
        $conversation = $ai->getConversationById($conversationId);

        if ($conversation) {
            $ai->updateStatus($conversationId, 'closed', Auth::id());
            (new SelfLearningService())->learnFromOperatorResolution($conversationId);
            $_SESSION['flash'] = t('admin.ai_closed_learned');
        }

        $this->redirect('/admin/ai-chats/' . $conversationId);
    }

    public function aiExportDataset(): void
    {
        Auth::requirePermission('ai_chats');
        $jsonl = (new SelfLearningService())->exportJsonlDataset();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="zakopeyki_ai_dataset_' . date('Y-m-d') . '.jsonl"');
        echo $jsonl;
        exit;
    }

    public function aiDashboard(): void
    {
        Auth::requirePermission('ai_chats');
        $hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 24;
        $metrics = (new \App\Services\AI\AiMetricsService())->dashboard($hours);
        $candidates = (new \App\Services\AI\Learning\PromptOptimizer())->listCandidates();
        $evalSummary = (new \App\Services\AI\Learning\EvaluationService())->summary(7);
        $n = new Notification();
        $uid = Auth::id();

        $this->view('admin/ai-dashboard', [
            'title' => 'AI Dashboard',
            'currentNav' => 'admin',
            'metrics' => $metrics,
            'candidates' => $candidates,
            'evalSummary' => $evalSummary,
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function aiPromptRollback(): void
    {
        Auth::requirePermission('ai_chats');
        $version = trim((string) ($_POST['version'] ?? ''));
        if ($version === '') {
            $_SESSION['error'] = 'Укажите version';
            $this->redirect('/admin/ai');
        }

        $pdo = \App\Core\Database::connect();
        $promptId = (int) $pdo->query(
            "SELECT id FROM ai_prompts WHERE slug = 'assistant_system' LIMIT 1"
        )->fetchColumn();
        if ($promptId <= 0) {
            $_SESSION['error'] = 'Промпт не найден';
            $this->redirect('/admin/ai');
        }

        $check = $pdo->prepare(
            'SELECT id FROM ai_prompt_versions WHERE prompt_id = ? AND version = ? LIMIT 1'
        );
        $check->execute([$promptId, $version]);
        if (!$check->fetch()) {
            $_SESSION['error'] = 'Версия не найдена';
            $this->redirect('/admin/ai');
        }

        $pdo->prepare(
            "UPDATE ai_prompt_versions SET status = 'archived' WHERE prompt_id = ? AND status = 'active'"
        )->execute([$promptId]);
        $pdo->prepare(
            "UPDATE ai_prompt_versions SET status = 'active' WHERE prompt_id = ? AND version = ?"
        )->execute([$promptId, $version]);

        $_SESSION['flash'] = 'Активный промпт: ' . $version;
        $this->redirect('/admin/ai');
    }

    public function aiReindexKnowledge(): void
    {
        Auth::requirePermission('ai_chats');
        try {
            $stats = (new \App\Services\AI\RagEngine(
                null,
                new \App\Services\AI\Providers\OllamaProvider()
            ))->reindexAll();
            $_SESSION['flash'] = 'Индексация: docs=' . ($stats['documents'] ?? 0)
                . ', chunks=' . ($stats['chunks'] ?? 0)
                . ', embedded=' . ($stats['embedded'] ?? 0);
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Индексация не удалась. Проверьте Ollama.';
        }
        $this->redirect('/admin/ai');
    }

    public function aiProcessLearning(): void
    {
        Auth::requirePermission('ai_chats');
        try {
            $stats = (new \App\Services\AI\Learning\LearningPipeline())->processPending(100);
            $msg = 'Learning: processed=' . ($stats['processed'] ?? 0)
                . ', failed=' . ($stats['failed'] ?? 0)
                . ', skipped=' . ($stats['skipped'] ?? 0);
            if (!empty($stats['candidates'])) {
                $msg .= '. Candidates: ' . implode(', ', $stats['candidates']);
            }
            $_SESSION['flash'] = $msg;
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Learning pipeline error';
        }
        $this->redirect('/admin/ai');
    }

    public function aiApprovePromptCandidate(): void
    {
        Auth::requirePermission('ai_chats');
        $version = trim((string) ($_POST['version'] ?? ''));
        if ($version === '') {
            $_SESSION['error'] = 'Укажите version кандидата';
            $this->redirect('/admin/ai');
        }

        $result = (new \App\Services\AI\Learning\PromptOptimizer())->approveCandidate($version);
        if (empty($result['ok'])) {
            $_SESSION['error'] = 'Не удалось активировать: ' . ($result['error'] ?? 'unknown');
        } else {
            $_SESSION['flash'] = 'Кандидат активирован: ' . $version;
        }
        $this->redirect('/admin/ai');
    }

    public function users(): void
    {
        Auth::requireAdmin();
        $role = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : null;
        if ($role === 'all' || $role === '') {
            $role = null;
        }
        if ($role !== null && !in_array($role, ['admin', 'manager', 'user'], true)) {
            $role = null;
        }
        $access = isset($_GET['access']) ? strtolower(trim((string) $_GET['access'])) : null;
        $siteAccessFilter = null;
        if ($access === 'open') {
            $siteAccessFilter = true;
            $role = null;
        } elseif ($access === 'closed') {
            $siteAccessFilter = false;
            $role = null;
        }
        $q = trim((string) ($_GET['q'] ?? ''));

        $userModel = new User();
        $n = new Notification();
        $uid = Auth::id();

        $userStats = $userModel->registrationStats();
        $userStats['logins_today'] = 0;
        $userStats['logins_week'] = 0;
        try {
            $log = new ActivityLog();
            $userStats['logins_today'] = $log->countUniqueLoginsSince('CURDATE()');
            $userStats['logins_week'] = $log->countUniqueLoginsSince('(CURDATE() - INTERVAL 7 DAY)');
        } catch (\Throwable) {
            // ignore
        }

        $this->view('admin/users', [
            'title' => t('admin.users'),
            'currentNav' => 'admin',
            'users' => $userModel->listForAdmin($role, $q !== '' ? $q : null, $siteAccessFilter),
            'filterRole' => $role,
            'filterAccess' => $access === 'open' || $access === 'closed' ? $access : null,
            'searchQuery' => $q,
            'userCount' => $userStats['total'],
            'siteAccessCount' => $userStats['site_access'],
            'userStats' => $userStats,
            'stubMode' => !empty($GLOBALS['appConfig']['stub_mode']),
            'permissionKeys' => Auth::PERMISSIONS,
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function aml(): void
    {
        Auth::requireAdmin();
        AMLService::make()->ensureSchema();

        $status = strtolower(trim((string) ($_GET['status'] ?? 'blocked')));
        $amlFilter = match ($status) {
            'clear' => 'clear',
            'pending' => 'pending',
            'all' => null,
            default => 'AML_BLOCKED',
        };
        if ($status !== 'clear' && $status !== 'pending' && $status !== 'all') {
            $status = 'blocked';
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        $userModel = new User();
        $aml = AMLService::make();
        $logModel = new ActivityLog();
        $n = new Notification();

        $this->view('admin/aml', [
            'title' => t('admin.aml'),
            'currentNav' => 'admin',
            'users' => $userModel->listForAdmin(null, $q !== '' ? $q : null, null, $amlFilter),
            'filterStatus' => $status,
            'searchQuery' => $q,
            'amlUserStats' => $userModel->amlStats(),
            'amlListStats' => $aml->listStats(),
            'amlEvents' => $logModel->search(['action' => 'aml'], 1, 30)['items'],
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function userClearAml(string $id): void
    {
        Auth::requireAdmin();
        $userId = (int) $id;
        $userModel = new User();
        $user = $userModel->find($userId);
        if (!$user) {
            $_SESSION['error'] = t('admin.user_not_found');
            $this->redirect('/admin/aml');
        }

        if (($user['aml_status'] ?? '') !== AMLService::STATUS_BLOCKED) {
            $_SESSION['error'] = t('admin.aml_not_blocked');
            $this->redirect('/admin/users/' . $userId);
        }

        if ($userModel->clearAmlBlock($userId)) {
            ActivityLogger::warning(
                'aml.unblocked',
                'Админ снял AML_BLOCKED',
                'user',
                $userId,
                ['admin_id' => Auth::id()]
            );
            $_SESSION['flash'] = t('admin.aml_unblocked');
        } else {
            $_SESSION['error'] = t('admin.aml_unblock_failed');
        }
        $this->redirect('/admin/users/' . $userId);
    }

    public function userShow(string $id): void
    {
        Auth::requireAdmin();
        $userId = (int) $id;
        $userModel = new User();
        $user = $userModel->find($userId);

        if (!$user) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('admin.user_not_found')]);
            return;
        }

        $n = new Notification();
        $uid = Auth::id();
        $userPerms = Auth::normalizePermissions($user['permissions'] ?? null, (string) ($user['role'] ?? 'user'));
        $amlEvents = [];
        try {
            $amlEvents = (new ActivityLog())->search([
                'action' => 'aml',
                'user_id' => $userId,
            ], 1, 20)['items'];
        } catch (\Throwable) {
        }

        $this->view('admin/user-show', [
            'title' => t('admin.user') . ' #' . $userId,
            'currentNav' => 'admin',
            'user' => $user,
            'amlEvents' => $amlEvents,
            'userPermissions' => $userPerms,
            'permissionKeys' => Auth::PERMISSIONS,
            'adminCount' => $userModel->countAdmins(),
            'isSelf' => $userId === $uid,
            'stubMode' => !empty($GLOBALS['appConfig']['stub_mode']),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function userToggleSiteAccess(string $id): void
    {
        Auth::requireAdmin();
        $userId = (int) $id;
        $userModel = new User();
        $user = $userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = t('admin.user_not_found');
            $this->redirect('/admin/users');
            return;
        }

        if (($user['role'] ?? '') === 'admin') {
            $_SESSION['flash'] = t('admin.user_site_access_admin');
            $this->redirect('/admin/users/' . $userId);
            return;
        }

        $allow = isset($_POST['allow']) && (string) $_POST['allow'] === '1';
        if ($userModel->setSiteAccess($userId, $allow)) {
            ActivityLogger::info(
                'admin.user_site_access',
                $allow
                    ? 'Выдан доступ к сайту: ' . ($user['email'] ?? ('#' . $userId))
                    : 'Снят доступ к сайту: ' . ($user['email'] ?? ('#' . $userId)),
                'user',
                $userId,
                ['site_access' => $allow]
            );
            $_SESSION['flash'] = $allow
                ? t('admin.user_site_access_granted')
                : t('admin.user_site_access_revoked');
        } else {
            $_SESSION['error'] = t('admin.user_site_access_failed');
        }

        $back = trim((string) ($_POST['redirect'] ?? ''));
        if ($back === 'list') {
            $this->redirect('/admin/users');
            return;
        }
        $this->redirect('/admin/users/' . $userId);
    }

    public function userCreate(): void
    {
        Auth::requireAdmin();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $login = trim((string) ($_POST['login'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $role = User::normalizeRole((string) ($_POST['role'] ?? 'user'));
        $permissions = $this->permissionsFromPost();

        if ($name === '' || mb_strlen($name, 'UTF-8') < 2) {
            $_SESSION['error'] = t('admin.user_name_required');
            $this->redirect('/admin/users');
            return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = t('admin.user_email_invalid');
            $this->redirect('/admin/users');
            return;
        }
        if (strlen($password) < 6) {
            $_SESSION['error'] = t('admin.user_password_short');
            $this->redirect('/admin/users');
            return;
        }

        $userModel = new User();
        if ($userModel->findByEmail($email)) {
            $_SESSION['error'] = t('admin.user_email_taken');
            $this->redirect('/admin/users');
            return;
        }
        if ($login !== '' && $userModel->findByLogin($login)) {
            $_SESSION['error'] = t('admin.user_login_taken');
            $this->redirect('/admin/users');
            return;
        }

        try {
            $newId = $userModel->createByAdmin([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'login' => $login,
                'phone' => $phone,
                'role' => $role,
                'permissions' => $permissions,
            ]);
            ActivityLogger::info('admin.user_create', 'Создан пользователь ' . $email, 'user', $newId, [
                'role' => $role,
            ]);
            $_SESSION['flash'] = t('admin.user_created');
            $this->redirect('/admin/users/' . $newId);
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'admin.user_create');
            $_SESSION['error'] = t('admin.user_create_failed');
            $this->redirect('/admin/users');
        }
    }

    public function userUpdateRole(string $id): void
    {
        Auth::requireAdmin();
        $userId = (int) $id;
        $role = User::normalizeRole((string) ($_POST['role'] ?? 'user'));
        $permissions = $this->permissionsFromPost();
        $userModel = new User();
        $user = $userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = t('admin.user_not_found');
            $this->redirect('/admin/users');
            return;
        }

        $currentRole = (string) ($user['role'] ?? 'user');
        if ($currentRole === $role && $role !== 'manager') {
            $_SESSION['flash'] = t('admin.user_role_unchanged');
            $this->redirect('/admin/users/' . $userId);
            return;
        }

        if ($currentRole === 'admin' && $role !== 'admin') {
            if ($userId === Auth::id()) {
                $_SESSION['error'] = t('admin.user_cannot_demote_self');
                $this->redirect('/admin/users/' . $userId);
                return;
            }
            if ($userModel->countAdmins() <= 1) {
                $_SESSION['error'] = t('admin.user_last_admin');
                $this->redirect('/admin/users/' . $userId);
                return;
            }
        }

        if ($role === 'manager' && $permissions === [] && isset($_POST['permissions'])) {
            // keep existing if form didn't send checkboxes (role-only button)
            $permissions = Auth::normalizePermissions($user['permissions'] ?? null, 'manager');
            if ($permissions === []) {
                $permissions = ['tickets'];
            }
        }

        if ($userModel->updateRole($userId, $role, $permissions)) {
            ActivityLogger::info(
                'admin.user_role',
                'Роль ' . ($user['name'] ?? '') . ': ' . $currentRole . ' → ' . $role,
                'user',
                $userId,
                ['from' => $currentRole, 'to' => $role, 'permissions' => $permissions]
            );
            $_SESSION['flash'] = t('admin.user_role_updated');
        } else {
            $_SESSION['error'] = t('admin.user_role_failed');
        }
        $this->redirect('/admin/users/' . $userId);
    }

    public function userUpdatePermissions(string $id): void
    {
        Auth::requireAdmin();
        $userId = (int) $id;
        $userModel = new User();
        $user = $userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = t('admin.user_not_found');
            $this->redirect('/admin/users');
            return;
        }

        if (($user['role'] ?? '') !== 'manager') {
            $_SESSION['error'] = t('admin.user_perms_only_manager');
            $this->redirect('/admin/users/' . $userId);
            return;
        }

        $permissions = $this->permissionsFromPost();
        if ($userModel->updatePermissions($userId, $permissions)) {
            ActivityLogger::info(
                'admin.user_role',
                'Обновлены доступы менеджера ' . ($user['name'] ?? ''),
                'user',
                $userId,
                ['permissions' => $permissions]
            );
            $_SESSION['flash'] = t('admin.user_perms_updated');
        } else {
            $_SESSION['error'] = t('admin.user_perms_failed');
        }
        $this->redirect('/admin/users/' . $userId);
    }

    public function userDelete(string $id): void
    {
        Auth::requireAdmin();
        $userId = (int) $id;
        $userModel = new User();
        $user = $userModel->find($userId);

        if (!$user) {
            $_SESSION['error'] = t('admin.user_not_found');
            $this->redirect('/admin/users');
            return;
        }

        if ($userId === Auth::id()) {
            $_SESSION['error'] = t('admin.user_cannot_delete_self');
            $this->redirect('/admin/users/' . $userId);
            return;
        }

        if (($user['role'] ?? '') === 'admin' && $userModel->countAdmins() <= 1) {
            $_SESSION['error'] = t('admin.user_last_admin');
            $this->redirect('/admin/users/' . $userId);
            return;
        }

        if ($userModel->deleteAccount($userId)) {
            ActivityLogger::info(
                'admin.user_delete',
                'Удалён пользователь ' . ($user['email'] ?? '') . ' (' . ($user['name'] ?? '') . ')',
                'user',
                $userId
            );
            $_SESSION['flash'] = t('admin.user_deleted');
            $this->redirect('/admin/users');
            return;
        }

        $_SESSION['error'] = t('admin.user_delete_failed');
        $this->redirect('/admin/users/' . $userId);
    }

    public function delete(string $id): void
    {
        Auth::requirePermission('products');
        $product = (new Product())->find((int) $id);
        (new Product())->delete((int) $id);
        ActivityLogger::info(
            'admin.product_delete',
            'Админ удалил лот «' . ($product['title'] ?? ('#' . $id)) . '»',
            'product',
            (int) $id
        );
        $_SESSION['flash'] = 'Товар удалён';
        $this->redirect('/admin');
    }

    public function toggleStatus(string $id): void
    {
        Auth::requirePermission('products');
        $model = new Product();
        $item = $model->find((int) $id);
        if ($item) {
            $status = $item['status'] === 'active' ? 'archived' : 'active';
            $model->updateProduct((int) $id, array_merge($item, ['status' => $status]));
            ActivityLogger::info(
                'admin.product_toggle',
                'Статус лота «' . ($item['title'] ?? '') . '»: ' . $status,
                'product',
                (int) $id,
                ['status' => $status]
            );
            $_SESSION['flash'] = 'Статус обновлён';
        }
        $this->redirect('/admin');
    }

    /** @return list<string> */
    private function permissionsFromPost(): array
    {
        $raw = $_POST['permissions'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        return Auth::normalizePermissions($raw, 'manager');
    }

    private function sendReplyEmail(array $ticket, string $replyBody): void
    {
        $email = trim((string) ($ticket['user_email'] ?? ''));
        if ($email === '') {
            return;
        }

        try {
            $mail = new Mail();
            $ticketUrl = $mail->absoluteUrl('/support/' . (int) $ticket['id']);
            $number = (string) $ticket['ticket_number'];
            $html = $mail->render('emails/support-ticket-created', [
                'name' => (string) ($ticket['user_name'] ?? ''),
                'ticketNumber' => $number,
                'subject' => (string) ($ticket['subject'] ?? ''),
                'ticketUrl' => $ticketUrl,
                'greeting' => t('support.mail_reply_greeting', [
                    'name' => (string) ($ticket['user_name'] ?? t('support.mail_user')),
                ]),
                'body' => t('support.mail_reply_body', ['number' => $number]),
                'cta' => t('support.mail_cta'),
                'hint' => mb_substr(trim($replyBody), 0, 280),
                'footer' => t('support.mail_footer'),
            ]);
            $text = t('support.mail_reply_text', [
                'number' => $number,
                'url' => $ticketUrl,
            ]);
            $mail->send(
                $email,
                t('support.mail_reply_subject', ['number' => $number]),
                $text,
                $html
            );
        } catch (\Throwable $e) {
            // ignore mail errors
        }
    }

    public function businessRequests(): void
    {
        Auth::requireAdmin();
        $status = (string) ($_GET['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }
        $model = new BusinessUpgradeRequest();
        $n = new Notification();
        $pkgSvc = new BusinessPackageService();
        $this->view('admin/business', [
            'title' => t('admin.business'),
            'currentNav' => 'admin',
            'requests' => $model->listByStatus($status),
            'pendingCount' => $model->countPending(),
            'filterStatus' => $status,
            'businessSalesEnabled' => $pkgSvc->isSalesEnabled(),
            'businessFeaturesEnabled' => $pkgSvc->isFeaturesEnabled(),
            'businessAiAddonSalesFlags' => $pkgSvc->aiAddonSalesFlags(),
            'notifications' => $n->forUser(Auth::id()),
            'unread' => $n->unreadCount(Auth::id()),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function businessApprove(string $id): void
    {
        Auth::requireAdmin();
        $result = (new BusinessUpgradeService())->approve((int) $id, Auth::id(), trim((string) ($_POST['note'] ?? '')));
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('admin.business_approved')
            : ($result['error'] ?? t('business.err_generic'));
        $this->redirect('/admin/business');
    }

    public function businessReject(string $id): void
    {
        Auth::requireAdmin();
        $result = (new BusinessUpgradeService())->reject(
            (int) $id,
            Auth::id(),
            trim((string) ($_POST['reason'] ?? ''))
        );
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('admin.business_rejected')
            : ($result['error'] ?? t('business.err_generic'));
        $this->redirect('/admin/business');
    }

    public function businessRevoke(string $id): void
    {
        Auth::requireAdmin();
        $result = (new BusinessUpgradeService())->revoke(
            (int) $id,
            Auth::id(),
            trim((string) ($_POST['reason'] ?? ''))
        );
        $_SESSION[$result['ok'] ? 'flash' : 'error'] = $result['ok']
            ? t('admin.business_revoked')
            : ($result['error'] ?? t('business.err_generic'));
        $this->redirect('/admin/business?status=approved');
    }

    public function resetPersonalLimits(): void
    {
        Auth::requireAdmin();
        $count = (new PersonalLimitService())->resetAllForNewYear();
        $_SESSION['flash'] = t('admin.business_limits_reset', ['count' => (string) $count]);
        $this->redirect('/admin/business');
    }

    public function toggleBusinessPackageSales(): void
    {
        Auth::requireAdmin();
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new BusinessPackageService())->setSalesEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.business_sales_enabled') : t('admin.business_sales_disabled'))
            : t('admin.business_toggle_failed');
        $this->redirect('/admin/business');
    }

    public function toggleBusinessPackageFeatures(): void
    {
        Auth::requireAdmin();
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $ok = (new BusinessPackageService())->setFeaturesEnabled($enabled);
        $_SESSION[$ok ? 'flash' : 'error'] = $ok
            ? ($enabled ? t('admin.business_features_enabled') : t('admin.business_features_disabled'))
            : t('admin.business_toggle_failed');
        $this->redirect('/admin/business');
    }

    public function toggleBusinessAiAddonSales(): void
    {
        Auth::requireAdmin();
        $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
        $slug = trim((string) ($_POST['package_slug'] ?? ''));
        $svc = new BusinessPackageService();
        if ($slug === '' || !BusinessPackagePolicy::isAiAddonSlug($slug)) {
            $_SESSION['error'] = t('admin.business_toggle_failed');
            $this->redirect('/admin/business');
        }
        $ok = $svc->setAiAddonSalesEnabledForSlug($slug, $enabled);
        if ($ok) {
            $_SESSION['flash'] = $enabled
                ? t('admin.business_ai_addon_sales_enabled_one', ['slug' => $slug])
                : t('admin.business_ai_addon_sales_disabled_one', ['slug' => $slug]);
        } else {
            $_SESSION['error'] = t('admin.business_toggle_failed');
        }
        $this->redirect('/admin/business');
    }

    public function streamSettings(): void
    {
        Auth::requireAdmin();
        $s = new Setting();
        $this->view('admin/stream', [
            'title' => t('admin.stream_title'),
            'currentNav' => 'admin',
            'accountId' => (string) $s->get('cf_stream_account_id', ''),
            'hasToken' => trim((string) $s->get('cf_stream_api_token', '')) !== '',
            'customerSubdomain' => (string) $s->get('cf_stream_customer_subdomain', ''),
            'signingKeyId' => (string) $s->get('cf_stream_signing_key_id', ''),
            'hasPem' => trim((string) $s->get('cf_stream_signing_key_pem', '')) !== '',
            'hasWebhook' => trim((string) $s->get('cf_stream_webhook_secret', '')) !== '',
            'requireSigned' => $s->getBool('cf_stream_require_signed', true) !== false,
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function streamSettingsSave(): void
    {
        Auth::requireAdmin();
        $s = new Setting();
        $s->set('cf_stream_account_id', trim((string) ($_POST['cf_stream_account_id'] ?? '')));
        $token = trim((string) ($_POST['cf_stream_api_token'] ?? ''));
        if ($token !== '') {
            $s->set('cf_stream_api_token', $token);
        }
        $s->set('cf_stream_customer_subdomain', trim((string) ($_POST['cf_stream_customer_subdomain'] ?? '')));
        $s->set('cf_stream_signing_key_id', trim((string) ($_POST['cf_stream_signing_key_id'] ?? '')));
        $pem = trim((string) ($_POST['cf_stream_signing_key_pem'] ?? ''));
        if ($pem !== '') {
            $s->set('cf_stream_signing_key_pem', $pem);
        }
        $wh = trim((string) ($_POST['cf_stream_webhook_secret'] ?? ''));
        if ($wh !== '') {
            $s->set('cf_stream_webhook_secret', $wh);
        }
        $s->set('cf_stream_require_signed', isset($_POST['cf_stream_require_signed']) ? '1' : '0');
        ActivityLogger::info('admin.stream_settings', 'Обновлены настройки Cloudflare Stream', 'settings', 0);
        $_SESSION['flash'] = t('admin.stream_saved');
        $this->redirect('/admin/stream');
    }
}
