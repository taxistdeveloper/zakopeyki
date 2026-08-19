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
use App\Models\MicroTask;
use App\Models\User;
use App\Services\UnskilledTaskValidator;
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
        $disputes = $canDisputes ? $orderModel->findByStatus('dispute') : [];

        $n = new Notification();
        $notifications = $n->forUser(Auth::id());
        $unread = $n->unreadCount(Auth::id());

        $recentErrors = 0;
        $stubMode = !empty($GLOBALS['appConfig']['stub_mode']);
        if ($isAdmin) {
            try {
                $userStats = $userModel->registrationStats();
                $userCount = $userStats['total'];
                $log = new ActivityLog();
                $recentErrors = $log->recentErrorCount(24);
                $userStats['logins_today'] = $log->countUniqueLoginsSince('CURDATE()');
                $userStats['logins_week'] = $log->countUniqueLoginsSince('(CURDATE() - INTERVAL 7 DAY)');
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
            'stubMode' => $stubMode,
            'canProducts' => $canProducts,
            'canTickets' => $canTickets,
            'canAi' => $canAi,
            'canDisputes' => $canDisputes,
            'isAdmin' => $isAdmin,
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

    public function gigCategories(): void
    {
        Auth::requireAdmin();
        $model = new MicroTask();
        $n = new Notification();
        $this->view('admin/gig-categories', [
            'title' => t('admin.gig_categories'),
            'currentNav' => 'admin',
            'categories' => $model->listCategoriesWithCounts(),
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
        $error = $this->validateGigCategoryName($name);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/admin/gig-categories');
            return;
        }

        $model = new MicroTask();
        if ($model->categoryNameExists($name)) {
            $_SESSION['error'] = t('admin.gig_cat_exists');
            $this->redirect('/admin/gig-categories');
            return;
        }

        $id = $model->createCategory($name, $unskilled);
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
        $error = $this->validateGigCategoryName($name);
        if ($error !== null) {
            $_SESSION['error'] = $error;
            $this->redirect('/admin/gig-categories');
            return;
        }

        if ($model->categoryNameExists($name, $catId)) {
            $_SESSION['error'] = t('admin.gig_cat_exists');
            $this->redirect('/admin/gig-categories');
            return;
        }

        $model->updateCategory($catId, $name, $unskilled);
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

        if ($model->categoryTaskCount($catId) > 0) {
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
        if ($len < 2 || $len > 100) {
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

        $this->view('admin/user-show', [
            'title' => t('admin.user') . ' #' . $userId,
            'currentNav' => 'admin',
            'user' => $user,
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
}
