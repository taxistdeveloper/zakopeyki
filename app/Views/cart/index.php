<?php
use App\Core\Auth;
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$items = $items ?? [];
$total = (int) ($total ?? 0);
$firstItem = $items[0] ?? null;
$buyUrl = $firstItem
    ? (Auth::check() ? ProductHelper::cartCheckoutUrl() : ProductHelper::url('/login'))
    : null;
?>
<section class="max-w-3xl mx-auto space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('cart.eyebrow')) ?></p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('cart.title')) ?></h1>
            <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('cart.subtitle')) ?></p>
        </div>
        <?php if (!empty($items)): ?>
            <form method="post" action="<?= ProductHelper::url('/cart/clear') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="text-xs font-semibold text-gray-400 hover:text-red-500 transition"><?= htmlspecialchars(t('cart.clear')) ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 shadow-soft px-6 py-14 text-center space-y-4">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-brand-50 dark:bg-brand-500/10 text-brand-600 flex items-center justify-center">
                <?= IconHelper::svg('bag', 'w-7 h-7') ?>
            </div>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('cart.empty')) ?></p>
            <a href="<?= ProductHelper::url('/catalog/new') ?>" class="inline-flex items-center justify-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold px-5 py-3 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft">
                <?= htmlspecialchars(t('cart.to_catalog')) ?>
            </a>
        </div>
    <?php else: ?>
        <div class="space-y-3" data-cart-list>
            <?php foreach ($items as $item):
                $imageUrl = ProductHelper::imageUrl($item);
                $price = ProductHelper::formatPrice($item);
                $showUrl = ProductHelper::url('/product/' . (int) $item['id']);
            ?>
                <article class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft overflow-hidden flex flex-col sm:flex-row gap-0" data-cart-item="<?= (int) $item['id'] ?>">
                    <a href="<?= $showUrl ?>" class="sm:w-32 h-36 sm:h-auto bg-gradient-to-br from-ink-100 via-brand-50 to-accent-50 dark:from-white/10 dark:via-brand-900/20 dark:to-transparent flex-shrink-0 flex items-center justify-center overflow-hidden">
                        <?php if ($imageUrl): ?>
                            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= ProductHelper::icon($item['type'], 'w-12 h-12 text-brand-500/70') ?>
                        <?php endif; ?>
                    </a>
                    <div class="flex-1 p-4 sm:p-5 flex flex-col gap-3 min-w-0">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="font-semibold text-ink-900 dark:text-white text-sm leading-snug line-clamp-2">
                                    <a href="<?= $showUrl ?>" class="hover:text-brand-600 transition"><?= htmlspecialchars($item['title']) ?></a>
                                </h2>
                                <p class="text-xs text-gray-400 mt-1 truncate"><?= htmlspecialchars($item['seller_name'] ?? '') ?> · <?= htmlspecialchars($item['location'] ?? '') ?></p>
                                <p class="font-display text-lg font-extrabold text-brand-600 mt-2"><?= htmlspecialchars($price) ?></p>
                            </div>
                            <form method="post" action="<?= ProductHelper::url('/cart/' . (int) $item['id'] . '/remove') ?>" class="flex-shrink-0">
                                <?= csrf_field() ?>
                                <button type="submit" class="cart-remove-btn p-2 rounded-xl text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30 transition" title="<?= htmlspecialchars(t('cart.remove')) ?>" aria-label="<?= htmlspecialchars(t('cart.remove')) ?>">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                                </button>
                            </form>
                        </div>
                        <div class="mt-auto">
                            <a href="<?= $showUrl ?>" class="inline-flex items-center justify-center border border-black/[0.08] dark:border-white/10 hover:bg-black/[0.03] dark:hover:bg-white/5 font-semibold py-2.5 px-4 rounded-xl text-[10px] uppercase tracking-wider transition text-ink-700 dark:text-gray-300">
                                <?= htmlspecialchars(t('card.more')) ?>
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('cart.total')) ?></p>
                <p class="font-display text-2xl font-extrabold text-ink-900 dark:text-white mt-0.5"><?= number_format($total, 0, '', ' ') ?> ₸</p>
                <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('cart.checkout_hint')) ?></p>
            </div>
            <?php if ($buyUrl): ?>
                <a href="<?= $buyUrl ?>" class="inline-flex items-center justify-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3 px-6 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft">
                    <?= htmlspecialchars(t('card.buy')) ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
