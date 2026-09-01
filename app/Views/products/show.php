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
$flashError = $_SESSION['error'] ?? null;
$purchasable = ProductHelper::isPurchasable($item);
$checkoutUrl = ProductHelper::checkoutUrl($item['id']);
unset($_SESSION['flash'], $_SESSION['error']);

$type = $item['type'] ?? '';
$isOwnProduct = Auth::check() && (int) ($item['user_id'] ?? 0) === (int) Auth::id();
$inCart = \App\Services\Cart::has((int) $item['id']);
$description = trim((string) ($item['description'] ?? ''));
$postedAt = !empty($item['created_at']) ? date('d.m.Y', strtotime((string) $item['created_at'])) : null;
$buyLabel = in_array($type, ['course', 'service', 'gig'], true) ? t('card.order') : t('card.buy');
$buyUrl = Auth::check() ? $checkoutUrl : ProductHelper::url('/login');
$buyText = Auth::check() ? $buyLabel : t('product.login_to_buy');
$showBuyChoice = $purchasable && ProductHelper::supportsDirectBuy($item);

$catalogByType = [
    'new' => ['url' => '/catalog/new', 'label' => t('nav.new')],
    'used' => ['url' => '/catalog/used', 'label' => t('nav.used')],
    'auction' => ['url' => '/auctions', 'label' => t('nav.auctions')],
    'free' => ['url' => '/catalog/free', 'label' => t('nav.free')],
    'exchange' => ['url' => '/catalog/exchange', 'label' => t('nav.exchange')],
    'service' => ['url' => '/catalog/services', 'label' => t('nav.services')],
    'gig' => ['url' => '/catalog/gigs', 'label' => t('nav.services_board')],
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
    'account_type' => $item['seller_account_type'] ?? '',
    'business_status' => $item['seller_business_status'] ?? '',
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
$whatsappHref = ProductHelper::whatsappDigits((string) ($item['whatsapp'] ?? ''))
    ? ProductHelper::url('/product/' . (int) $item['id'] . '/whatsapp')
    : null;
$waIconSvg = '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
$waBtnClass = 'w-full inline-flex items-center justify-center gap-2 h-12 px-4 rounded-xl bg-[#25D366] hover:bg-[#20bd5a] text-white font-semibold text-[15px] transition shadow-sm';
?>
<section class="max-w-5xl mx-auto space-y-5 fade-up pb-8">
    <?php if ($flash): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="bg-red-50 dark:bg-red-900/25 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flashError) ?></div>
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
                <span class="flex items-center gap-1.5 min-w-0 flex-wrap">
                    <span class="font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($item['seller_name']) ?></span>
                    <?php if (ProductHelper::sellerIsBusiness($item)): ?>
                        <span class="inline-flex items-center text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded-md bg-sky-600 text-white shrink-0"><?= htmlspecialchars(t('business.badge')) ?></span>
                    <?php else: ?>
                    <svg class="w-4 h-4 text-accent-500 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    <?php endif; ?>
                </span>
                <?php
                $sellerBizLabel = ProductHelper::sellerBusinessLabel($item);
                ?>
                <?php if ($sellerBizLabel !== ''): ?>
                    <span class="block text-[12px] text-sky-700 dark:text-sky-300 font-semibold mt-0.5 truncate"><?= htmlspecialchars($sellerBizLabel) ?></span>
                <?php endif; ?>
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
            <?php
            $viewCount = (int) ($item['view_count'] ?? 0);
            $isAuctionLot = ($item['type'] ?? '') === 'auction';
            $bidCount = (int) ($item['bid_count'] ?? count($bids ?? []));
            $hasRating = !$isAuctionLot && ($sr['count'] ?? 0) > 0;
            ?>
                <p class="mt-1 inline-flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[13px] text-ink-700 dark:text-gray-300">
                    <span class="inline-flex items-center gap-1 text-gray-500" title="<?= htmlspecialchars(t('product.views_hint')) ?>">
                        <span><?= htmlspecialchars(t('product.views_label')) ?></span>
                        <?= IconHelper::svg('eye', 'w-3.5 h-3.5') ?>
                        <span><?= (int) $viewCount ?></span>
                    </span>
                    <?php if ($isAuctionLot): ?>
                    <span class="inline-flex items-center gap-1 text-gray-500">
                        <?= htmlspecialchars(t('product.bids_label')) ?>
                        <?= IconHelper::svg('gavel', 'w-3.5 h-3.5') ?>
                        <?= (int) $bidCount ?>
                    </span>
                    <?php elseif ($hasRating): ?>
                    <span class="inline-flex items-center gap-1">
                        <span class="text-amber-500"><?= IconHelper::star('w-3.5 h-3.5', true) ?></span>
                        <span class="font-semibold"><?= htmlspecialchars(number_format((float) $sr['avg'], 1)) ?></span>
                        <span class="text-gray-400">(<?= htmlspecialchars(t('product.reviews_n', ['n' => (int) $sr['count']])) ?>)</span>
                    </span>
                    <?php endif; ?>
                </p>
        </div>
    </div>

    <?php if ($whatsappHref && !$isOwnProduct): ?>
    <a href="<?= htmlspecialchars($whatsappHref) ?>"
       target="_blank"
       rel="noopener noreferrer"
       class="<?= $waBtnClass ?>">
        <?= $waIconSvg ?>
        <span><?= htmlspecialchars(t('product.whatsapp_call')) ?></span>
    </a>
    <?php endif; ?>

    <?php if ($type === 'exchange' && !empty($item['exchange_for'])): ?>
        <div class="text-sm bg-indigo-50/80 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
            <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-indigo-400 block mb-1"><?= htmlspecialchars(t('product.exchange_for')) ?></span>
            <span class="font-semibold text-indigo-800 dark:text-indigo-200"><?= htmlspecialchars($item['exchange_for']) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($type === 'auction'):
        $kind = $item['auction_kind'] ?? 'english';
        $calcPrice = (int) ($item['calculated_current_price'] ?? ($item['current_bid'] ?: $item['price']));
        $minNext = $calcPrice + (int) ($item['bid_step'] ?? 0);
        $isActive = ($item['status'] ?? '') === 'active';
        $isSeller = $isOwnProduct;
    ?>
        <div class="border border-red-200/80 dark:border-red-900/40 rounded-2xl p-5 space-y-3 bg-gradient-to-br from-red-50/80 to-orange-50/40 dark:from-red-950/30 dark:to-transparent"
             id="auction-panel"
             data-auction-id="<?= (int) $item['id'] ?>"
             data-kind="<?= htmlspecialchars($kind) ?>"
             data-end-at="<?= htmlspecialchars((string) ($item['auction_end_at'] ?? '')) ?>"
             data-anti-snipe="<?= (int) ($item['anti_snipe_seconds'] ?? 30) ?>"
             data-live-url="<?= htmlspecialchars(ProductHelper::url('/auctions/' . $item['id'] . '/live')) ?>">
            <div class="flex items-start justify-between gap-3">
                <h3 class="font-display font-bold text-red-600 dark:text-red-400"><?= htmlspecialchars($kind === 'dutch' ? t('auctions.buy_now') : t('product.place_bid')) ?></h3>
                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-lg bg-white/80 dark:bg-black/20 text-red-600"><?= htmlspecialchars(t('auctions.kind_' . $kind)) ?></span>
            </div>
            <div class="rounded-xl bg-white/70 dark:bg-black/20 px-4 py-3 text-center" id="auction-timer-box">
                <p class="text-[10px] uppercase tracking-wider text-gray-400" id="auction-timer-label"><?= htmlspecialchars($kind === 'continuous' ? t('auctions.timer_open') : t('auctions.time_left')) ?></p>
                <p class="font-mono text-2xl font-bold text-ink-900 dark:text-white" id="auction-timer"><?= $kind === 'continuous' ? '∞' : '—' ?></p>
            </div>
            <p class="text-xs text-gray-500">
                <?= htmlspecialchars($kind === 'dutch' ? t('auctions.buyout_price') : t('auctions.current_price')) ?>:
                <span class="font-semibold text-ink-800 dark:text-white" id="auction-current-price"><?= number_format($calcPrice, 0, '', ' ') ?> ₸</span>
                <?php if ($kind !== 'dutch'): ?>
                    · <?= htmlspecialchars(t('product.bid_step_only', ['step' => number_format((int) $item['bid_step'], 0, '', ' ')])) ?>
                <?php endif; ?>
            </p>
            <?php if ($isActive && Auth::check() && !$isSeller): ?>
                <form method="post" action="<?= ProductHelper::url('/auctions/' . $item['id'] . '/bid') ?>" class="flex gap-2" id="auction-bid-form">
                    <?= csrf_field() ?>
                    <?php if ($kind !== 'dutch'): ?>
                        <input type="text" name="amount" id="auction-amount" required value="<?= $minNext ?>" placeholder="<?= htmlspecialchars(t('product.bid_amount')) ?>" class="ui-input flex-1 border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 h-11 px-3.5 rounded-xl text-sm">
                    <?php else: ?>
                        <input type="hidden" name="amount" value="<?= $calcPrice ?>">
                    <?php endif; ?>
                    <button class="bg-red-600 hover:bg-red-700 text-white font-display font-bold px-5 rounded-xl text-xs uppercase tracking-wider transition" id="auction-submit">
                        <?= htmlspecialchars($kind === 'dutch' ? t('auctions.buy_now') : t('product.bid_btn')) ?>
                    </button>
                </form>
                <?php if (!empty($item['buy_now_price']) && $kind !== 'dutch'): ?>
                    <form method="post" action="<?= ProductHelper::url('/auctions/' . $item['id'] . '/buy-now') ?>">
                        <?= csrf_field() ?>
                        <button class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold h-11 rounded-xl text-xs uppercase tracking-wider transition">
                            <?= htmlspecialchars(t('auctions.buy_now')) ?> · <?= number_format((int) $item['buy_now_price'], 0, '', ' ') ?> ₸
                        </button>
                    </form>
                <?php endif; ?>
            <?php elseif ($isActive && $isSeller): ?>
                <p class="text-sm text-gray-600 dark:text-gray-300 bg-white/70 dark:bg-black/20 rounded-xl px-3 py-2.5"><?= htmlspecialchars(t('auctions.own_no_bid')) ?></p>
                <?php if ($kind === 'continuous'): ?>
                <form method="post" action="<?= ProductHelper::url('/auctions/' . $item['id'] . '/accept') ?>">
                    <?= csrf_field() ?>
                    <button class="w-full bg-ink-900 hover:bg-ink-800 text-white font-display font-bold h-11 rounded-xl text-xs uppercase tracking-wider"><?= htmlspecialchars(t('auctions.accept_highest')) ?></button>
                </form>
                <?php endif; ?>
            <?php elseif ($isActive && !Auth::check()): ?>
                <a href="<?= ProductHelper::url('/login') ?>" class="inline-block text-sm font-semibold text-red-600 hover:underline"><?= htmlspecialchars(t('product.login_to_bid')) ?></a>
            <?php elseif (!$isActive): ?>
                <p class="text-sm font-semibold text-gray-500"><?= htmlspecialchars(t('auctions.closed')) ?></p>
            <?php endif; ?>
            <?php if (!empty($bids)): ?>
                <div class="pt-2 space-y-1" id="auction-history">
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
        <script>
        (function () {
            var panel = document.getElementById('auction-panel');
            if (!panel) return;
            var timerEl = document.getElementById('auction-timer');
            var labelEl = document.getElementById('auction-timer-label');
            var box = document.getElementById('auction-timer-box');
            var priceEl = document.getElementById('auction-current-price');
            var kind = panel.getAttribute('data-kind');
            var anti = parseInt(panel.getAttribute('data-anti-snipe') || '30', 10);
            function pad(n) { return String(n).padStart(2, '0'); }
            function fmt(ms) {
                if (ms <= 0) return '00:00:00';
                var s = Math.floor(ms / 1000);
                return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor((s % 3600) / 60)) + ':' + pad(s % 60);
            }
            function setEnd(endAt) {
                panel.setAttribute('data-end-at', endAt || '');
            }
            function tick() {
                var end = panel.getAttribute('data-end-at');
                if (kind === 'continuous') {
                    timerEl.textContent = '∞';
                    return;
                }
                if (!end) { timerEl.textContent = '—'; return; }
                var left = new Date(end.replace(' ', 'T')).getTime() - Date.now();
                timerEl.textContent = fmt(left);
                if (left > 0 && left <= anti * 1000) {
                    box.classList.add('ring-2', 'ring-red-500');
                    labelEl.textContent = <?= json_encode(t('auctions.sniping')) ?>;
                }
            }
            tick();
            setInterval(tick, 1000);
            var liveUrl = panel.getAttribute('data-live-url');
            setInterval(function () {
                fetch(liveUrl, { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.ok || !res.data) return;
                        var d = res.data;
                        if (priceEl) priceEl.textContent = Number(d.current_price).toLocaleString('ru-RU') + ' ₸';
                        if (d.end_at) setEnd(d.end_at);
                    }).catch(function () {});
            }, 4000);
        })();
        </script>
    <?php endif; ?>

    <?php if ($purchasable && !$isOwnProduct): ?>
        <div class="space-y-2.5">
            <div class="grid grid-cols-2 gap-2.5">
                <?php if ($showBuyChoice): ?>
                <button type="button"
                        class="inline-flex items-center justify-center gap-2 h-12 px-3 rounded-xl bg-accent-500 hover:bg-accent-400 text-white font-semibold text-[13px] sm:text-sm transition"
                        data-buy-open
                        data-product-id="<?= (int) $item['id'] ?>"
                        data-checkout-url="<?= htmlspecialchars($checkoutUrl) ?>"
                        data-title="<?= htmlspecialchars($item['title']) ?>"
                        data-auth="<?= Auth::check() ? '1' : '0' ?>"
                        data-login-url="<?= htmlspecialchars(ProductHelper::url('/login')) ?>">
                    <?= $cartIconSvg ?>
                    <?= htmlspecialchars($buyText) ?>
                </button>
                <?php else: ?>
                <a href="<?= $buyUrl ?>" class="inline-flex items-center justify-center gap-2 h-12 px-3 rounded-xl bg-accent-500 hover:bg-accent-400 text-white font-semibold text-[13px] sm:text-sm transition">
                    <?= $cartIconSvg ?>
                    <?= htmlspecialchars($buyText) ?>
                </a>
                <?php endif; ?>
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
    <?php elseif ($type === 'free' || $type === 'exchange' || $type === 'service'): ?>
        <div class="space-y-2.5">
            <?php if ($type === 'free'): ?>
                <p class="text-sm text-center text-gray-500 bg-violet-50/80 dark:bg-violet-950/20 border border-violet-100 dark:border-violet-900/40 rounded-2xl px-4 py-3">
                    <?= htmlspecialchars(t('product.free_contact')) ?>
                </p>
            <?php elseif ($type === 'exchange'): ?>
                <p class="text-sm text-center text-gray-500 bg-indigo-50/80 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900/40 rounded-2xl px-4 py-3">
                    <?= htmlspecialchars(t('product.exchange_contact')) ?>
                </p>
            <?php else: ?>
                <p class="text-sm text-center text-gray-500 bg-emerald-50/80 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/40 rounded-2xl px-4 py-3">
                    <?= htmlspecialchars(t('product.service_contact')) ?>
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

    <?php if (!$isOwnProduct): ?>
        <div class="pt-1 text-center">
            <?php if (Auth::check()): ?>
                <button type="button"
                        id="product-report-open"
                        class="inline-flex items-center gap-1.5 text-[12px] font-medium text-gray-400 hover:text-red-500 transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/>
                    </svg>
                    <?= htmlspecialchars(t('product.report')) ?>
                </button>
            <?php else: ?>
                <a href="<?= ProductHelper::url('/login') ?>"
                   class="inline-flex items-center gap-1.5 text-[12px] font-medium text-gray-400 hover:text-red-500 transition">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/>
                    </svg>
                    <?= htmlspecialchars(t('product.report')) ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
<?php if (Auth::check() && !$isOwnProduct):
    $reportReasons = \App\Models\SupportTicket::REPORT_REASONS;
?>
<div id="product-report-modal"
     class="hidden fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="product-report-title"
     aria-hidden="true">
    <div class="w-full sm:max-w-md bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10 translate-y-3 sm:translate-y-2 opacity-0 transition duration-200 ease-out max-h-[92vh] overflow-y-auto"
         data-report-panel
         onclick="event.stopPropagation()">
        <div class="sm:hidden flex justify-center pt-3 pb-1" aria-hidden="true">
            <span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span>
        </div>
        <div class="px-5 pt-4 sm:pt-6 pb-2">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 id="product-report-title" class="font-display text-xl font-bold text-ink-900 dark:text-white">
                        <?= htmlspecialchars(t('product.report_title')) ?>
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">
                        <?= htmlspecialchars(t('product.report_hint')) ?>
                    </p>
                </div>
                <button type="button" data-report-close class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-ink-800 dark:hover:text-white hover:bg-black/[0.05] dark:hover:bg-white/10 transition shrink-0" aria-label="<?= htmlspecialchars(t('product.close_photo')) ?>">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <form method="post" action="<?= ProductHelper::url('/product/' . (int) $item['id'] . '/report') ?>" class="p-5 pt-3 space-y-4">
            <?= csrf_field() ?>
            <fieldset class="space-y-1.5">
                <legend class="text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2"><?= htmlspecialchars(t('product.report_reason')) ?></legend>
                <?php foreach ($reportReasons as $reason): ?>
                    <label class="flex items-start gap-3 p-3 rounded-xl border border-black/[0.08] dark:border-white/10 hover:border-accent-400/50 cursor-pointer transition has-[:checked]:border-accent-500 has-[:checked]:bg-accent-50/60 dark:has-[:checked]:bg-accent-500/10">
                        <input type="radio" name="reason" value="<?= htmlspecialchars($reason) ?>" required class="mt-0.5 accent-accent-500">
                        <span class="text-[13px] font-medium text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('product.report_reason_' . $reason)) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div>
                <label for="product-report-comment" class="block text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1.5"><?= htmlspecialchars(t('product.report_comment')) ?></label>
                <textarea id="product-report-comment" name="comment" maxlength="2000" rows="3"
                          placeholder="<?= htmlspecialchars(t('product.report_comment_placeholder')) ?>"
                          class="ui-input w-full px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm resize-y min-h-[88px]"></textarea>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <button type="button"
                        data-report-close
                        class="h-11 px-3 rounded-xl border border-black/10 dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 font-semibold text-[13px] hover:bg-black/[0.03] dark:hover:bg-white/10 transition order-2 sm:order-1">
                    <?= htmlspecialchars(t('product.report_cancel')) ?>
                </button>
                <button type="submit"
                        class="h-11 px-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold text-[13px] transition order-1 sm:order-2">
                    <?= htmlspecialchars(t('product.report_submit')) ?>
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('product-report-modal');
    var openBtn = document.getElementById('product-report-open');
    var panel = modal && modal.querySelector('[data-report-panel]');
    if (!modal || !openBtn || !panel) return;

    var closeBtns = modal.querySelectorAll('[data-report-close]');
    var lastFocus = null;

    function openModal() {
        lastFocus = document.activeElement;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
        requestAnimationFrame(function () {
            panel.classList.remove('translate-y-3', 'sm:translate-y-2', 'opacity-0');
            panel.classList.add('translate-y-0', 'opacity-100');
        });
        var first = modal.querySelector('input[name="reason"]');
        if (first) first.focus({ preventScroll: true });
    }

    function closeModal() {
        panel.classList.add('translate-y-3', 'sm:translate-y-2', 'opacity-0');
        panel.classList.remove('translate-y-0', 'opacity-100');
        window.setTimeout(function () {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            document.documentElement.style.overflow = '';
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus({ preventScroll: true });
            }
        }, 180);
    }

    openBtn.addEventListener('click', openModal);
    closeBtns.forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
            e.preventDefault();
            closeModal();
        }
    });
})();
</script>
<?php endif; ?>
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
