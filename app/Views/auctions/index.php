<?php use App\Core\View; ?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500 mb-1"><?= htmlspecialchars(t('auctions.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white flex items-center gap-2.5">
            <span class="inline-flex text-accent-500">
                <svg class="w-6 h-6 sm:w-7 sm:h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 13-7.5 7.5c-.83.83-2.17.83-3 0a2.12 2.12 0 0 1 0-3L11 10"/><path d="m16 16 6-6"/><path d="m8 8 6-6"/><path d="m9 7 8 8"/><path d="m21 11-8-8"/></svg>
            </span>
            <span><?= htmlspecialchars(t('auctions.title')) ?></span>
        </h2>
        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('auctions.subtitle')) ?></p>
    </div>
    <?php if (empty($items)): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/15 px-5 py-14 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('auctions.empty')) ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5">
            <?php foreach ($items as $item) {
                View::partial('partials/product-card', [
                    'item' => $item,
                    'favorited' => in_array((int) $item['id'], $favoriteIds ?? [], true),
                ]);
            } ?>
        </div>
    <?php endif; ?>
</section>
