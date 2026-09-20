<?php
use App\Helpers\ProductHelper;
use App\Models\OpsTask;

$task = $task ?? null;
$files = $files ?? [];
$staff = $staff ?? [];
$isEdit = is_array($task);
$action = $isEdit
    ? ProductHelper::url('/admin/tasks/' . (int) $task['id'] . '/update')
    : ProductHelper::url('/admin/tasks');
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$deadlineVal = '';
if ($isEdit && !empty($task['deadline'])) {
    $deadlineVal = str_replace(' ', 'T', substr((string) $task['deadline'], 0, 16));
}
?>
<section class="space-y-5 fade-up pb-8 max-w-3xl">
    <div>
        <a href="<?= ProductHelper::url('/admin/tasks') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.tasks')) ?></a>
        <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars((string) ($title ?? t('admin.task_new'))) ?></h1>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-6 space-y-4">
        <?= csrf_field() ?>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.task_title')) ?></label>
            <input type="text" name="title" required minlength="2" maxlength="240" class="<?= $input ?>"
                   value="<?= htmlspecialchars((string) ($task['title'] ?? '')) ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.task_description')) ?></label>
            <textarea id="task-body" name="description" rows="8" class="<?= $input ?> min-h-[180px] py-3"><?= htmlspecialchars((string) ($task['description'] ?? '')) ?></textarea>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.task_priority')) ?></label>
                <select name="priority" class="<?= $input ?>">
                    <?php foreach (OpsTask::PRIORITIES as $pr): ?>
                        <option value="<?= $pr ?>" <?= ($task['priority'] ?? 'medium') === $pr ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_priority_' . $pr)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.status')) ?></label>
                <select name="status" class="<?= $input ?>">
                    <?php foreach (OpsTask::STATUSES as $st): ?>
                        <option value="<?= $st ?>" <?= ($task['status'] ?? 'new') === $st ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.task_status_' . $st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.task_assignee')) ?></label>
                <select name="assignee_id" class="<?= $input ?>">
                    <option value=""><?= htmlspecialchars(t('admin.task_unassigned')) ?></option>
                    <?php foreach ($staff as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (int) ($task['assignee_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $u['name']) ?> (<?= htmlspecialchars(t('nav.role_' . $u['role'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.task_deadline')) ?></label>
                <input type="datetime-local" name="deadline" class="<?= $input ?>" value="<?= htmlspecialchars($deadlineVal) ?>">
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.library_files')) ?></label>
            <input type="file" name="files[]" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg" class="block w-full text-sm text-gray-500 file:mr-3 file:h-10 file:px-4 file:rounded-xl file:border-0 file:bg-sky-600 file:text-white file:text-xs file:font-bold">
            <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('admin.library_files_hint')) ?></p>
        </div>
        <?php if ($isEdit && !empty($files)): ?>
            <div class="space-y-1">
                <?php foreach ($files as $file): ?>
                    <a href="<?= ProductHelper::url('/admin/tasks/files/' . (int) $file['id']) ?>" class="block text-sm text-sky-700 dark:text-sky-300 truncate"><?= htmlspecialchars((string) $file['original_name']) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <button type="submit" class="h-11 px-5 rounded-2xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold uppercase tracking-wider">
            <?= htmlspecialchars($isEdit ? t('admin.task_save') : t('admin.task_create')) ?>
        </button>
    </form>
</section>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.4/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    if (!window.tinymce) return;
    var dark = document.documentElement.classList.contains('dark');
    tinymce.init({
        selector: '#task-body',
        menubar: false,
        plugins: 'lists link autolink',
        toolbar: 'undo redo | bold italic | bullist numlist | link | removeformat',
        height: 240,
        branding: false,
        skin: dark ? 'oxide-dark' : 'oxide',
        content_css: dark ? 'dark' : 'default',
        convert_urls: false
    });
    document.querySelector('form[enctype="multipart/form-data"]')?.addEventListener('submit', function () {
        if (window.tinymce) tinymce.triggerSave();
    });
})();
</script>
