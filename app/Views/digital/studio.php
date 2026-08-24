<?php
use App\Helpers\ProductHelper;
$rows = $rows ?? [];
$cfReady = !empty($cfReady);
?>
<section class="max-w-4xl mx-auto pb-16 space-y-6">
    <div>
        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-violet-600"><?= htmlspecialchars(t('digital.eyebrow')) ?></p>
        <h1 class="font-display text-2xl font-bold mt-1"><?= htmlspecialchars(t('digital.studio_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('digital.studio_lead')) ?></p>
    </div>
    <?php if (!$cfReady): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm"><?= htmlspecialchars(t('digital.cf_admin_hint')) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash)): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="rounded-2xl border border-dashed p-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('digital.studio_empty')) ?></div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($rows as $row): ?>
                <a href="<?= ProductHelper::url('/digital/studio/' . (int) $row['id']) ?>" class="flex items-center justify-between gap-3 rounded-2xl border border-black/[0.06] px-4 py-3.5 bg-white dark:bg-white/[0.04] hover:border-violet-300">
                    <div class="min-w-0">
                        <div class="font-semibold truncate"><?= htmlspecialchars((string) ($row['title'] ?? '')) ?></div>
                        <div class="text-[11px] text-gray-400 mt-0.5">
                            <?= htmlspecialchars(t('digital.kind_' . ($row['kind'] ?? 'course'))) ?>
                            · <?= htmlspecialchars(t('digital.status_' . ($row['live_status'] ?? 'idle'))) ?>
                        </div>
                    </div>
                    <span class="text-xs font-bold text-violet-700"><?= htmlspecialchars(t('digital.manage')) ?> →</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
