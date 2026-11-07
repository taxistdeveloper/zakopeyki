<?php
use App\Helpers\ProductHelper;

$document = $document ?? [];
$files = $files ?? [];
$canManage = !empty($canManage);
$tags = array_filter(array_map('trim', explode(',', (string) ($document['tags'] ?? ''))));
$docId = (int) ($document['id'] ?? 0);
$e = static fn ($s): string => htmlspecialchars((string) $s);
$wasUpdated = !empty($document['updated_at']) && $document['updated_at'] !== ($document['created_at'] ?? null);
?>
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumb">
                <a href="<?= ProductHelper::url('/admin/library') ?>"><?= $e(t('admin.library')) ?></a>
                <?php if (!empty($document['category_name'])): ?>
                    <span class="sep">/</span>
                    <?php if (!empty($document['category_id'])): ?>
                        <a href="<?= ProductHelper::url('/admin/library?category=' . (int) $document['category_id']) ?>"><?= $e($document['category_name']) ?></a>
                    <?php else: ?>
                        <span><?= $e($document['category_name']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </nav>
            <h1 class="adm-title"><?= $e($document['title'] ?? '') ?></h1>
            <p class="adm-sub">
                <?= $e($document['author_name'] ?? '') ?>
                · <?= $e(substr((string) ($document['created_at'] ?? ''), 0, 16)) ?>
                <?php if ($wasUpdated): ?>
                    · <?= $e(t('admin.library_updated_at')) ?> <?= $e(substr((string) $document['updated_at'], 0, 16)) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($canManage): ?>
            <div class="adm-head-actions">
                <a href="<?= ProductHelper::url('/admin/library/' . $docId . '/edit') ?>" class="adm-btn"><?= $e(t('admin.library_edit')) ?></a>
                <form method="post" action="<?= ProductHelper::url('/admin/library/' . $docId . '/delete') ?>" class="adm-inline" onsubmit="return confirm(<?= $e(json_encode(t('admin.library_delete_confirm'))) ?>)">
                    <?= csrf_field() ?>
                    <button type="submit" class="adm-btn adm-btn--danger"><?= $e(t('admin.delete')) ?></button>
                </form>
            </div>
        <?php endif; ?>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>

    <div class="adm-grid-2 adm-grid-main" style="align-items:start">
        <article class="adm-card">
            <div class="adm-card-body library-body prose prose-sm dark:prose-invert max-w-none" style="padding:20px 24px">
                <?php if (trim(strip_tags((string) ($document['body'] ?? ''))) === ''): ?>
                    <p class="muted"><?= $e(t('admin.library_no_body')) ?></p>
                <?php else: ?>
                    <?= $document['body'] ?>
                <?php endif; ?>
            </div>
        </article>

        <aside class="adm-stack">
            <?php if (!empty($document['category_name']) || $tags): ?>
            <section class="adm-card">
                <dl class="adm-kv">
                    <?php if (!empty($document['category_name'])): ?>
                        <div>
                            <dt><?= $e(t('admin.library_category')) ?></dt>
                            <dd><?= $e($document['category_name']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($tags): ?>
                        <div>
                            <dt><?= $e(t('admin.library_tags')) ?></dt>
                            <dd style="display:flex;flex-wrap:wrap;gap:4px">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="adm-badge adm-badge--neutral"><?= $e($tag) ?></span>
                                <?php endforeach; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>
            <?php endif; ?>

            <?php if (!empty($files)): ?>
                <section class="adm-card">
                    <div class="adm-card-head">
                        <h2 class="adm-card-title"><?= $e(t('admin.library_attached')) ?> <span class="adm-count"><?= count($files) ?></span></h2>
                    </div>
                    <div class="adm-list">
                        <?php foreach ($files as $file): ?>
                            <a href="<?= ProductHelper::url('/admin/library/files/' . (int) $file['id']) ?>" class="adm-row">
                                <span class="adm-row-title truncate" style="min-width:0"><?= $e($file['original_name']) ?></span>
                                <span class="adm-row-side nowrap"><?= number_format(((int) $file['size_bytes']) / 1024, 0, ',', ' ') ?> KB</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
<style>
.library-body h1,.library-body h2,.library-body h3 { font-weight: 600; margin: 0.8em 0 0.4em; }
.library-body ul { list-style: disc; padding-left: 1.25rem; }
.library-body ol { list-style: decimal; padding-left: 1.25rem; }
.library-body a { color: var(--adm-accent); text-decoration: underline; }
.library-body table { width: 100%; border-collapse: collapse; font-size: 13px; }
.library-body td,.library-body th { border: 1px solid var(--adm-border); padding: 6px 8px; }
</style>
