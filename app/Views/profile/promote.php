<?php
use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Boost\BoostPolicy;
use App\Services\Highlight\HighlightPolicy;
use App\Services\TopFixed\TopFixedPolicy;
use App\Services\TopFixedXl\TopFixedXlPolicy;
use App\Services\Vip\VipPolicy;

$product = $product ?? [];
$productId = (int) ($product['id'] ?? 0);
$thumb = ProductHelper::imageUrl($product);
$backUrl = ProductHelper::url('/profile?tab=my-listings');
$showUrl = ProductHelper::url('/product/' . $productId);
$title = (string) ($product['title'] ?? '');
$priceLabel = ProductHelper::formatPrice($product);

$continueUi = null;
foreach ([$boostUi ?? null, $highlightUi ?? null, $topFixedUi ?? null, $topFixedXlUi ?? null, $vipUi ?? null] as $uiRow) {
    if (is_array($uiRow) && !empty($uiRow['continue_payment'])) {
        $continueUi = $uiRow;
        break;
    }
}
$autoContinue = $continueUi !== null && empty($flash) && empty($error);

$from = Wallet::formatMoney(BoostPolicy::PRICE_KZT);
?>
<style>
@keyframes promote-rise {
    from { opacity: 0; transform: translateY(14px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes promote-glow {
    0%, 100% { opacity: .45; }
    50% { opacity: .85; }
}
[data-promote-page] {
    --promote-rise: promote-rise .55s cubic-bezier(.22,1,.36,1) both;
}
[data-promote-page] .promote-hero { animation: var(--promote-rise); }
[data-promote-page] .promote-product { animation: var(--promote-rise); animation-delay: .06s; }
[data-promote-page] .promote-services { animation: var(--promote-rise); animation-delay: .12s; }
[data-promote-page] .promote-paybar { animation: var(--promote-rise); animation-delay: .18s; }
[data-promote-page] .promote-orb {
    animation: promote-glow 5s ease-in-out infinite;
}
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-boost-card],
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-highlight-card],
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-card],
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-xl-card],
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-vip-card] {
    border: 1px solid rgba(0,0,0,.06) !important;
    border-radius: 18px !important;
    margin: 0 0 .75rem !important;
    padding: 1rem 1.05rem !important;
    background: rgba(255,255,255,.72);
    box-shadow: 0 1px 0 rgba(255,255,255,.6) inset;
    transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
}
.dark [data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-boost-card],
.dark [data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-highlight-card],
.dark [data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-card],
.dark [data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-xl-card],
.dark [data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-vip-card] {
    border-color: rgba(255,255,255,.1) !important;
    background: rgba(255,255,255,.03);
    box-shadow: none;
}
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-boost-card]:hover,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-highlight-card]:hover,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-card]:hover,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-xl-card]:hover,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-vip-card]:hover {
    border-color: rgba(234, 88, 12, .35) !important;
    box-shadow: 0 10px 28px -16px rgba(234, 88, 12, .35);
    transform: translateY(-1px);
}
[data-promote-page] #lot-promote-<?= (int) $productId ?> > *:last-child {
    margin-bottom: 0 !important;
}
/* Кнопка рядом с текстом, по вертикали по центру */
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-boost-card] > div,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-highlight-card] > div,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-card] > div,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-top-fixed-xl-card] > div,
[data-promote-page] #lot-promote-<?= (int) $productId ?> > [data-vip-card] > div {
    align-items: center !important;
}
[data-promote-page] #lot-promote-<?= (int) $productId ?> form {
    display: flex;
    align-items: center;
    justify-content: center;
}
@media (prefers-reduced-motion: reduce) {
    [data-promote-page] .promote-hero,
    [data-promote-page] .promote-product,
    [data-promote-page] .promote-services,
    [data-promote-page] .promote-paybar,
    [data-promote-page] .promote-orb { animation: none !important; }
}
</style>

<section class="relative max-w-xl mx-auto pb-10"
         data-promote-page="<?= $productId ?>"
         <?= $autoContinue ? 'data-promote-auto-pay="1"' : '' ?>>

    <div class="pointer-events-none absolute -top-8 -right-10 w-56 h-56 rounded-full bg-orange-400/20 blur-3xl promote-orb" aria-hidden="true"></div>
    <div class="pointer-events-none absolute top-40 -left-12 w-48 h-48 rounded-full bg-brand-400/15 blur-3xl promote-orb" style="animation-delay:1.2s" aria-hidden="true"></div>

    <div class="promote-hero relative mb-5">
        <a href="<?= htmlspecialchars($backUrl) ?>"
           class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-orange-600 dark:hover:text-orange-400 transition mb-4">
            <span aria-hidden="true">←</span> <?= htmlspecialchars(t('profile.promote_back')) ?>
        </a>

        <div class="flex items-start gap-3.5">
            <span class="mt-0.5 w-11 h-11 rounded-2xl bg-gradient-to-br from-orange-500 to-amber-500 text-white shadow-soft flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-orange-600/80 dark:text-orange-400/90"><?= htmlspecialchars(t('profile.promote_eyebrow')) ?></p>
                <h1 class="font-display text-2xl sm:text-[1.85rem] font-bold tracking-tight text-ink-900 dark:text-white mt-1 leading-tight">
                    <?= htmlspecialchars(t('profile.promote_page_title')) ?>
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed max-w-md">
                    <?= htmlspecialchars(t('profile.promote_page_lead')) ?>
                </p>
                <p class="text-[11px] text-gray-400 mt-2">
                    <?= htmlspecialchars(t('profile.promote_from_price', ['amount' => $from])) ?>
                </p>
            </div>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="mb-4 bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="mb-4 bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>

    <?php if ($autoContinue): ?>
        <div class="mb-4 relative overflow-hidden rounded-2xl border border-orange-200/70 dark:border-orange-500/30 bg-gradient-to-r from-orange-50 to-amber-50 dark:from-orange-950/40 dark:to-amber-950/20 px-4 py-3.5" id="promote-auto-hint">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-xl bg-orange-500 text-white flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                </span>
                <p class="text-sm font-semibold text-orange-900 dark:text-orange-100"><?= htmlspecialchars(t('profile.promote_opening_epay')) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <a href="<?= htmlspecialchars($showUrl) ?>"
       class="promote-product group relative block overflow-hidden rounded-[28px] border border-black/[0.06] dark:border-white/10 shadow-lift mb-5">
        <div class="absolute inset-0 bg-gradient-to-br from-ink-900 via-ink-800 to-orange-900"></div>
        <?php if ($thumb): ?>
            <img src="<?= htmlspecialchars($thumb) ?>" alt=""
                 class="absolute inset-0 w-full h-full object-cover opacity-55 group-hover:opacity-65 group-hover:scale-[1.03] transition duration-500">
        <?php endif; ?>
        <div class="absolute inset-0 bg-gradient-to-t from-ink-950/90 via-ink-950/35 to-transparent"></div>
        <div class="relative min-h-[9.5rem] sm:min-h-[11rem] p-5 sm:p-6 flex flex-col justify-end">
            <span class="inline-flex self-start text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-lg bg-white/15 text-white/90 backdrop-blur-sm mb-2.5">
                <?= htmlspecialchars(t('profile.promote_listing_badge')) ?>
            </span>
            <p class="font-display text-lg sm:text-xl font-bold text-white leading-snug line-clamp-2 drop-shadow-sm"><?= htmlspecialchars($title) ?></p>
            <p class="mt-1.5 font-display text-base font-extrabold text-orange-300 tabular-nums"><?= htmlspecialchars($priceLabel) ?></p>
        </div>
    </a>

    <div class="promote-services relative rounded-[28px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft overflow-hidden backdrop-blur-sm">
        <div class="px-5 sm:px-6 pt-5 pb-4 border-b border-black/[0.05] dark:border-white/10 bg-gradient-to-r from-orange-50/80 via-transparent to-brand-50/40 dark:from-orange-950/20 dark:via-transparent dark:to-brand-950/10">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('profile.promote_services_title')) ?></h2>
                    <p class="text-[12px] text-gray-500 dark:text-gray-400 mt-1 leading-relaxed"><?= htmlspecialchars(t('profile.promote_services_hint')) ?></p>
                </div>
                <span class="hidden sm:inline-flex text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:text-orange-300 bg-orange-100/80 dark:bg-orange-500/15 px-2.5 py-1 rounded-lg">
                    Halyk ePay
                </span>
            </div>
            <div class="mt-3.5 flex flex-wrap gap-1.5 text-[10px] font-semibold text-gray-500 dark:text-gray-400">
                <span class="px-2 py-1 rounded-lg bg-black/[0.04] dark:bg-white/5">Boost · <?= htmlspecialchars(Wallet::formatMoney(BoostPolicy::PRICE_KZT)) ?></span>
                <span class="px-2 py-1 rounded-lg bg-black/[0.04] dark:bg-white/5">Highlight · <?= (int) HighlightPolicy::DURATION_DAYS ?>д</span>
                <span class="px-2 py-1 rounded-lg bg-black/[0.04] dark:bg-white/5">Top · <?= (int) TopFixedPolicy::DURATION_DAYS ?>д</span>
                <span class="px-2 py-1 rounded-lg bg-black/[0.04] dark:bg-white/5">XL · <?= (int) TopFixedXlPolicy::DURATION_DAYS ?>д</span>
                <span class="px-2 py-1 rounded-lg bg-black/[0.04] dark:bg-white/5">VIP · <?= (int) VipPolicy::DURATION_DAYS ?>д</span>
            </div>
        </div>

        <div class="px-4 sm:px-5 py-4" id="lot-promote-<?= $productId ?>">
            <?php \App\Core\View::partial('partials/lot-boost', ['product' => $product, 'ui' => $boostUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-highlight', ['product' => $product, 'ui' => $highlightUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-top-fixed', ['product' => $product, 'ui' => $topFixedUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-top-fixed-xl', ['product' => $product, 'ui' => $topFixedXlUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-vip', ['product' => $product, 'ui' => $vipUi ?? []]); ?>
        </div>
    </div>

    <div class="promote-paybar mt-5 rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-gradient-to-br from-ink-900 via-ink-800 to-orange-950 text-white px-5 py-4 shadow-lift">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-white/50"><?= htmlspecialchars(t('profile.promote_secure_pay')) ?></p>
                <p class="text-sm font-semibold mt-1 text-white/90"><?= htmlspecialchars(t('profile.promote_pay_note')) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <?php
                $payLogos = [
                    ['file' => 'halyk.png', 'alt' => 'Halyk', 'class' => 'h-7 w-auto max-w-[7.5rem] object-contain bg-white rounded-md px-1.5 py-1'],
                    ['file' => 'visa.png', 'alt' => 'Visa', 'class' => 'h-7 w-auto max-w-[4.5rem] object-contain bg-white rounded-md px-1.5 py-0.5'],
                    ['file' => 'mastercard.png', 'alt' => 'Mastercard', 'class' => 'h-7 w-auto max-w-[5rem] object-contain bg-white rounded-md px-1.5 py-0.5'],
                ];
                foreach ($payLogos as $logo):
                ?>
                <img src="<?= ProductHelper::url('/public/assets/img/payments/' . $logo['file']) ?>" alt="<?= htmlspecialchars($logo['alt']) ?>" class="<?= $logo['class'] ?>">
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<script>
function promoteLockAndSubmit(form, lockAttr, waitAttr) {
    if (!form || form.getAttribute(lockAttr) === '1') return false;
    form.setAttribute(lockAttr, '1');
    var btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        var wait = form.getAttribute(waitAttr);
        if (wait) btn.textContent = wait;
    }
    return true;
}
function profileBoostSubmit(form) {
    return promoteLockAndSubmit(form, 'data-boost-locked', 'data-boost-wait');
}
function profileHighlightSubmit(form) {
    return promoteLockAndSubmit(form, 'data-highlight-locked', 'data-highlight-wait');
}
function profileTopFixedSubmit(form) {
    return promoteLockAndSubmit(form, 'data-top-fixed-locked', 'data-top-fixed-wait');
}
function profileTopFixedXlSubmit(form) {
    return promoteLockAndSubmit(form, 'data-top-fixed-xl-locked', 'data-top-fixed-xl-wait');
}
function profileVipSubmit(form) {
    return promoteLockAndSubmit(form, 'data-vip-locked', 'data-vip-wait');
}

(function autoOpenEpay() {
    var page = document.querySelector('[data-promote-auto-pay="1"]');
    if (!page) return;
    var form = page.querySelector(
        '[data-boost-card] form, [data-highlight-card] form, [data-top-fixed-card] form, [data-top-fixed-xl-card] form, [data-vip-card] form, form[action*="/boost"], form[action*="/highlight"], form[action*="/top-fixed"], form[action*="/vip"]'
    );
    if (!form) return;
    setTimeout(function () {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }, 80);
})();
</script>
