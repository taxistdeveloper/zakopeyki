<?php

use App\Core\Auth;
use App\Helpers\AboutDocumentsHelper;
use App\Helpers\ProductHelper;

if (!Auth::check() || Auth::listingRulesAccepted()) {
    return;
}

$sellerManualUrl = ProductHelper::url('/about/document/' . AboutDocumentsHelper::slugFor('Мануал продавца.pdf'));
$publicOfferUrl = ProductHelper::url('/offer');
$acceptUrl = ProductHelper::url('/profile/listing-rules');
?>
<div id="listing-rules-modal" class="hidden fixed inset-0 z-[120] flex items-end sm:items-center justify-center p-0 sm:p-4" aria-hidden="true">
    <button type="button" class="absolute inset-0 bg-ink-900/55 backdrop-blur-sm cursor-default" data-rules-close aria-label="<?= htmlspecialchars(t('listing_rules.close')) ?>"></button>
    <div role="dialog" aria-modal="true" aria-labelledby="listing-rules-title"
         class="relative w-full sm:max-w-lg max-h-[92vh] flex flex-col rounded-t-[28px] sm:rounded-[28px] bg-white dark:bg-ink-800 shadow-lift border border-black/[0.06] dark:border-white/10 overflow-hidden">
        <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-black/[0.06] dark:border-white/10 shrink-0">
            <h2 id="listing-rules-title" class="font-display text-lg font-bold text-ink-900 dark:text-white leading-snug"><?= htmlspecialchars(t('listing_rules.title')) ?></h2>
            <button type="button" data-rules-close class="h-9 w-9 rounded-xl border border-black/10 dark:border-white/15 text-gray-500 hover:text-ink-900 dark:hover:text-white hover:bg-gray-50 dark:hover:bg-white/5 transition shrink-0" aria-label="<?= htmlspecialchars(t('listing_rules.close')) ?>">&times;</button>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-3">
            <p class="text-sm text-ink-800 dark:text-gray-200 leading-relaxed"><?= htmlspecialchars(t('listing_rules.lead')) ?></p>
            <p class="text-sm font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('listing_rules.forbidden')) ?></p>
            <ul class="space-y-1.5 text-sm text-ink-800 dark:text-gray-200">
                <?php for ($rulesI = 1; $rulesI <= 9; $rulesI++): ?>
                    <li class="flex gap-2"><span class="text-brand-500 shrink-0" aria-hidden="true">•</span><span><?= htmlspecialchars(t('listing_rules.item_' . $rulesI)) ?></span></li>
                <?php endfor; ?>
            </ul>
            <p class="text-sm text-ink-700 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t('listing_rules.warning')) ?></p>
            <label class="flex items-start gap-3 rounded-2xl border border-black/[0.08] dark:border-white/10 bg-brand-50/40 dark:bg-white/[0.03] px-3 py-3 cursor-pointer">
                <input type="checkbox" id="listing-rules-check" class="mt-0.5 h-4 w-4 rounded border-black/20 text-accent-500 focus:ring-accent-400">
                <span class="text-sm text-ink-900 dark:text-white leading-snug"><?= htmlspecialchars(t('listing_rules.checkbox')) ?></span>
            </label>
        </div>
        <div class="shrink-0 border-t border-black/[0.06] dark:border-white/10 px-5 py-4 space-y-2 bg-white dark:bg-ink-800">
            <div class="flex flex-col sm:flex-row gap-2">
                <a href="<?= htmlspecialchars($sellerManualUrl) ?>" target="_blank" rel="noopener" class="flex-1 text-center px-4 py-3 rounded-2xl border border-black/[0.08] dark:border-white/10 text-xs font-bold uppercase tracking-wide hover:bg-black/[0.03] dark:hover:bg-white/5 transition"><?= htmlspecialchars(t('listing_rules.manual')) ?></a>
                <a href="<?= htmlspecialchars($publicOfferUrl) ?>" target="_blank" rel="noopener" class="flex-1 text-center px-4 py-3 rounded-2xl border border-black/[0.08] dark:border-white/10 text-xs font-bold uppercase tracking-wide hover:bg-black/[0.03] dark:hover:bg-white/5 transition"><?= htmlspecialchars(t('listing_rules.offer')) ?></a>
            </div>
            <button type="button" id="listing-rules-continue" disabled class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-accent-500">
                <?= htmlspecialchars(t('listing_rules.continue')) ?>
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('listing-rules-modal');
    const link = document.getElementById('header-add-listing');
    const checkbox = document.getElementById('listing-rules-check');
    const cont = document.getElementById('listing-rules-continue');
    if (!modal || !checkbox || !cont) return;
    if (modal.parentElement !== document.body) document.body.appendChild(modal);

    const acceptUrl = <?= json_encode($acceptUrl) ?>;

    function setOpen(open) {
        modal.classList.toggle('hidden', !open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.style.overflow = open ? 'hidden' : '';
        if (!open) return;
        checkbox.checked = false;
        cont.disabled = true;
        checkbox.focus();
    }

    checkbox.addEventListener('change', function () {
        cont.disabled = !checkbox.checked;
    });
    modal.querySelectorAll('[data-rules-close]').forEach(function (el) {
        el.addEventListener('click', function () { setOpen(false); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) setOpen(false);
    });

    function proceed() {
        const needsVerify = link && link.dataset.needsVerify === '1';
        const href = link ? link.getAttribute('href') : '';
        setOpen(false);
        if (needsVerify && typeof openListingVerify === 'function') {
            openListingVerify();
            return;
        }
        if (href) window.location.href = href;
    }

    cont.addEventListener('click', function () {
        if (!checkbox.checked) return;
        cont.disabled = true;
        fetch(acceptUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new URLSearchParams()
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || !data || !data.ok) throw new Error('fail');
                if (link) link.dataset.rulesAccepted = '1';
                proceed();
            });
        }).catch(function () {
            cont.disabled = !checkbox.checked;
        });
    });

    if (!link) return;
    link.addEventListener('click', function (e) {
        if (link.dataset.rulesAccepted === '1') {
            if (link.dataset.needsVerify === '1' && typeof openListingVerify === 'function') {
                e.preventDefault();
                openListingVerify();
            }
            return;
        }
        e.preventDefault();
        setOpen(true);
    });
})();
</script>
