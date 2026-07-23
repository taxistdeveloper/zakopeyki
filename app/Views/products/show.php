<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

$badge = ProductHelper::badge($item['type']);
$price = ProductHelper::formatPrice($item);
$imageUrls = ProductHelper::imageUrls($item);
$imageUrl = $imageUrls[0] ?? null;
$flash = $_SESSION['flash'] ?? null;
$purchasable = ProductHelper::isPurchasable($item);
$checkoutUrl = ProductHelper::checkoutUrl($item['id']);
unset($_SESSION['flash']);
?>
<section class="max-w-3xl mx-auto space-y-5 fade-up pb-8">
    <?php if ($flash): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft backdrop-blur">
        <div class="h-52 sm:h-72 bg-gradient-to-br from-ink-100 via-brand-50 to-orange-50 dark:from-white/10 dark:via-brand-900/20 dark:to-ink-900 flex items-center justify-center relative overflow-hidden">
            <?php if ($imageUrl): ?>
                <img id="product-main-image" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="absolute inset-0 w-full h-full object-cover">
            <?php else: ?>
                <?= ProductHelper::icon($item['type'], 'w-24 h-24 text-brand-500/60') ?>
            <?php endif; ?>
<span class="absolute top-4 left-4 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-xl shadow-sm <?= $badge['class'] ?>">
                <?= $badge['text'] ?>
            </span>
            <button type="button"
                    class="favorite-btn absolute top-4 right-4 z-10 w-10 h-10 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 <?= !empty($isFavorite) ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
                    data-product-id="<?= (int) $item['id'] ?>"
                    data-favorited="<?= !empty($isFavorite) ? '1' : '0' ?>"
                    aria-label="<?= htmlspecialchars(!empty($isFavorite) ? t('card.unfavorite') : t('card.favorite')) ?>">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="<?= !empty($isFavorite) ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                </svg>
            </button>
        </div>
        <?php if (count($imageUrls) > 1): ?>
            <div class="flex gap-2 p-3 sm:px-5 border-b border-black/[0.05] dark:border-white/10 overflow-x-auto">
                <?php foreach ($imageUrls as $i => $url): ?>
                    <button type="button"
                            class="product-thumb flex-shrink-0 w-16 h-16 rounded-xl overflow-hidden border-2 transition <?= $i === 0 ? 'border-brand-500' : 'border-transparent opacity-80 hover:opacity-100' ?>"
                            data-src="<?= htmlspecialchars($url) ?>"
                            aria-label="<?= htmlspecialchars(t('product.photo', ['n' => $i + 1])) ?>">
                        <img src="<?= htmlspecialchars($url) ?>" alt="" class="w-full h-full object-cover">
                    </button>
                <?php endforeach; ?>
            </div>
            <script>
            (function () {
                const main = document.getElementById('product-main-image');
                if (!main) return;
                document.querySelectorAll('.product-thumb').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        main.src = btn.dataset.src;
                        document.querySelectorAll('.product-thumb').forEach(function (b) {
                            b.classList.toggle('border-brand-500', b === btn);
                            b.classList.toggle('border-transparent', b !== btn);
                            b.classList.toggle('opacity-80', b !== btn);
                        });
                    });
                });
            })();
            </script>
        <?php endif; ?>
        <div class="p-5 sm:p-8 space-y-5">
            <div>
                <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars($item['title']) ?></h1>
                <?php
                $showProductCategory = in_array($item['type'] ?? '', ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true)
                    && !empty($item['category'])
                    && ($item['category'] ?? '') !== 'Разное';
                if ($showProductCategory):
                    [$catParent, $catChild] = ProductHelper::parseCategory($item['category']);
                ?>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-3 text-sm">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('product.category')) ?></span>
                        <span class="inline-flex flex-wrap items-center gap-1.5">
                            <span class="px-2.5 py-1 rounded-xl bg-ink-50 dark:bg-white/[0.06] border border-black/[0.06] dark:border-white/10 text-ink-800 dark:text-gray-200 font-medium text-xs"><?= htmlspecialchars(ProductHelper::categoryLabel($catParent)) ?></span>
                            <span class="text-gray-300 dark:text-gray-600">/</span>
                            <span class="px-2.5 py-1 rounded-xl bg-brand-50 dark:bg-brand-500/10 border border-brand-200/60 dark:border-brand-500/20 text-brand-700 dark:text-brand-400 font-semibold text-xs"><?= htmlspecialchars(ProductHelper::categoryLabel($catChild)) ?></span>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="font-display text-2xl sm:text-3xl font-extrabold <?= ($item['type'] ?? '') === 'free' ? 'text-violet-600' : 'text-brand-600' ?> mt-2"><?= htmlspecialchars($price) ?></div>
                <?php if (($item['type'] ?? '') === 'exchange' && !empty($item['exchange_for'])): ?>
                    <div class="mt-3 text-sm bg-indigo-50/80 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-indigo-400 block mb-1"><?= htmlspecialchars(t('product.exchange_for')) ?></span>
                        <span class="font-semibold text-indigo-800 dark:text-indigo-200"><?= htmlspecialchars($item['exchange_for']) ?></span>
                    </div>
                <?php endif; ?>
                <p class="text-sm text-gray-400 mt-2">
                    <?= htmlspecialchars($item['location']) ?>
                    <span class="mx-1.5 text-gray-300">·</span>
                    <?= htmlspecialchars($item['seller_name']) ?>
                </p>
            </div>

            <div class="space-y-2">
                <h4 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('product.description')) ?></h4>
                <p class="text-sm text-ink-700 dark:text-gray-300 leading-relaxed bg-ink-50/80 dark:bg-white/[0.03] border border-black/[0.04] dark:border-white/10 p-4 rounded-2xl"><?= nl2br(htmlspecialchars($item['description'])) ?></p>
            </div>

            <?php if ($item['type'] === 'auction'): ?>
                <div class="border border-red-200/80 dark:border-red-900/40 rounded-[22px] p-5 space-y-3 bg-gradient-to-br from-red-50/80 to-orange-50/40 dark:from-red-950/30 dark:to-transparent">
                    <h3 class="font-display font-bold text-red-600 dark:text-red-400"><?= htmlspecialchars(t('product.place_bid')) ?></h3>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('product.bid_step', ['step' => number_format((int) $item['bid_step'], 0, '', ' ')])) ?><span class="font-semibold text-ink-800 dark:text-white"><?= number_format((int)($item['current_bid'] ?: $item['price']), 0, '', ' ') ?> ₸</span></p>
                    <?php if (Auth::check()): ?>
                        <form method="post" action="<?= ProductHelper::url('/auctions/' . $item['id'] . '/bid') ?>" class="flex gap-2">
                            <input type="text" name="amount" required placeholder="<?= htmlspecialchars(t('product.bid_amount')) ?>" class="ui-input flex-1 border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 h-11 px-3.5 rounded-xl text-sm">
                            <button class="bg-red-600 hover:bg-red-700 text-white font-display font-bold px-5 rounded-xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('product.bid_btn')) ?></button>
                        </form>
                    <?php else: ?>
                        <a href="<?= ProductHelper::url('/login') ?>" class="inline-block text-sm font-semibold text-red-600 hover:underline"><?= htmlspecialchars(t('product.login_to_bid')) ?></a>
                    <?php endif; ?>

                    <?php if (!empty($bids)): ?>
                        <div class="pt-2 space-y-1">
                            <h4 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400 mb-2"><?= htmlspecialchars(t('product.bid_history')) ?></h4>
                            <?php foreach ($bids as $b): ?>
                                <div class="flex justify-between text-sm py-2 border-b border-red-100/80 dark:border-red-900/30 last:border-0">
                                    <span class="text-ink-700 dark:text-gray-300"><?= htmlspecialchars($b['bidder_name']) ?></span>
                                    <span class="font-display font-bold"><?= number_format((int)$b['amount'], 0, '', ' ') ?> ₸</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($purchasable): ?>
                <div class="pt-1">
                    <?php if (Auth::check()): ?>
                        <a href="<?= $checkoutUrl ?>" class="block w-full text-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-sm uppercase tracking-wider transition shadow-soft">
                            <?= htmlspecialchars(t('card.buy')) ?>
                        </a>
                    <?php else: ?>
                        <a href="<?= ProductHelper::url('/login') ?>" class="block w-full text-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-sm uppercase tracking-wider transition shadow-soft">
                            <?= htmlspecialchars(t('product.login_to_buy')) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php elseif (($item['type'] ?? '') === 'free'): ?>
                <div class="pt-1">
                    <p class="text-sm text-center text-gray-500 bg-violet-50/80 dark:bg-violet-950/20 border border-violet-100 dark:border-violet-900/40 rounded-2xl px-4 py-3">
                        <?= htmlspecialchars(t('product.free_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                    </p>
                </div>
            <?php elseif (($item['type'] ?? '') === 'exchange'): ?>
                <div class="pt-1">
                    <p class="text-sm text-center text-gray-500 bg-indigo-50/80 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                        <?= htmlspecialchars(t('product.exchange_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <a href="javascript:history.back()" class="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-600 font-medium transition"><?= htmlspecialchars(t('product.back')) ?></a>
</section>
