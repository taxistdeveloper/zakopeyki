<?php

/**
 * Cron модуля «Конкурсы и розыгрыши» (рекомендуется каждые 5 минут):
 *  - scheduled → active, active → closed (с окончательной проверкой условий);
 *  - напоминание участникам за сутки до окончания;
 *  - сроки вручения призов (просрочка → expired, «передан» без ответа 7 дней → confirmed).
 * Пример: php /path/to/zakopeyki/scripts/cron_contests.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$appConfig = require $root . '/config/app.php';
$GLOBALS['appConfig'] = $appConfig;
date_default_timezone_set($appConfig['timezone'] ?? 'Asia/Almaty');

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $root . '/app/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
\App\Core\Lang::boot();

$lock = fopen(sys_get_temp_dir() . '/zakopeyki_cron_contests.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another instance is running\n";
    exit(0);
}

$contests = new \App\Models\Contest();
$service = new \App\Services\Contest\ContestService($contests);
$notifier = new \App\Services\Contest\ContestNotifier();
$entries = new \App\Models\ContestEntry();

$transitions = 0;
foreach ($contests->dueForTransition() as $row) {
    $c = $contests->find((int) $row['id']);
    if (!$c) {
        continue;
    }
    try {
        $after = $service->syncTime($c);
        if ($after['status'] !== $c['status']) {
            $transitions++;
        }
    } catch (\Throwable $e) {
        \App\Helpers\ActivityLogger::exception($e, 'contest.cron_transition_failed');
    }
}

$reminders = 0;
foreach ($contests->dueForReminder() as $c) {
    // Отметка до рассылки: повторный запуск не продублирует напоминания
    $stmt = $contests->db()->prepare('UPDATE contests SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL');
    $stmt->execute([(int) $c['id']]);
    if ($stmt->rowCount() !== 1) {
        continue;
    }
    $notifier->notifyMany($entries->participantUserIds((int) $c['id'], ['pending']), 'ending_soon', $c);
    $reminders++;
}

$fulfil = (new \App\Services\Contest\ContestResultService())->processFulfilmentDeadlines();

echo sprintf(
    "[%s] contests: transitions=%d reminders=%d prize_expired=%d prize_auto_confirmed=%d\n",
    date('Y-m-d H:i:s'),
    $transitions,
    $reminders,
    $fulfil['expired'],
    $fulfil['auto_confirmed']
);

flock($lock, LOCK_UN);
fclose($lock);
