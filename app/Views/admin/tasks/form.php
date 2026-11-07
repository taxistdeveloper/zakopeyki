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
$e = static fn ($s): string => htmlspecialchars((string) $s);
$cancelUrl = $isEdit
    ? ProductHelper::url('/admin/tasks/' . (int) $task['id'])
    : ProductHelper::url('/admin/tasks');
$deadlineVal = '';
if ($isEdit && !empty($task['deadline'])) {
    $deadlineVal = str_replace(' ', 'T', substr((string) $task['deadline'], 0, 16));
}
?>
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumb">
                <a href="<?= ProductHelper::url('/admin/tasks') ?>"><?= $e(t('admin.tasks')) ?></a>
                <?php if ($isEdit): ?>
                    <span class="sep">/</span>
                    <a href="<?= $cancelUrl ?>" class="truncate" style="max-width:40ch"><?= $e($task['title'] ?? '') ?></a>
                <?php endif; ?>
            </nav>
            <h1 class="adm-title"><?= $e($title ?? t('admin.task_new')) ?></h1>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="adm-card" style="max-width:880px">
        <?= csrf_field() ?>
        <div class="adm-card-body">
            <div class="adm-form-grid">
                <div class="adm-field span-2">
                    <label class="adm-label" for="task-title"><?= $e(t('admin.task_title')) ?></label>
                    <input id="task-title" type="text" name="title" required minlength="2" maxlength="240" class="adm-input"
                           value="<?= $e($task['title'] ?? '') ?>">
                </div>
                <div class="adm-field span-2">
                    <label class="adm-label" for="task-body"><?= $e(t('admin.task_description')) ?></label>
                    <textarea id="task-body" name="description" rows="8" class="adm-textarea" style="min-height:180px"><?= $e($task['description'] ?? '') ?></textarea>
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="task-priority"><?= $e(t('admin.task_priority')) ?></label>
                    <select id="task-priority" name="priority" class="adm-select">
                        <?php foreach (OpsTask::PRIORITIES as $pr): ?>
                            <option value="<?= $pr ?>" <?= ($task['priority'] ?? 'medium') === $pr ? 'selected' : '' ?>><?= $e(t('admin.task_priority_' . $pr)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="task-status"><?= $e(t('admin.status')) ?></label>
                    <select id="task-status" name="status" class="adm-select">
                        <?php foreach (OpsTask::STATUSES as $st): ?>
                            <option value="<?= $st ?>" <?= ($task['status'] ?? 'new') === $st ? 'selected' : '' ?>><?= $e(t('admin.task_status_' . $st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="task-assignee"><?= $e(t('admin.task_assignee')) ?></label>
                    <select id="task-assignee" name="assignee_id" class="adm-select">
                        <option value=""><?= $e(t('admin.task_unassigned')) ?></option>
                        <?php foreach ($staff as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($task['assignee_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= $e($u['name']) ?> (<?= $e(t('nav.role_' . $u['role'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="task-deadline"><?= $e(t('admin.task_deadline')) ?></label>
                    <input id="task-deadline" type="datetime-local" name="deadline" class="adm-input" value="<?= $e($deadlineVal) ?>">
                </div>
                <div class="adm-field span-2">
                    <label class="adm-label" for="task-files"><?= $e(t('admin.library_files')) ?></label>
                    <input id="task-files" type="file" name="files[]" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg" style="font-size:13px;max-width:100%">
                    <span class="adm-help"><?= $e(t('admin.library_files_hint')) ?></span>
                    <?php if ($isEdit && !empty($files)): ?>
                        <div style="display:flex;flex-direction:column;gap:2px;margin-top:4px">
                            <?php foreach ($files as $file): ?>
                                <a href="<?= ProductHelper::url('/admin/tasks/files/' . (int) $file['id']) ?>" class="adm-link truncate"><?= $e($file['original_name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="adm-card-foot adm-form-actions">
            <a href="<?= $cancelUrl ?>" class="adm-btn adm-btn--ghost"><?= $e(t('admin.cancel')) ?></a>
            <button type="submit" class="adm-btn adm-btn--primary">
                <?= $e($isEdit ? t('admin.task_save') : t('admin.task_create')) ?>
            </button>
        </div>
    </form>
</div>
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
