<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\Mail;
use App\Models\Notification;
use App\Models\SupportTicket;

class SupportController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $tickets = new SupportTicket();
        $n = new Notification();

        $this->view('support/index', [
            'title' => t('support.title'),
            'currentNav' => 'help',
            'tickets' => $tickets->listForUser($uid),
            'supportUnread' => $tickets->unreadCountForUser($uid),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function createForm(): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $n = new Notification();
        $tickets = new SupportTicket();
        $category = strtolower(trim((string) ($_GET['category'] ?? 'general')));
        if (!in_array($category, SupportTicket::CATEGORIES, true)) {
            $category = 'general';
        }

        $this->view('support/create', [
            'title' => t('support.create_title'),
            'currentNav' => $category === 'idea' ? 'idea' : 'help',
            'category' => $category,
            'categories' => SupportTicket::CATEGORIES,
            'supportUnread' => $tickets->unreadCountForUser($uid),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'error' => $_SESSION['error'] ?? null,
            'old' => $_SESSION['old_support'] ?? [],
        ]);
        unset($_SESSION['error'], $_SESSION['old_support']);
    }

    public function store(): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $user = Auth::user();
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $category = strtolower(trim((string) ($_POST['category'] ?? 'general')));

        $_SESSION['old_support'] = [
            'subject' => $subject,
            'body' => $body,
            'category' => $category,
        ];

        $model = new SupportTicket();
        $result = $model->createTicket($uid, $subject, $body, $category);

        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('support.create_failed');
            $this->redirect('/support/new');
            return;
        }

        unset($_SESSION['old_support']);

        $ticketNumber = (string) ($result['ticket_number'] ?? '');
        $ticketId = (int) ($result['ticket_id'] ?? 0);

        ActivityLogger::info('support.create', 'Обращение ' . $ticketNumber . ': ' . $subject, 'ticket', $ticketId, [
            'category' => $category,
            'number' => $ticketNumber,
        ]);

        $this->sendCreatedEmail(
            (string) ($user['email'] ?? ''),
            (string) ($user['name'] ?? ''),
            $ticketNumber,
            $subject,
            $ticketId
        );

        $notify = new Notification();
        $notify->createFor($uid, t('support.notify_created', ['number' => $ticketNumber]));

        foreach ($model->adminUsers() as $admin) {
            if ((int) $admin['id'] === $uid) {
                continue;
            }
            $notify->createFor(
                (int) $admin['id'],
                t('support.notify_admin_new', [
                    'number' => $ticketNumber,
                    'name' => (string) ($user['name'] ?? ''),
                ])
            );
        }

        $_SESSION['flash'] = t('support.ticket_created', ['number' => $ticketNumber]);
        $this->redirect('/support/' . $ticketId);
    }

    public function show(string $id): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $model = new SupportTicket();
        $ticketId = (int) $id;

        $ticket = $model->findForUser($ticketId, $uid);
        if (!$ticket) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('support.not_found')]);
            return;
        }

        $model->markReadByUser($ticketId);
        $messages = $model->messages($ticketId);
        $n = new Notification();

        $this->view('support/show', [
            'title' => t('support.ticket_title', ['number' => $ticket['ticket_number']]),
            'currentNav' => 'help',
            'ticket' => $ticket,
            'messages' => $messages,
            'supportUnread' => $model->unreadCountForUser($uid),
            'notifications' => $n->forUser($uid),
            'unread' => $n->unreadCount($uid),
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function reply(string $id): void
    {
        Auth::requireLogin();
        $uid = Auth::id();
        $ticketId = (int) $id;
        $body = (string) ($_POST['body'] ?? '');
        $model = new SupportTicket();

        $result = $model->replyAsUser($ticketId, $uid, $body);
        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'] ?? t('support.send_failed');
            $this->redirect('/support/' . $ticketId);
            return;
        }

        $ticket = $model->findForUser($ticketId, $uid);
        $notify = new Notification();
        foreach ($model->adminUsers() as $admin) {
            if ((int) $admin['id'] === $uid) {
                continue;
            }
            $notify->createFor(
                (int) $admin['id'],
                t('support.notify_admin_reply', [
                    'number' => (string) ($ticket['ticket_number'] ?? ''),
                    'name' => (string) (Auth::user()['name'] ?? ''),
                ])
            );
        }

        $this->redirect('/support/' . $ticketId);
    }

    private function sendCreatedEmail(
        string $email,
        string $name,
        string $ticketNumber,
        string $subject,
        int $ticketId
    ): void {
        if ($email === '') {
            return;
        }

        try {
            $mail = new Mail();
            $ticketUrl = $mail->absoluteUrl('/support/' . $ticketId);
            $html = $mail->render('emails/support-ticket-created', [
                'name' => $name,
                'ticketNumber' => $ticketNumber,
                'subject' => $subject,
                'ticketUrl' => $ticketUrl,
                'greeting' => t('support.mail_greeting', ['name' => $name !== '' ? $name : t('support.mail_user')]),
                'body' => t('support.mail_body', ['number' => $ticketNumber]),
                'cta' => t('support.mail_cta'),
                'hint' => t('support.mail_hint'),
                'footer' => t('support.mail_footer'),
            ]);
            $text = t('support.mail_text', [
                'number' => $ticketNumber,
                'url' => $ticketUrl,
            ]);
            $mail->send(
                $email,
                t('support.mail_subject', ['number' => $ticketNumber]),
                $text,
                $html
            );
        } catch (\Throwable $e) {
            // Mail failure must not block ticket creation
        }
    }
}
