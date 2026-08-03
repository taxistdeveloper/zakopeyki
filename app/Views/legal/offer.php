<?php
use App\Helpers\ProductHelper;
?>
<section class="max-w-3xl mx-auto space-y-6 fade-up pb-10">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('offer.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('offer.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2 leading-relaxed"><?= htmlspecialchars(t('offer.lead')) ?></p>
        <p class="text-xs text-gray-400 mt-2"><?= htmlspecialchars(t('offer.effective')) ?></p>
    </div>

    <?php
    $sections = [
        ['title' => t('offer.s1_title'), 'body' => t('offer.s1_body')],
        ['title' => t('offer.s2_title'), 'body' => t('offer.s2_body')],
        ['title' => t('offer.s3_title'), 'body' => t('offer.s3_body')],
        ['title' => t('offer.s4_title'), 'body' => t('offer.s4_body')],
        ['title' => t('offer.s5_title'), 'body' => t('offer.s5_body')],
        ['title' => t('offer.s6_title'), 'body' => t('offer.s6_body')],
        ['title' => t('offer.s7_title'), 'body' => t('offer.s7_body')],
        ['title' => t('offer.s8_title'), 'body' => t('offer.s8_body')],
        ['title' => t('offer.s9_title'), 'body' => t('offer.s9_body')],
        ['title' => t('offer.s10_title'), 'body' => t('offer.s10_body')],
        ['title' => t('offer.s11_title'), 'body' => t('offer.s11_body')],
        ['title' => t('offer.s12_title'), 'body' => t('offer.s12_body')],
    ];
    foreach ($sections as $section):
    ?>
    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-3">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars($section['title']) ?></h2>
        <div class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($section['body']) ?></div>
    </div>
    <?php endforeach; ?>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-2">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('offer.contacts_title')) ?></h2>
        <p class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t('offer.contacts_body')) ?></p>
        <p class="text-sm">
            <a href="mailto:support@zakopeyki.kz" class="text-brand-600 font-semibold hover:underline">support@zakopeyki.kz</a>
        </p>
    </div>

    <div class="pt-1">
        <a href="<?= ProductHelper::url('/register') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-display font-bold text-xs uppercase tracking-wide transition">
            <?= htmlspecialchars(t('offer.cta_register')) ?>
        </a>
    </div>
</section>
