<?php
use App\Helpers\ProductHelper;

/** @var string $docKey */
/** @var list<string> $sectionIds */
$docKey = $docKey ?? 'privacy';
$sectionIds = $sectionIds ?? [];
?>
<section class="max-w-3xl mx-auto space-y-6 fade-up pb-10">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t($docKey . '.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t($docKey . '.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2 leading-relaxed"><?= htmlspecialchars(t($docKey . '.lead')) ?></p>
        <p class="text-xs text-gray-400 mt-2"><?= htmlspecialchars(t($docKey . '.effective')) ?></p>
    </div>

    <?php foreach ($sectionIds as $id): ?>
    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-3">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t($docKey . '.' . $id . '_title')) ?></h2>
        <div class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed whitespace-pre-line"><?= htmlspecialchars(t($docKey . '.' . $id . '_body')) ?></div>
    </div>
    <?php endforeach; ?>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t($docKey . '.contacts_title')) ?></h2>
        <p class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t($docKey . '.contacts_body')) ?></p>
        <p class="text-sm">
            <a href="mailto:support@zakopeyki.kz" class="text-brand-600 font-semibold hover:underline">support@zakopeyki.kz</a>
        </p>
    </div>

    <div class="pt-1 flex flex-wrap gap-2">
        <a href="<?= ProductHelper::url('/offer') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white/80 dark:bg-white/[0.04] text-ink-800 dark:text-gray-200 font-display font-bold text-xs uppercase tracking-wide hover:border-brand-400/50 transition">
            <?= htmlspecialchars(t('offer.title')) ?>
        </a>
        <a href="<?= ProductHelper::url('/support') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-display font-bold text-xs uppercase tracking-wide transition">
            <?= htmlspecialchars(t('nav.help')) ?>
        </a>
    </div>
</section>
