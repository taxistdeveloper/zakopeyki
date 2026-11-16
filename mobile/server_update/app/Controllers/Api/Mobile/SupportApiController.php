<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\ActivityLogger;
use App\Models\Notification;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\MobileApi\Presenter;

/**
 * Обращения в поддержку из приложения: та же App\Models\SupportTicket, что у сайта (/support).
 */
final class SupportApiController extends BaseMobileController
{
    /** GET /support/tickets */
    public function index(): void
    {
        $uid = $this->requireUserId();
        $model = new SupportTicket();
        $this->ok([
            'items' => array_map(fn (array $t): array => $this->ticket($t), $model->listForUser($uid)),
            'unread' => $model->unreadCountForUser($uid),
            'categories' => array_map(
                static fn (string $c): array => ['code' => $c, 'label' => t('support.cat_' . $c)],
                SupportTicket::CATEGORIES
            ),
        ]);
    }

    /** GET /support/tickets/{id} */
    public function show(string $id): void
    {
        $uid = $this->requireUserId();
        $model = new SupportTicket();
        $ticket = $model->findForUser((int) $id, $uid);
        if (!$ticket) {
            $this->fail('not_found', 404);
        }
        $model->markReadByUser((int) $id);
        $this->ok([
            'ticket' => $this->ticket($ticket),
            'messages' => array_map(static fn (array $m): array => [
                'id' => (int) $m['id'],
                'from' => (string) $m['sender_type'], // user | admin | system
                'body' => (string) $m['body'],
                'created_at' => Presenter::date($m['created_at'] ?? null),
            ], $model->messages((int) $id)),
        ]);
    }

    /** POST /support/tickets {subject, body, category} */
    public function create(): void
    {
        $uid = $this->requireUserId();
        $model = new SupportTicket();
        $category = strtolower($this->input('category', 'general'));
        $subject = $this->input('subject');
        $res = $model->createTicket($uid, $subject, (string) ($this->body()['body'] ?? ''), $category);
        if (empty($res['ok'])) {
            $this->fail('support_failed', 422, (string) ($res['error'] ?? t('support.create_failed')));
        }
        $ticketId = (int) $res['ticket_id'];
        $number = (string) ($res['ticket_number'] ?? '');
        ActivityLogger::info('support.create', 'Обращение (mobile) ' . $number . ': ' . $subject, 'ticket', $ticketId, ['category' => $category]);

        $user = (new User())->find($uid) ?? [];
        $notify = new Notification();
        $notify->createFor($uid, t('support.notify_created', ['number' => $number]));
        foreach ($model->adminUsers() as $admin) {
            if ((int) $admin['id'] !== $uid) {
                $notify->createFor((int) $admin['id'], t('support.notify_admin_new', [
                    'number' => $number,
                    'name' => (string) ($user['name'] ?? ''),
                ]));
            }
        }
        $ticket = $model->findForUser($ticketId, $uid) ?? [];
        $this->ok(['ticket' => $this->ticket($ticket)], [], 201);
    }

    /** POST /support/tickets/{id}/reply {body} */
    public function reply(string $id): void
    {
        $uid = $this->requireUserId();
        $model = new SupportTicket();
        $res = $model->replyAsUser((int) $id, $uid, (string) ($this->body()['body'] ?? ''));
        if (empty($res['ok'])) {
            $this->fail('support_failed', 422, (string) ($res['error'] ?? t('support.send_failed')));
        }
        $ticket = $model->findForUser((int) $id, $uid) ?? [];
        $user = (new User())->find($uid) ?? [];
        $notify = new Notification();
        foreach ($model->adminUsers() as $admin) {
            if ((int) $admin['id'] !== $uid) {
                $notify->createFor((int) $admin['id'], t('support.notify_admin_reply', [
                    'number' => (string) ($ticket['ticket_number'] ?? ''),
                    'name' => (string) ($user['name'] ?? ''),
                ]));
            }
        }
        $this->show($id);
    }

    /** @param array<string, mixed> $t */
    private function ticket(array $t): array
    {
        $status = (string) ($t['status'] ?? 'open');
        $category = (string) ($t['category'] ?? 'general');

        return [
            'id' => (int) ($t['id'] ?? 0),
            'number' => (string) ($t['ticket_number'] ?? ''),
            'subject' => (string) ($t['subject'] ?? ''),
            'category' => $category,
            'category_label' => t('support.cat_' . $category),
            'status' => $status,
            'status_label' => t('support.status_' . $status),
            'last_preview' => ($t['last_preview'] ?? '') !== '' ? (string) $t['last_preview'] : null,
            'last_message_at' => Presenter::date($t['last_message_at'] ?? $t['created_at'] ?? null),
            'unread' => (int) ($t['unread_count'] ?? 0),
        ];
    }
}
