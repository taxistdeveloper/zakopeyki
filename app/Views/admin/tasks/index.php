<?php
use App\Helpers\ProductHelper;
use App\Models\OpsTask;

$tasks = $tasks ?? [];
$kanban = $kanban ?? [];
$filters = $filters ?? [];
$viewMode = ($viewMode ?? 'list') === 'kanban' ? 'kanban' : 'list';
$staff = $staff ?? [];
$isAdmin = !empty($isAdmin);
$e = static fn ($s): string => htmlspecialchars((string) $s);

$priorityTone = static function (string $p): string {
    return match ($p) {
        'high' => 'danger',
        'low' => 'neutral',
        default => 'warn',
    };
};
$statusTone = static function (string $s): string {
    return match ($s) {
        'done' => 'ok',
        'cancelled' => 'neutral',
        default => 'info',
    };
};

$qs = static function (array $extra = []) use ($filters, $viewMode): string {
    $params = [
        'view' => $extra['view'] ?? $viewMode,
        'status' => $extra['status'] ?? ($filters['status'] ?? ''),
        'assignee' => array_key_exists('assignee', $extra) ? $extra['assignee'] : (string) ($filters['assignee_id'] ?? ''),
        'priority' => $extra['priority'] ?? ($filters['priority'] ?? ''),
        'deadline' => $extra['deadline'] ?? ($filters['deadline'] ?? ''),
        'sort' => $extra['sort'] ?? ($filters['sort'] ?? 'priority'),
    ];
    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== '0');
    $q = http_build_query($params);
    return ProductHelper::url('/admin/tasks') . ($q !== '' ? '?' . $q : '');
};

$overdue = static function (array $task): bool {
    $dl = $task['deadline'] ?? null;
    $st = (string) ($task['status'] ?? '');
    return $dl && !in_array($st, ['done', 'cancelled'], true) && strtotime((string) $dl) < time();
};
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.tasks')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.tasks_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <a href="<?= ProductHelper::url('/admin/tasks/new') ?>" class="adm-btn adm-btn--primary"><?= $e(t('admin.task_new')) ?></a>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/admin/tasks') ?>" class="adm-toolbar" style="align-items:flex-end">
        <input type="hidden" name="view" value="<?= $e($viewMode) ?>">
        <div class="adm-seg" role="tablist" style="align-self:flex-end">
            <a href="<?= $e($qs(['view' => 'list'])) ?>" class="<?= $viewMode === 'list' ? 'is-active' : '' ?>"<?= $viewMode === 'list' ? ' aria-current="page"' : '' ?>><?= $e(t('admin.task_view_list')) ?></a>
            <a href="<?= $e($qs(['view' => 'kanban'])) ?>" class="<?= $viewMode === 'kanban' ? 'is-active' : '' ?>"<?= $viewMode === 'kanban' ? ' aria-current="page"' : '' ?>><?= $e(t('admin.task_view_kanban')) ?></a>
        </div>
        <span class="grow"></span>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.status')) ?></span>
            <select name="status" class="adm-select" style="min-width:140px">
                <option value=""><?= $e(t('admin.tickets_all')) ?></option>
                <?php foreach (OpsTask::STATUSES as $st): ?>
                    <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $e(t('admin.task_status_' . $st)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.task_assignee')) ?></span>
            <select name="assignee" class="adm-select" style="min-width:160px">
                <option value=""><?= $e(t('admin.tickets_all')) ?></option>
                <?php foreach ($staff as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) ($filters['assignee_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= $e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.task_priority')) ?></span>
            <select name="priority" class="adm-select" style="min-width:120px">
                <option value=""><?= $e(t('admin.tickets_all')) ?></option>
                <?php foreach (OpsTask::PRIORITIES as $pr): ?>
                    <option value="<?= $pr ?>" <?= ($filters['priority'] ?? '') === $pr ? 'selected' : '' ?>><?= $e(t('admin.task_priority_' . $pr)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.task_deadline')) ?></span>
            <select name="deadline" class="adm-select" style="min-width:140px">
                <option value=""><?= $e(t('admin.tickets_all')) ?></option>
                <option value="overdue" <?= ($filters['deadline'] ?? '') === 'overdue' ? 'selected' : '' ?>><?= $e(t('admin.task_deadline_overdue')) ?></option>
                <option value="today" <?= ($filters['deadline'] ?? '') === 'today' ? 'selected' : '' ?>><?= $e(t('admin.task_deadline_today')) ?></option>
                <option value="week" <?= ($filters['deadline'] ?? '') === 'week' ? 'selected' : '' ?>><?= $e(t('admin.task_deadline_week')) ?></option>
                <option value="upcoming" <?= ($filters['deadline'] ?? '') === 'upcoming' ? 'selected' : '' ?>><?= $e(t('admin.task_deadline_upcoming')) ?></option>
            </select>
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.task_sort')) ?></span>
            <select name="sort" class="adm-select" style="min-width:140px">
                <option value="priority" <?= ($filters['sort'] ?? '') === 'priority' ? 'selected' : '' ?>><?= $e(t('admin.task_sort_priority')) ?></option>
                <option value="deadline" <?= ($filters['sort'] ?? '') === 'deadline' ? 'selected' : '' ?>><?= $e(t('admin.task_sort_deadline')) ?></option>
                <option value="created" <?= ($filters['sort'] ?? '') === 'created' ? 'selected' : '' ?>><?= $e(t('admin.task_sort_created')) ?></option>
            </select>
        </label>
        <button type="submit" class="adm-btn"><?= $e(t('admin.users_find')) ?></button>
    </form>

    <?php if ($viewMode === 'list'): ?>
        <section class="adm-card">
            <?php if (empty($tasks)): ?>
                <div class="adm-empty"><?= $e(t('admin.tasks_empty')) ?></div>
            <?php else: ?>
                <div class="adm-table-wrap">
                    <table class="adm-table">
                        <thead>
                            <tr>
                                <th><?= $e(t('admin.library_doc_title')) ?></th>
                                <th><?= $e(t('admin.task_priority')) ?></th>
                                <th><?= $e(t('admin.status')) ?></th>
                                <th><?= $e(t('admin.task_assignee')) ?></th>
                                <th class="num"><?= $e(t('admin.task_deadline')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task):
                                $isOverdue = $overdue($task);
                            ?>
                                <tr class="js-row-link" data-href="<?= ProductHelper::url('/admin/tasks/' . (int) $task['id']) ?>">
                                    <td style="min-width:240px">
                                        <a href="<?= ProductHelper::url('/admin/tasks/' . (int) $task['id']) ?>" class="strong"><?= $e($task['title']) ?></a>
                                    </td>
                                    <td class="nowrap">
                                        <span class="adm-badge adm-badge--<?= $priorityTone((string) $task['priority']) ?>"><span class="adm-dot"></span><?= $e(t('admin.task_priority_' . $task['priority'])) ?></span>
                                    </td>
                                    <td class="nowrap">
                                        <span class="adm-badge adm-badge--<?= $statusTone((string) $task['status']) ?>"><span class="adm-dot"></span><?= $e(t('admin.task_status_' . $task['status'])) ?></span>
                                    </td>
                                    <td class="nowrap<?= empty($task['assignee_name']) ? ' subtle' : '' ?>"><?= $e($task['assignee_name'] ?: t('admin.task_unassigned')) ?></td>
                                    <td class="num"<?= $isOverdue ? ' style="color:var(--adm-danger);font-weight:500"' : '' ?>>
                                        <?= !empty($task['deadline']) ? $e(substr((string) $task['deadline'], 0, 16)) : '<span class="subtle">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <div style="overflow-x:auto;padding-bottom:4px">
            <div style="display:grid;grid-template-columns:repeat(<?= count(OpsTask::STATUSES) ?>, minmax(180px, 1fr));gap:12px;min-width:980px">
                <?php foreach (OpsTask::STATUSES as $st):
                    $column = $kanban[$st] ?? [];
                ?>
                    <section class="adm-card" data-kanban-col="<?= $e($st) ?>" style="background:var(--adm-surface-2)">
                        <div class="adm-card-head">
                            <h2 class="adm-card-title"><?= $e(t('admin.task_status_' . $st)) ?></h2>
                            <span class="adm-count"><?= count($column) ?></span>
                        </div>
                        <div data-kanban-drop="<?= $e($st) ?>" style="display:flex;flex-direction:column;gap:8px;padding:8px;min-height:120px;transition:background .12s">
                            <?php foreach ($column as $task):
                                $isOverdue = $overdue($task);
                            ?>
                                <article draggable="true" data-task-id="<?= (int) $task['id'] ?>" class="kanban-card"
                                         style="cursor:grab;padding:10px 12px;background:var(--adm-surface);border:1px solid <?= $isOverdue ? 'var(--adm-danger-bd)' : 'var(--adm-border)' ?>;border-radius:var(--adm-radius);box-shadow:var(--adm-shadow)">
                                    <a href="<?= ProductHelper::url('/admin/tasks/' . (int) $task['id']) ?>" style="display:block;font-weight:500;color:var(--adm-text);line-height:1.35">
                                        <?= $e($task['title']) ?>
                                    </a>
                                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:8px">
                                        <span class="adm-badge adm-badge--<?= $priorityTone((string) $task['priority']) ?>"><span class="adm-dot"></span><?= $e(t('admin.task_priority_' . $task['priority'])) ?></span>
                                        <?php if (!empty($task['assignee_name'])): ?>
                                            <span class="muted truncate" style="font-size:12px;max-width:100%"><?= $e($task['assignee_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($task['deadline'])): ?>
                                        <div style="font-size:12px;margin-top:6px;<?= $isOverdue ? 'color:var(--adm-danger);font-weight:500' : 'color:var(--adm-muted)' ?>"><?= $e(substr((string) $task['deadline'], 0, 16)) ?></div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('.js-row-link').forEach(function (row) {
        row.addEventListener('click', function (e) {
            // Ignore clicks on links, buttons, form controls or text selections
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            if (window.getSelection && String(window.getSelection())) return;
            const href = row.getAttribute('data-href');
            if (!href) return;
            if (e.metaKey || e.ctrlKey) {
                window.open(href, '_blank');
            } else {
                window.location.href = href;
            }
        });
    });
})();
</script>
<?php if ($viewMode === 'kanban'): ?>
<script>
(function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let dragged = null;
    document.querySelectorAll('.kanban-card').forEach(function (card) {
        card.addEventListener('dragstart', function (e) {
            dragged = card;
            e.dataTransfer.effectAllowed = 'move';
            card.style.opacity = '0.5';
        });
        card.addEventListener('dragend', function () {
            card.style.opacity = '';
            dragged = null;
        });
    });
    document.querySelectorAll('[data-kanban-drop]').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            e.preventDefault();
            col.style.background = 'var(--adm-hover)';
        });
        col.addEventListener('dragleave', function () {
            col.style.background = '';
        });
        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.style.background = '';
            if (!dragged) return;
            const status = col.getAttribute('data-kanban-drop');
            const id = dragged.getAttribute('data-task-id');
            col.prepend(dragged);
            fetch(<?= json_encode(ProductHelper::url('/admin/tasks/')) ?> + id + '/status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: '_csrf=' + encodeURIComponent(token) + '&status=' + encodeURIComponent(status)
            }).catch(function () {});
        });
    });
})();
</script>
<?php endif; ?>
