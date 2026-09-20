<?php
use App\Helpers\ProductHelper;
use App\Models\OpsTask;

$task = $task ?? [];
$comments = $comments ?? [];
$events = $events ?? [];
$files = $files ?? [];
$commentFiles = $commentFiles ?? [];
$staff = $staff ?? [];
$isAdmin = !empty($isAdmin);
$taskId = (int) ($task['id'] ?? 0);
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';

$priorityClass = match ((string) ($task['priority'] ?? '')) {
    'high' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    'low' => 'bg-gray-100 text-gray-600 dark:bg-white/10',
    default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
};
$statusClass = match ((string) ($task['status'] ?? '')) {
    'in_progress' => 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
    'review' => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    'done' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    'cancelled' => 'bg-gray-100 text-gray-500 dark:bg-white/10',
    default => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
};

$eventLabel = static function (array $ev): string {
    $type = (string) ($ev['event_type'] ?? '');
    $meta = is_array($ev['meta'] ?? null) ? $ev['meta'] : [];
    return match ($type) {
        'created' => t('admin.task_event_created'),
        'assigned' => empty($meta['assignee_id'])
            ? t('admin.task_event_unassigned')
            : t('admin.task_event_assigned'),
        'status' => t('admin.task_event_status', [
            'from' => t('admin.task_status_' . ($meta['from'] ?? 'new')),
            'to' => t('admin.task_status_' . ($meta['to'] ?? 'new')),
        ]),
        'priority' => t('admin.task_event_priority', [
            'from' => t('admin.task_priority_' . ($meta['from'] ?? 'medium')),
            'to' => t('admin.task_priority_' . ($meta['to'] ?? 'medium')),
        ]),
        'deadline' => t('admin.task_event_deadline'),
        'commented' => t('admin.task_event_commented'),
        default => $type,
    };
};
?>
<section class="space-y-5 fade-up pb-8 max-w-3xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin/tasks') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.tasks')) ?></a>
            <div class="flex flex-wrap gap-2 mb-2">
                <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase <?= $priorityClass ?>"><?= htmlspecialchars(t('admin.task_priority_' . ($task['priority'] ?? 'medium'))) ?></span>
                <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase <?= $statusClass ?>"><?= htmlspecialchars(t('admin.task_status_' . ($task['status'] ?? 'new'))) ?></span>
            </div>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars((string) ($task['title'] ?? '')) ?></h1>
            <p class="text-[11px] text-gray-400 mt-1">
                <?= htmlspecialchars(t('admin.task_created_by')) ?> <?= htmlspecialchars((string) ($task['creator_name'] ?? '')) ?>
                · <?= htmlspecialchars(substr((string) ($task['created_at'] ?? ''), 0, 16)) ?>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/edit') ?>" class="h-10 px-4 inline-flex items-center rounded-xl bg-ink-900 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.task_edit')) ?></a>
            <?php if ($isAdmin): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/delete') ?>" onsubmit="return confirm(<?= json_encode(t('admin.task_delete_confirm')) ?>)">
                    <?= csrf_field() ?>
                    <button type="submit" class="h-10 px-4 rounded-xl border border-red-200 text-red-600 text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.delete')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.task_assignee')) ?></p>
            <p class="text-sm font-semibold mt-1"><?= htmlspecialchars((string) ($task['assignee_name'] ?: t('admin.task_unassigned'))) ?></p>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.task_deadline')) ?></p>
            <p class="text-sm font-semibold mt-1"><?= !empty($task['deadline']) ? htmlspecialchars(substr((string) $task['deadline'], 0, 16)) : '—' ?></p>
        </div>
        <form method="post" action="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/status') ?>" class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4">
            <?= csrf_field() ?>
            <label class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.status')) ?></label>
            <div class="flex gap-2 mt-1">
                <select name="status" class="<?= $input ?> h-9">
                    <?php foreach (OpsTask::STATUSES as $st): ?>
                        <option value="<?= $st ?>" <?= ($task['status'] ?? '') === $st ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_status_' . $st)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="h-9 px-3 rounded-xl bg-sky-600 text-white text-[10px] font-bold uppercase"><?= htmlspecialchars(t('admin.task_save')) ?></button>
            </div>
        </form>
    </div>

    <div class="library-body bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-5 prose prose-sm dark:prose-invert max-w-none">
        <?php if (trim(strip_tags((string) ($task['description'] ?? ''))) === ''): ?>
            <p class="text-gray-400 text-sm"><?= htmlspecialchars(t('admin.task_no_description')) ?></p>
        <?php else: ?>
            <?= $task['description'] ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($files)): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4">
            <h2 class="font-display font-bold text-sm mb-3"><?= htmlspecialchars(t('admin.library_attached')) ?></h2>
            <div class="space-y-2">
                <?php foreach ($files as $file): ?>
                    <a href="<?= ProductHelper::url('/admin/tasks/files/' . (int) $file['id']) ?>" class="flex justify-between gap-2 rounded-xl border border-black/[0.06] px-3 py-2 text-sm font-semibold hover:border-sky-400/50">
                        <span class="truncate"><?= htmlspecialchars((string) $file['original_name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5">
        <h2 class="font-display font-bold text-sm mb-3"><?= htmlspecialchars(t('admin.task_history')) ?></h2>
        <?php if (empty($events)): ?>
            <p class="text-sm text-gray-400"><?= htmlspecialchars(t('admin.task_history_empty')) ?></p>
        <?php else: ?>
            <ol class="space-y-2">
                <?php foreach ($events as $ev): ?>
                    <li class="text-xs text-gray-500">
                        <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars((string) ($ev['user_name'] ?? t('admin.task_mail_user'))) ?></span>
                        · <?= htmlspecialchars($eventLabel($ev)) ?>
                        <span class="text-gray-400">· <?= htmlspecialchars(substr((string) $ev['created_at'], 0, 16)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 space-y-4">
        <h2 class="font-display font-bold text-sm"><?= htmlspecialchars(t('admin.task_comments')) ?></h2>
        <?php if (empty($comments)): ?>
            <p class="text-sm text-gray-400"><?= htmlspecialchars(t('admin.task_comments_empty')) ?></p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($comments as $c):
                    $cid = (int) $c['id'];
                ?>
                    <div class="rounded-2xl bg-ink-50/80 dark:bg-white/[0.04] p-3.5">
                        <p class="text-[11px] font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars((string) ($c['user_name'] ?? '')) ?>
                            <span class="font-normal text-gray-400">· <?= htmlspecialchars(substr((string) $c['created_at'], 0, 16)) ?></span>
                        </p>
                        <p class="text-sm mt-1 whitespace-pre-wrap break-words"><?= nl2br(htmlspecialchars((string) $c['body'])) ?></p>
                        <?php if (!empty($commentFiles[$cid])): ?>
                            <div class="mt-2 space-y-1">
                                <?php foreach ($commentFiles[$cid] as $file): ?>
                                    <a href="<?= ProductHelper::url('/admin/tasks/files/' . (int) $file['id']) ?>" class="block text-xs text-sky-700 dark:text-sky-300 truncate"><?= htmlspecialchars((string) $file['original_name']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/comment') ?>" enctype="multipart/form-data" class="space-y-2">
            <?= csrf_field() ?>
            <textarea name="body" rows="3" required maxlength="4000" placeholder="<?= htmlspecialchars(t('admin.task_comment_ph')) ?>"
                      class="ui-input w-full px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm resize-y"></textarea>
            <input type="file" name="files[]" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg" class="block w-full text-xs text-gray-500">
            <button type="submit" class="h-10 px-4 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.task_comment_send')) ?></button>
        </form>
    </div>
</section>
<style>
.library-body h1,.library-body h2,.library-body h3 { font-family: Sora, sans-serif; font-weight: 700; margin: 0.8em 0 0.4em; }
.library-body ul { list-style: disc; padding-left: 1.25rem; }
.library-body ol { list-style: decimal; padding-left: 1.25rem; }
.library-body a { color: #2563EB; text-decoration: underline; }
</style>
