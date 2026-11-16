<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Ads\AdPolicy;

$rows = $rows ?? [];
$e = static fn ($s): string => htmlspecialchars((string) $s);
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.ads_queue')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.ads_moderation')) ?></p>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('admin.ads_queue')) ?> <span class="adm-count"><?= count($rows) ?></span></h2>
        </div>
        <?php if ($rows === []): ?>
            <div class="adm-empty"><?= $e(t('admin.ads_queue_empty')) ?></div>
        <?php else: ?>
            <div class="adm-list">
                <?php foreach ($rows as $row): ?>
                    <article class="adm-row" style="align-items:flex-start;flex-wrap:wrap;gap:16px;padding:16px">
                        <img src="<?= $e(ProductHelper::url('public/uploads/ads/' . basename((string) $row['image_file']))) ?>" alt="" style="width:200px;max-width:100%;height:120px;object-fit:cover;border-radius:6px;border:1px solid var(--adm-border);background:var(--adm-surface-2)">
                        <div class="adm-row-main adm-stack" style="gap:8px;min-width:240px">
                            <div>
                                <span class="adm-badge adm-badge--neutral"><?= $e(t('ads.format_' . (string) $row['format'])) ?></span>
                            </div>
                            <h3 class="adm-row-title" style="font-size:14px;margin:0"><?= $e((string) $row['title']) ?></h3>
                            <div class="mono muted" style="overflow-wrap:anywhere"><?= $e((string) $row['target_url']) ?></div>
                            <div class="adm-row-sub">
                                <?= $e((string) ($row['category_key'] ?? '—')) ?>
                                · <?= $e(Wallet::formatMoney((int) $row['amount'])) ?>
                                · <?= (int) $row['units'] ?>
                            </div>
                            <div class="adm-toolbar">
                                <form method="post" action="<?= ProductHelper::url('/admin/ads/' . (int) $row['id'] . '/moderate') ?>" class="adm-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="decision" value="approve">
                                    <button type="submit" class="adm-btn adm-btn--sm"><?= $e(t('admin.ads_approve')) ?></button>
                                </form>
                                <form method="post" action="<?= ProductHelper::url('/admin/ads/' . (int) $row['id'] . '/moderate') ?>" class="adm-toolbar">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="decision" value="reject">
                                    <input type="text" name="reason" required maxlength="255" placeholder="<?= $e(t('admin.ads_reject_reason')) ?>" class="adm-input" style="height:28px">
                                    <button type="submit" class="adm-btn adm-btn--sm adm-btn--danger"><?= $e(t('admin.ads_reject')) ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
