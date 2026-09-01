<div id="buy-choice-modal"
     class="hidden fixed inset-0 z-[95] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4"
     role="dialog"
     aria-modal="true"
     aria-labelledby="buy-choice-title"
     aria-hidden="true">
    <div class="w-full sm:max-w-md bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10 translate-y-3 sm:translate-y-2 opacity-0 transition duration-200 ease-out"
         data-buy-choice-panel
         onclick="event.stopPropagation()">
        <div class="sm:hidden flex justify-center pt-3 pb-1" aria-hidden="true">
            <span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span>
        </div>
        <div class="px-5 pt-4 sm:pt-6 pb-2">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 id="buy-choice-title" class="font-display text-xl font-bold text-ink-900 dark:text-white">
                        <?= htmlspecialchars(t('product.buy_choice_title')) ?>
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">
                        <?= htmlspecialchars(t('product.buy_choice_subtitle')) ?>
                    </p>
                    <p id="buy-choice-product" class="text-sm font-semibold text-ink-800 dark:text-gray-200 mt-2 truncate hidden"></p>
                </div>
                <button type="button"
                        data-buy-choice-close
                        class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-ink-800 dark:hover:text-white hover:bg-black/[0.05] dark:hover:bg-white/10 transition shrink-0"
                        aria-label="<?= htmlspecialchars(t('product.close_photo')) ?>">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="p-5 pt-2 space-y-3">
            <a href="#"
               id="buy-choice-escrow"
               class="flex items-start gap-3.5 p-4 rounded-2xl border-2 border-emerald-500/40 bg-emerald-50/80 dark:bg-emerald-950/25 hover:border-emerald-500 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 transition group">
                <span class="w-11 h-11 rounded-xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-sm">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-display font-bold text-ink-900 dark:text-white text-[15px]"><?= htmlspecialchars(t('product.buy_escrow_title')) ?></span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed"><?= htmlspecialchars(t('product.buy_escrow_hint')) ?></span>
                </span>
                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0 mt-1 opacity-60 group-hover:opacity-100 transition" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>

            <button type="button"
                    id="buy-choice-direct"
                    class="w-full flex items-start gap-3.5 p-4 rounded-2xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.03] hover:border-accent-400/60 hover:bg-accent-50/50 dark:hover:bg-accent-500/10 transition text-left group">
                <span class="w-11 h-11 rounded-xl bg-ink-100 dark:bg-white/10 text-ink-700 dark:text-gray-200 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-display font-bold text-ink-900 dark:text-white text-[15px]"><?= htmlspecialchars(t('product.buy_direct_title')) ?></span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed"><?= htmlspecialchars(t('product.buy_direct_hint')) ?></span>
                </span>
                <svg class="w-4 h-4 text-gray-400 shrink-0 mt-1 opacity-60 group-hover:opacity-100 transition" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>

            <button type="button"
                    data-buy-choice-close
                    class="w-full h-11 px-3 rounded-xl border border-black/10 dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 font-semibold text-[13px] hover:bg-black/[0.03] dark:hover:bg-white/10 transition">
                <?= htmlspecialchars(t('product.report_cancel')) ?>
            </button>
        </div>
    </div>
</div>
