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
$buyUrl = Auth::check() ? $checkoutUrl : ProductHelper::url('/login');
$buyText = Auth::check() ? $buyLabel : t('product.login_to_buy');

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
$sellerSince = '';
if (!empty($item['seller_created_at'])) {
    $ts = strtotime((string) $item['seller_created_at']);
    if ($ts) {
        $months = [
            1 => t('seller.month_1'), 2 => t('seller.month_2'), 3 => t('seller.month_3'),
            4 => t('seller.month_4'), 5 => t('seller.month_5'), 6 => t('seller.month_6'),
            7 => t('seller.month_7'), 8 => t('seller.month_8'), 9 => t('seller.month_9'),
            10 => t('seller.month_10'), 11 => t('seller.month_11'), 12 => t('seller.month_12'),
        ];
        $sellerSince = t('seller.member_since', [
            'month' => $months[(int) date('n', $ts)] ?? date('m', $ts),
            'year' => date('Y', $ts),
        ]);
    }
}

$chars = [];
$chars[] = [t('product.condition'), ProductHelper::label($type)];
if ($showProductCategory && $catParent) {
    $chars[] = [t('product.category'), ProductHelper::categoryLabel($catParent)];
    if ($catChild) {
        $chars[] = [t('catalog.subsection'), ProductHelper::categoryLabel($catChild)];
    }
}
if (!empty($item['location'])) {
    $chars[] = [t('product.city'), $item['location']];
}
if ($type === 'exchange' && !empty($item['exchange_for'])) {
    $chars[] = [t('product.exchange_for'), $item['exchange_for']];
}
if ($postedAt) {
    $chars[] = [t('product.listed'), $postedAt];
}
$charsVisible = array_slice($chars, 0, 4);
$charsHidden = array_slice($chars, 4);
$descLong = mb_strlen($description) > 220;

$thumbLimit = 5;
$visibleThumbs = array_slice($imageUrls, 0, $thumbLimit);
$extraPhotos = max(0, $photoCount - $thumbLimit);
$similarAllUrl = $childUrl ?: ($parentUrl ?: ($sectionMeta ? ProductHelper::url($sectionMeta['url']) : ProductHelper::url('/')));

$priceClass = $type === 'free' ? 'text-violet-600 dark:text-violet-300' : 'text-ink-900 dark:text-white';
$outlineBtn = 'flex-1 min-w-0 inline-flex items-center justify-center gap-2 h-11 px-3 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 hover:border-accent-400 hover:text-accent-600 font-semibold text-[13px] transition';
$cartIconSvg = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>';
$chatIconSvg = '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>';
$chevron = '<svg class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>';
$galleryJson = htmlspecialchars(json_encode(array_values($imageUrls), JSON_UNESCAPED_SLASHES));
$shareBarClass = 'w-full inline-flex items-center justify-center gap-2 h-11 px-3 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 hover:border-accent-400 hover:text-accent-600 font-semibold text-[13px] transition';
?>
<section class="max-w-5xl mx-auto space-y-5 fade-up pb-8">
    <?php if ($flash): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <nav class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12px] text-gray-400 min-w-0" aria-label="breadcrumb">
        <a href="<?= ProductHelper::url('/') ?>" class="hover:text-accent-600 transition shrink-0"><?= htmlspecialchars(t('nav.home')) ?></a>
        <?php if ($sectionMeta): ?>
            <?= $chevron ?>
            <a href="<?= ProductHelper::url($sectionMeta['url']) ?>" class="hover:text-accent-600 transition shrink-0"><?= htmlspecialchars($sectionMeta['label']) ?></a>
        <?php endif; ?>
        <?php if ($showProductCategory && $catParent && $parentUrl): ?>
            <?= $chevron ?>
            <a href="<?= htmlspecialchars($parentUrl) ?>" class="hover:text-accent-600 transition truncate max-w-[12rem]"><?= htmlspecialchars(ProductHelper::categoryLabel($catParent)) ?></a>
            <?php if ($catChild && $childUrl): ?>
                <?= $chevron ?>
                <a href="<?= htmlspecialchars($childUrl) ?>" class="hover:text-accent-600 transition truncate max-w-[12rem]"><?= htmlspecialchars(ProductHelper::categoryLabel($catChild)) ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <?= $chevron ?>
        <span class="text-ink-700 dark:text-gray-300 font-medium truncate"><?= htmlspecialchars($item['title']) ?></span>
    </nav>

    <div class="space-y-3">
        <div class="relative rounded-2xl overflow-hidden bg-ink-100 dark:bg-white/10">
            <div id="product-gallery-stage"
                 class="h-[230px] sm:h-[280px] md:h-[320px] flex items-center justify-center relative<?= $imageUrl ? ' photo-wm photo-wm--md cursor-zoom-in' : '' ?>"
                 <?php if ($imageUrl): ?>
                 role="button"
                 tabindex="0"
                 data-lightbox
                 data-lightbox-src="<?= htmlspecialchars($imageUrl) ?>"
                 data-lightbox-gallery="<?= $galleryJson ?>"
                 aria-label="<?= htmlspecialchars(t('product.zoom')) ?>"
                 <?php endif; ?>>
                <?php if ($imageUrl): ?>
                    <img id="product-main-image" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="absolute inset-0 w-full h-full object-contain pointer-events-none">
                <?php else: ?>
                    <?= ProductHelper::icon($item['type'], 'w-16 h-16 text-brand-500/60') ?>
                <?php endif; ?>
                <span class="absolute top-3 left-3 z-10 text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full shadow-sm <?= $badge['class'] ?>">
                    <?= htmlspecialchars($badge['text']) ?>
                </span>
                <?php if ($photoCount > 0): ?>
                    <span id="product-photo-counter" class="absolute bottom-3 left-3 z-10 text-[11px] font-semibold tabular-nums px-2.5 py-1 rounded-full bg-ink-900/70 text-white backdrop-blur-sm pointer-events-none">
                        <?= htmlspecialchars(t('product.photo_short', ['current' => 1, 'total' => max(1, $photoCount)])) ?>
                    </span>
                <?php endif; ?>
            </div>

            <button type="button"
                    class="favorite-btn absolute top-3 right-3 z-20 w-9 h-9 rounded-full bg-white/95 dark:bg-ink-900/80 shadow-sm flex items-center justify-center transition hover:scale-105 <?= !empty($isFavorite) ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
                    data-product-id="<?= (int) $item['id'] ?>"
                    data-favorited="<?= !empty($isFavorite) ? '1' : '0' ?>"
                    aria-label="<?= htmlspecialchars(!empty($isFavorite) ? t('card.unfavorite') : t('card.favorite')) ?>">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="<?= !empty($isFavorite) ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                </svg>
            </button>

            <?php if ($hasGallery): ?>
                <button type="button" data-gallery-prev class="absolute left-2 top-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full bg-white/95 dark:bg-ink-900/80 shadow-sm flex items-center justify-center text-ink-800 dark:text-white hover:scale-105 transition" aria-label="<?= htmlspecialchars(t('product.prev_photo')) ?>">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" data-gallery-next class="absolute right-2 top-1/2 -translate-y-1/2 z-20 w-8 h-8 rounded-full bg-white/95 dark:bg-ink-900/80 shadow-sm flex items-center justify-center text-ink-800 dark:text-white hover:scale-105 transition" aria-label="<?= htmlspecialchars(t('product.next_photo')) ?>">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            <?php endif; ?>

            <?php if ($imageUrl): ?>
                <button type="button"
                        class="absolute bottom-3 right-3 z-20 w-8 h-8 rounded-full bg-white/95 dark:bg-ink-900/80 shadow-sm flex items-center justify-center text-ink-700 dark:text-white hover:scale-105 transition"
                        data-gallery-expand
                        data-lightbox
                        data-lightbox-src="<?= htmlspecialchars($imageUrl) ?>"
                        data-lightbox-gallery="<?= $galleryJson ?>"
                        aria-label="<?= htmlspecialchars(t('product.zoom')) ?>">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($hasGallery): ?>
            <div class="flex gap-2 overflow-x-auto scrollbar-hide pb-0.5">
                <?php foreach ($visibleThumbs as $i => $url):
                    $isLastExtra = $extraPhotos > 0 && $i === count($visibleThumbs) - 1;
                ?>
                    <button type="button"
                            class="product-thumb relative flex-shrink-0 w-[72px] h-[72px] rounded-xl overflow-hidden border-2 transition <?= $i === 0 ? 'border-accent-500' : 'border-transparent opacity-80 hover:opacity-100' ?>"
                            data-src="<?= htmlspecialchars($url) ?>"
                            aria-label="<?= htmlspecialchars(t('product.photo', ['n' => $i + 1])) ?>">
                        <img src="<?= htmlspecialchars($url) ?>" alt="" class="w-full h-full object-cover">
                        <?php if ($isLastExtra): ?>
                            <span class="absolute inset-0 bg-ink-900/55 text-white text-sm font-bold flex items-center justify-center"><?= htmlspecialchars(t('product.more_photos', ['n' => $extraPhotos])) ?></span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <h1 class="font-display text-[1.45rem] sm:text-2xl font-bold tracking-tight leading-tight text-ink-900 dark:text-white"><?= htmlspecialchars($item['title']) ?></h1>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <button type="button"
                class="seller-profile-trigger flex items-center gap-3 p-3.5 rounded-2xl bg-white dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 text-left hover:border-accent-300 transition"
                data-seller-id="<?= (int) $item['user_id'] ?>"
                aria-label="<?= htmlspecialchars(t('product.open_seller') . ': ' . ($item['seller_name'] ?? '')) ?>">
            <?= AvatarHelper::html($sellerUser, 'w-11 h-11', 'text-sm', 'rounded-full') ?>
            <span class="min-w-0 flex-1">
                <span class="flex items-center gap-1.5 min-w-0">
                    <span class="font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($item['seller_name']) ?></span>
                    <svg class="w-4 h-4 text-accent-500 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </span>
                <?php if ($sellerSince !== ''): ?>
                    <span class="block text-[12px] text-gray-400 mt-0.5"><?= htmlspecialchars($sellerSince) ?></span>
                <?php endif; ?>
            </span>
        </button>

        <div class="p-3.5 rounded-2xl bg-white dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10">
            <div class="font-display text-[1.65rem] sm:text-2xl font-extrabold tracking-tight <?= $priceClass ?>"><?= htmlspecialchars($price) ?></div>
            <?php if ($purchasable): ?>
                <p class="mt-1 text-[13px] font-semibold text-emerald-600 dark:text-emerald-400"><?= htmlspecialchars(t('product.in_stock')) ?></p>
            <?php endif; ?>
            <?php if (($sr['count'] ?? 0) > 0): ?>
                <p class="mt-1 inline-flex items-center gap-1 text-[13px] text-ink-700 dark:text-gray-300">
                    <span class="text-amber-500"><?= IconHelper::star('w-3.5 h-3.5', true) ?></span>
                    <span class="font-semibold"><?= htmlspecialchars(number_format((float) $sr['avg'], 1)) ?></span>
                    <span class="text-gray-400">(<?= htmlspecialchars(t('product.reviews_n', ['n' => (int) $sr['count']])) ?>)</span>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($type === 'exchange' && !empty($item['exchange_for'])): ?>
        <div class="text-sm bg-indigo-50/80 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
            <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-indigo-400 block mb-1"><?= htmlspecialchars(t('product.exchange_for')) ?></span>
            <span class="font-semibold text-indigo-800 dark:text-indigo-200"><?= htmlspecialchars($item['exchange_for']) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($type === 'auction'): ?>
        <div class="border border-red-200/80 dark:border-red-900/40 rounded-2xl p-5 space-y-3 bg-gradient-to-br from-red-50/80 to-orange-50/40 dark:from-red-950/30 dark:to-transparent">
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
            <div class="grid grid-cols-2 gap-2.5">
                <a href="<?= $buyUrl ?>" class="inline-flex items-center justify-center gap-2 h-12 px-3 rounded-xl bg-accent-500 hover:bg-accent-400 text-white font-semibold text-[13px] sm:text-sm transition">
                    <?= $cartIconSvg ?>
                    <?= htmlspecialchars($buyText) ?>
                </a>
                <button type="button"
                        class="cart-btn inline-flex items-center justify-center gap-2 h-12 px-3 rounded-xl border-2 border-accent-500 text-accent-600 dark:text-accent-400 bg-white dark:bg-transparent hover:bg-accent-50 dark:hover:bg-accent-500/10 font-semibold text-[13px] sm:text-sm transition disabled:opacity-40 disabled:pointer-events-none <?= $inCart ? 'is-in-cart bg-accent-50 dark:bg-accent-500/10' : '' ?>"
                        data-product-id="<?= (int) $item['id'] ?>"
                        data-in-cart="<?= $inCart ? '1' : '0' ?>"
                        <?= $isOwnProduct ? 'disabled' : '' ?>
                        aria-label="<?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?>">
                    <?= $cartIconSvg ?>
                    <span class="cart-btn-label truncate"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                </button>
            </div>
            <?php if (!$isOwnProduct): ?>
                <div class="grid grid-cols-2 gap-2.5">
                    <?php if (Auth::check()): ?>
                        <button type="button" data-chat-open data-product-id="<?= (int) $item['id'] ?>" class="<?= $outlineBtn ?>">
                            <?= $chatIconSvg ?>
                            <span class="truncate"><?= htmlspecialchars(t('chat.write_seller')) ?></span>
                        </button>
                    <?php else: ?>
                        <a href="<?= ProductHelper::url('/login') ?>" class="<?= $outlineBtn ?>">
                            <?= $chatIconSvg ?>
                            <span class="truncate"><?= htmlspecialchars(t('chat.login_to_write')) ?></span>
                        </a>
                    <?php endif; ?>
                    <?php View::partial('partials/share-buttons', [
                        'item' => $item,
                        'compact' => true,
                        'fullWidth' => true,
                        'buttonClass' => $shareBarClass,
                    ]); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ($type === 'free' || $type === 'exchange'): ?>
        <div class="space-y-2.5">
            <?php if ($type === 'free'): ?>
                <p class="text-sm text-center text-gray-500 bg-violet-50/80 dark:bg-violet-950/20 border border-violet-100 dark:border-violet-900/40 rounded-2xl px-4 py-3">
                    <?= htmlspecialchars(t('product.free_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                </p>
            <?php else: ?>
                <p class="text-sm text-center text-gray-500 bg-indigo-50/80 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                    <?= htmlspecialchars(t('product.exchange_contact', ['phone' => $item['seller_phone'] ?: t('product.no_phone')])) ?>
                </p>
            <?php endif; ?>
            <div class="grid grid-cols-2 gap-2.5">
                <?php if (Auth::check() && !$isOwnProduct): ?>
                    <button type="button" data-chat-open data-product-id="<?= (int) $item['id'] ?>" class="<?= $outlineBtn ?>">
                        <?= $chatIconSvg ?>
                        <span class="truncate"><?= htmlspecialchars(t('chat.write_seller')) ?></span>
                    </button>
                <?php elseif (!Auth::check()): ?>
                    <a href="<?= ProductHelper::url('/login') ?>" class="<?= $outlineBtn ?>">
                        <?= $chatIconSvg ?>
                        <span class="truncate"><?= htmlspecialchars(t('chat.login_to_write')) ?></span>
                    </a>
                <?php endif; ?>
                <?php View::partial('partials/share-buttons', [
                    'item' => $item,
                    'compact' => true,
                    'fullWidth' => true,
                    'buttonClass' => $shareBarClass,
                ]); ?>
            </div>
        </div>
    <?php elseif (!$isOwnProduct): ?>
        <div class="grid grid-cols-2 gap-2.5">
            <?php if (Auth::check()): ?>
                <button type="button" data-chat-open data-product-id="<?= (int) $item['id'] ?>" class="<?= $outlineBtn ?>">
                    <?= $chatIconSvg ?>
                    <span class="truncate"><?= htmlspecialchars(t('chat.write_seller')) ?></span>
                </button>
            <?php else: ?>
                <a href="<?= ProductHelper::url('/login') ?>" class="<?= $outlineBtn ?>">
                    <?= $chatIconSvg ?>
                    <span class="truncate"><?= htmlspecialchars(t('chat.login_to_write')) ?></span>
                </a>
            <?php endif; ?>
            <?php View::partial('partials/share-buttons', [
                'item' => $item,
                'compact' => true,
                'fullWidth' => true,
                'buttonClass' => $shareBarClass,
            ]); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] p-4 sm:p-5">
            <h2 class="font-display text-[15px] font-bold text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('product.chars')) ?></h2>
            <dl class="space-y-2 text-[13px]">
                <?php foreach ($charsVisible as [$label, $value]): ?>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400 shrink-0"><?= htmlspecialchars($label) ?></dt>
                        <dd class="text-ink-800 dark:text-gray-200 font-medium text-right"><?= htmlspecialchars((string) $value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php if ($charsHidden): ?>
                <div id="pdp-chars-more" class="hidden mt-2 space-y-2 text-[13px]">
                    <?php foreach ($charsHidden as [$label, $value]): ?>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-400 shrink-0"><?= htmlspecialchars($label) ?></span>
                            <span class="text-ink-800 dark:text-gray-200 font-medium text-right"><?= htmlspecialchars((string) $value) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" data-toggle="pdp-chars-more" data-more="<?= htmlspecialchars(t('product.all_chars')) ?>" data-less="<?= htmlspecialchars(t('product.show_less')) ?>" class="mt-3 text-[13px] font-semibold text-accent-600 hover:underline">
                    <?= htmlspecialchars(t('product.all_chars')) ?>
                </button>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] p-4 sm:p-5">
            <h2 class="font-display text-[15px] font-bold text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('product.description')) ?></h2>
            <?php if ($description !== ''): ?>
                <p id="pdp-desc" class="text-[13px] text-ink-700 dark:text-gray-300 leading-relaxed whitespace-pre-line<?= $descLong ? ' line-clamp-5' : '' ?>"><?= htmlspecialchars($item['description']) ?></p>
                <?php if ($descLong): ?>
                    <button type="button" data-toggle="pdp-desc" data-clamp="line-clamp-5" data-more="<?= htmlspecialchars(t('product.show_full')) ?>" data-less="<?= htmlspecialchars(t('product.show_less')) ?>" class="mt-3 text-[13px] font-semibold text-accent-600 hover:underline">
                        <?= htmlspecialchars(t('product.show_full')) ?>
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-[13px] text-gray-400"><?= htmlspecialchars(t('product.no_description')) ?></p>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] p-4 sm:p-5">
            <h2 class="font-display text-[15px] font-bold text-ink-900 dark:text-white mb-3"><?= htmlspecialchars(t('product.delivery')) ?></h2>
            <div class="space-y-3 text-[13px]">
                <?php if (!empty($item['location'])): ?>
                    <div>
                        <p class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('product.delivery_city')) ?></p>
                        <p class="text-gray-400 mt-0.5"><?= htmlspecialchars(t('product.delivery_city_hint', ['city' => $item['location']])) ?></p>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('product.delivery_kz')) ?></p>
                    <p class="text-gray-400 mt-0.5"><?= htmlspecialchars(t('product.delivery_kz_hint')) ?></p>
                </div>
                <div id="pdp-delivery-more" class="hidden text-gray-500 leading-relaxed">
                    <?= htmlspecialchars(t('product.delivery_escrow')) ?>
                </div>
                <button type="button" data-toggle="pdp-delivery-more" data-more="<?= htmlspecialchars(t('product.delivery_more')) ?>" data-less="<?= htmlspecialchars(t('product.show_less')) ?>" class="text-[13px] font-semibold text-accent-600 hover:underline">
                    <?= htmlspecialchars(t('product.delivery_more')) ?>
                </button>
            </div>
        </div>
    </div>

    <?php if (!empty($similar)): ?>
        <div class="space-y-3 pt-1">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-display text-lg font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars(t('product.similar')) ?></h2>
                <a href="<?= htmlspecialchars($similarAllUrl) ?>" class="text-[13px] font-semibold text-accent-600 hover:underline shrink-0"><?= htmlspecialchars(t('product.view_all')) ?></a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php foreach (array_slice($similar, 0, 4) as $rel) {
                    View::partial('partials/product-card', [
                        'item' => $rel,
                        'favorited' => in_array((int) $rel['id'], $favoriteIds ?? [], true),
                        'mini' => true,
                    ]);
                } ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<script>
(function () {
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const el = document.getElementById(btn.getAttribute('data-toggle'));
            if (!el) return;
            const clamp = btn.getAttribute('data-clamp');
            const open = clamp ? el.classList.contains(clamp) : el.classList.contains('hidden');
            if (clamp) {
                el.classList.toggle(clamp, !open);
            } else {
                el.classList.toggle('hidden', !open);
            }
            btn.textContent = open ? (btn.getAttribute('data-less') || '') : (btn.getAttribute('data-more') || '');
        });
    });

    const urls = <?= json_encode(array_values($imageUrls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const main = document.getElementById('product-main-image');
    const stage = document.getElementById('product-gallery-stage');
    const counter = document.getElementById('product-photo-counter');
    const expand = document.querySelector('[data-gallery-expand]');
    if (!main || !stage || !urls.length) return;
    let index = 0;
    const labelTpl = <?= json_encode(t('product.photo_short'), JSON_UNESCAPED_UNICODE) ?>;

    function setActive(i) {
        index = (i + urls.length) % urls.length;
        const src = urls[index];
        main.src = src;
        stage.setAttribute('data-lightbox-src', src);
        stage.setAttribute('data-lightbox-index', String(index));
        if (expand) {
            expand.setAttribute('data-lightbox-src', src);
            expand.setAttribute('data-lightbox-index', String(index));
        }
        if (counter) {
            counter.textContent = labelTpl.replace(':current', String(index + 1)).replace(':total', String(urls.length));
        }
        document.querySelectorAll('.product-thumb').forEach(function (b, idx) {
            const on = b.dataset.src === src || idx === index;
            b.classList.toggle('border-accent-500', on);
            b.classList.toggle('border-transparent', !on);
            b.classList.toggle('opacity-80', !on);
        });
    }

    document.querySelectorAll('.product-thumb').forEach(function (btn, idx) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            setActive(idx);
        });
    });
    const prev = document.querySelector('[data-gallery-prev]');
    const next = document.querySelector('[data-gallery-next]');
    if (prev) prev.addEventListener('click', function (e) { e.stopPropagation(); setActive(index - 1); });
    if (next) next.addEventListener('click', function (e) { e.stopPropagation(); setActive(index + 1); });
})();
</script>
