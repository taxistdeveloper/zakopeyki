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
$e = static fn ($s): string => htmlspecialchars((string) $s);

$priorityTone = match ((string) ($task['priority'] ?? '')) {
    'high' => 'danger',
    'low' => 'neutral',
    default => 'warn',
};
$statusTone = match ((string) ($task['status'] ?? '')) {
    'done' => 'ok',
    'cancelled' => 'neutral',
    default => 'info',
};
$isOverdue = !empty($task['deadline'])
    && !in_array((string) ($task['status'] ?? ''), ['done', 'cancelled'], true)
    && strtotime((string) $task['deadline']) < time();

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
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumb">
                <a href="<?= ProductHelper::url('/admin/tasks') ?>"><?= $e(t('admin.tasks')) ?></a>
                <span class="sep">/</span>
                <span class="truncate" style="max-width:40ch">#<?= $taskId ?></span>
            </nav>
            <h1 class="adm-title"><?= $e($task['title'] ?? '') ?></h1>
            <p class="adm-sub">
                <?= $e(t('admin.task_created_by')) ?> <?= $e($task['creator_name'] ?? '') ?>
                · <?= $e(substr((string) ($task['created_at'] ?? ''), 0, 16)) ?>
            </p>
        </div>
        <div class="adm-head-actions">
            <a href="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/edit') ?>" class="adm-btn"><?= $e(t('admin.task_edit')) ?></a>
            <?php if ($isAdmin): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/delete') ?>" class="adm-inline" onsubmit="return confirm(<?= $e(json_encode(t('admin.task_delete_confirm'))) ?>)">
                    <?= csrf_field() ?>
                    <button type="submit" class="adm-btn adm-btn--danger"><?= $e(t('admin.delete')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <div class="adm-grid-2 adm-grid-main" style="align-items:start">
        <div class="adm-stack">
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= $e(t('admin.task_description')) ?></h2>
                </div>
                <div class="adm-card-body library-body prose prose-sm dark:prose-invert max-w-none">
                    <?php if (trim(strip_tags((string) ($task['description'] ?? ''))) === ''): ?>
                        <p class="muted"><?= $e(t('admin.task_no_description')) ?></p>
                    <?php else: ?>
                        <?= $task['description'] ?>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (!empty($files)): ?>
                <section class="adm-card">
                    <div class="adm-card-head">
                        <h2 class="adm-card-title"><?= $e(t('admin.library_attached')) ?> <span class="adm-count"><?= count($files) ?></span></h2>
                    </div>
                    <div class="adm-list">
                        <?php foreach ($files as $file): ?>
                            <a href="<?= ProductHelper::url('/admin/tasks/files/' . (int) $file['id']) ?>" class="adm-row">
                                <div class="adm-row-main">
                                    <div class="adm-row-title truncate"><?= $e($file['original_name']) ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= $e(t('admin.task_comments')) ?><?php if (!empty($comments)): ?> <span class="adm-count"><?= count($comments) ?></span><?php endif; ?></h2>
                </div>
                <?php if (empty($comments)): ?>
                    <div class="adm-empty"><?= $e(t('admin.task_comments_empty')) ?></div>
                <?php else: ?>
                    <div class="adm-list">
                        <?php foreach ($comments as $c):
                            $cid = (int) $c['id'];
                        ?>
                            <div class="adm-row" style="align-items:flex-start">
                                <div class="adm-row-main">
                                    <div class="adm-row-sub">
                                        <span class="strong" style="color:var(--adm-text)"><?= $e($c['user_name'] ?? '') ?></span>
                                        · <?= $e(substr((string) $c['created_at'], 0, 16)) ?>
                                    </div>
                                    <div style="margin-top:4px;white-space:pre-wrap;overflow-wrap:anywhere;color:var(--adm-text)"><?= nl2br(htmlspecialchars((string) $c['body'])) ?></div>
                                    <?php if (!empty($commentFiles[$cid])): ?>
                                        <div style="display:flex;flex-direction:column;gap:2px;margin-top:6px">
                                            <?php foreach ($commentFiles[$cid] as $file): ?>
                                                <a href="<?= ProductHelper::url('/admin/tasks/files/' . (int) $file['id']) ?>" class="adm-link truncate" style="font-size:12px"><?= $e($file['original_name']) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/comment') ?>" enctype="multipart/form-data" class="adm-card-foot" style="display:flex;flex-direction:column;gap:8px;background:var(--adm-surface)">
                    <?= csrf_field() ?>
                    <textarea name="body" rows="3" required maxlength="4000" placeholder="<?= $e(t('admin.task_comment_ph')) ?>" class="adm-textarea"></textarea>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px">
                        <input type="file" name="files[]" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg" style="font-size:12px;max-width:100%">
                        <button type="submit" class="adm-btn adm-btn--primary"><?= $e(t('admin.task_comment_send')) ?></button>
                    </div>
                </form>
            </section>
        </div>

        <aside class="adm-stack">
            <section class="adm-card">
                <dl class="adm-kv">
                    <div>
                        <dt><?= $e(t('admin.status')) ?></dt>
                        <dd><span class="adm-badge adm-badge--<?= $statusTone ?>"><span class="adm-dot"></span><?= $e(t('admin.task_status_' . ($task['status'] ?? 'new'))) ?></span></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.task_priority')) ?></dt>
                        <dd><span class="adm-badge adm-badge--<?= $priorityTone ?>"><span class="adm-dot"></span><?= $e(t('admin.task_priority_' . ($task['priority'] ?? 'medium'))) ?></span></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.task_assignee')) ?></dt>
                        <dd<?= empty($task['assignee_name']) ? ' class="subtle"' : '' ?>><?= $e(($task['assignee_name'] ?? '') ?: t('admin.task_unassigned')) ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.task_deadline')) ?></dt>
                        <dd<?= $isOverdue ? ' style="color:var(--adm-danger);font-weight:500"' : '' ?>><?= !empty($task['deadline']) ? $e(substr((string) $task['deadline'], 0, 16)) : '—' ?></dd>
                    </div>
                    <div>
                        <dt><?= $e(t('admin.task_created_by')) ?></dt>
                        <dd><?= $e($task['creator_name'] ?? '') ?></dd>
                    </div>
                </dl>
                <form method="post" action="<?= ProductHelper::url('/admin/tasks/' . $taskId . '/status') ?>" class="adm-card-foot" style="display:flex;gap:8px;align-items:center">
                    <?= csrf_field() ?>
                    <label class="sr-only" for="task-status-select"><?= $e(t('admin.status')) ?></label>
                    <select id="task-status-select" name="status" class="adm-select">
                        <?php foreach (OpsTask::STATUSES as $st): ?>
                            <option value="<?= $st ?>" <?= ($task['status'] ?? '') === $st ? 'selected' : '' ?>><?= $e(t('admin.task_status_' . $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="adm-btn"><?= $e(t('admin.task_save')) ?></button>
                </form>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= $e(t('admin.task_history')) ?></h2>
                </div>
                <?php if (empty($events)): ?>
                    <div class="adm-empty"><?= $e(t('admin.task_history_empty')) ?></div>
                <?php else: ?>
                    <ol class="adm-card-body" style="list-style:none;margin:0;display:flex;flex-direction:column;gap:8px;font-size:12px">
                        <?php foreach ($events as $ev): ?>
                            <li class="muted">
                                <span style="color:var(--adm-text);font-weight:500"><?= $e($ev['user_name'] ?? t('admin.task_mail_user')) ?></span>
                                · <?= $e($eventLabel($ev)) ?>
                                <span class="subtle nowrap">· <?= $e(substr((string) $ev['created_at'], 0, 16)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</div>
<style>
.library-body h1,.library-body h2,.library-body h3 { font-weight: 600; margin: 0.8em 0 0.4em; }
.library-body ul { list-style: disc; padding-left: 1.25rem; }
.library-body ol { list-style: decimal; padding-left: 1.25rem; }
.library-body a { color: var(--adm-accent); text-decoration: underline; }
</style>
