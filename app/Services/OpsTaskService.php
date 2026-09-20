<?php

namespace App\Services;

use App\Helpers\Mail;
use App\Models\Notification;
use App\Models\OpsTask;
use App\Models\User;
use Throwable;

class OpsTaskService
{
    private OpsTask $tasks;
    private Notification $notifications;

    public function __construct(?OpsTask $tasks = null, ?Notification $notifications = null)
    {
        $this->tasks = $tasks ?? new OpsTask();
        $this->notifications = $notifications ?? new Notification();
    }

    public function notifyAssigned(array $task, int $actorId): void
    {
        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        if ($assigneeId <= 0 || $assigneeId === $actorId) {
            return;
        }
        $this->notifyUser(
            $assigneeId,
            t('admin.task_notify_assigned', ['title' => (string) $task['title']]),
            'assigned',
            (int) $task['id']
        );
    }

    public function notifyStatus(array $task, string $from, string $to, int $actorId): void
    {
        $targets = [];
        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        $creatorId = (int) ($task['created_by'] ?? 0);
        if ($assigneeId > 0) {
            $targets[$assigneeId] = true;
        }
        if ($creatorId > 0) {
            $targets[$creatorId] = true;
        }
        unset($targets[$actorId]);
        if ($targets === []) {
            return;
        }

        $message = t('admin.task_notify_status', [
            'title' => (string) $task['title'],
            'status' => t('admin.task_status_' . $to),
        ]);
        foreach (array_keys($targets) as $userId) {
            $this->notifyUser((int) $userId, $message, 'status', (int) $task['id']);
        }
    }

    public function notifyDeadlineApproaching(array $task): void
    {
        $assigneeId = (int) ($task['assignee_id'] ?? 0);
        if ($assigneeId <= 0) {
            $assigneeId = (int) ($task['created_by'] ?? 0);
        }
        if ($assigneeId <= 0) {
            return;
        }
        $this->notifyUser(
            $assigneeId,
            t('admin.task_notify_deadline', ['title' => (string) $task['title']]),
            'deadline',
            (int) $task['id']
        );
        $this->tasks->markDeadlineNotified((int) $task['id']);
    }

    public function remindUpcomingDeadlines(): int
    {
        $count = 0;
        foreach ($this->tasks->dueSoonUnnotified() as $task) {
            $this->notifyDeadlineApproaching($task);
            $count++;
        }
        return $count;
    }

    private function notifyUser(int $userId, string $message, string $kind, int $taskId): void
    {
        try {
            $this->notifications->createFor($userId, $message);
        } catch (Throwable) {
            // keep going — email still useful
        }

        $user = (new User())->find($userId);
        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            return;
        }

        try {
            $mail = new Mail();
            $url = $mail->absoluteUrl('/admin/tasks/' . $taskId);
            $name = (string) ($user['name'] ?? '');
            $subjectKey = match ($kind) {
                'assigned' => 'admin.task_mail_assigned_subject',
                'deadline' => 'admin.task_mail_deadline_subject',
                default => 'admin.task_mail_status_subject',
            };
            $html = $mail->render('emails/support-ticket-created', [
                'name' => $name,
                'ticketNumber' => '',
                'subject' => '',
                'ticketUrl' => $url,
                'greeting' => t('admin.task_mail_greeting', ['name' => $name !== '' ? $name : t('admin.task_mail_user')]),
                'body' => $message,
                'cta' => t('admin.task_mail_cta'),
                'hint' => t('admin.task_mail_hint'),
                'footer' => t('admin.task_mail_footer'),
            ]);
            $mail->send($email, t($subjectKey), $message . "\n" . $url, $html);
        } catch (Throwable) {
            // ignore mail errors
        }
    }
}
