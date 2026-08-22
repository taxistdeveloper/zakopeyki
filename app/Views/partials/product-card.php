<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

$badge = ProductHelper::badge($item['type']);
$price = ProductHelper::formatPrice($item);
$imageUrls = ProductHelper::imageUrls($item);
$imageUrl = $imageUrls[0] ?? ProductHelper::imageUrl($item);
$showUrl = ProductHelper::url('/product/' . $item['id']);
$purchasable = ProductHelper::isPurchasable($item);
$checkoutUrl = ProductHelper::checkoutUrl($item['id']);
$buyUrl = $purchasable
    ? (Auth::check() ? $checkoutUrl : ProductHelper::url('/login'))
    : $showUrl;
$favorited = !empty($favorited);
$canFavorite = Auth::check();
$inCart = \App\Services\Cart::has((int) ($item['id'] ?? 0));
$isOwn = Auth::check() && (int) ($item['user_id'] ?? 0) === (int) Auth::id();
$canCart = $purchasable && !$isOwn;

$type = $item['type'] ?? '';
$isFreePrice = $type === 'free'
    || (
        (int) ($item['price'] ?? 0) === 0
        && !in_array($type, ['auction', 'exchange'], true)
    );

$showCardCategory = in_array($type, ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true)
    && !empty($item['category'])
    && ($item['category'] ?? '') !== 'Разное';

$compact = !empty($compact);
$mini = !empty($mini);
$ctaBtn = 'inline-flex items-center justify-center gap-1.5 w-full font-display font-bold text-[10px] sm:text-[11px] py-2.5 px-2.5 rounded-xl transition uppercase tracking-wider';
$ctaSolo = 'inline-flex items-center justify-center gap-1.5 w-full font-display font-bold text-[10px] sm:text-[11px] py-2.5 px-3 rounded-xl transition uppercase tracking-wider';
$cartBtnClass = 'border border-black/[0.08] dark:border-white/10 text-ink-800 dark:text-gray-200 hover:border-brand-400/50 hover:text-brand-600'
    . ($inCart ? ' is-in-cart bg-brand-50/80 dark:bg-brand-500/10 text-brand-700 dark:text-brand-400' : '');

$primaryHref = null;
$primaryLabel = null;
$primaryClass = null;

if ($type === 'course') {
    $primaryHref = $buyUrl;
    $primaryLabel = t('card.order');
    $primaryClass = 'bg-blue-600 hover:bg-blue-700 text-white';
} elseif ($isFreePrice && in_array($type, ['free', 'used', 'new'], true)) {
    $primaryHref = $showUrl;
    $primaryLabel = t('card.take');
    $primaryClass = 'bg-violet-600 hover:bg-violet-700 text-white';
} elseif ($type === 'used' || $type === 'new') {
    $primaryHref = $buyUrl;
    $primaryLabel = t('card.buy');
    $primaryClass = 'bg-accent-500 hover:bg-accent-400 text-white';
} elseif ($type === 'service') {
    $primaryHref = $showUrl;
    $primaryLabel = t('card.more');
    $primaryClass = 'bg-emerald-600 hover:bg-emerald-700 text-white';
} elseif ($type === 'gig') {
    $primaryHref = $buyUrl;
    $primaryLabel = t('card.order');
    $primaryClass = 'bg-emerald-600 hover:bg-emerald-700 text-white';
} elseif ($type === 'auction') {
    $primaryHref = $showUrl;
    $primaryLabel = $isOwn ? t('auctions.your_lot') : t('card.bid');
    $primaryClass = $isOwn
        ? 'border border-black/10 dark:border-white/15 text-ink-800 dark:text-gray-200 bg-transparent'
        : 'bg-red-600 hover:bg-red-700 text-white';
} elseif ($type === 'exchange') {
    $primaryHref = $showUrl;
    $primaryLabel = t('card.exchange');
    $primaryClass = 'bg-indigo-600 hover:bg-indigo-700 text-white';
}

$showBuyCartPair = $canCart && $primaryHref && in_array($type, ['used', 'new', 'course', 'gig'], true) && !$isFreePrice;
$cartIcon = '<svg class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
?>
<?php if ($mini): ?>
<article class="bg-white dark:bg-white/[0.04] rounded-2xl border border-black/[0.06] dark:border-white/10 overflow-hidden hover:shadow-soft transition duration-300 flex flex-col h-full cursor-pointer group relative"
         data-card-href="<?= htmlspecialchars($showUrl) ?>">
    <div class="relative">
        <?php if ($imageUrl): ?>
        <a href="<?= $showUrl ?>" class="photo-wm aspect-[4/3] bg-ink-100 dark:bg-white/10 relative block overflow-hidden">
            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="absolute inset-0 w-full h-full object-cover transition duration-300 group-hover:scale-105 pointer-events-none">
        </a>
        <?php else: ?>
        <a href="<?= $showUrl ?>" class="aspect-[4/3] bg-gradient-to-br from-ink-100 via-brand-50 to-accent-50 dark:from-white/10 dark:via-brand-900/20 dark:to-transparent relative flex items-center justify-center">
            <?= ProductHelper::icon($item['type'], 'w-10 h-10 text-brand-500/70') ?>
        </a>
        <?php endif; ?>
        <button type="button"
                class="favorite-btn absolute top-2 right-2 z-20 w-8 h-8 rounded-full bg-white/95 dark:bg-ink-900/80 shadow-sm flex items-center justify-center transition hover:scale-105 <?= $favorited ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
                data-product-id="<?= (int) $item['id'] ?>"
                data-favorited="<?= $favorited ? '1' : '0' ?>"
                aria-label="<?= htmlspecialchars($favorited ? t('card.unfavorite') : t('card.favorite')) ?>">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="<?= $favorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
        </button>
    </div>
    <div class="p-3 flex flex-col flex-1 gap-2">
        <h3 class="text-[13px] font-semibold line-clamp-2 min-h-[2.4rem] text-ink-800 dark:text-gray-200 leading-snug">
            <a href="<?= $showUrl ?>"><?= htmlspecialchars($item['title']) ?></a>
        </h3>
        <div class="mt-auto flex items-center justify-between gap-2">
            <span class="text-sm font-display font-bold <?= $isFreePrice ? 'text-violet-600 dark:text-violet-300' : 'text-ink-900 dark:text-white' ?>"><?= htmlspecialchars($price) ?></span>
            <?php if ($canCart): ?>
                <button type="button"
                        class="cart-btn inline-flex items-center justify-center w-9 h-9 rounded-xl bg-accent-50 dark:bg-accent-500/10 text-accent-600 dark:text-accent-400 hover:bg-accent-500 hover:text-white transition <?= $inCart ? 'is-in-cart bg-accent-500 text-white' : '' ?>"
                        data-product-id="<?= (int) $item['id'] ?>"
                        data-in-cart="<?= $inCart ? '1' : '0' ?>"
                        aria-label="<?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?>">
                    <?= $cartIcon ?>
                    <span class="cart-btn-label sr-only"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</article>
<?php else: ?>
<article class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft hover:shadow-lift hover:-translate-y-0.5 transition duration-300 flex flex-col h-full cursor-pointer group backdrop-blur-sm relative"
         data-card-href="<?= htmlspecialchars($showUrl) ?>">
    <?php if ($imageUrl): ?>
    <a href="<?= $showUrl ?>"
       class="photo-wm aspect-[4/3] bg-ink-100 dark:bg-white/10 relative block overflow-hidden shrink-0 cursor-zoom-in"
       data-lightbox
       data-lightbox-src="<?= htmlspecialchars($imageUrl) ?>"
       data-lightbox-gallery="<?= htmlspecialchars(json_encode(array_values($imageUrls ?: [$imageUrl]), JSON_UNESCAPED_SLASHES)) ?>"
       aria-label="<?= htmlspecialchars(t('product.zoom')) ?>">
        <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="absolute inset-0 w-full h-full object-cover transition duration-300 group-hover:scale-105 pointer-events-none">
        <span class="absolute top-2.5 left-2.5 z-[1] text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-lg shadow-sm <?= $badge['class'] ?>">
            <?= htmlspecialchars($badge['text']) ?>
        </span>
    </a>
    <?php else: ?>
    <a href="<?= $showUrl ?>" class="aspect-[4/3] bg-gradient-to-br from-ink-100 via-brand-50 to-accent-50 dark:from-white/10 dark:via-brand-900/20 dark:to-transparent relative flex items-center justify-center overflow-hidden shrink-0">
        <span class="transition duration-300 group-hover:scale-110"><?= ProductHelper::icon($item['type'], 'w-14 h-14 text-brand-500/70') ?></span>
        <span class="absolute top-2.5 left-2.5 text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-lg shadow-sm <?= $badge['class'] ?>">
            <?= htmlspecialchars($badge['text']) ?>
        </span>
    </a>
    <?php endif; ?>
    <div class="absolute top-2.5 right-2.5 z-20 flex flex-col gap-1.5">
        <button type="button"
                class="favorite-btn w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 <?= $favorited ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
                data-product-id="<?= (int) $item['id'] ?>"
                data-favorited="<?= $favorited ? '1' : '0' ?>"
                aria-label="<?= htmlspecialchars($favorited ? t('card.unfavorite') : t('card.favorite')) ?>"
                title="<?= htmlspecialchars($canFavorite ? ($favorited ? t('card.unfavorite') : t('card.favorite')) : t('card.favorite_login')) ?>">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="<?= $favorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
            </svg>
        </button>
        <?php \App\Core\View::partial('partials/share-buttons', ['item' => $item, 'overlay' => true]); ?>
    </div>
    <div class="p-4 flex flex-col flex-1 <?= $compact ? 'gap-2.5' : 'gap-3' ?>">
        <div class="<?= $compact ? '' : 'min-h-[4.75rem]' ?>">
            <h3 class="text-xs sm:text-sm font-semibold line-clamp-2 <?= $compact ? '' : 'min-h-[2.5rem]' ?> text-ink-800 dark:text-gray-200 leading-snug">
                <a href="<?= $showUrl ?>"><?= htmlspecialchars($item['title']) ?></a>
            </h3>
            <?php if ($showCardCategory):
                [$cardParent, $cardChild] = ProductHelper::parseCategory($item['category']);
            ?>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1.5 line-clamp-1 h-4" title="<?= htmlspecialchars(ProductHelper::categoryLabel($cardParent) . ' / ' . ProductHelper::categoryLabel($cardChild)) ?>">
                    <span class="text-ink-700 dark:text-gray-300 font-medium"><?= htmlspecialchars(ProductHelper::categoryLabel($cardParent)) ?></span>
                    <span class="text-gray-300 dark:text-gray-600 mx-0.5">/</span>
                    <span class="text-brand-600 dark:text-brand-400"><?= htmlspecialchars(ProductHelper::categoryLabel($cardChild)) ?></span>
                </p>
            <?php else: ?>
                <p class="mt-1.5 h-4" aria-hidden="true"></p>
            <?php endif; ?>
            <p class="text-[10px] text-gray-400 mt-1 truncate"><?= htmlspecialchars($item['location']) ?></p>
            <?php if ($type === 'exchange' && !empty($item['exchange_for'])): ?>
                <p class="text-[10px] text-indigo-600 dark:text-indigo-300 mt-1 line-clamp-2">
                    <span class="font-semibold"><?= htmlspecialchars(t('product.exchange_for_short')) ?>:</span>
                    <?= htmlspecialchars($item['exchange_for']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="mt-auto space-y-2">
            <div class="flex justify-between items-center min-h-[1.25rem]">
                <span class="text-sm font-display font-bold <?= $isFreePrice ? 'text-violet-600 dark:text-violet-300' : 'text-ink-900 dark:text-white' ?>"><?= htmlspecialchars($price) ?></span>
            </div>
            <?php if ($primaryHref || $canCart): ?>
            <div class="flex <?= $compact && $showBuyCartPair ? 'flex-row items-stretch' : 'flex-col' ?> gap-1.5 pt-2 border-t border-black/[0.05] dark:border-white/10">
                <?php if ($showBuyCartPair): ?>
                    <a href="<?= $primaryHref ?>" class="<?= $ctaBtn ?> <?= $primaryClass ?>"><?= htmlspecialchars($primaryLabel) ?></a>
                    <button type="button"
                            class="cart-btn <?= $compact ? 'inline-flex items-center justify-center w-11 shrink-0 rounded-xl ' . $cartBtnClass : $ctaBtn . ' ' . $cartBtnClass ?>"
                            data-product-id="<?= (int) $item['id'] ?>"
                            data-in-cart="<?= $inCart ? '1' : '0' ?>"
                            aria-label="<?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?>">
                        <?= $cartIcon ?>
                        <?php if (!$compact): ?>
                        <span class="cart-btn-label"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                        <?php else: ?>
                        <span class="cart-btn-label sr-only"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                        <?php endif; ?>
                    </button>
                <?php else: ?>
                    <?php if ($primaryHref): ?>
                        <a href="<?= $primaryHref ?>" class="<?= $ctaSolo ?> <?= $primaryClass ?>"><?= htmlspecialchars($primaryLabel) ?></a>
                    <?php endif; ?>
                    <?php if ($canCart): ?>
                        <button type="button"
                                class="cart-btn <?= $ctaSolo ?> <?= $cartBtnClass ?>"
                                data-product-id="<?= (int) $item['id'] ?>"
                                data-in-cart="<?= $inCart ? '1' : '0' ?>"
                                aria-label="<?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?>">
                            <?= $cartIcon ?>
                            <span class="cart-btn-label"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</article>
<?php endif; ?>
