<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\Mail;
use App\Helpers\ProductHelper;
use App\Models\AiSupport;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AI\SelfLearningService;
use App\Services\EscrowService;

class AdminController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();
        (new EscrowService())->processDeadlines();

        $productModel = new Product();
        $userModel = new User();
        $orderModel = new Order();
        $support = new SupportTicket();
        $aiSupport = new AiSupport();

        $items = $productModel->all('created_at DESC');
        $counts = $productModel->countByType();
        $userCount = $userModel->countAll();
        $disputes = $orderModel->findByStatus('dispute');

        $n = new Notification();
        $notifications = $n->forUser(Auth::id());
        $unread = $n->unreadCount(Auth::id());

        $this->view('admin/index', [
            'title' => t('admin.title'),
            'currentNav' => 'admin',
            'items' => $items,
            'counts' => $counts,
            'userCount' => $userCount,
            'disputes' => $disputes,
            'openTickets' => $support->openCount(),
            'ticketUnread' => $support->unreadCountForAdmin(),
            'aiEscalated' => $aiSupport->countEscalated(),
            'types' => ProductHelper::TYPES,
            'notifications' => $notifications,
            'unread' => $unread,
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function tickets(): void
    {
        Auth::requireAdmin();
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
        Auth::requireAdmin();
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
        Auth::requireAdmin();
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
        Auth::requireAdmin();
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
        Auth::requireAdmin();
        $ticketId = (int) $id;
        $support = new SupportTicket();
        if ($support->reopen($ticketId)) {
            $_SESSION['flash'] = t('admin.ticket_reopened');
        }
        $this->redirect('/admin/tickets/' . $ticketId);
    }

    public function aiChats(): void
    {
        Auth::requireAdmin();
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
        Auth::requireAdmin();
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
        Auth::requireAdmin();
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
        Auth::requireAdmin();
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
        Auth::requireAdmin();
        $jsonl = (new SelfLearningService())->exportJsonlDataset();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="zakopeyki_ai_dataset_' . date('Y-m-d') . '.jsonl"');
        echo $jsonl;
        exit;
    }

    public function delete(string $id): void
    {
        Auth::requireAdmin();
        (new Product())->delete((int) $id);
        $_SESSION['flash'] = 'Товар удалён';
        $this->redirect('/admin');
    }

    public function toggleStatus(string $id): void
    {
        Auth::requireAdmin();
        $model = new Product();
        $item = $model->find((int) $id);
        if ($item) {
            $status = $item['status'] === 'active' ? 'archived' : 'active';
            $model->updateProduct((int) $id, array_merge($item, ['status' => $status]));
            $_SESSION['flash'] = 'Статус обновлён';
        }
        $this->redirect('/admin');
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
