<?php
use App\Helpers\ProductHelper;

$categories = $categories ?? [];
$documents = $documents ?? [];
$filters = $filters ?? [];
$canManage = !empty($canManage);
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$q = (string) ($filters['q'] ?? '');
$categoryId = (int) ($filters['category_id'] ?? 0);
$dateFrom = (string) ($filters['date_from'] ?? '');
$dateTo = (string) ($filters['date_to'] ?? '');
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';

$query = static function (array $extra = []) use ($q, $categoryId, $dateFrom, $dateTo): string {
    $params = array_filter([
        'q' => $extra['q'] ?? $q,
        'category' => (string) ($extra['category'] ?? $categoryId),
        'date_from' => $extra['date_from'] ?? $dateFrom,
        'date_to' => $extra['date_to'] ?? $dateTo,
        'page' => isset($extra['page']) ? (string) $extra['page'] : null,
    ], static fn ($v) => $v !== null && $v !== '' && $v !== '0');
    $qs = http_build_query($params);
    return ProductHelper::url('/admin/library') . ($qs !== '' ? '?' . $qs : '');
};
?>
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-indigo-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.library')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.library_hint')) ?></p>
        </div>
        <?php if ($canManage): ?>
            <a href="<?= ProductHelper::url('/admin/library/new' . ($categoryId ? '?category=' . $categoryId : '')) ?>"
               class="h-11 px-4 inline-flex items-center rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold uppercase tracking-wider">
                <?= htmlspecialchars(t('admin.library_new')) ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/admin/library') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="lg:col-span-2">
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.library_search')) ?></label>
            <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" class="<?= $input ?>" placeholder="<?= htmlspecialchars(t('admin.library_search_ph')) ?>">
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.library_category')) ?></label>
            <select name="category" class="<?= $input ?>">
                <option value=""><?= htmlspecialchars(t('admin.library_all_cats')) ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars(str_repeat('— ', (int) ($cat['depth'] ?? 0)) . $cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.library_date_from')) ?></label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="<?= $input ?>">
        </div>
        <div>
            <label class="block text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('admin.library_date_to')) ?></label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="<?= $input ?>">
        </div>
        <div class="sm:col-span-2 lg:col-span-5 flex gap-2">
            <button type="submit" class="h-11 px-5 rounded-xl bg-ink-900 hover:bg-ink-800 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.users_find')) ?></button>
            <a href="<?= ProductHelper::url('/admin/library') ?>" class="h-11 px-4 inline-flex items-center rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold"><?= htmlspecialchars(t('admin.tickets_all')) ?></a>
        </div>
    </form>

    <div class="grid grid-cols-1 lg:grid-cols-[280px_minmax(0,1fr)] gap-4">
        <aside class="space-y-3">
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4">
                <h2 class="font-display font-bold text-sm text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('admin.library_folders')) ?></h2>
                <a href="<?= htmlspecialchars($query(['category' => '', 'page' => null])) ?>"
                   class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-sm <?= $categoryId === 0 ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-semibold' : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.04]' ?>">
                    <span><?= htmlspecialchars(t('admin.library_all_cats')) ?></span>
                    <span class="text-[11px] text-gray-400"><?= (int) ($total ?? 0) ?></span>
                </a>
                <div class="mt-1 space-y-0.5">
                    <?php foreach ($categories as $cat):
                        $cid = (int) $cat['id'];
                        $depth = (int) ($cat['depth'] ?? 0);
                    ?>
                        <a href="<?= htmlspecialchars($query(['category' => (string) $cid, 'page' => null])) ?>"
                           class="flex items-center justify-between gap-2 px-3 py-2 rounded-xl text-sm <?= $categoryId === $cid ? 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 font-semibold' : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.04]' ?>"
                           style="padding-left: <?= 12 + min($depth, 2) * 14 ?>px">
                            <span class="truncate"><?= htmlspecialchars((string) $cat['name']) ?></span>
                            <span class="text-[11px] text-gray-400"><?= (int) ($cat['doc_count'] ?? 0) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($canManage): ?>
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4">
                <h2 class="font-display font-bold text-sm text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('admin.library_cat_add')) ?></h2>
                <form method="post" action="<?= ProductHelper::url('/admin/library/categories') ?>" class="space-y-2">
                    <?= csrf_field() ?>
                    <input type="text" name="name" required minlength="2" maxlength="180" class="<?= $input ?>" placeholder="<?= htmlspecialchars(t('admin.library_cat_name')) ?>">
                    <select name="parent_id" class="<?= $input ?>">
                        <option value=""><?= htmlspecialchars(t('admin.library_cat_root')) ?></option>
                        <?php foreach ($categories as $opt):
                            if ((int) ($opt['depth'] ?? 0) >= 1) {
                                continue;
                            }
                        ?>
                            <option value="<?= (int) $opt['id'] ?>"><?= htmlspecialchars((string) $opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('admin.library_cat_parent_hint')) ?></p>
                    <button type="submit" class="h-10 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold uppercase tracking-wider w-full">
                        <?= htmlspecialchars(t('admin.library_cat_create')) ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </aside>

        <div class="space-y-3 min-w-0">
            <?php if (empty($documents)): ?>
                <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
                    <?= htmlspecialchars(t('admin.library_empty')) ?>
                </div>
            <?php else: ?>
                <?php foreach ($documents as $doc):
                    $did = (int) $doc['id'];
                    $tags = array_filter(array_map('trim', explode(',', (string) ($doc['tags'] ?? ''))));
                ?>
                    <a href="<?= ProductHelper::url('/admin/library/' . $did) ?>" class="block bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 hover:border-indigo-400/50 transition">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-600"><?= htmlspecialchars((string) ($doc['category_name'] ?? '')) ?></p>
                                <h3 class="font-display font-bold text-ink-900 dark:text-white mt-0.5"><?= htmlspecialchars((string) $doc['title']) ?></h3>
                                <p class="text-[11px] text-gray-400 mt-1">
                                    <?= htmlspecialchars((string) ($doc['author_name'] ?? '')) ?>
                                    · <?= htmlspecialchars(substr((string) $doc['created_at'], 0, 16)) ?>
                                    <?php if ((int) ($doc['file_count'] ?? 0) > 0): ?>
                                        · <?= (int) $doc['file_count'] ?> <?= htmlspecialchars(t('admin.library_files_short')) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($tags): ?>
                                    <div class="flex flex-wrap gap-1 mt-2">
                                        <?php foreach ($tags as $tag): ?>
                                            <span class="px-2 py-0.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 text-[10px] font-semibold"><?= htmlspecialchars($tag) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="text-xs font-bold text-indigo-600"><?= htmlspecialchars(t('admin.open')) ?> →</span>
                        </div>
                    </a>
                <?php endforeach; ?>

                <?php if ($pages > 1): ?>
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.gig_cat_page', ['current' => (string) $page, 'total' => (string) $pages])) ?></p>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="<?= htmlspecialchars($query(['page' => (string) ($page - 1)])) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06]"><?= htmlspecialchars(t('admin.logs_prev')) ?></a>
                            <?php endif; ?>
                            <?php if ($page < $pages): ?>
                                <a href="<?= htmlspecialchars($query(['page' => (string) ($page + 1)])) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06]"><?= htmlspecialchars(t('admin.logs_next')) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($canManage && $categoryId > 0):
                $current = null;
                foreach ($categories as $cat) {
                    if ((int) $cat['id'] === $categoryId) {
                        $current = $cat;
                        break;
                    }
                }
                if ($current):
                    $inUse = (int) ($current['doc_count'] ?? 0) > 0 || (int) ($current['child_count'] ?? 0) > 0;
            ?>
                <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5">
                    <h2 class="font-display font-bold text-sm text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('admin.library_cat_edit')) ?></h2>
                    <form method="post" action="<?= ProductHelper::url('/admin/library/categories/' . $categoryId . '/update') ?>" class="space-y-2">
                        <?= csrf_field() ?>
                        <input type="text" name="name" required minlength="2" maxlength="180" class="<?= $input ?>" value="<?= htmlspecialchars((string) $current['name']) ?>">
                        <?php if ((int) ($current['depth'] ?? 0) === 0): ?>
                            <input type="hidden" name="parent_id" value="">
                        <?php else: ?>
                            <select name="parent_id" class="<?= $input ?>">
                                <option value=""><?= htmlspecialchars(t('admin.library_cat_root')) ?></option>
                                <?php foreach ($categories as $opt):
                                    if ((int) ($opt['depth'] ?? 0) >= 1 || (int) $opt['id'] === $categoryId) {
                                        continue;
                                    }
                                ?>
                                    <option value="<?= (int) $opt['id'] ?>" <?= (int) ($current['parent_id'] ?? 0) === (int) $opt['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $opt['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="h-10 px-4 rounded-xl bg-ink-900 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.library_cat_save')) ?></button>
                            <?php if (!$inUse): ?>
                                <button type="submit" form="lib-cat-del-<?= $categoryId ?>" class="h-10 px-4 rounded-xl border border-red-200 text-red-600 text-xs font-bold uppercase tracking-wider"
                                        onclick="return confirm(<?= json_encode(t('admin.library_cat_delete_confirm')) ?>)"><?= htmlspecialchars(t('admin.delete')) ?></button>
                            <?php else: ?>
                                <span class="inline-flex items-center text-[11px] text-gray-400"><?= htmlspecialchars(t('admin.library_cat_in_use')) ?></span>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if (!$inUse): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/library/categories/' . $categoryId . '/delete') ?>" id="lib-cat-del-<?= $categoryId ?>" class="hidden"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </div>
            <?php endif; endif; ?>
        </div>
    </div>
</section>
