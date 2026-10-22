<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Ads\AdPolicy;

$rows = $rows ?? [];
?>
<section class="space-y-5 fade-up">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('admin.ads_moderation')) ?></p>
            <h1 class="font-display text-xl font-bold"><?= htmlspecialchars(t('admin.ads_queue')) ?></h1>
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
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('admin.ads_queue_empty')) ?></p>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
        <article class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 grid gap-4 md:grid-cols-[220px_minmax(0,1fr)]">
            <img src="<?= htmlspecialchars(ProductHelper::url('public/uploads/ads/' . basename((string) $row['image_file']))) ?>" alt="" class="w-full h-36 object-cover rounded-2xl bg-black/5">
            <div class="space-y-2 min-w-0">
                <p class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('ads.format_' . (string) $row['format'])) ?></p>
                <h2 class="font-display font-bold"><?= htmlspecialchars((string) $row['title']) ?></h2>
                <p class="text-sm break-all"><?= htmlspecialchars((string) $row['target_url']) ?></p>
                <p class="text-xs text-gray-500">
                    <?= htmlspecialchars((string) ($row['category_key'] ?? '—')) ?>
                    · <?= htmlspecialchars(Wallet::formatMoney((int) $row['amount'])) ?>
                    · <?= (int) $row['units'] ?>
                </p>
                <div class="flex flex-wrap gap-2 pt-1">
                    <form method="post" action="<?= ProductHelper::url('/admin/ads/' . (int) $row['id'] . '/moderate') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="approve">
                        <button type="submit" class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.ads_approve')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/admin/ads/' . (int) $row['id'] . '/moderate') ?>" class="flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="decision" value="reject">
                        <input type="text" name="reason" required maxlength="255" placeholder="<?= htmlspecialchars(t('admin.ads_reject_reason')) ?>" class="h-10 px-3 rounded-xl border border-black/10 text-sm">
                        <button type="submit" class="h-10 px-4 rounded-xl bg-amber-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.ads_reject')) ?></button>
                    </form>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
