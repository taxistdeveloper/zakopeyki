<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\Mail;
use App\Helpers\ProductHelper;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupportTicket;
use App\Models\User;
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
