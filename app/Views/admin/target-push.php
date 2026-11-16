<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\TargetPush\TargetPushPolicy;

$rows = $rows ?? [];
$enabled = !empty($enabled);
$e = static fn ($s): string => htmlspecialchars((string) $s);
$tone = static fn (string $s): string => match ($s) {
    TargetPushPolicy::STATUS_COMPLETED => 'ok',
    TargetPushPolicy::STATUS_MODERATION, TargetPushPolicy::STATUS_PARTIAL => 'warn',
    TargetPushPolicy::STATUS_REJECTED, TargetPushPolicy::STATUS_FAILED => 'danger',
    TargetPushPolicy::STATUS_APPROVED, TargetPushPolicy::STATUS_PAID, TargetPushPolicy::STATUS_SENDING => 'info',
    default => 'neutral',
};
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.target_push_queue')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.target_push_moderation')) ?></p>
        </div>
        <div class="adm-head-actions">
            <span class="adm-badge adm-badge--<?= $enabled ? 'ok' : 'neutral' ?>"><span class="adm-dot"></span><?= $e($enabled ? t('admin.target_push_on') : t('admin.target_push_off')) ?></span>
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
            <h2 class="adm-card-title"><?= $e(t('admin.target_push_queue')) ?> <span class="adm-count"><?= count($rows) ?></span></h2>
        </div>
        <?php if ($rows === []): ?>
            <div class="adm-empty"><?= $e(t('admin.target_push_empty')) ?></div>
        <?php else: ?>
            <div class="adm-list">
                <?php foreach ($rows as $row): ?>
                    <?php $status = (string) ($row['status'] ?? ''); ?>
                    <article class="adm-row" style="align-items:flex-start;padding:14px 16px">
                        <div class="adm-row-main adm-stack" style="gap:6px">
                            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px">
                                <h3 class="adm-row-title" style="font-size:14px;margin:0"><?= $e((string) $row['title']) ?></h3>
                                <span class="adm-badge adm-badge--<?= $tone($status) ?>"><span class="adm-dot"></span><?= $e(t('target_push.status_' . $status)) ?></span>
                            </div>
                            <div style="font-size:13px;color:var(--adm-text-2);overflow-wrap:anywhere"><?= $e((string) $row['body']) ?></div>
                            <div class="adm-row-sub">
                                <?= $e((string) $row['category_key']) ?>
                                · <?= $e(t('admin.target_push_audience', ['n' => (string) (int) $row['audience_count']])) ?>
                                · <?= $e(Wallet::formatMoney((int) $row['amount'])) ?>
                                <?php if (!empty($row['started_at'])): ?>
                                    · <?= $e((string) $row['started_at']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="adm-row-sub"><?= $e(t('target_push.stats', [
                                'audience' => (string) (int) $row['audience_count'],
                                'accepted' => (string) (int) $row['accepted_count'],
                                'failed' => (string) (int) $row['failed_count'],
                                'pending' => (string) max(0, (int) $row['audience_count'] - (int) $row['accepted_count'] - (int) $row['failed_count']),
                            ])) ?></div>
                            <?php if (!empty($row['failure_reason'])): ?>
                                <div class="adm-alert adm-alert--warn" style="padding:6px 10px;font-size:12px"><?= $e((string) $row['failure_reason']) ?></div>
                            <?php endif; ?>
                            <?php if ($status === TargetPushPolicy::STATUS_MODERATION): ?>
                                <div class="adm-toolbar" style="padding-top:4px">
                                    <form method="post" action="<?= ProductHelper::url('/admin/target-push/' . (int) $row['id'] . '/moderate') ?>" class="adm-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="decision" value="approve">
                                        <button type="submit" class="adm-btn adm-btn--sm"><?= $e(t('admin.target_push_approve')) ?></button>
                                    </form>
                                    <form method="post" action="<?= ProductHelper::url('/admin/target-push/' . (int) $row['id'] . '/moderate') ?>" class="adm-toolbar">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="decision" value="reject">
                                        <input type="text" name="reason" required maxlength="255" placeholder="<?= $e(t('admin.target_push_reject_reason')) ?>" class="adm-input" style="height:28px">
                                        <button type="submit" class="adm-btn adm-btn--sm adm-btn--danger"><?= $e(t('admin.target_push_reject')) ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
