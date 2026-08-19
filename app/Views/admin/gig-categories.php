<?php
use App\Helpers\ProductHelper;

$categories = $categories ?? [];
$parentChoices = $parentChoices ?? [];
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$pageUrl = static function (int $p) {
    $base = ProductHelper::url('/admin/gig-categories');
    return $p > 1 ? $base . '?page=' . $p : $base;
};
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="space-y-5 fade-up pb-8">
    <div>
        <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-teal-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
        <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.gig_categories')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.gig_categories_hint')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5">
        <h2 class="font-display font-bold text-ink-900 dark:text-white text-sm mb-3"><?= htmlspecialchars(t('admin.gig_cat_add')) ?></h2>
        <form method="post" action="<?= ProductHelper::url('/admin/gig-categories') ?>" class="space-y-3">
            <?= csrf_field() ?>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.gig_cat_name')) ?></label>
                <input type="text" name="name" required minlength="2" maxlength="180" class="<?= $input ?>" placeholder="<?= htmlspecialchars(t('admin.gig_cat_name')) ?>">
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.gig_cat_parent')) ?></label>
                <select name="parent_id" class="<?= $input ?>">
                    <option value=""><?= htmlspecialchars(t('admin.gig_cat_parent_root')) ?></option>
                    <?php foreach ($parentChoices as $opt):
                        $prefix = str_repeat('— ', (int) ($opt['depth'] ?? 0));
                    ?>
                        <option value="<?= (int) $opt['id'] ?>">
                            <?= htmlspecialchars($prefix . $opt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('admin.gig_cat_parent_hint')) ?></p>
            </div>
            <label class="flex items-start gap-3 rounded-2xl border border-black/[0.08] dark:border-white/10 p-3.5 cursor-pointer">
                <input type="checkbox" name="is_unskilled_only" value="1" checked class="mt-0.5 rounded border-gray-300">
                <span>
                    <span class="block text-sm font-semibold text-ink-800 dark:text-gray-100"><?= htmlspecialchars(t('admin.gig_cat_unskilled')) ?></span>
                    <span class="block text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars(t('gigs.lead')) ?></span>
                </span>
            </label>
            <button type="submit" class="h-11 px-5 rounded-2xl bg-teal-600 hover:bg-teal-500 text-white text-xs font-bold uppercase tracking-wider transition">
                <?= htmlspecialchars(t('admin.gig_cat_create')) ?>
            </button>
        </form>
    </div>

    <?php if (empty($categories)): ?>
        <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('admin.gig_cat_empty')) ?>
        </div>
    <?php else: ?>
        <?php if ($pages > 1): ?>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.gig_cat_page', ['current' => (string) $page, 'total' => (string) $pages])) ?></p>
                <div class="flex flex-wrap items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50"><?= htmlspecialchars(t('admin.logs_prev')) ?></a>
                    <?php endif; ?>
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <a href="<?= htmlspecialchars($pageUrl($p)) ?>"
                           class="h-9 min-w-9 px-2.5 inline-flex items-center justify-center rounded-xl text-xs font-semibold border <?= $p === $page ? 'bg-teal-600 text-white border-teal-600' : 'bg-white/80 dark:bg-white/[0.04] border-black/[0.06] dark:border-white/10 hover:border-brand-400/50' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50"><?= htmlspecialchars(t('admin.logs_next')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="space-y-3">
            <?php foreach ($categories as $cat):
                $cid = (int) $cat['id'];
                $inUse = (int) $cat['task_count'] > 0 || (int) ($cat['child_count'] ?? 0) > 0;
                $depth = (int) ($cat['depth'] ?? 0);
                $levelKey = $depth === 0 ? 'admin.gig_cat_level_category' : ($depth === 1 ? 'admin.gig_cat_level_sub' : 'admin.gig_cat_level_service');
            ?>
                <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5" style="margin-left: <?= min($depth, 3) * 18 ?>px">
                    <form method="post" action="<?= ProductHelper::url('/admin/gig-categories/' . $cid . '/update') ?>" class="space-y-3">
                        <?= csrf_field() ?>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-teal-600"><?= htmlspecialchars(t($levelKey)) ?> · #<?= $cid ?></span>
                            <span class="text-[11px] font-semibold text-gray-500"><?= htmlspecialchars(t('admin.gig_cat_tasks')) ?>: <?= (int) $cat['task_count'] ?></span>
                        </div>
                        <input type="text" name="name" required minlength="2" maxlength="180" class="<?= $input ?>"
                               value="<?= htmlspecialchars((string) $cat['name']) ?>">
                        <?php if ($depth < 2): ?>
                        <div>
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('admin.gig_cat_parent')) ?></label>
                            <select name="parent_id" class="<?= $input ?>">
                                <option value=""><?= htmlspecialchars(t('admin.gig_cat_parent_root')) ?></option>
                                <?php foreach ($parentChoices as $opt):
                                    $oid = (int) $opt['id'];
                                    if ($oid === $cid) {
                                        continue;
                                    }
                                    $prefix = str_repeat('— ', (int) ($opt['depth'] ?? 0));
                                ?>
                                    <option value="<?= $oid ?>" <?= (int) ($cat['parent_id'] ?? 0) === $oid ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prefix . $opt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="parent_id" value="<?= (int) ($cat['parent_id'] ?? 0) ?>">
                        <?php endif; ?>
                        <label class="flex items-center gap-2 text-sm font-semibold cursor-pointer">
                            <input type="checkbox" name="is_unskilled_only" value="1" <?= (int) $cat['is_unskilled_only'] === 1 ? 'checked' : '' ?> class="rounded border-gray-300">
                            <?= htmlspecialchars(t('admin.gig_cat_unskilled')) ?>
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="h-10 px-4 rounded-xl bg-ink-900 hover:bg-ink-800 text-white text-xs font-bold uppercase tracking-wider">
                                <?= htmlspecialchars(t('admin.gig_cat_save')) ?>
                            </button>
                            <?php if ($inUse): ?>
                                <span class="inline-flex items-center h-10 px-3 text-[11px] text-gray-400"><?= htmlspecialchars(t('admin.gig_cat_in_use')) ?></span>
                            <?php else: ?>
                                <button type="button"
                                        class="h-10 px-4 rounded-xl border border-red-200 dark:border-red-900/40 text-red-600 text-xs font-bold uppercase tracking-wider"
                                        data-gig-cat-delete="<?= $cid ?>"
                                        data-gig-cat-name="<?= htmlspecialchars((string) $cat['name'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars(t('admin.delete')) ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if (!$inUse): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/gig-categories/' . $cid . '/delete') ?>" id="gig-cat-del-<?= $cid ?>" class="hidden">
                            <?= csrf_field() ?>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
            <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50"><?= htmlspecialchars(t('admin.logs_prev')) ?></a>
                <?php endif; ?>
                <span class="text-xs text-gray-500"><?= htmlspecialchars(t('admin.gig_cat_page', ['current' => (string) $page, 'total' => (string) $pages])) ?></span>
                <?php if ($page < $pages): ?>
                    <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50"><?= htmlspecialchars(t('admin.logs_next')) ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<div id="gig-cat-confirm" class="hidden fixed inset-0 z-[90] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" role="dialog" aria-modal="true">
    <div class="w-full sm:max-w-md bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10">
        <div class="sm:hidden flex justify-center pt-3" aria-hidden="true"><span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span></div>
        <div class="px-5 pt-5 pb-2 text-center space-y-3">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-red-50 dark:bg-red-500/15 flex items-center justify-center text-red-600">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
            </div>
            <h3 class="font-display text-xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.delete')) ?></h3>
            <p id="gig-cat-confirm-body" class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"><?= htmlspecialchars(t('admin.gig_cat_delete_confirm')) ?></p>
        </div>
        <div class="p-5 pt-3 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
            <button type="button" id="gig-cat-confirm-no" class="order-2 sm:order-1 py-3 rounded-2xl border border-black/10 dark:border-white/15 text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('gigs.modal_back')) ?></button>
            <button type="button" id="gig-cat-confirm-yes" class="order-1 sm:order-2 py-3 rounded-2xl bg-red-600 hover:bg-red-500 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('admin.delete')) ?></button>
        </div>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('gig-cat-confirm');
    const body = document.getElementById('gig-cat-confirm-body');
    const yes = document.getElementById('gig-cat-confirm-yes');
    const no = document.getElementById('gig-cat-confirm-no');
    if (!modal || !yes || !no) return;
    let pending = null;
    const hint = <?= json_encode(t('admin.gig_cat_delete_confirm')) ?>;
    document.querySelectorAll('[data-gig-cat-delete]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pending = btn.dataset.gigCatDelete;
            body.textContent = hint + ' «' + (btn.dataset.gigCatName || '') + '»';
            modal.classList.remove('hidden');
        });
    });
    function close() {
        modal.classList.add('hidden');
        pending = null;
    }
    no.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    yes.addEventListener('click', function () {
        if (!pending) return;
        const form = document.getElementById('gig-cat-del-' + pending);
        if (form) form.submit();
        close();
    });
})();
</script>
