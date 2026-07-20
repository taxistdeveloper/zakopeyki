<?php use App\Helpers\ProductHelper; ?>
<section class="flex flex-col items-center justify-center py-20 text-center space-y-5 fade-up">
    <div class="font-display text-7xl font-extrabold text-brand-500 tracking-tight">404</div>
    <h1 class="font-display text-xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('error.404_title')) ?></h1>
    <p class="text-sm text-gray-400 max-w-xs"><?= htmlspecialchars(t('error.404_text')) ?></p>
    <a href="<?= ProductHelper::url('/') ?>" class="bg-ink-900 hover:bg-black text-white font-display font-bold px-6 py-3 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft"><?= htmlspecialchars(t('error.home')) ?></a>
</section>
