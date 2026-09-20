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
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$tags = $isEdit ? (string) ($document['tags'] ?? '') : '';
$selectedCat = $isEdit ? (int) $document['category_id'] : $prefillCategory;
?>
<section class="space-y-5 fade-up pb-8 max-w-3xl">
    <div>
        <a href="<?= ProductHelper::url('/admin/library') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.library')) ?></a>
        <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars((string) ($title ?? t('admin.library_new'))) ?></h1>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $action ?>" enctype="multipart/form-data" class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-6 space-y-4">
        <?= csrf_field() ?>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.library_doc_title')) ?></label>
            <input type="text" name="title" required minlength="2" maxlength="240" class="<?= $input ?>"
                   value="<?= htmlspecialchars((string) ($document['title'] ?? '')) ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.library_category')) ?></label>
            <select name="category_id" required class="<?= $input ?>">
                <option value=""><?= htmlspecialchars(t('admin.library_cat_choose')) ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $selectedCat === (int) $cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(str_repeat('— ', (int) ($cat['depth'] ?? 0)) . $cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.library_tags')) ?></label>
            <input type="text" name="tags" maxlength="500" class="<?= $input ?>" value="<?= htmlspecialchars($tags) ?>"
                   placeholder="<?= htmlspecialchars(t('admin.library_tags_ph')) ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.library_body')) ?></label>
            <textarea id="library-body" name="body" rows="12" class="<?= $input ?> min-h-[280px] py-3"><?= htmlspecialchars((string) ($document['body'] ?? '')) ?></textarea>
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.library_files')) ?></label>
            <input type="file" name="files[]" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg" class="block w-full text-sm text-gray-500 file:mr-3 file:h-10 file:px-4 file:rounded-xl file:border-0 file:bg-indigo-600 file:text-white file:text-xs file:font-bold">
            <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('admin.library_files_hint')) ?></p>
        </div>

        <?php if ($isEdit && !empty($files)): ?>
            <div class="space-y-2">
                <p class="text-xs font-bold"><?= htmlspecialchars(t('admin.library_attached')) ?></p>
                <?php foreach ($files as $file): ?>
                    <div class="flex items-center justify-between gap-2 rounded-xl border border-black/[0.06] dark:border-white/10 px-3 py-2">
                        <a href="<?= ProductHelper::url('/admin/library/files/' . (int) $file['id']) ?>" class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 truncate">
                            <?= htmlspecialchars((string) $file['original_name']) ?>
                        </a>
                        <button type="submit" form="lib-file-del-<?= (int) $file['id'] ?>" class="text-xs font-bold text-red-600"
                                onclick="return confirm(<?= json_encode(t('admin.library_file_delete_confirm')) ?>)"><?= htmlspecialchars(t('admin.delete')) ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <button type="submit" class="h-11 px-5 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold uppercase tracking-wider">
            <?= htmlspecialchars($isEdit ? t('admin.library_save') : t('admin.library_create')) ?>
        </button>
    </form>

    <?php if ($isEdit): ?>
        <?php foreach ($files as $file): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/library/files/' . (int) $file['id'] . '/delete') ?>" id="lib-file-del-<?= (int) $file['id'] ?>" class="hidden"><?= csrf_field() ?></form>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
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
