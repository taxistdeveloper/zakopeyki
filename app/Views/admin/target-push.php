<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\TargetPush\TargetPushPolicy;

$rows = $rows ?? [];
$enabled = !empty($enabled);
?>
<section class="space-y-5 fade-up">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('admin.target_push_moderation')) ?></p>
            <h1 class="font-display text-xl font-bold"><?= htmlspecialchars(t('admin.target_push_queue')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($enabled ? t('admin.target_push_on') : t('admin.target_push_off')) ?></p>
        </div>
        <a href="<?= ProductHelper::url('/admin') ?>" class="text-sm font-semibold text-brand-600"><?= htmlspecialchars(t('admin.title')) ?></a>
    </div>
    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($rows === []): ?>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('admin.target_push_empty')) ?></p>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <?php $status = (string) ($row['status'] ?? ''); ?>
        <article class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 space-y-2">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <h2 class="font-display font-bold"><?= htmlspecialchars((string) $row['title']) ?></h2>
                <span class="text-[10px] font-bold uppercase tracking-wider"><?= htmlspecialchars(t('target_push.status_' . $status)) ?></span>
            </div>
            <p class="text-sm"><?= htmlspecialchars((string) $row['body']) ?></p>
            <p class="text-xs text-gray-500">
                <?= htmlspecialchars((string) $row['category_key']) ?>
                · <?= htmlspecialchars(t('admin.target_push_audience', ['n' => (string) (int) $row['audience_count']])) ?>
                · <?= htmlspecialchars(Wallet::formatMoney((int) $row['amount'])) ?>
                <?php if (!empty($row['started_at'])): ?>
                    · <?= htmlspecialchars((string) $row['started_at']) ?>
                <?php endif; ?>
            </p>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('target_push.stats', [
                'audience' => (string) (int) $row['audience_count'],
                'accepted' => (string) (int) $row['accepted_count'],
                'failed' => (string) (int) $row['failed_count'],
                'pending' => (string) max(0, (int) $row['audience_count'] - (int) $row['accepted_count'] - (int) $row['failed_count']),
            ])) ?></p>
            <?php if (!empty($row['failure_reason'])): ?>
                <p class="text-xs text-amber-700"><?= htmlspecialchars((string) $row['failure_reason']) ?></p>
            <?php endif; ?>
            <?php if ($status === TargetPushPolicy::STATUS_MODERATION): ?>
                <div class="flex flex-wrap gap-2 pt-1">
                    <form method="post" action="<?= ProductHelper::url('/admin/target-push/' . (int) $row['id'] . '/moderate') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.target_push_approve')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/admin/target-push/' . (int) $row['id'] . '/moderate') ?>" class="flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="reject">
                        <input type="text" name="reason" required maxlength="255" placeholder="<?= htmlspecialchars(t('admin.target_push_reject_reason')) ?>" class="h-10 px-3 rounded-xl border border-black/10 text-sm">
                        <button type="submit" class="h-10 px-4 rounded-xl bg-amber-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.target_push_reject')) ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
