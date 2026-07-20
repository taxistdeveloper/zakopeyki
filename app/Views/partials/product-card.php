<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

$badge = ProductHelper::badge($item['type']);
$price = ProductHelper::formatPrice($item);
$icon = ProductHelper::icon($item['type']);
$imageUrl = ProductHelper::imageUrl($item);
$showUrl = ProductHelper::url('/product/' . $item['id']);
$favorited = !empty($favorited);
$canFavorite = Auth::check();

$ctaBase = 'block w-full text-center font-display font-bold text-[10px] py-2.5 rounded-xl transition uppercase tracking-wider';
?>
<article class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft hover:shadow-lift hover:-translate-y-0.5 transition duration-300 flex flex-col justify-between h-[360px] cursor-pointer group backdrop-blur-sm relative">
    <a href="<?= $showUrl ?>" class="h-32 bg-gradient-to-br from-ink-100 via-brand-50 to-orange-50 dark:from-white/10 dark:via-brand-900/20 dark:to-transparent relative flex items-center justify-center text-4xl overflow-hidden">
        <?php if ($imageUrl): ?>
            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="absolute inset-0 w-full h-full object-cover transition duration-300 group-hover:scale-105">
        <?php else: ?>
            <span class="transition duration-300 group-hover:scale-110"><?= $icon ?></span>
        <?php endif; ?>
        <span class="absolute top-2.5 left-2.5 text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-lg shadow-sm <?= $badge['class'] ?>">
            <?= htmlspecialchars($badge['text']) ?>
        </span>
    </a>
    <button type="button"
            class="favorite-btn absolute top-2.5 right-2.5 z-10 w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 <?= $favorited ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
            data-product-id="<?= (int) $item['id'] ?>"
            data-favorited="<?= $favorited ? '1' : '0' ?>"
            aria-label="<?= htmlspecialchars($favorited ? t('card.unfavorite') : t('card.favorite')) ?>"
            title="<?= htmlspecialchars($canFavorite ? ($favorited ? t('card.unfavorite') : t('card.favorite')) : t('card.favorite_login')) ?>">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="<?= $favorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
        </svg>
    </button>
    <div class="p-4 flex flex-col flex-1 justify-between gap-2">
        <div>
            <h3 class="text-xs sm:text-sm font-semibold line-clamp-2 text-ink-800 dark:text-gray-200 leading-snug">
                <a href="<?= $showUrl ?>"><?= htmlspecialchars($item['title']) ?></a>
            </h3>
            <?php
            $showCardCategory = in_array($item['type'] ?? '', ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true)
                && !empty($item['category'])
                && ($item['category'] ?? '') !== 'Разное';
            if ($showCardCategory):
                [$cardParent, $cardChild] = ProductHelper::parseCategory($item['category']);
            ?>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1.5 line-clamp-1" title="<?= htmlspecialchars(ProductHelper::categoryLabel($cardParent) . ' / ' . ProductHelper::categoryLabel($cardChild)) ?>">
                    <span class="text-ink-700 dark:text-gray-300 font-medium"><?= htmlspecialchars(ProductHelper::categoryLabel($cardParent)) ?></span>
                    <span class="text-gray-300 dark:text-gray-600 mx-0.5">/</span>
                    <span class="text-brand-600 dark:text-brand-400"><?= htmlspecialchars(ProductHelper::categoryLabel($cardChild)) ?></span>
                </p>
            <?php endif; ?>
            <p class="text-[10px] text-gray-400 mt-1.5"><?= htmlspecialchars($item['location']) ?></p>
            <?php if (($item['type'] ?? '') === 'exchange' && !empty($item['exchange_for'])): ?>
                <p class="text-[10px] text-indigo-600 dark:text-indigo-300 mt-1 line-clamp-2">
                    <span class="font-semibold"><?= htmlspecialchars(t('product.exchange_for_short')) ?>:</span>
                    <?= htmlspecialchars($item['exchange_for']) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="space-y-2">
            <div class="flex justify-between items-center">
                <span class="text-sm font-display font-bold <?= ($item['type'] ?? '') === 'free' ? 'text-violet-600 dark:text-violet-300' : 'text-ink-900 dark:text-white' ?>"><?= htmlspecialchars($price) ?></span>
            </div>
            <div class="space-y-1.5 pt-2 border-t border-black/[0.05] dark:border-white/10">
                <?php
                $type = $item['type'];
                if ($type === 'course'): ?>
                    <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-blue-600 hover:bg-blue-700 text-white"><?= htmlspecialchars(t('card.order')) ?></a>
                <?php elseif ($type === 'used'): ?>
                    <div class="grid grid-cols-2 gap-1.5">
                        <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-brand-500 hover:bg-brand-400 text-ink-900"><?= htmlspecialchars(t('card.buy')) ?></a>
                        <a href="https://wa.me/77000000000?text=<?= urlencode(t('card.wa_bargain', ['title' => $item['title']])) ?>" target="_blank" class="<?= $ctaBase ?> bg-ink-800 hover:bg-ink-900 text-white"><?= htmlspecialchars(t('card.bargain')) ?></a>
                    </div>
                <?php elseif ($type === 'new'): ?>
                    <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-brand-500 hover:bg-brand-400 text-ink-900"><?= htmlspecialchars(t('card.buy')) ?></a>
                <?php elseif ($type === 'service'): ?>
                    <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-emerald-600 hover:bg-emerald-700 text-white"><?= htmlspecialchars(t('card.order')) ?></a>
                <?php elseif ($type === 'free'): ?>
                    <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-violet-600 hover:bg-violet-700 text-white"><?= htmlspecialchars(t('card.take')) ?></a>
                <?php elseif ($type === 'auction'): ?>
                    <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-red-600 hover:bg-red-700 text-white"><?= htmlspecialchars(t('card.bid')) ?></a>
                <?php elseif ($type === 'exchange'): ?>
                    <a href="<?= $showUrl ?>" class="<?= $ctaBase ?> bg-indigo-600 hover:bg-indigo-700 text-white"><?= htmlspecialchars(t('card.exchange')) ?></a>
                <?php endif; ?>
                <a href="<?= $showUrl ?>" class="block w-full text-center text-gray-400 hover:text-ink-700 dark:hover:text-gray-200 font-medium text-[10px] py-1 transition"><?= htmlspecialchars(t('card.more')) ?></a>
            </div>
        </div>
    </div>
</article>
