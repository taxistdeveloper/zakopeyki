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
$e = static fn ($s): string => htmlspecialchars((string) $s);

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
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.library')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.library_hint')) ?></p>
        </div>
        <?php if ($canManage): ?>
            <div class="adm-head-actions">
                <a href="<?= ProductHelper::url('/admin/library/new' . ($categoryId ? '?category=' . $categoryId : '')) ?>" class="adm-btn adm-btn--primary"><?= $e(t('admin.library_new')) ?></a>
            </div>
        <?php endif; ?>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/admin/library') ?>" class="adm-toolbar" style="align-items:flex-end">
        <label class="adm-field grow" style="min-width:220px">
            <span class="adm-label"><?= $e(t('admin.library_search')) ?></span>
            <input type="search" name="q" value="<?= $e($q) ?>" class="adm-input" placeholder="<?= $e(t('admin.library_search_ph')) ?>">
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.library_category')) ?></span>
            <select name="category" class="adm-select">
                <option value=""><?= $e(t('admin.library_all_cats')) ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $categoryId === (int) $cat['id'] ? 'selected' : '' ?>>
                        <?= $e(str_repeat('— ', (int) ($cat['depth'] ?? 0)) . $cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.library_date_from')) ?></span>
            <input type="date" name="date_from" value="<?= $e($dateFrom) ?>" class="adm-input" style="min-width:150px">
        </label>
        <label class="adm-field">
            <span class="adm-label"><?= $e(t('admin.library_date_to')) ?></span>
            <input type="date" name="date_to" value="<?= $e($dateTo) ?>" class="adm-input" style="min-width:150px">
        </label>
        <button type="submit" class="adm-btn"><?= $e(t('admin.users_find')) ?></button>
        <a href="<?= ProductHelper::url('/admin/library') ?>" class="adm-btn adm-btn--ghost"><?= $e(t('admin.tickets_all')) ?></a>
    </form>

    <div class="adm-lib-grid">
        <aside class="adm-stack">
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= $e(t('admin.library_folders')) ?></h2>
                </div>
                <div class="adm-list">
                    <a href="<?= $e($query(['category' => '', 'page' => null])) ?>"
                       class="adm-row adm-folder<?= $categoryId === 0 ? ' is-active' : '' ?>"<?= $categoryId === 0 ? ' aria-current="page"' : '' ?>>
                        <span class="adm-row-title truncate"><?= $e(t('admin.library_all_cats')) ?></span>
                        <span class="adm-row-side"><?= (int) ($total ?? 0) ?></span>
                    </a>
                    <?php foreach ($categories as $cat):
                        $cid = (int) $cat['id'];
                        $depth = (int) ($cat['depth'] ?? 0);
                    ?>
                        <a href="<?= $e($query(['category' => (string) $cid, 'page' => null])) ?>"
                           class="adm-row adm-folder<?= $categoryId === $cid ? ' is-active' : '' ?>"<?= $categoryId === $cid ? ' aria-current="page"' : '' ?>
                           style="padding-left: <?= 16 + min($depth, 2) * 14 ?>px">
                            <span class="adm-row-title truncate"><?= $e($cat['name']) ?></span>
                            <span class="adm-row-side"><?= (int) ($cat['doc_count'] ?? 0) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($canManage): ?>
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= $e(t('admin.library_cat_add')) ?></h2>
                </div>
                <form method="post" action="<?= ProductHelper::url('/admin/library/categories') ?>" class="adm-card-body" style="display:flex;flex-direction:column;gap:8px">
                    <?= csrf_field() ?>
                    <input type="text" name="name" required minlength="2" maxlength="180" class="adm-input" placeholder="<?= $e(t('admin.library_cat_name')) ?>" aria-label="<?= $e(t('admin.library_cat_name')) ?>">
                    <select name="parent_id" class="adm-select" aria-label="<?= $e(t('admin.library_cat_parent')) ?>">
                        <option value=""><?= $e(t('admin.library_cat_root')) ?></option>
                        <?php foreach ($categories as $opt):
                            if ((int) ($opt['depth'] ?? 0) >= 1) {
                                continue;
                            }
                        ?>
                            <option value="<?= (int) $opt['id'] ?>"><?= $e($opt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="adm-help"><?= $e(t('admin.library_cat_parent_hint')) ?></span>
                    <button type="submit" class="adm-btn"><?= $e(t('admin.library_cat_create')) ?></button>
                </form>
            </section>
            <?php endif; ?>
        </aside>

        <div class="adm-stack" style="min-width:0">
            <section class="adm-card">
                <?php if (empty($documents)): ?>
                    <div class="adm-empty"><?= $e(t('admin.library_empty')) ?></div>
                <?php else: ?>
                    <div class="adm-list">
                        <?php foreach ($documents as $doc):
                            $did = (int) $doc['id'];
                            $tags = array_filter(array_map('trim', explode(',', (string) ($doc['tags'] ?? ''))));
                        ?>
                            <a href="<?= ProductHelper::url('/admin/library/' . $did) ?>" class="adm-row" style="align-items:flex-start">
                                <div class="adm-row-main">
                                    <div class="adm-row-title"><?= $e($doc['title']) ?></div>
                                    <div class="adm-row-sub">
                                        <?php if (!empty($doc['category_name'])): ?><?= $e($doc['category_name']) ?> · <?php endif; ?>
                                        <?= $e($doc['author_name'] ?? '') ?>
                                        <?php if ((int) ($doc['file_count'] ?? 0) > 0): ?>
                                            · <?= (int) $doc['file_count'] ?> <?= $e(t('admin.library_files_short')) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($tags): ?>
                                        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px">
                                            <?php foreach ($tags as $tag): ?>
                                                <span class="adm-badge adm-badge--neutral"><?= $e($tag) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="adm-row-side nowrap"><?= $e(substr((string) $doc['created_at'], 0, 16)) ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($pages > 1): ?>
                        <div class="adm-pager">
                            <span><?= $e(t('admin.gig_cat_page', ['current' => (string) $page, 'total' => (string) $pages])) ?></span>
                            <div style="display:flex;gap:8px">
                                <?php if ($page > 1): ?>
                                    <a href="<?= $e($query(['page' => (string) ($page - 1)])) ?>" class="adm-btn adm-btn--sm"><?= $e(t('admin.logs_prev')) ?></a>
                                <?php endif; ?>
                                <?php if ($page < $pages): ?>
                                    <a href="<?= $e($query(['page' => (string) ($page + 1)])) ?>" class="adm-btn adm-btn--sm"><?= $e(t('admin.logs_next')) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

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
                <section class="adm-card">
                    <div class="adm-card-head">
                        <h2 class="adm-card-title"><?= $e(t('admin.library_cat_edit')) ?></h2>
                    </div>
                    <form method="post" action="<?= ProductHelper::url('/admin/library/categories/' . $categoryId . '/update') ?>">
                        <?= csrf_field() ?>
                        <div class="adm-card-body">
                            <div class="adm-form-grid">
                                <div class="adm-field<?= (int) ($current['depth'] ?? 0) === 0 ? ' span-2' : '' ?>">
                                    <label class="adm-label" for="lib-cat-name"><?= $e(t('admin.library_cat_name')) ?></label>
                                    <input id="lib-cat-name" type="text" name="name" required minlength="2" maxlength="180" class="adm-input" value="<?= $e($current['name']) ?>">
                                </div>
                                <?php if ((int) ($current['depth'] ?? 0) === 0): ?>
                                    <input type="hidden" name="parent_id" value="">
                                <?php else: ?>
                                    <div class="adm-field">
                                        <label class="adm-label" for="lib-cat-parent"><?= $e(t('admin.library_cat_parent')) ?></label>
                                        <select id="lib-cat-parent" name="parent_id" class="adm-select">
                                            <option value=""><?= $e(t('admin.library_cat_root')) ?></option>
                                            <?php foreach ($categories as $opt):
                                                if ((int) ($opt['depth'] ?? 0) >= 1 || (int) $opt['id'] === $categoryId) {
                                                    continue;
                                                }
                                            ?>
                                                <option value="<?= (int) $opt['id'] ?>" <?= (int) ($current['parent_id'] ?? 0) === (int) $opt['id'] ? 'selected' : '' ?>><?= $e($opt['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="adm-card-foot adm-form-actions" style="align-items:center">
                            <?php if (!$inUse): ?>
                                <button type="submit" form="lib-cat-del-<?= $categoryId ?>" class="adm-btn adm-btn--danger"
                                        onclick="return confirm(<?= $e(json_encode(t('admin.library_cat_delete_confirm'))) ?>)"><?= $e(t('admin.delete')) ?></button>
                            <?php else: ?>
                                <span class="adm-help"><?= $e(t('admin.library_cat_in_use')) ?></span>
                            <?php endif; ?>
                            <button type="submit" class="adm-btn"><?= $e(t('admin.library_cat_save')) ?></button>
                        </div>
                    </form>
                    <?php if (!$inUse): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/library/categories/' . $categoryId . '/delete') ?>" id="lib-cat-del-<?= $categoryId ?>" hidden><?= csrf_field() ?></form>
                    <?php endif; ?>
                </section>
            <?php endif; endif; ?>
        </div>
    </div>
</div>
<style>
.adm-lib-grid { display: grid; gap: 16px; grid-template-columns: 1fr; align-items: start; }
@media (min-width: 1024px) { .adm-lib-grid { grid-template-columns: 260px minmax(0, 1fr); } }
.adm-folder { padding-top: 7px; padding-bottom: 7px; border-top: 0; }
.adm-folder .adm-row-title { font-weight: 400; color: var(--adm-text-2); }
.adm-folder.is-active { background: var(--adm-accent-soft); }
.adm-folder.is-active .adm-row-title { font-weight: 500; color: var(--adm-text); }
</style>
