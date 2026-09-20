<?php
use App\Helpers\ProductHelper;

$document = $document ?? [];
$files = $files ?? [];
$canManage = !empty($canManage);
$tags = array_filter(array_map('trim', explode(',', (string) ($document['tags'] ?? ''))));
$docId = (int) ($document['id'] ?? 0);
?>
<section class="space-y-5 fade-up pb-8 max-w-3xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin/library') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.library')) ?></a>
            <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-600"><?= htmlspecialchars((string) ($document['category_name'] ?? '')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-0.5"><?= htmlspecialchars((string) ($document['title'] ?? '')) ?></h1>
            <p class="text-[11px] text-gray-400 mt-1">
                <?= htmlspecialchars((string) ($document['author_name'] ?? '')) ?>
                · <?= htmlspecialchars(substr((string) ($document['created_at'] ?? ''), 0, 16)) ?>
                <?php if (!empty($document['updated_at']) && $document['updated_at'] !== $document['created_at']): ?>
                    · <?= htmlspecialchars(t('admin.library_updated_at')) ?> <?= htmlspecialchars(substr((string) $document['updated_at'], 0, 16)) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canManage): ?>
            <div class="flex gap-2">
                <a href="<?= ProductHelper::url('/admin/library/' . $docId . '/edit') ?>" class="h-10 px-4 inline-flex items-center rounded-xl bg-ink-900 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.library_edit')) ?></a>
                <form method="post" action="<?= ProductHelper::url('/admin/library/' . $docId . '/delete') ?>" onsubmit="return confirm(<?= json_encode(t('admin.library_delete_confirm')) ?>)">
                    <?= csrf_field() ?>
                    <button type="submit" class="h-10 px-4 rounded-xl border border-red-200 text-red-600 text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.delete')) ?></button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($tags): ?>
        <div class="flex flex-wrap gap-1">
            <?php foreach ($tags as $tag): ?>
                <span class="px-2 py-0.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 text-[10px] font-semibold"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <article class="library-body bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-5 sm:p-7 prose prose-sm dark:prose-invert max-w-none text-ink-800 dark:text-gray-200">
        <?php if (trim(strip_tags((string) ($document['body'] ?? ''))) === ''): ?>
            <p class="text-gray-400 text-sm"><?= htmlspecialchars(t('admin.library_no_body')) ?></p>
        <?php else: ?>
            <?= $document['body'] ?>
        <?php endif; ?>
    </article>

    <?php if (!empty($files)): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5">
            <h2 class="font-display font-bold text-sm text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('admin.library_attached')) ?></h2>
            <div class="space-y-2">
                <?php foreach ($files as $file): ?>
                    <a href="<?= ProductHelper::url('/admin/library/files/' . (int) $file['id']) ?>" class="flex items-center justify-between gap-2 rounded-xl border border-black/[0.06] dark:border-white/10 px-3 py-2.5 hover:border-indigo-400/50">
                        <span class="text-sm font-semibold truncate"><?= htmlspecialchars((string) $file['original_name']) ?></span>
                        <span class="text-[11px] text-gray-400"><?= number_format(((int) $file['size_bytes']) / 1024, 0, ',', ' ') ?> KB</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<style>
.library-body h1,.library-body h2,.library-body h3 { font-family: Sora, sans-serif; font-weight: 700; margin: 0.8em 0 0.4em; }
.library-body ul { list-style: disc; padding-left: 1.25rem; }
.library-body ol { list-style: decimal; padding-left: 1.25rem; }
.library-body a { color: #2563EB; text-decoration: underline; }
.library-body table { width: 100%; border-collapse: collapse; font-size: 13px; }
.library-body td,.library-body th { border: 1px solid rgba(0,0,0,.08); padding: 6px 8px; }
</style>
