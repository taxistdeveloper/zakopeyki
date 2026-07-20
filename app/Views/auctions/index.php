<?php use App\Core\View; ?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500 mb-1"><?= htmlspecialchars(t('auctions.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars(t('auctions.title')) ?></h2>
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
