<?php
use App\Helpers\ProductHelper;
$items = $items ?? [];
?>
<section class="max-w-4xl mx-auto pb-16 space-y-6">
    <div>
        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-violet-600"><?= htmlspecialchars(t('digital.eyebrow')) ?></p>
        <h1 class="font-display text-2xl font-bold mt-1"><?= htmlspecialchars(t('digital.library_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('digital.library_lead')) ?></p>
    </div>
    <?php if (!empty($flash)): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="rounded-2xl border border-dashed border-black/10 p-10 text-center text-gray-400 text-sm"><?= htmlspecialchars(t('digital.library_empty')) ?></div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($items as $row): ?>
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] px-4 py-3.5">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold truncate"><?= htmlspecialchars((string) ($row['title'] ?? '')) ?></div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            <?= htmlspecialchars(t('digital.kind_' . ($row['kind'] ?? 'course'))) ?>
                            · <?= htmlspecialchars(t('digital.status_' . ($row['live_status'] ?? 'idle'))) ?>
                            <?php if (!empty($row['access_until'])): ?>
                                · <?= htmlspecialchars(t('digital.until')) ?> <?= htmlspecialchars(date('d.m.Y', strtotime((string) $row['access_until']))) ?>
                            <?php endif; ?>
                            <?php if (!empty($row['progress']['required'])): ?>
                                · <?= (int) ($row['progress']['percent'] ?? 0) ?>%
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <?php if (!empty($row['certificate'])): ?>
                            <a href="<?= ProductHelper::url('/digital/' . (int) $row['listing_id'] . '/certificate') ?>" class="h-11 px-4 inline-flex items-center justify-center rounded-xl border border-violet-300 text-violet-800 text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.cert_short')) ?></a>
                        <?php endif; ?>
                        <a href="<?= ProductHelper::url('/digital/' . (int) $row['listing_id'] . '/watch') ?>" class="h-11 px-4 inline-flex items-center justify-center rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.open_player')) ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
