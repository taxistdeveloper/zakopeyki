<?php
use App\Core\Auth;
use App\Core\View;
use App\Helpers\AvatarHelper;
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;

$badge = ProductHelper::badge($item['type']);
$price = ProductHelper::formatPrice($item);
$imageUrls = ProductHelper::imageUrls($item);
$imageUrl = $imageUrls[0] ?? null;
$photoCount = count($imageUrls);
$hasGallery = $photoCount > 1;
$flash = $_SESSION['flash'] ?? null;
$purchasable = ProductHelper::isPurchasable($item);
$checkoutUrl = ProductHelper::checkoutUrl($item['id']);
unset($_SESSION['flash']);

$type = $item['type'] ?? '';
$isOwnProduct = Auth::check() && (int) ($item['user_id'] ?? 0) === (int) Auth::id();
$inCart = \App\Services\Cart::has((int) $item['id']);
$description = trim((string) ($item['description'] ?? ''));
$postedAt = !empty($item['created_at']) ? date('d.m.Y', strtotime((string) $item['created_at'])) : null;
$buyLabel = in_array($type, ['course', 'service', 'gig'], true) ? t('card.order') : t('card.buy');

$catalogByType = [
    'new' => ['url' => '/catalog/new', 'label' => t('nav.new')],
    'used' => ['url' => '/catalog/used', 'label' => t('nav.used')],
    'auction' => ['url' => '/auctions', 'label' => t('nav.auctions')],
    'free' => ['url' => '/catalog/free', 'label' => t('nav.free')],
    'exchange' => ['url' => '/catalog/exchange', 'label' => t('nav.exchange')],
    'service' => ['url' => '/catalog/services', 'label' => t('nav.services')],
    'gig' => ['url' => '/catalog/gigs', 'label' => t('nav.services_board')],
    'course' => ['url' => '/catalog/courses', 'label' => t('nav.courses')],
];
$sectionMeta = $catalogByType[$type] ?? null;

$showProductCategory = in_array($type, ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true)
    && !empty($item['category'])
    && ($item['category'] ?? '') !== 'Разное';
$catParent = $catChild = $parentUrl = $childUrl = null;
if ($showProductCategory) {
    [$catParent, $catChild] = ProductHelper::parseCategory($item['category']);
    $catalogBase = ProductHelper::url('/catalog/' . ($type === 'new' ? 'new' : 'used'));
    $parentUrl = $catalogBase . '?' . http_build_query(['parent' => $catParent]);
    $childUrl = $catalogBase . '?' . http_build_query(['parent' => $catParent, 'sub' => $catChild]);
}

$sellerUser = [
    'name' => $item['seller_name'] ?? '',
    'avatar' => $item['seller_avatar'] ?? null,
    'avatar_file' => $item['seller_avatar_file'] ?? null,
];
$sr = $sellerRating ?? ['avg' => 0, 'count' => 0];

$priceClass = $type === 'free' ? 'text-violet-600 dark:text-violet-300' : 'text-brand-600 dark:text-brand-400';
$secBtn = 'flex-1 min-w-0 inline-flex items-center justify-center gap-2 h-12 px-3 rounded-2xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 hover:border-brand-400/50 hover:text-brand-600 font-display font-bold text-[11px] uppercase tracking-wider transition';
$cartIconSvg = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
$chatIconSvg = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>';
$chevron = '<svg class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';

$thumbBtn = static function (string $url, int $i): string {
    $active = $i === 0;
    return '<button type="button" class="product-thumb flex-shrink-0 w-14 h-14 lg:w-full lg:h-14 rounded-xl overflow-hidden border-2 transition '
        . ($active ? 'border-brand-500 ring-2 ring-brand-500/20' : 'border-transparent opacity-70 hover:opacity-100')
        . '" data-src="' . htmlspecialchars($url) . '" aria-label="' . htmlspecialchars(t('product.photo', ['n' => $i + 1])) . '">'
        . '<img src="' . htmlspecialchars($url) . '" alt="" class="w-full h-full object-cover">'
        . '</button>';
};
?>
<section class="max-w-6xl mx-auto space-y-5 sm:space-y-6 fade-up pb-8">
    <?php if ($flash): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <nav class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12px] text-gray-400 min-w-0" aria-label="breadcrumb">
        <a href="<?= ProductHelper::url('/') ?>" class="hover:text-brand-600 transition shrink-0"><?= htmlspecialchars(t('nav.home')) ?></a>
        <?php if ($sectionMeta): ?>
            <?= $chevron ?>
            <a href="<?= ProductHelper::url($sectionMeta['url']) ?>" class="hover:text-brand-600 transition shrink-0"><?= htmlspecialchars($sectionMeta['label']) ?></a>
        <?php endif; ?>
        <?php if ($showProductCategory && $catParent && $parentUrl): ?>
            <?= $chevron ?>
            <a href="<?= htmlspecialchars($parentUrl) ?>" class="hover:text-brand-600 transition truncate max-w-[12rem]"><?= htmlspecialchars(ProductHelper::categoryLabel($catParent)) ?></a>
            <?php if ($catChild && $childUrl): ?>
                <?= $chevron ?>
                <a href="<?= htmlspecialchars($childUrl) ?>" class="hover:text-brand-600 transition truncate max-w-[12rem]"><?= htmlspecialchars(ProductHelper::categoryLabel($catChild)) ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <?= $chevron ?>
        <span class="text-ink-700 dark:text-gray-300 font-medium truncate"><?= htmlspecialchars($item['title']) ?></span>
    </nav>

    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,380px)_minmax(0,1fr)] gap-5 lg:gap-7 items-start">
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft backdrop-blur lg:max-w-[380px]">
            <div class="lg:flex lg:items-stretch">
                <?php if ($hasGallery): ?>
                    <div class="hidden lg:flex flex-col gap-1.5 p-2 w-[72px] shrink-0 overflow-y-auto max-h-[320px] scrollbar-hide border-r border-black/[0.05] dark:border-white/10">
                        <?php foreach ($imageUrls as $i => $url) echo $thumbBtn($url, $i); ?>
                    </div>
                <?php endif; ?>
                <div class="relative flex-1 min-w-0">
                    <div id="product-gallery-stage"
                         class="h-[220px] sm:h-[260px] lg:h-[300px] bg-gradient-to-br from-ink-100 via-brand-50 to-orange-50 dark:from-white/10 dark:via-brand-900/20 dark:to-ink-900 flex items-center justify-center relative overflow-hidden<?= $imageUrl ? ' photo-wm photo-wm--md cursor-zoom-in' : '' ?>"
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
                            <?= ProductHelper::icon($item['type'], 'w-16 h-16 text-brand-500/60') ?>
                        <?php endif; ?>
                        <span class="absolute top-4 left-4 z-10 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-xl shadow-sm <?= $badge['class'] ?>">
                            <?= $badge['text'] ?>
                        </span>
                        <?php if ($hasGallery): ?>
                            <span id="product-photo-counter" class="absolute bottom-4 left-4 z-10 text-[11px] font-semibold tabular-nums px-2.5 py-1 rounded-full bg-ink-900/70 text-white backdrop-blur-sm pointer-events-none">
                                <?= htmlspecialchars(t('product.photo_of', ['current' => 1, 'total' => $photoCount])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasGallery): ?>
                        <button type="button" data-gallery-prev class="absolute left-2 top-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center text-ink-800 dark:text-white hover:scale-105 transition" aria-label="<?= htmlspecialchars(t('product.prev_photo')) ?>">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" data-gallery-next class="absolute right-2 top-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center text-ink-800 dark:text-white hover:scale-105 transition" aria-label="<?= htmlspecialchars(t('product.next_photo')) ?>">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    <?php endif; ?>
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
                        <?php View::partial('partials/share-buttons', ['item' => $item, 'overlay' => 'lg']); ?>
                    </div>
                </div>
            </div>
            <?php if ($hasGallery): ?>
                <div class="flex lg:hidden gap-2 p-3 border-t border-black/[0.05] dark:border-white/10 overflow-x-auto scrollbar-hide">
                    <?php foreach ($imageUrls as $i => $url) echo $thumbBtn($url, $i); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="lg:sticky lg:top-4 space-y-4">
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 shadow-soft backdrop-blur p-5 sm:p-7 space-y-5">
                <div class="space-y-3">
                    <?php if ($showProductCategory && $catParent): ?>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <a href="<?= htmlspecialchars($parentUrl) ?>"
                               class="px-2.5 py-1 rounded-full bg-ink-50 dark:bg-white/[0.06] border border-black/[0.06] dark:border-white/10 text-ink-700 dark:text-gray-200 font-medium text-[11px] hover:border-brand-300 hover:text-brand-700 dark:hover:text-brand-300 transition">
                                <?= htmlspecialchars(ProductHelper::categoryLabel($catParent)) ?>
                            </a>
                            <?php if ($catChild): ?>
                                <a href="<?= htmlspecialchars($childUrl) ?>"
                                   class="px-2.5 py-1 rounded-full bg-brand-50 dark:bg-brand-500/10 border border-brand-200/60 dark:border-brand-500/20 text-brand-700 dark:text-brand-400 font-semibold text-[11px] hover:bg-brand-100 dark:hover:bg-brand-500/20 transition">
                                    <?= htmlspecialchars(ProductHelper::categoryLabel($catChild)) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <h1 class="font-display text-[1.65rem] sm:text-[1.85rem] font-bold tracking-tight leading-tight text-ink-900 dark:text-white"><?= htmlspecialchars($item['title']) ?></h1>
                    <div class="font-display text-3xl sm:text-[2.15rem] font-extrabold tracking-tight <?= $priceClass ?>"><?= htmlspecialchars($price) ?></div>

                    <?php if ($type === 'exchange' && !empty($item['exchange_for'])): ?>
                        <div class="text-sm bg-indigo-50/80 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                            <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-indigo-400 block mb-1"><?= htmlspecialchars(t('product.exchange_for')) ?></span>
                            <span class="font-semibold text-indigo-800 dark:text-indigo-200"><?= htmlspecialchars($item['exchange_for']) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm text-gray-500">
                        <?php if (!empty($item['location'])): ?>
                            <span class="inline-flex items-center gap-1.5 text-ink-700 dark:text-gray-300">
                                <?= IconHelper::svg('map-pin', 'w-3.5 h-3.5 text-brand-500 shrink-0') ?>
                                <?= htmlspecialchars($item['location']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($postedAt): ?>
                            <span class="inline-flex items-center gap-1.5">
                                <?= IconHelper::svg('clock', 'w-3.5 h-3.5 text-gray-400 shrink-0') ?>
                                <?= htmlspecialchars(t('product.posted', ['date' => $postedAt])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="button"
                        class="seller-profile-trigger w-full flex items-center gap-3 p-3 rounded-2xl bg-ink-50/80 dark:bg-white/[0.04] border border-black/[0.05] dark:border-white/10 text-left hover:border-brand-300/70 hover:bg-brand-50/50 dark:hover:bg-brand-500/10 transition group"
                        data-seller-id="<?= (int) $item['user_id'] ?>"
                        aria-label="<?= htmlspecialchars(t('product.open_seller') . ': ' . ($item['seller_name'] ?? '')) ?>">
                    <?= AvatarHelper::html($sellerUser, 'w-11 h-11', 'text-sm', 'rounded-xl') ?>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('seller.title')) ?></span>
                        <span class="block font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($item['seller_name']) ?></span>
                        <?php if (($sr['count'] ?? 0) > 0): ?>
                            <span class="mt-0.5 inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 font-semibold">
                                <span class="text-amber-500"><?= IconHelper::star('w-3.5 h-3.5', true) ?></span>
                                <?= htmlspecialchars(number_format((float) $sr['avg'], 1)) ?>
                                <span class="text-gray-400 font-normal">(<?= (int) $sr['count'] ?>)</span>
                            </span>
                        <?php endif; ?>
                    </span>
                    <svg class="w-4 h-4 text-gray-300 group-hover:text-brand-500 shrink-0 transition" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>

                <?php if ($purchasable): ?>
                    <div class="flex items-start gap-2.5 px-3.5 py-3 rounded-2xl bg-emerald-50/80 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/40">
                        <span class="mt-0.5 text-emerald-600 dark:text-emerald-400"><?= IconHelper::svg('shield', 'w-4 h-4') ?></span>
                        <span>
                            <span class="block text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-300"><?= htmlspecialchars(t('product.safe_deal')) ?></span>
                            <span class="block text-[12px] text-emerald-800/80 dark:text-emerald-200/80 leading-snug mt-0.5"><?= htmlspecialchars(t('product.safe_deal_hint')) ?></span>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($type === 'auction'): ?>
                    <div class="border border-red-200/80 dark:border-red-900/40 rounded-[22px] p-5 space-y-3 bg-gradient-to-br from-red-50/80 to-orange-50/40 dark:from-red-950/30 dark:to-transparent">
                        <h3 class="font-display font-bold text-red-600 dark:text-red-400"><?= htmlspecialchars(t('product.place_bid')) ?></h3>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('product.bid_step', ['step' => number_format((int) $item['bid_step'], 0, '', ' ')])) ?><span class="font-semibold text-ink-800 dark:text-white"><?= number_format((int)($item['current_bid'] ?: $item['price']), 0, '', ' ') ?> ₸</span></p>
                        <?php if (Auth::check()): ?>
                            <form method="post" action="<?= ProductHelper::url('/auctions/' . $item['id'] . '/bid') ?>" class="flex gap-2">
                                <?= csrf_field() ?>
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
                    <div class="space-y-2.5">
                        <?php if (Auth::check()): ?>
                            <a href="<?= $checkoutUrl ?>" class="flex w-full items-center justify-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold h-14 px-4 rounded-2xl text-sm uppercase tracking-wider transition shadow-soft">
                                <?= htmlspecialchars($buyLabel) ?>
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
                            <a href="<?= ProductHelper::url('/login') ?>" class="flex w-full items-center justify-center bg-accent-500 hover:bg-accent-400 text-white font-display font-bold min-h-[3.25rem] px-4 rounded-2xl text-sm uppercase tracking-wider transition shadow-soft">
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
                <?php elseif ($type === 'free' || $type === 'exchange'): ?>
                    <div class="space-y-2">
                        <?php if ($type === 'free'): ?>
                            <p class="text-sm text-center text-gray-500 bg-violet-50/80 dark:bg-violet-950/20 border border-violet-100 dark:border-violet-900/40 rounded-2xl px-4 py-3">
                                <?= htmlspecialchars(t('product.free_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                            </p>
                        <?php else: ?>
                            <p class="text-sm text-center text-gray-500 bg-indigo-50/80 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                                <?= htmlspecialchars(t('product.exchange_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                            </p>
                        <?php endif; ?>
                        <?php if (Auth::check() && !$isOwnProduct): ?>
                            <button type="button"
                                    data-chat-open
                                    data-product-id="<?= (int) $item['id'] ?>"
                                    class="w-full text-center bg-ink-900 hover:bg-ink-800 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition">
                                <?= htmlspecialchars(t('chat.write_seller')) ?>
                            </button>
                        <?php elseif (!Auth::check()): ?>
                            <a href="<?= ProductHelper::url('/login') ?>" class="block w-full text-center border border-black/[0.08] dark:border-white/10 font-semibold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition hover:bg-black/[0.03]">
                                <?= htmlspecialchars(t('chat.login_to_write')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php elseif (Auth::check() && !$isOwnProduct): ?>
                    <button type="button"
                            data-chat-open
                            data-product-id="<?= (int) $item['id'] ?>"
                            class="w-full text-center bg-ink-900 hover:bg-ink-800 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition">
                        <?= htmlspecialchars(t('chat.write_seller')) ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($description !== ''): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 shadow-soft backdrop-blur p-5 sm:p-8">
            <h2 class="font-display text-lg font-bold tracking-tight text-ink-900 dark:text-white mb-4 flex items-center gap-2.5">
                <span class="w-1 h-5 rounded-full bg-brand-500"></span>
                <?= htmlspecialchars(t('product.description')) ?>
            </h2>
            <div class="text-[15px] text-ink-700 dark:text-gray-300 leading-7 whitespace-pre-line"><?= htmlspecialchars($item['description']) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($similar)): ?>
        <div class="space-y-4 pt-1">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('product.similar_hint')) ?></p>
                <h2 class="font-display text-lg sm:text-xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars(t('product.similar')) ?></h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5">
                <?php foreach ($similar as $rel) {
                    View::partial('partials/product-card', [
                        'item' => $rel,
                        'favorited' => in_array((int) $rel['id'], $favoriteIds ?? [], true),
                        'compact' => true,
                    ]);
                } ?>
            </div>
        </div>
    <?php endif; ?>

    <a href="javascript:history.back()" class="inline-flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-600 font-medium transition"><?= htmlspecialchars(t('product.back')) ?></a>
</section>
<?php if ($hasGallery): ?>
<script>
(function () {
    const urls = <?= json_encode(array_values($imageUrls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const main = document.getElementById('product-main-image');
    const stage = document.getElementById('product-gallery-stage');
    const counter = document.getElementById('product-photo-counter');
    if (!main || !stage || !urls.length) return;
    let index = 0;
    const labelTpl = <?= json_encode(t('product.photo_of'), JSON_UNESCAPED_UNICODE) ?>;

    function setActive(i) {
        index = (i + urls.length) % urls.length;
        const src = urls[index];
        main.src = src;
        stage.setAttribute('data-lightbox-src', src);
        stage.setAttribute('data-lightbox-index', String(index));
        if (counter) {
            counter.textContent = labelTpl.replace(':current', String(index + 1)).replace(':total', String(urls.length));
        }
        document.querySelectorAll('.product-thumb').forEach(function (b) {
            const on = b.dataset.src === src;
            b.classList.toggle('border-brand-500', on);
            b.classList.toggle('ring-2', on);
            b.classList.toggle('ring-brand-500/20', on);
            b.classList.toggle('border-transparent', !on);
            b.classList.toggle('opacity-70', !on);
        });
    }

    document.querySelectorAll('.product-thumb').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const i = urls.indexOf(btn.dataset.src);
            if (i >= 0) setActive(i);
        });
    });
    const prev = document.querySelector('[data-gallery-prev]');
    const next = document.querySelector('[data-gallery-next]');
    if (prev) prev.addEventListener('click', function (e) { e.stopPropagation(); setActive(index - 1); });
    if (next) next.addEventListener('click', function (e) { e.stopPropagation(); setActive(index + 1); });
})();
</script>
<?php endif; ?>
