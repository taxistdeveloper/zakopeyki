<?php
use App\Helpers\ProductHelper;
use App\Models\OpsTask;

$tasks = $tasks ?? [];
$kanban = $kanban ?? [];
$filters = $filters ?? [];
$viewMode = ($viewMode ?? 'list') === 'kanban' ? 'kanban' : 'list';
$staff = $staff ?? [];
$isAdmin = !empty($isAdmin);
$input = 'ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';

$priorityClass = static function (string $p): string {
    return match ($p) {
        'high' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'low' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
        default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    };
};
$statusClass = static function (string $s): string {
    return match ($s) {
        'in_progress' => 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
        'review' => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        'done' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        'cancelled' => 'bg-gray-100 text-gray-500 dark:bg-white/10',
        default => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
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
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.tasks')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.tasks_hint')) ?></p>
        </div>
        <a href="<?= ProductHelper::url('/admin/tasks/new') ?>" class="h-11 px-4 inline-flex items-center rounded-2xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold uppercase tracking-wider">
            <?= htmlspecialchars(t('admin.task_new')) ?>
        </a>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars($qs(['view' => 'list'])) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-bold <?= $viewMode === 'list' ? 'bg-ink-900 text-white' : 'bg-white/80 dark:bg-white/[0.04] border border-black/[0.06]' ?>"><?= htmlspecialchars(t('admin.task_view_list')) ?></a>
        <a href="<?= htmlspecialchars($qs(['view' => 'kanban'])) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-bold <?= $viewMode === 'kanban' ? 'bg-ink-900 text-white' : 'bg-white/80 dark:bg-white/[0.04] border border-black/[0.06]' ?>"><?= htmlspecialchars(t('admin.task_view_kanban')) ?></a>
    </div>

    <form method="get" action="<?= ProductHelper::url('/admin/tasks') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 grid grid-cols-2 lg:grid-cols-6 gap-3">
        <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.status')) ?></label>
            <select name="status" class="<?= $input ?> w-full">
                <option value=""><?= htmlspecialchars(t('admin.tickets_all')) ?></option>
                <?php foreach (OpsTask::STATUSES as $st): ?>
                    <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_status_' . $st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.task_assignee')) ?></label>
            <select name="assignee" class="<?= $input ?> w-full">
                <option value=""><?= htmlspecialchars(t('admin.tickets_all')) ?></option>
                <?php foreach ($staff as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) ($filters['assignee_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.task_priority')) ?></label>
            <select name="priority" class="<?= $input ?> w-full">
                <option value=""><?= htmlspecialchars(t('admin.tickets_all')) ?></option>
                <?php foreach (OpsTask::PRIORITIES as $pr): ?>
                    <option value="<?= $pr ?>" <?= ($filters['priority'] ?? '') === $pr ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_priority_' . $pr)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.task_deadline')) ?></label>
            <select name="deadline" class="<?= $input ?> w-full">
                <option value=""><?= htmlspecialchars(t('admin.tickets_all')) ?></option>
                <option value="overdue" <?= ($filters['deadline'] ?? '') === 'overdue' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_deadline_overdue')) ?></option>
                <option value="today" <?= ($filters['deadline'] ?? '') === 'today' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_deadline_today')) ?></option>
                <option value="week" <?= ($filters['deadline'] ?? '') === 'week' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_deadline_week')) ?></option>
                <option value="upcoming" <?= ($filters['deadline'] ?? '') === 'upcoming' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_deadline_upcoming')) ?></option>
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.task_sort')) ?></label>
            <select name="sort" class="<?= $input ?> w-full">
                <option value="priority" <?= ($filters['sort'] ?? '') === 'priority' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_sort_priority')) ?></option>
                <option value="deadline" <?= ($filters['sort'] ?? '') === 'deadline' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_sort_deadline')) ?></option>
                <option value="created" <?= ($filters['sort'] ?? '') === 'created' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_sort_created')) ?></option>
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="h-10 px-4 rounded-xl bg-ink-900 text-white text-xs font-bold uppercase tracking-wider w-full"><?= htmlspecialchars(t('admin.users_find')) ?></button>
        </div>
    </form>

    <?php if ($viewMode === 'list'): ?>
        <?php if (empty($tasks)): ?>
            <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm"><?= htmlspecialchars(t('admin.tasks_empty')) ?></div>
        <?php else: ?>
            <div class="overflow-x-auto bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft">
                <table class="w-full text-left text-xs">
                    <thead class="bg-ink-50/80 dark:bg-white/[0.03] border-b border-black/[0.06] dark:border-white/10">
                        <tr>
                            <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.library_doc_title')) ?></th>
                            <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.task_priority')) ?></th>
                            <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.status')) ?></th>
                            <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.task_assignee')) ?></th>
                            <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.task_deadline')) ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/[0.04] dark:divide-white/5">
                        <?php foreach ($tasks as $task): ?>
                            <tr class="hover:bg-sky-50/40 dark:hover:bg-white/[0.03] <?= $overdue($task) ? 'bg-red-50/50 dark:bg-red-950/20' : '' ?>">
                                <td class="px-4 py-3.5">
                                    <a href="<?= ProductHelper::url('/admin/tasks/' . (int) $task['id']) ?>" class="font-semibold text-ink-800 dark:text-gray-200 hover:text-brand-600">
                                        <?= htmlspecialchars((string) $task['title']) ?>
                                    </a>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase <?= $priorityClass((string) $task['priority']) ?>"><?= htmlspecialchars(t('admin.task_priority_' . $task['priority'])) ?></span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase <?= $statusClass((string) $task['status']) ?>"><?= htmlspecialchars(t('admin.task_status_' . $task['status'])) ?></span>
                                </td>
                                <td class="px-4 py-3.5 text-gray-500"><?= htmlspecialchars((string) ($task['assignee_name'] ?: t('admin.task_unassigned'))) ?></td>
                                <td class="px-4 py-3.5 <?= $overdue($task) ? 'text-red-600 font-semibold' : 'text-gray-500' ?>">
                                    <?= !empty($task['deadline']) ? htmlspecialchars(substr((string) $task['deadline'], 0, 16)) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="overflow-x-auto pb-2 -mx-1 px-1">
            <div class="flex gap-3 min-w-[980px]">
                <?php foreach (OpsTask::STATUSES as $st):
                    $column = $kanban[$st] ?? [];
                ?>
                    <div class="flex-1 min-w-[180px] rounded-[22px] bg-ink-50/80 dark:bg-white/[0.03] border border-black/[0.06] dark:border-white/10 p-2.5"
                         data-kanban-col="<?= htmlspecialchars($st) ?>">
                        <div class="flex items-center justify-between px-1.5 py-2">
                            <h3 class="text-[11px] font-bold uppercase tracking-wider text-gray-500"><?= htmlspecialchars(t('admin.task_status_' . $st)) ?></h3>
                            <span class="text-[10px] font-bold text-gray-400"><?= count($column) ?></span>
                        </div>
                        <div class="space-y-2 min-h-[120px]" data-kanban-drop="<?= htmlspecialchars($st) ?>">
                            <?php foreach ($column as $task): ?>
                                <article draggable="true" data-task-id="<?= (int) $task['id'] ?>"
                                         class="kanban-card cursor-grab active:cursor-grabbing rounded-2xl bg-white dark:bg-white/[0.06] border border-black/[0.06] dark:border-white/10 p-3 shadow-soft <?= $overdue($task) ? 'ring-1 ring-red-400/70' : '' ?>">
                                    <a href="<?= ProductHelper::url('/admin/tasks/' . (int) $task['id']) ?>" class="block font-semibold text-sm text-ink-900 dark:text-white leading-snug">
                                        <?= htmlspecialchars((string) $task['title']) ?>
                                    </a>
                                    <div class="flex flex-wrap items-center gap-1 mt-2">
                                        <span class="inline-flex px-1.5 py-0.5 rounded-md text-[9px] font-bold uppercase <?= $priorityClass((string) $task['priority']) ?>"><?= htmlspecialchars(t('admin.task_priority_' . $task['priority'])) ?></span>
                                        <?php if (!empty($task['assignee_name'])): ?>
                                            <span class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars((string) $task['assignee_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($task['deadline'])): ?>
                                        <p class="text-[10px] mt-1 <?= $overdue($task) ? 'text-red-600 font-semibold' : 'text-gray-400' ?>"><?= htmlspecialchars(substr((string) $task['deadline'], 0, 16)) ?></p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php if ($viewMode === 'kanban'): ?>
<script>
(function () {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let dragged = null;
    document.querySelectorAll('.kanban-card').forEach(function (card) {
        card.addEventListener('dragstart', function (e) {
            dragged = card;
            e.dataTransfer.effectAllowed = 'move';
            card.classList.add('opacity-50');
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('opacity-50');
            dragged = null;
        });
    });
    document.querySelectorAll('[data-kanban-drop]').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
            e.preventDefault();
            col.classList.add('bg-sky-50/80', 'dark:bg-sky-950/20');
        });
        col.addEventListener('dragleave', function () {
            col.classList.remove('bg-sky-50/80', 'dark:bg-sky-950/20');
        });
        col.addEventListener('drop', function (e) {
            e.preventDefault();
            col.classList.remove('bg-sky-50/80', 'dark:bg-sky-950/20');
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
