<?php
use App\Helpers\ProductHelper;

$product = $product ?? [];
$productId = (int) ($product['id'] ?? 0);
$thumb = ProductHelper::imageUrl($product);
$backUrl = ProductHelper::url('/profile?tab=lots');
$showUrl = ProductHelper::url('/product/' . $productId);

$continueUi = null;
foreach ([$boostUi ?? null, $highlightUi ?? null, $topFixedUi ?? null, $topFixedXlUi ?? null, $vipUi ?? null] as $uiRow) {
    if (is_array($uiRow) && !empty($uiRow['continue_payment'])) {
        $continueUi = $uiRow;
        break;
    }
}
$autoContinue = $continueUi !== null && empty($flash) && empty($error);
?>
<section class="max-w-lg mx-auto space-y-5 fade-up pb-8" data-promote-page="<?= $productId ?>" <?= $autoContinue ? 'data-promote-auto-pay="1"' : '' ?>>
    <div>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 hover:text-brand-600 transition mb-3">
            ← <?= htmlspecialchars(t('profile.promote_back')) ?>
        </a>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('profile.promote_eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('profile.promote_page_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('profile.promote_page_lead')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>

    <?php if ($autoContinue): ?>
        <div class="bg-brand-50/80 dark:bg-brand-950/30 border border-brand-200/60 dark:border-brand-800/40 rounded-2xl px-4 py-3 text-sm text-brand-800 dark:text-brand-200 font-semibold" id="promote-auto-hint">
            <?= htmlspecialchars(t('profile.promote_opening_epay')) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-4 sm:p-5 shadow-soft">
        <a href="<?= htmlspecialchars($showUrl) ?>" class="flex items-center gap-3 hover:opacity-90 transition">
            <div class="w-14 h-14 rounded-xl overflow-hidden bg-brand-50 dark:bg-white/5 flex-shrink-0 flex items-center justify-center">
                <?php if ($thumb): ?>
                    <img src="<?= htmlspecialchars($thumb) ?>" alt="" class="w-full h-full object-cover">
                <?php else: ?>
                    <?= ProductHelper::icon($product['type'] ?? '', 'w-7 h-7 text-brand-500/80') ?>
                <?php endif; ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars((string) ($product['title'] ?? '')) ?></p>
                <p class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars(ProductHelper::formatPrice($product)) ?></p>
            </div>
        </a>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft">
        <div class="px-4 sm:px-5 py-4 border-b border-black/[0.05] dark:border-white/10">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('profile.promote_services_title')) ?></h2>
            <p class="text-[11px] text-gray-500 mt-1"><?= htmlspecialchars(t('profile.promote_services_hint')) ?></p>
        </div>
        <div class="px-4 sm:px-5 py-2 space-y-1" id="lot-promote-<?= $productId ?>">
            <?php \App\Core\View::partial('partials/lot-boost', ['product' => $product, 'ui' => $boostUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-highlight', ['product' => $product, 'ui' => $highlightUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-top-fixed', ['product' => $product, 'ui' => $topFixedUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-top-fixed-xl', ['product' => $product, 'ui' => $topFixedXlUi ?? []]); ?>
            <?php \App\Core\View::partial('partials/lot-vip', ['product' => $product, 'ui' => $vipUi ?? []]); ?>
        </div>
        <div class="px-4 sm:px-5 py-4 border-t border-black/[0.05] dark:border-white/10">
            <div class="flex flex-wrap items-center justify-center gap-3">
                <?php
                $payLogos = [
                    ['file' => 'halyk.png', 'alt' => 'Halyk', 'class' => 'h-8 w-auto max-w-[9rem] object-contain'],
                    ['file' => 'visa.png', 'alt' => 'Visa', 'class' => 'h-9 w-auto max-w-[6rem] object-contain rounded-md'],
                    ['file' => 'mastercard.png', 'alt' => 'Mastercard', 'class' => 'h-9 w-auto max-w-[7rem] object-contain rounded-md'],
                ];
                foreach ($payLogos as $logo):
                ?>
                <img src="<?= ProductHelper::url('/public/assets/img/payments/' . $logo['file']) ?>" alt="<?= htmlspecialchars($logo['alt']) ?>" class="<?= $logo['class'] ?>">
                <?php endforeach; ?>
            </div>
            <p class="text-[11px] text-center text-gray-400 mt-3"><?= htmlspecialchars(t('profile.promote_pay_note')) ?></p>
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
    // Первая незавершённая оплата → сразу Halyk ePay
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
