<!-- Seller shop modal (по макету профиля продавца) -->
<div id="seller-profile-modal" class="hidden fixed inset-0 z-[85] flex items-stretch sm:items-center justify-center bg-ink-900/50 backdrop-blur-[2px] p-0 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="seller-profile-name">
    <div class="seller-shop-panel w-full sm:max-w-5xl bg-[#f7f7fb] dark:bg-ink-950 sm:rounded-[24px] shadow-lift border-0 sm:border border-black/[0.06] dark:border-white/10 overflow-hidden h-[100dvh] sm:h-[min(94vh,880px)] flex flex-col relative">
        <header class="seller-shop-top shrink-0 flex items-center justify-between gap-3 px-4 sm:px-5 h-14 bg-white dark:bg-ink-900 border-b border-black/[0.06] dark:border-white/10">
            <button type="button" id="seller-profile-back" class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-700 dark:text-gray-200 hover:text-brand-600 transition" aria-label="<?= htmlspecialchars(t('seller.close')) ?>">
                <span class="text-lg leading-none" aria-hidden="true">←</span>
                <span class="hidden sm:inline"><?= htmlspecialchars(t('seller.back')) ?></span>
            </button>
            <span class="font-display font-extrabold text-sm tracking-tight text-ink-900 dark:text-white">za<span class="text-[#7c3aed]">kopeyki</span>.kz</span>
            <div class="relative">
                <button type="button" id="seller-profile-menu" class="w-9 h-9 rounded-xl text-ink-500 hover:bg-ink-50 dark:hover:bg-white/10 transition flex items-center justify-center text-xl leading-none" aria-label="<?= htmlspecialchars(t('seller.menu')) ?>" aria-expanded="false" aria-haspopup="true">⋯</button>
                <div id="seller-profile-menu-dd" class="hidden absolute right-0 top-full mt-1 z-20 min-w-[11rem] rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-ink-900 shadow-lift py-1 text-sm">
                    <button type="button" id="seller-profile-copy-link" class="w-full text-left px-3.5 py-2.5 hover:bg-ink-50 dark:hover:bg-white/5 text-ink-800 dark:text-gray-100 font-medium"><?= htmlspecialchars(t('seller.copy_link')) ?></button>
                </div>
            </div>
        </header>

        <div id="seller-profile-loading" class="flex-1 flex items-center justify-center text-sm text-gray-400 px-5"><?= htmlspecialchars(t('seller.loading')) ?></div>
        <div id="seller-profile-error" class="hidden flex-1 flex items-center justify-center text-sm text-red-500 px-5"></div>

        <div id="seller-profile-body" class="hidden flex-1 min-h-0 overflow-y-auto">
            <div class="bg-white dark:bg-ink-900 border-b border-black/[0.06] dark:border-white/10 px-4 sm:px-6 py-5">
                <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                    <div id="seller-profile-avatar" class="w-[72px] h-[72px] sm:w-20 sm:h-20 rounded-full bg-[#7c3aed] font-black text-white text-2xl flex items-center justify-center overflow-hidden shrink-0 ring-2 ring-black/[0.04]"></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <h2 id="seller-profile-name" class="font-display text-xl sm:text-2xl font-extrabold text-ink-900 dark:text-white tracking-tight"></h2>
                                    <span id="seller-profile-business-badge" class="hidden inline-flex items-center text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-md bg-sky-600 text-white"><?= htmlspecialchars(t('business.badge')) ?></span>
                                    <svg id="seller-profile-verified-icon" class="w-5 h-5 text-[#7c3aed] shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                </div>
                                <p id="seller-profile-business-name" class="hidden text-sm font-semibold text-sky-700 dark:text-sky-300 mt-1"></p>
                                <p id="seller-profile-since" class="text-sm text-gray-400 mt-1"></p>
                                <p id="seller-profile-online" class="hidden mt-1.5 text-sm font-medium text-emerald-600 inline-flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                    <span><?= htmlspecialchars(t('seller.online')) ?></span>
                                </p>
                            </div>
                            <div id="seller-profile-actions" class="flex flex-wrap gap-2 w-full sm:w-auto"></div>
                        </div>
                    </div>
                </div>

                <div id="seller-profile-stats" class="seller-shop-stats mt-5"></div>
            </div>

            <div class="bg-white dark:bg-ink-900 px-4 sm:px-6 pt-3 border-b border-black/[0.06] dark:border-white/10 sticky top-0 z-10">
                <div class="flex gap-5 sm:gap-8 overflow-x-auto" role="tablist">
                    <button type="button" class="seller-shop-tab is-active" data-seller-tab="products" role="tab"><?= htmlspecialchars(t('seller.tab_products')) ?></button>
                    <button type="button" class="seller-shop-tab" data-seller-tab="reviews" role="tab">
                        <?= htmlspecialchars(t('seller.tab_reviews')) ?>
                        <span id="seller-tab-reviews-count" class="text-gray-400 font-normal"></span>
                    </button>
                    <button type="button" class="seller-shop-tab" data-seller-tab="about" role="tab"><?= htmlspecialchars(t('seller.tab_about')) ?></button>
                </div>
            </div>

            <div class="px-4 sm:px-6 py-4 sm:py-5 space-y-5">
                <section id="seller-tab-products" class="seller-shop-pane" data-seller-pane="products">
                    <div class="flex flex-col sm:flex-row gap-2 mb-4">
                        <label class="relative flex-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/></svg>
                            </span>
                            <input type="search" id="seller-products-search" class="w-full h-11 pl-10 pr-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/5 text-sm outline-none focus:border-[#7c3aed]/50 focus:ring-2 focus:ring-[#7c3aed]/15" placeholder="<?= htmlspecialchars(t('seller.search_products')) ?>">
                        </label>
                        <div class="flex gap-2">
                            <select id="seller-products-sort" class="h-11 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/5 text-sm min-w-[8.5rem]">
                                <option value="new"><?= htmlspecialchars(t('seller.sort_new')) ?></option>
                                <option value="price_asc"><?= htmlspecialchars(t('seller.sort_price_asc')) ?></option>
                                <option value="price_desc"><?= htmlspecialchars(t('seller.sort_price_desc')) ?></option>
                            </select>
                        </div>
                    </div>
                    <div id="seller-profile-lots" class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4"></div>
                    <div id="seller-products-empty" class="hidden text-center py-12 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-sm text-gray-400"><?= htmlspecialchars(t('seller.no_lots')) ?></div>
                    <div class="flex justify-center pt-2">
                        <button type="button" id="seller-products-more" class="hidden inline-flex items-center gap-1.5 h-10 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/5 text-sm font-semibold text-ink-700 dark:text-gray-200 hover:border-[#7c3aed]/40 transition">
                            <?= htmlspecialchars(t('seller.show_more')) ?>
                            <span aria-hidden="true">▾</span>
                        </button>
                    </div>

                    <div id="seller-reviews-preview" class="pt-6 hidden">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('seller.buyer_reviews')) ?></h3>
                            <button type="button" class="text-sm font-semibold text-[#7c3aed] hover:underline" data-seller-goto-reviews><?= htmlspecialchars(t('seller.all_reviews')) ?></button>
                        </div>
                        <div id="seller-reviews-preview-list" class="space-y-3"></div>
                    </div>
                </section>

                <section id="seller-tab-reviews" class="seller-shop-pane hidden" data-seller-pane="reviews">
                    <div id="seller-reviews-full" class="space-y-3"></div>
                    <div id="seller-reviews-empty" class="hidden text-center py-12 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-sm text-gray-400"><?= htmlspecialchars(t('seller.no_reviews')) ?></div>
                </section>

                <section id="seller-tab-about" class="seller-shop-pane hidden" data-seller-pane="about">
                    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.03] p-5">
                        <h3 class="font-display font-bold text-ink-900 dark:text-white mb-2"><?= htmlspecialchars(t('seller.tab_about')) ?></h3>
                        <p id="seller-profile-bio" class="text-sm text-ink-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap"></p>
                        <p id="seller-profile-bio-empty" class="hidden text-sm text-gray-400"><?= htmlspecialchars(t('seller.no_bio')) ?></p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
