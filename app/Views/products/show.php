<?php
use App\Core\Auth;
use App\Helpers\IconHelper;
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
        <div class="relative">
            <div class="aspect-square sm:aspect-[4/3] bg-gradient-to-br from-ink-100 via-brand-50 to-orange-50 dark:from-white/10 dark:via-brand-900/20 dark:to-ink-900 flex items-center justify-center relative overflow-hidden<?= $imageUrl ? ' photo-wm photo-wm--md cursor-zoom-in' : '' ?>"
                 <?php if ($imageUrl): ?>
                 role="button"
                 tabindex="0"
                 data-lightbox
                 data-lightbox-src="<?= htmlspecialchars($imageUrl) ?>"
                 data-lightbox-gallery="<?= htmlspecialchars(json_encode(array_values($imageUrls), JSON_UNESCAPED_SLASHES)) ?>"
                 aria-label="<?= htmlspecialchars(t('product.zoom')) ?>"
                 <?php endif; ?>>
                <?php if ($imageUrl): ?>
                    <img id="product-main-image" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="absolute inset-0 w-full h-full object-contain pointer-events-none">
                <?php else: ?>
                    <?= ProductHelper::icon($item['type'], 'w-24 h-24 text-brand-500/60') ?>
                <?php endif; ?>
                <span class="absolute top-4 left-4 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-xl shadow-sm <?= $badge['class'] ?>">
                    <?= $badge['text'] ?>
                </span>
            </div>
            <div class="absolute top-4 right-4 z-20 flex flex-col gap-2">
                <button type="button"
                        class="favorite-btn w-10 h-10 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 <?= !empty($isFavorite) ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
                        data-product-id="<?= (int) $item['id'] ?>"
                        data-favorited="<?= !empty($isFavorite) ? '1' : '0' ?>"
                        aria-label="<?= htmlspecialchars(!empty($isFavorite) ? t('card.unfavorite') : t('card.favorite')) ?>">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="<?= !empty($isFavorite) ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                </button>
                <?php \App\Core\View::partial('partials/share-buttons', ['item' => $item, 'overlay' => 'lg']); ?>
            </div>
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
                const stage = main && main.parentElement;
                if (!main || !stage) return;
                document.querySelectorAll('.product-thumb').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        main.src = btn.dataset.src;
                        stage.setAttribute('data-lightbox-src', btn.dataset.src || '');
                        const gallery = stage.getAttribute('data-lightbox-gallery');
                        if (gallery) {
                            try {
                                const urls = JSON.parse(gallery);
                                const idx = urls.indexOf(btn.dataset.src);
                                if (idx >= 0) stage.setAttribute('data-lightbox-index', String(idx));
                            } catch (e) {}
                        }
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
                    $catalogSection = ($item['type'] ?? '') === 'new' ? 'new' : 'used';
                    $catalogBase = ProductHelper::url('/catalog/' . $catalogSection);
                    $parentUrl = $catalogBase . '?' . http_build_query(['parent' => $catParent]);
                    $childUrl = $catalogBase . '?' . http_build_query(['parent' => $catParent, 'sub' => $catChild]);
                ?>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-3 text-sm">
                        <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('product.category')) ?></span>
                        <span class="inline-flex flex-wrap items-center gap-1.5">
                            <a href="<?= htmlspecialchars($parentUrl) ?>"
                               class="px-2.5 py-1 rounded-xl bg-ink-50 dark:bg-white/[0.06] border border-black/[0.06] dark:border-white/10 text-ink-800 dark:text-gray-200 font-medium text-xs hover:border-brand-300 hover:text-brand-700 dark:hover:text-brand-300 transition">
                                <?= htmlspecialchars(ProductHelper::categoryLabel($catParent)) ?>
                            </a>
                            <span class="text-gray-300 dark:text-gray-600">/</span>
                            <a href="<?= htmlspecialchars($childUrl) ?>"
                               class="px-2.5 py-1 rounded-xl bg-brand-50 dark:bg-brand-500/10 border border-brand-200/60 dark:border-brand-500/20 text-brand-700 dark:text-brand-400 font-semibold text-xs hover:bg-brand-100 dark:hover:bg-brand-500/20 hover:border-brand-300 transition">
                                <?= htmlspecialchars(ProductHelper::categoryLabel($catChild)) ?>
                            </a>
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
                <p class="text-sm text-gray-400 mt-2 flex flex-wrap items-center gap-x-1.5 gap-y-1.5">
                    <?= htmlspecialchars($item['location']) ?>
                    <span class="text-gray-300">·</span>
                    <button type="button"
                            class="seller-profile-trigger inline-flex items-center gap-1.5 max-w-full px-2.5 py-1 rounded-xl bg-brand-50 dark:bg-brand-500/10 border border-brand-200/70 dark:border-brand-500/25 text-brand-700 dark:text-brand-300 font-semibold hover:bg-brand-100 dark:hover:bg-brand-500/20 hover:border-brand-300 transition"
                            data-seller-id="<?= (int) $item['user_id'] ?>"
                            aria-label="<?= htmlspecialchars(t('seller.title') . ': ' . ($item['seller_name'] ?? '')) ?>">
                        <svg class="w-3.5 h-3.5 shrink-0 opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span class="truncate"><?= htmlspecialchars($item['seller_name']) ?></span>
                    </button>
                    <?php
                    $sr = $sellerRating ?? ['avg' => 0, 'count' => 0];
                    if (($sr['count'] ?? 0) > 0):
                    ?>
                        <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 font-semibold">
                            <span class="text-amber-500"><?= IconHelper::star('w-3.5 h-3.5', true) ?></span>
                            <?= htmlspecialchars(number_format((float) $sr['avg'], 1)) ?>
                            <span class="text-gray-400 font-normal">(<?= (int) $sr['count'] ?>)</span>
                        </span>
                    <?php endif; ?>
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
                <div class="pt-1 space-y-2.5">
                    <?php
                    $isOwnProduct = Auth::check() && (int) ($item['user_id'] ?? 0) === (int) Auth::id();
                    $inCart = \App\Services\Cart::has((int) $item['id']);
                    $secBtn = 'flex-1 min-w-0 inline-flex items-center justify-center gap-2 h-12 sm:h-14 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 hover:border-brand-400/50 hover:text-brand-600 font-display font-bold text-xs uppercase tracking-wider transition shadow-soft';
                    $cartIconSvg = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
                    $chatIconSvg = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>';
                    ?>
                    <?php if (Auth::check()): ?>
                        <a href="<?= $checkoutUrl ?>" class="flex w-full items-center justify-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold h-12 sm:h-14 px-4 rounded-xl text-sm uppercase tracking-wider transition shadow-soft">
                            <?= htmlspecialchars(t('card.buy')) ?>
                        </a>
                        <?php if (!$isOwnProduct): ?>
                            <div class="flex gap-2.5">
                                <button type="button"
                                        class="cart-btn <?= $secBtn ?> <?= $inCart ? 'is-in-cart bg-brand-50/80 dark:bg-brand-500/10 text-brand-700 dark:text-brand-400 border-brand-200/60' : '' ?>"
                                        data-product-id="<?= (int) $item['id'] ?>"
                                        data-in-cart="<?= $inCart ? '1' : '0' ?>"
                                        aria-label="<?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?>">
                                    <?= $cartIconSvg ?>
                                    <span class="cart-btn-label truncate"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                                </button>
                                <button type="button"
                                        data-chat-open
                                        data-product-id="<?= (int) $item['id'] ?>"
                                        class="<?= $secBtn ?>"
                                        aria-label="<?= htmlspecialchars(t('chat.write_seller')) ?>">
                                    <?= $chatIconSvg ?>
                                    <span class="truncate"><?= htmlspecialchars(t('seller.write')) ?></span>
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="<?= ProductHelper::url('/login') ?>" class="flex w-full items-center justify-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold h-12 sm:h-14 px-4 rounded-xl text-sm uppercase tracking-wider transition shadow-soft">
                            <?= htmlspecialchars(t('product.login_to_buy')) ?>
                        </a>
                        <button type="button"
                                class="cart-btn <?= $secBtn ?> w-full <?= $inCart ? 'is-in-cart bg-brand-50/80 dark:bg-brand-500/10 text-brand-700 dark:text-brand-400 border-brand-200/60' : '' ?>"
                                data-product-id="<?= (int) $item['id'] ?>"
                                data-in-cart="<?= $inCart ? '1' : '0' ?>"
                                aria-label="<?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?>">
                            <?= $cartIconSvg ?>
                            <span class="cart-btn-label truncate"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php elseif (($item['type'] ?? '') === 'free' || ($item['type'] ?? '') === 'exchange'): ?>
                <div class="pt-1 space-y-2">
                    <?php if (($item['type'] ?? '') === 'free'): ?>
                        <p class="text-sm text-center text-gray-500 bg-violet-50/80 dark:bg-violet-950/20 border border-violet-100 dark:border-violet-900/40 rounded-2xl px-4 py-3">
                            <?= htmlspecialchars(t('product.free_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                        </p>
                    <?php else: ?>
                        <p class="text-sm text-center text-gray-500 bg-indigo-50/80 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                            <?= htmlspecialchars(t('product.exchange_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (Auth::check() && (int) ($item['user_id'] ?? 0) !== (int) Auth::id()): ?>
                        <button type="button"
                                data-chat-open
                                data-product-id="<?= (int) $item['id'] ?>"
                                class="w-full text-center bg-ink-900 hover:bg-ink-800 text-white font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition">
                            <?= htmlspecialchars(t('chat.write_seller')) ?>
                        </button>
                    <?php elseif (!Auth::check()): ?>
                        <a href="<?= ProductHelper::url('/login') ?>" class="block w-full text-center border border-black/[0.08] dark:border-white/10 font-semibold py-3 rounded-2xl text-xs uppercase tracking-wider transition hover:bg-black/[0.03]">
                            <?= htmlspecialchars(t('chat.login_to_write')) ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php elseif (Auth::check() && (int) ($item['user_id'] ?? 0) !== (int) Auth::id()): ?>
                <div class="pt-1">
                    <button type="button"
                            data-chat-open
                            data-product-id="<?= (int) $item['id'] ?>"
                            class="w-full text-center bg-ink-900 hover:bg-ink-800 text-white font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition">
                        <?= htmlspecialchars(t('chat.write_seller')) ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <a href="javascript:history.back()" class="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-600 font-medium transition"><?= htmlspecialchars(t('product.back')) ?></a>
</section>
