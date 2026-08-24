<?php
use App\Core\Auth;
use App\Core\View;
use App\Helpers\AvatarHelper;
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;

$course = ProductHelper::coursePage($item);
$authorYear = '';
if (!empty($item['seller_created_at'])) {
    $authorYear = date('Y', strtotime((string) $item['seller_created_at']));
}
$similarCourses = array_values(array_filter($similar ?? [], static fn ($row) => ($row['type'] ?? '') === 'course'));
$aboutLong = mb_strlen($course['body']) > 420;
$digitalHasAccess = !empty($digitalHasAccess) || !empty($digitalHasAccess);
?>
<section class="max-w-6xl mx-auto pb-12 fade-up">
    <?php if ($flash): ?>
        <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="mb-4 bg-red-50 dark:bg-red-900/25 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <nav class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[12px] text-gray-400 mb-6" aria-label="breadcrumb">
        <a href="<?= ProductHelper::url('/') ?>" class="hover:text-violet-600 transition"><?= htmlspecialchars(t('nav.home')) ?></a>
        <?= $chevron ?>
        <a href="<?= ProductHelper::url('/catalog/courses') ?>" class="hover:text-violet-600 transition"><?= htmlspecialchars(t('nav.courses')) ?></a>
        <?= $chevron ?>
        <span class="text-ink-700 dark:text-gray-300 font-medium truncate"><?= htmlspecialchars($item['title']) ?></span>
    </nav>

    <header class="mb-8 space-y-3">
        <ul class="flex flex-wrap gap-2">
            <li class="text-[11px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-violet-100 dark:bg-violet-500/15 text-violet-800 dark:text-violet-200"><?= htmlspecialchars(t('product.course_tag')) ?></li>
            <li class="text-[11px] font-semibold px-2.5 py-1 rounded-full bg-ink-100 dark:bg-white/10 text-ink-700 dark:text-gray-200"><?= htmlspecialchars($course['format_label']) ?></li>
            <?php if (!empty($item['location'])): ?>
                <li class="text-[11px] font-semibold px-2.5 py-1 rounded-full bg-ink-100 dark:bg-white/10 text-ink-700 dark:text-gray-200"><?= htmlspecialchars($item['location']) ?></li>
            <?php endif; ?>
        </ul>
        <h1 class="font-display text-2xl sm:text-4xl font-bold tracking-tight text-ink-900 dark:text-white leading-tight"><?= htmlspecialchars($item['title']) ?></h1>
        <?php if ($course['excerpt'] !== ''): ?>
            <p class="text-[15px] sm:text-base text-gray-600 dark:text-gray-300 max-w-3xl leading-relaxed"><?= htmlspecialchars($course['excerpt']) ?></p>
        <?php endif; ?>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <div class="lg:col-span-8 space-y-10 order-2 lg:order-1">
            <div class="relative rounded-2xl overflow-hidden bg-ink-100 dark:bg-white/10">
                <div id="product-gallery-stage"
                     class="aspect-[16/9] flex items-center justify-center relative<?= $imageUrl ? ' photo-wm photo-wm--md cursor-zoom-in' : '' ?>"
                     <?php if ($imageUrl): ?>
                     data-lightbox
                     data-lightbox-src="<?= htmlspecialchars($imageUrl) ?>"
                     data-lightbox-gallery="<?= $galleryJson ?>"
                     <?php endif; ?>>
                    <?php if ($imageUrl): ?>
                        <img id="product-main-image" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="absolute inset-0 w-full h-full object-cover pointer-events-none">
                    <?php else: ?>
                        <?= ProductHelper::icon('course', 'w-20 h-20 text-violet-400') ?>
                    <?php endif; ?>
                </div>
                <button type="button"
                        class="favorite-btn absolute top-3 right-3 z-20 w-10 h-10 rounded-full bg-white/95 dark:bg-ink-900/80 shadow-sm flex items-center justify-center <?= !empty($isFavorite) ? 'is-favorited text-red-500' : 'text-gray-400 hover:text-red-500' ?>"
                        data-product-id="<?= (int) $item['id'] ?>"
                        data-favorited="<?= !empty($isFavorite) ? '1' : '0' ?>">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="<?= !empty($isFavorite) ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                </button>
            </div>

            <div>
                <h2 class="font-display text-xl sm:text-2xl font-bold text-violet-800 dark:text-violet-200 mb-4"><?= htmlspecialchars(t('product.course_about')) ?></h2>
                <?php if ($course['body'] === ''): ?>
                    <p class="text-sm text-gray-400"><?= htmlspecialchars(t('product.no_description')) ?></p>
                <?php else: ?>
                    <div id="course-about" class="text-[15px] leading-7 text-ink-800 dark:text-gray-200 whitespace-pre-line<?= $aboutLong ? ' line-clamp-8' : '' ?>"><?= htmlspecialchars($course['body']) ?></div>
                    <?php if ($aboutLong): ?>
                        <button type="button" class="mt-2 text-sm font-semibold text-violet-700 hover:underline" data-toggle="course-about" data-clamp="line-clamp-8" data-more="<?= htmlspecialchars(t('product.course_more')) ?>" data-less="<?= htmlspecialchars(t('product.course_less')) ?>"><?= htmlspecialchars(t('product.course_more')) ?></button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="font-display text-xl sm:text-2xl font-bold text-violet-800 dark:text-violet-200 mb-5"><?= htmlspecialchars(t('product.course_for')) ?></h2>
                <div class="grid sm:grid-cols-3 gap-4">
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                        <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] p-4">
                            <div class="font-semibold text-ink-900 dark:text-white mb-2"><?= htmlspecialchars(t('product.course_for_' . $i . '_title')) ?></div>
                            <p class="text-sm text-gray-500 leading-relaxed"><?= htmlspecialchars(t('product.course_for_' . $i . '_text')) ?></p>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div>
                <h2 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mb-4"><?= htmlspecialchars(t('product.course_program')) ?></h2>
                <?php if ($course['lessons'] === []): ?>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars(t('product.course_program_empty')) ?></p>
                <?php else: ?>
                    <ol class="divide-y divide-black/[0.06] dark:divide-white/10 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] overflow-hidden">
                        <?php foreach ($course['lessons'] as $i => $lesson): ?>
                            <li class="flex items-start gap-3 px-4 py-3.5">
                                <span class="mt-0.5 w-8 h-8 rounded-full bg-violet-100 dark:bg-violet-500/20 text-violet-800 dark:text-violet-200 text-xs font-bold flex items-center justify-center shrink-0"><?= $i + 1 ?></span>
                                <span class="text-sm text-ink-800 dark:text-gray-100 pt-1.5"><?= htmlspecialchars($lesson) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <div>
                <h2 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mb-4"><?= htmlspecialchars(t('product.course_author')) ?></h2>
                <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] p-5">
                    <button type="button" class="seller-profile-trigger flex items-start gap-4 text-left w-full" data-seller-id="<?= (int) $item['user_id'] ?>">
                        <?= AvatarHelper::html($sellerUser, 'w-16 h-16', 'text-lg', 'rounded-2xl') ?>
                        <span>
                            <span class="block font-semibold text-lg text-ink-900 dark:text-white"><?= htmlspecialchars($item['seller_name'] ?? '') ?></span>
                            <?php if ($authorYear !== ''): ?>
                                <span class="block text-sm text-gray-400 mt-0.5"><?= htmlspecialchars(t('product.course_on_platform', ['year' => $authorYear])) ?></span>
                            <?php endif; ?>
                            <?php if (($sr['count'] ?? 0) > 0): ?>
                                <span class="inline-flex items-center gap-1 mt-1 text-sm">
                                    <span class="text-amber-500"><?= IconHelper::star('w-4 h-4', true) ?></span>
                                    <span class="font-semibold"><?= htmlspecialchars(number_format((float) $sr['avg'], 1)) ?></span>
                                    <span class="text-gray-400">(<?= (int) $sr['count'] ?>)</span>
                                </span>
                            <?php endif; ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <aside class="lg:col-span-4 order-1 lg:order-2 lg:sticky lg:top-24 space-y-4">
            <div class="rounded-2xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.05] p-5 shadow-sm">
                <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('product.course_cost')) ?></div>
                <div class="font-display text-3xl font-extrabold text-ink-900 dark:text-white mb-4"><?= htmlspecialchars($price) ?></div>
                <?php if (!empty($digitalHasAccess)): ?>
                    <a href="<?= ProductHelper::url('/digital/' . (int) $item['id'] . '/watch') ?>" class="flex items-center justify-center h-12 rounded-xl bg-violet-700 hover:bg-violet-600 text-white font-semibold mb-2.5 transition"><?= htmlspecialchars(t('digital.open_player')) ?></a>
                <?php elseif ($purchasable && !$isOwnProduct): ?>
                    <a href="<?= $buyUrl ?>" class="flex items-center justify-center h-12 rounded-xl bg-violet-700 hover:bg-violet-600 text-white font-semibold mb-2.5 transition"><?= htmlspecialchars($buyText) ?></a>
                    <button type="button"
                            class="cart-btn w-full inline-flex items-center justify-center gap-2 h-11 px-3 rounded-xl border-2 border-violet-700 text-violet-800 dark:text-violet-200 font-semibold text-sm mb-2.5 <?= $inCart ? 'is-in-cart bg-violet-50 dark:bg-violet-500/10' : '' ?>"
                            data-product-id="<?= (int) $item['id'] ?>"
                            data-in-cart="<?= $inCart ? '1' : '0' ?>">
                        <?= $cartIconSvg ?>
                        <span class="cart-btn-label"><?= htmlspecialchars($inCart ? t('card.in_cart') : t('card.add_cart')) ?></span>
                    </button>
                <?php endif; ?>
                <?php if (Auth::check() && !$isOwnProduct): ?>
                    <button type="button" data-chat-open data-product-id="<?= (int) $item['id'] ?>" class="w-full h-11 rounded-xl border border-black/10 dark:border-white/15 font-semibold text-sm"><?= htmlspecialchars(t('chat.write_seller')) ?></button>
                <?php elseif (!Auth::check()): ?>
                    <a href="<?= ProductHelper::url('/login') ?>" class="flex items-center justify-center w-full h-11 rounded-xl border border-black/10 font-semibold text-sm"><?= htmlspecialchars($buyText) ?></a>
                <?php endif; ?>
                <?php if ($whatsappHref && !$isOwnProduct): ?>
                    <a href="<?= htmlspecialchars($whatsappHref) ?>" target="_blank" rel="noopener noreferrer" class="mt-2.5 flex items-center justify-center gap-2 h-11 rounded-xl bg-[#25D366] text-white font-semibold text-sm"><?= $waIconSvg ?> WhatsApp</a>
                <?php endif; ?>
                <p class="mt-4 text-[12px] leading-relaxed text-gray-500"><?= htmlspecialchars(t('product.course_access_note')) ?></p>
            </div>
        </aside>
    </div>

    <?php if ($similarCourses): ?>
        <div class="mt-12">
            <h2 class="font-display text-xl font-bold mb-4"><?= htmlspecialchars(t('product.course_similar')) ?></h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <?php foreach (array_slice($similarCourses, 0, 4) as $row): ?>
                    <?php View::partial('partials/product-card', ['item' => $row, 'favorited' => in_array((int) $row['id'], $favoriteIds ?? [], true), 'compact' => true]); ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
