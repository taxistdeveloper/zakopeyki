<?php
use App\Helpers\ProductHelper;

$document = $document ?? null;
$files = $files ?? [];
$categories = $categories ?? [];
$prefillCategory = (int) ($prefillCategory ?? 0);
$isEdit = is_array($document);
$action = $isEdit
    ? ProductHelper::url('/admin/library/' . (int) $document['id'] . '/update')
    : ProductHelper::url('/admin/library');
$e = static fn ($s): string => htmlspecialchars((string) $s);
$cancelUrl = $isEdit
    ? ProductHelper::url('/admin/library/' . (int) $document['id'])
    : ProductHelper::url('/admin/library');
$tags = $isEdit ? (string) ($document['tags'] ?? '') : '';
$selectedCat = $isEdit ? (int) $document['category_id'] : $prefillCategory;
?>
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumb">
                <a href="<?= ProductHelper::url('/admin/library') ?>"><?= $e(t('admin.library')) ?></a>
                <?php if ($isEdit): ?>
                    <span class="sep">/</span>
                    <a href="<?= $cancelUrl ?>" class="truncate" style="max-width:40ch"><?= $e($document['title'] ?? '') ?></a>
                <?php endif; ?>
            </nav>
            <h1 class="adm-title"><?= $e($title ?? t('admin.library_new')) ?></h1>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="adm-card" style="max-width:960px">
        <?= csrf_field() ?>
        <div class="adm-card-body">
            <div class="adm-form-grid">
                <div class="adm-field span-2">
                    <label class="adm-label" for="library-title"><?= $e(t('admin.library_doc_title')) ?></label>
                    <input id="library-title" type="text" name="title" required minlength="2" maxlength="240" class="adm-input"
                           value="<?= $e($document['title'] ?? '') ?>">
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="library-category"><?= $e(t('admin.library_category')) ?></label>
                    <select id="library-category" name="category_id" required class="adm-select">
                        <option value=""><?= $e(t('admin.library_cat_choose')) ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= $selectedCat === (int) $cat['id'] ? 'selected' : '' ?>>
                                <?= $e(str_repeat('— ', (int) ($cat['depth'] ?? 0)) . $cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="library-tags"><?= $e(t('admin.library_tags')) ?></label>
                    <input id="library-tags" type="text" name="tags" maxlength="500" class="adm-input" value="<?= $e($tags) ?>"
                           placeholder="<?= $e(t('admin.library_tags_ph')) ?>">
                </div>
                <div class="adm-field span-2">
                    <label class="adm-label" for="library-body"><?= $e(t('admin.library_body')) ?></label>
                    <textarea id="library-body" name="body" rows="12" class="adm-textarea" style="min-height:280px"><?= $e($document['body'] ?? '') ?></textarea>
                </div>
                <div class="adm-field span-2">
                    <label class="adm-label" for="library-files"><?= $e(t('admin.library_files')) ?></label>
                    <input id="library-files" type="file" name="files[]" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg" style="font-size:13px;max-width:100%">
                    <span class="adm-help"><?= $e(t('admin.library_files_hint')) ?></span>
                </div>
            </div>
        </div>

        <?php if ($isEdit && !empty($files)): ?>
            <div class="adm-card-head" style="border-top:1px solid var(--adm-border)">
                <h2 class="adm-card-title"><?= $e(t('admin.library_attached')) ?> <span class="adm-count"><?= count($files) ?></span></h2>
            </div>
            <div class="adm-list">
                <?php foreach ($files as $file): ?>
                    <div class="adm-row">
                        <a href="<?= ProductHelper::url('/admin/library/files/' . (int) $file['id']) ?>" class="adm-row-main adm-row-title adm-link truncate">
                            <?= $e($file['original_name']) ?>
                        </a>
                        <button type="submit" form="lib-file-del-<?= (int) $file['id'] ?>" class="adm-link adm-link--danger"
                                onclick="return confirm(<?= $e(json_encode(t('admin.library_file_delete_confirm'))) ?>)"><?= $e(t('admin.delete')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="adm-card-foot adm-form-actions">
            <a href="<?= $cancelUrl ?>" class="adm-btn adm-btn--ghost"><?= $e(t('admin.cancel')) ?></a>
            <button type="submit" class="adm-btn adm-btn--primary">
                <?= $e($isEdit ? t('admin.library_save') : t('admin.library_create')) ?>
            </button>
        </div>
    </form>

    <?php if ($isEdit): ?>
        <?php foreach ($files as $file): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/library/files/' . (int) $file['id'] . '/delete') ?>" id="lib-file-del-<?= (int) $file['id'] ?>" hidden><?= csrf_field() ?></form>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.4/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    if (!window.tinymce) return;
    var dark = document.documentElement.classList.contains('dark');
    tinymce.init({
        selector: '#library-body',
        menubar: false,
        plugins: 'lists link table autolink',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat',
        height: 360,
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
