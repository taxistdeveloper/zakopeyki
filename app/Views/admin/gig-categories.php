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
$input = 'adm-input';
$select = 'adm-select';
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= htmlspecialchars(t('admin.gig_categories')) ?></h1>
            <p class="adm-sub"><?= htmlspecialchars(t('admin.gig_categories_hint')) ?></p>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= ProductHelper::url('/admin/gig-categories') ?>" class="adm-card">
        <?= csrf_field() ?>
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.gig_cat_add')) ?></h2>
        </div>
        <div class="adm-card-body adm-stack" style="gap:14px">
            <div class="adm-form-grid">
                <div class="adm-field">
                    <label class="adm-label" for="gig-cat-new-name"><?= htmlspecialchars(t('admin.gig_cat_name')) ?></label>
                    <input type="text" id="gig-cat-new-name" name="name" required minlength="2" maxlength="180" class="<?= $input ?>" placeholder="<?= htmlspecialchars(t('admin.gig_cat_name')) ?>">
                </div>
                <div class="adm-field">
                    <label class="adm-label" for="gig-cat-new-parent"><?= htmlspecialchars(t('admin.gig_cat_parent')) ?></label>
                    <select id="gig-cat-new-parent" name="parent_id" class="<?= $select ?>">
                        <option value=""><?= htmlspecialchars(t('admin.gig_cat_parent_root')) ?></option>
                        <?php foreach ($parentChoices as $opt):
                            $prefix = str_repeat('— ', (int) ($opt['depth'] ?? 0));
                        ?>
                            <option value="<?= (int) $opt['id'] ?>">
                                <?= htmlspecialchars($prefix . $opt['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="adm-help"><?= htmlspecialchars(t('admin.gig_cat_parent_hint')) ?></p>
                </div>
            </div>
            <label class="adm-check" style="align-items:flex-start">
                <input type="checkbox" name="is_unskilled_only" value="1" checked style="margin-top:1px">
                <span>
                    <span class="strong" style="display:block;color:var(--adm-text)"><?= htmlspecialchars(t('admin.gig_cat_unskilled')) ?></span>
                    <span class="adm-help" style="display:block;margin-top:2px"><?= htmlspecialchars(t('gigs.lead')) ?></span>
                </span>
            </label>
        </div>
        <div class="adm-card-foot adm-form-actions">
            <button type="submit" class="adm-btn adm-btn--primary"><?= htmlspecialchars(t('admin.gig_cat_create')) ?></button>
        </div>
    </form>

    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.gig_categories')) ?></h2>
            <?php if (!empty($categories) && $pages > 1): ?>
                <span class="muted" style="font-size:12px"><?= htmlspecialchars(t('admin.gig_cat_page', ['current' => (string) $page, 'total' => (string) $pages])) ?></span>
            <?php endif; ?>
        </div>
    <?php if (empty($categories)): ?>
        <div class="adm-empty"><?= htmlspecialchars(t('admin.gig_cat_empty')) ?></div>
    <?php else: ?>
        <div class="adm-list">
            <?php foreach ($categories as $cat):
                $cid = (int) $cat['id'];
                $inUse = (int) $cat['task_count'] > 0 || (int) ($cat['child_count'] ?? 0) > 0;
                $depth = (int) ($cat['depth'] ?? 0);
                $levelKey = $depth === 0 ? 'admin.gig_cat_level_category' : ($depth === 1 ? 'admin.gig_cat_level_sub' : 'admin.gig_cat_level_service');
            ?>
                <div class="adm-row" style="display:block;padding-left:<?= 16 + min($depth, 3) * 24 ?>px">
                    <form method="post" action="<?= ProductHelper::url('/admin/gig-categories/' . $cid . '/update') ?>" class="adm-toolbar" style="gap:8px 12px">
                        <?= csrf_field() ?>
                        <div style="width:150px;flex-shrink:0">
                            <div class="adm-row-title"><?= htmlspecialchars(t($levelKey)) ?> <span class="subtle">#<?= $cid ?></span></div>
                            <div class="adm-row-sub"><?= htmlspecialchars(t('admin.gig_cat_tasks')) ?>: <span class="num"><?= (int) $cat['task_count'] ?></span></div>
                        </div>
                        <input type="text" name="name" required minlength="2" maxlength="180" class="<?= $input ?>" style="flex:1 1 220px"
                               aria-label="<?= htmlspecialchars(t('admin.gig_cat_name')) ?>"
                               value="<?= htmlspecialchars((string) $cat['name']) ?>">
                        <?php if ($depth < 2): ?>
                            <select name="parent_id" class="<?= $select ?>" style="flex:0 1 220px" aria-label="<?= htmlspecialchars(t('admin.gig_cat_parent')) ?>" title="<?= htmlspecialchars(t('admin.gig_cat_parent')) ?>">
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
                        <?php else: ?>
                            <input type="hidden" name="parent_id" value="<?= (int) ($cat['parent_id'] ?? 0) ?>">
                        <?php endif; ?>
                        <label class="adm-check nowrap">
                            <input type="checkbox" name="is_unskilled_only" value="1" <?= (int) $cat['is_unskilled_only'] === 1 ? 'checked' : '' ?>>
                            <?= htmlspecialchars(t('admin.gig_cat_unskilled')) ?>
                        </label>
                        <div class="adm-toolbar" style="margin-left:auto">
                            <button type="submit" class="adm-btn adm-btn--sm"><?= htmlspecialchars(t('admin.gig_cat_save')) ?></button>
                            <?php if ($inUse): ?>
                                <span class="subtle nowrap" style="font-size:12px"><?= htmlspecialchars(t('admin.gig_cat_in_use')) ?></span>
                            <?php else: ?>
                                <button type="button"
                                        class="adm-btn adm-btn--sm adm-btn--danger"
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
            <div class="adm-pager">
                <span><?= htmlspecialchars(t('admin.gig_cat_page', ['current' => (string) $page, 'total' => (string) $pages])) ?></span>
                <div class="adm-toolbar">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="adm-btn adm-btn--sm"><?= htmlspecialchars(t('admin.logs_prev')) ?></a>
                    <?php endif; ?>
                    <div class="adm-seg">
                        <?php for ($p = 1; $p <= $pages; $p++): ?>
                            <a href="<?= htmlspecialchars($pageUrl($p)) ?>"<?= $p === $page ? ' class="is-active" aria-current="page"' : '' ?>><?= $p ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php if ($page < $pages): ?>
                        <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="adm-btn adm-btn--sm"><?= htmlspecialchars(t('admin.logs_next')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </section>

    <div id="gig-cat-confirm" class="hidden fixed inset-0 z-[90] flex items-end sm:items-center justify-center p-0 sm:p-4" style="background:rgba(16,24,40,.45)" role="dialog" aria-modal="true" aria-labelledby="gig-cat-confirm-title">
        <div class="adm-card" style="width:100%;max-width:420px">
            <div class="adm-card-head">
                <h3 class="adm-card-title" id="gig-cat-confirm-title"><?= htmlspecialchars(t('admin.delete')) ?></h3>
            </div>
            <div class="adm-card-body">
                <p id="gig-cat-confirm-body" class="muted" style="margin:0;font-size:13px"><?= htmlspecialchars(t('admin.gig_cat_delete_confirm')) ?></p>
            </div>
            <div class="adm-card-foot adm-form-actions">
                <button type="button" id="gig-cat-confirm-no" class="adm-btn"><?= htmlspecialchars(t('gigs.modal_back')) ?></button>
                <button type="button" id="gig-cat-confirm-yes" class="adm-btn adm-btn--danger"><?= htmlspecialchars(t('admin.delete')) ?></button>
            </div>
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
