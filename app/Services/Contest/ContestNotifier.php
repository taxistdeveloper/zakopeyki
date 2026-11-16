<?php

declare(strict_types=1);

namespace App\Services\Contest;

use App\Helpers\ActivityLogger;
use App\Helpers\Mail;
use App\Models\Notification;
use App\Models\User;

/**
 * Уведомления модуля поверх существующих каналов: in-app (notifications) и email (Mail).
 * Ошибка доставки не должна ломать бизнес-операцию — всё обёрнуто в try/catch.
 */
final class ContestNotifier
{
    private ?Notification $notifications = null;

    public function notify(int $userId, string $key, array $contest, array $replace = [], ?string $path = null): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $this->notifications()->createFor(
                $userId,
                t('contests.notify.' . $key, ['title' => (string) ($contest['title'] ?? '')] + $replace),
                $path ?? $this->contestPath($contest)
            );
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'contest.notify_failed');
        }
    }

    /** @param list<int> $userIds */
    public function notifyMany(array $userIds, string $key, array $contest, array $replace = []): void
    {
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            $this->notify($uid, $key, $contest, $replace);
        }
    }

    /** Сотрудники с правом модерации акций (админы + менеджеры с permission contests). */
    public function notifyModerators(string $key, array $contest, array $replace = []): void
    {
        try {
            $db = \App\Core\Database::connect();
            $rows = $db->query("SELECT id, role, permissions FROM users WHERE role IN ('admin','manager')")->fetchAll();
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $r) {
            $perms = \App\Core\Auth::normalizePermissions($r['permissions'] ?? null, (string) $r['role']);
            if (in_array('contests', $perms, true)) {
                $this->notify((int) $r['id'], $key, $contest, $replace, '/admin/contests/' . (int) $contest['id']);
            }
        }
    }

    /** Email победителю (в дополнение к in-app). */
    public function emailWinner(int $userId, array $contest, string $prize): void
    {
        if (!Mail::configured()) {
            return;
        }
        try {
            $user = (new User())->find($userId);
            if (!$user || empty($user['email'])) {
                return;
            }
            $mail = new Mail();
            $url = $mail->absoluteUrl($this->contestPath($contest));
            $name = trim((string) ($user['name'] ?? '')) ?: (string) $user['email'];
            $title = (string) $contest['title'];
            $html = $mail->render('emails/password-reset', [
                'name' => $name,
                'resetUrl' => $url,
                'greeting' => t('auth.reset_mail_greeting', ['name' => $name]),
                'body' => t('contests.mail.winner_body', ['title' => $title, 'prize' => $prize]),
                'cta' => t('contests.mail.winner_cta'),
                'expiry' => t('contests.mail.winner_hint'),
                'linkHint' => t('auth.reset_mail_link_hint'),
                'footer' => t('auth.reset_mail_footer'),
            ]);
            $text = t('contests.mail.winner_text', ['name' => $name, 'title' => $title, 'prize' => $prize, 'url' => $url]);
            $mail->send((string) $user['email'], t('contests.mail.winner_subject', ['title' => $title]), $text, $html);
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'contest.winner_mail_failed');
        }
    }

    public function contestPath(array $contest): string
    {
        return '/contests/' . rawurlencode((string) ($contest['slug'] ?? ''));
    }

    private function notifications(): Notification
    {
        return $this->notifications ??= new Notification();
    }
}
