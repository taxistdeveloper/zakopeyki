<?php
use App\Helpers\ProductHelper;
?>
<section class="max-w-2xl mx-auto space-y-6 fade-up pb-10">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('about.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('about.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2 leading-relaxed"><?= htmlspecialchars(t('about.lead')) ?></p>
    </div>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.what_title')) ?></h2>
        <p class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t('about.what_text')) ?></p>
    </div>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.how_title')) ?></h2>
        <ul class="space-y-3 text-sm text-ink-700/80 dark:text-gray-300">
            <li class="flex gap-3"><span class="text-brand-500 font-bold">1.</span> <span><?= htmlspecialchars(t('about.how_1')) ?></span></li>
            <li class="flex gap-3"><span class="text-brand-500 font-bold">2.</span> <span><?= htmlspecialchars(t('about.how_2')) ?></span></li>
            <li class="flex gap-3"><span class="text-brand-500 font-bold">3.</span> <span><?= htmlspecialchars(t('about.how_3')) ?></span></li>
        </ul>
    </div>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.city_title')) ?></h2>
        <p class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t('about.city_text')) ?></p>
    </div>

    <div class="flex flex-wrap gap-3 pt-1">
        <a href="<?= ProductHelper::url('/catalog/new') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-display font-bold text-xs uppercase tracking-wide transition">
            <?= htmlspecialchars(t('about.cta_catalog')) ?>
        </a>
        <a href="<?= ProductHelper::url('/register') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl border border-black/[0.1] dark:border-white/15 text-ink-800 dark:text-gray-200 font-semibold text-sm hover:border-brand-400/50 transition">
            <?= htmlspecialchars(t('about.cta_register')) ?>
        </a>
    </div>
</section>
