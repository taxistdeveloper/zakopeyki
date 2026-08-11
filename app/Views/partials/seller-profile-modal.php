<!-- Seller profile modal -->
<div id="seller-profile-modal" class="hidden fixed inset-0 z-[85] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="seller-profile-name" onclick="if(event.target===this)closeSellerProfile()">
    <div class="seller-profile-panel w-full sm:max-w-md bg-white dark:bg-ink-900 rounded-t-[28px] sm:rounded-[28px] shadow-lift border border-black/[0.06] dark:border-white/10 overflow-hidden max-h-[min(92vh,720px)] flex flex-col" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between px-5 pt-4 pb-2 shrink-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('seller.eyebrow')) ?></p>
            <button type="button" onclick="closeSellerProfile()" class="w-9 h-9 rounded-xl bg-ink-50 dark:bg-white/10 text-ink-700 dark:text-gray-200 hover:bg-ink-100 dark:hover:bg-white/15 transition flex items-center justify-center text-lg leading-none" aria-label="<?= htmlspecialchars(t('seller.close')) ?>">✕</button>
        </div>

        <div id="seller-profile-loading" class="px-5 py-12 text-center text-sm text-gray-400"><?= htmlspecialchars(t('seller.loading')) ?></div>

        <div id="seller-profile-error" class="hidden px-5 py-12 text-center text-sm text-red-500"></div>

        <div id="seller-profile-body" class="hidden flex flex-col flex-1 min-h-0">
            <div class="px-5 pb-4 flex flex-col items-center text-center gap-3 shrink-0">
                <div id="seller-profile-avatar" class="w-20 h-20 rounded-[22px] bg-brand-400 dark:bg-brand-600 font-black text-white text-2xl flex items-center justify-center overflow-hidden shrink-0"></div>
                <div class="min-w-0 w-full">
                    <h2 id="seller-profile-name" class="font-display text-xl font-bold text-ink-900 dark:text-white tracking-tight"></h2>
                    <p id="seller-profile-login" class="text-sm text-gray-400 mt-0.5 hidden"></p>
                    <div id="seller-profile-meta" class="mt-2 flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-sm text-gray-500"></div>
                    <p id="seller-profile-bio" class="mt-3 text-sm text-ink-700 dark:text-gray-300 leading-relaxed hidden"></p>
                </div>
                <div id="seller-profile-actions" class="w-full pt-1"></div>
            </div>

            <div class="px-5 pb-2 shrink-0">
                <h3 class="font-display text-sm font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('seller.lots')) ?></h3>
            </div>
            <div id="seller-profile-lots" class="flex-1 overflow-y-auto px-5 pb-5 space-y-2 min-h-0"></div>
        </div>
    </div>
</div>
