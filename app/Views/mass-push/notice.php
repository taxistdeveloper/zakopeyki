<?php

use App\Helpers\ProductHelper;

$campaign = $campaign ?? [];
?>
<section class="max-w-xl mx-auto space-y-4 fade-up pb-8">
    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('mass_push.notice_label')) ?></p>
    <h1 class="font-display text-xl font-bold"><?= htmlspecialchars((string) ($campaign['title'] ?? '')) ?></h1>
    <p class="text-sm leading-relaxed"><?= htmlspecialchars((string) ($campaign['body'] ?? '')) ?></p>
    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($campaign['target_url'])): ?>
        <a href="<?= htmlspecialchars((string) $campaign['target_url']) ?>" class="inline-flex h-11 px-5 items-center rounded-xl bg-accent-500 text-white text-xs font-bold" rel="nofollow noopener"><?= htmlspecialchars(t('mass_push.open_link')) ?></a>
    <?php endif; ?>
    <?php if (empty($unsubscribed)): ?>
        <form method="post" action="<?= ProductHelper::url('/mass-push/unsubscribe') ?>" class="pt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="back" value="<?= htmlspecialchars('/mass-push/notice/' . (string) ($campaign['public_id'] ?? '')) ?>">
            <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('mass_push.unsubscribe')) ?></button>
        </form>
    <?php else: ?>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('mass_push.unsubscribed_state')) ?></p>
    <?php endif; ?>
</section>
