<?php
use App\Core\Auth;
use App\Core\View;
use App\Helpers\ProductHelper;
use App\Helpers\IconHelper;
use App\Models\Wallet;

$hasCategoryFilters = !empty($hasCategoryFilters);
$categoryTree = $categoryTree ?? ProductHelper::PRODUCT_CATEGORY_TREE;
$selectedParent = $selectedParent ?? '';
$selectedChild = $selectedChild ?? '';
$section = $section ?? '';
$type = $type ?? '';
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('catalog.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white flex items-center gap-2.5">
            <?php if ($type !== ''): ?>
                <span class="inline-flex text-brand-500"><?= IconHelper::type($type, 'w-6 h-6 sm:w-7 sm:h-7') ?></span>
            <?php endif; ?>
            <span><?= htmlspecialchars($heading) ?></span>
        </h2>
        <?php if ($type === 'new'): ?>
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2.5">
                <p class="text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t('catalog.new_lead')) ?></p>
                <button type="button" id="new-how-open"
                        class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 text-xs font-semibold hover:border-brand-400/50 hover:text-brand-700 dark:hover:text-brand-300 transition shrink-0"
                        aria-haspopup="dialog" aria-controls="new-how-modal" aria-expanded="false">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.2 2.4c-.7.3-1.2.8-1.2 1.6V14"/><path d="M12 17h.01"/></svg>
                    <?= htmlspecialchars(t('catalog.how_use')) ?>
                </button>
            </div>
        <?php elseif ($type === 'service'): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t('catalog.services_board_lead')) ?></p>
            <?php
            if (!Auth::check()) {
                $publishHref = ProductHelper::url('/login');
                $listingOk = false;
            } else {
                $listingOk = \App\Services\AMLService::userListingStatus(Auth::user()) === 'ok';
                $publishHref = $listingOk
                    ? ProductHelper::url('/profile?tab=lots&type=service')
                    : ProductHelper::url('/profile/verify-listing?type=service');
            }
            ?>
            <a href="<?= $publishHref ?>"
               <?php if (Auth::check() && !$listingOk): ?>onclick="if (typeof openListingVerify === 'function') { event.preventDefault(); openListingVerify('service'); }"<?php endif; ?>
               class="mt-3 inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-5 py-2.5 rounded-xl transition shadow-soft">
                <?= htmlspecialchars(ProductHelper::isServiceListingFreePromo()
                    ? t('catalog.publish_service_free')
                    : t('catalog.publish_service', ['amount' => Wallet::formatMoney(ProductHelper::serviceListingFee())])) ?>
            </a>
        <?php elseif ($type === 'free'): ?>
            <span class="mt-2 block text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t('catalog.free_board_lead')) ?></span>
            <?php
            if (!Auth::check()) {
                $publishHref = ProductHelper::url('/login');
                $listingOk = false;
            } else {
                $listingOk = \App\Services\AMLService::userListingStatus(Auth::user()) === 'ok';
                $publishHref = $listingOk
                    ? ProductHelper::url('/profile?tab=lots&type=free')
                    : ProductHelper::url('/profile/verify-listing?type=free');
            }
            ?>
            <a href="<?= $publishHref ?>"
               <?php if (Auth::check() && !$listingOk): ?>onclick="if (typeof openListingVerify === 'function') { event.preventDefault(); openListingVerify('free'); }"<?php endif; ?>
               class="mt-3 inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-6 py-2.5 rounded-full transition shadow-soft">
                <?= htmlspecialchars(t('catalog.publish_free')) ?>
            </a>
        <?php elseif ($type === 'exchange'): ?>
            <span class="mt-2 block text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t('catalog.exchange_board_lead')) ?></span>
            <?php
            if (!Auth::check()) {
                $publishHref = ProductHelper::url('/login');
                $listingOk = false;
            } else {
                $listingOk = \App\Services\AMLService::userListingStatus(Auth::user()) === 'ok';
                $publishHref = $listingOk
                    ? ProductHelper::url('/profile?tab=lots&type=exchange')
                    : ProductHelper::url('/profile/verify-listing?type=exchange');
            }
            ?>
            <a href="<?= $publishHref ?>"
               <?php if (Auth::check() && !$listingOk): ?>onclick="if (typeof openListingVerify === 'function') { event.preventDefault(); openListingVerify('exchange'); }"<?php endif; ?>
               class="mt-3 inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-6 py-2.5 rounded-full transition shadow-soft">
                <?= htmlspecialchars(t('catalog.publish_exchange')) ?>
            </a>
        <?php elseif ($type === 'gig'): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t('gigs.lead')) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($type === 'gig'): ?>
        <?php View::partial('catalog/gigs-board', [
            'microCategories' => $microCategories ?? [],
            'walletBalance' => $walletBalance ?? 0,
            'walletHeld' => $walletHeld ?? 0,
            'flash' => $flash ?? null,
            'error' => $error ?? null,
        ]); ?>
    <?php elseif ($hasCategoryFilters): ?>
        <form method="get" action="<?= ProductHelper::url('/catalog/' . rawurlencode($section)) ?>"
              id="catalog-category-filters"
              class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 sm:p-5 shadow-soft backdrop-blur">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div>
                    <label class="block text-xs font-bold mb-1.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('catalog.section')) ?></label>
                    <div class="relative" data-lot-select-wrap>
                        <select name="parent" id="catalog-parent" class="hidden">
                            <option value=""><?= htmlspecialchars(t('catalog.all_sections')) ?></option>
                            <?php foreach ($categoryTree as $parent => $children): ?>
                                <option value="<?= htmlspecialchars($parent) ?>" <?= $selectedParent === $parent ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ProductHelper::categoryLabel($parent)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" data-lot-trigger class="<?= $input ?> flex items-center justify-between gap-2 text-left pr-3 cursor-pointer" aria-haspopup="listbox" aria-expanded="false">
                            <span data-lot-label class="truncate"><?= htmlspecialchars($selectedParent !== '' ? ProductHelper::categoryLabel($selectedParent) : t('catalog.all_sections')) ?></span>
                            <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div data-lot-menu class="hidden absolute z-30 mt-1.5 w-full max-h-64 overflow-y-auto bg-white dark:bg-ink-800 border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-1.5" role="listbox"></div>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold mb-1.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('catalog.subsection')) ?></label>
                    <div class="relative" data-lot-select-wrap>
                        <select name="sub" id="catalog-sub" class="hidden" <?= $selectedParent === '' ? 'disabled' : '' ?>>
                            <option value=""><?= htmlspecialchars(t('catalog.all_subsections')) ?></option>
                            <?php if ($selectedParent !== '' && isset($categoryTree[$selectedParent])): ?>
                                <?php foreach ($categoryTree[$selectedParent] as $child): ?>
                                    <option value="<?= htmlspecialchars($child) ?>" <?= $selectedChild === $child ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ProductHelper::categoryLabel($child)) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <button type="button" data-lot-trigger class="<?= $input ?> flex items-center justify-between gap-2 text-left pr-3 cursor-pointer" aria-haspopup="listbox" aria-expanded="false" <?= $selectedParent === '' ? 'disabled' : '' ?>>
                            <span data-lot-label class="truncate"><?= htmlspecialchars($selectedChild !== '' ? ProductHelper::categoryLabel($selectedChild) : t('catalog.all_subsections')) ?></span>
                            <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div data-lot-menu class="hidden absolute z-30 mt-1.5 w-full max-h-64 overflow-y-auto bg-white dark:bg-ink-800 border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-1.5" role="listbox"></div>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-3">
                <button type="submit" class="bg-accent-500 hover:bg-accent-400 text-white font-display font-bold text-xs uppercase tracking-wider px-5 py-2.5 rounded-xl transition shadow-soft">
                    <?= htmlspecialchars(t('catalog.apply')) ?>
                </button>
                <?php if ($selectedParent !== '' || $selectedChild !== ''): ?>
                    <a href="<?= ProductHelper::url('/catalog/' . rawurlencode($section)) ?>"
                       class="text-xs font-semibold text-gray-500 hover:text-ink-800 dark:hover:text-gray-200 px-3 py-2.5 transition">
                        <?= htmlspecialchars(t('catalog.reset')) ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>
        <script>
        (function () {
            const tree = <?= js_encode($categoryTree) ?>;
            const labels = <?= js_encode(array_combine(array_keys($categoryTree), array_map(
                static fn ($parent) => ProductHelper::categoryLabel($parent),
                array_keys($categoryTree)
            )) + array_reduce($categoryTree, static function (array $labels, array $children): array {
                foreach ($children as $child) $labels[$child] = ProductHelper::categoryLabel($child);
                return $labels;
            }, [])) ?>;
            const parentSelect = document.getElementById('catalog-parent');
            const subSelect = document.getElementById('catalog-sub');
            const form = document.getElementById('catalog-category-filters');
            if (!parentSelect || !subSelect || !tree) return;
            const checkSvg = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

            function closeLotMenus(except) {
                document.querySelectorAll('#catalog-category-filters [data-lot-menu]').forEach(function (menu) {
                    if (except && menu === except) return;
                    menu.classList.add('hidden');
                    const wrap = menu.closest('[data-lot-select-wrap]');
                    const btn = wrap && wrap.querySelector('[data-lot-trigger]');
                    if (btn) btn.setAttribute('aria-expanded', 'false');
                });
            }
            function bindLotSelect(select) {
                if (!select) return;
                const wrap = select.closest('[data-lot-select-wrap]');
                if (!wrap) return;
                const btn = wrap.querySelector('[data-lot-trigger]');
                const menu = wrap.querySelector('[data-lot-menu]');
                const labelEl = wrap.querySelector('[data-lot-label]');
                if (!btn || !menu || !labelEl) return;
                function renderMenu() {
                    const selected = select.options[select.selectedIndex];
                    labelEl.textContent = selected ? selected.textContent : '';
                    btn.disabled = select.disabled;
                    btn.classList.toggle('opacity-50', select.disabled);
                    btn.classList.toggle('pointer-events-none', select.disabled);
                    menu.innerHTML = '';
                    Array.from(select.options).forEach(function (opt) {
                        const isSel = opt.selected || opt === selected;
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'w-full flex items-center gap-2 px-3.5 py-2.5 text-sm text-left transition ' +
                            (isSel
                                ? 'bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 font-semibold'
                                : 'text-ink-800 dark:text-gray-200 hover:bg-black/[0.04] dark:hover:bg-white/5');
                        const text = document.createElement('span');
                        text.className = 'truncate';
                        text.textContent = opt.textContent;
                        item.appendChild(text);
                        if (isSel) {
                            const mark = document.createElement('span');
                            mark.className = 'ml-auto shrink-0 text-brand-500';
                            mark.innerHTML = checkSvg;
                            item.appendChild(mark);
                        }
                        item.addEventListener('click', function () {
                            select.value = opt.value;
                            select.dispatchEvent(new Event('change'));
                            closeLotMenus();
                            renderMenu();
                        });
                        menu.appendChild(item);
                    });
                }
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (select.disabled) return;
                    const willOpen = menu.classList.contains('hidden');
                    closeLotMenus(willOpen ? menu : null);
                    menu.classList.toggle('hidden', !willOpen);
                    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
                select.addEventListener('change', renderMenu);
                select.refreshLotUI = renderMenu;
                renderMenu();
            }
            bindLotSelect(parentSelect);
            bindLotSelect(subSelect);
            document.addEventListener('click', function (e) {
                if (!e.target.closest('#catalog-category-filters [data-lot-select-wrap]')) closeLotMenus();
            });

            function fillSubs(keep) {
                const parent = parentSelect.value;
                const prev = keep || subSelect.value;
                subSelect.innerHTML = '<option value=""><?= htmlspecialchars(t('catalog.all_subsections'), ENT_QUOTES) ?></option>';
                if (!parent || !tree[parent]) {
                    subSelect.disabled = true;
                    subSelect.value = '';
                    if (typeof subSelect.refreshLotUI === 'function') subSelect.refreshLotUI();
                    return;
                }
                subSelect.disabled = false;
                tree[parent].forEach(function (child) {
                    const opt = document.createElement('option');
                    opt.value = child;
                    opt.textContent = labels[child] || child;
                    if (child === prev) opt.selected = true;
                    subSelect.appendChild(opt);
                });
                if (typeof subSelect.refreshLotUI === 'function') subSelect.refreshLotUI();
            }

            parentSelect.addEventListener('change', function () {
                fillSubs('');
            });
        })();
        </script>
    <?php endif; ?>

    <?php if ($type === 'gig'): ?>
        <?php /* карточки грузит JS */ ?>
    <?php elseif (empty($items)): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/15 px-5 py-14 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('catalog.empty')) ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5">
            <?php foreach ($items as $item) {
                View::partial('partials/product-card', [
                    'item' => $item,
                    'favorited' => in_array((int) $item['id'], $favoriteIds ?? [], true),
                ]);
            } ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($type === 'new'): ?>
<div id="new-how-modal" class="fixed inset-0 z-[110] hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-ink-900/55 backdrop-blur-sm" data-new-how-close></div>
    <div class="relative z-10 flex min-h-full items-end sm:items-center justify-center p-0 sm:p-4" data-new-how-close>
        <div role="dialog" aria-modal="true" aria-labelledby="new-how-title" id="new-how-panel"
             class="w-full sm:max-w-xl max-h-[92vh] flex flex-col rounded-t-[28px] sm:rounded-[28px] bg-white dark:bg-ink-800 shadow-lift border border-white/60 dark:border-white/10 overflow-hidden">
            <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-black/[0.06] dark:border-white/10 shrink-0">
                <h2 id="new-how-title" class="font-display text-base sm:text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('catalog.how_use')) ?></h2>
                <button type="button" id="new-how-dismiss" data-new-how-close class="h-9 w-9 rounded-xl border border-black/10 dark:border-white/15 text-gray-500 hover:text-ink-900 dark:hover:text-white hover:bg-gray-50 dark:hover:bg-white/5 transition shrink-0" aria-label="<?= htmlspecialchars(t('catalog.how_close')) ?>">&times;</button>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-5">
                <?php
                $howSections = [
                    'catalog.how_buyer' => 9,
                    'catalog.how_seller' => 7,
                ];
                $stepPrefix = [
                    'catalog.how_buyer' => 'catalog.new_buyer_',
                    'catalog.how_seller' => 'catalog.new_seller_',
                ];
                foreach ($howSections as $titleKey => $count):
                ?>
                    <section>
                        <h3 class="font-display font-bold text-sm text-ink-900 dark:text-white"><?= htmlspecialchars(t($titleKey)) ?></h3>
                        <ol class="mt-2.5 space-y-2 text-sm text-ink-800 dark:text-gray-200 list-decimal pl-5">
                            <?php for ($i = 1; $i <= $count; $i++): ?>
                                <li class="pl-1 leading-snug"><?= htmlspecialchars(t($stepPrefix[$titleKey] . $i)) ?></li>
                            <?php endfor; ?>
                        </ol>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="shrink-0 border-t border-black/[0.06] dark:border-white/10 px-5 py-4">
                <button type="button" data-new-how-close class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('catalog.how_ok')) ?></button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('new-how-modal');
    var openBtn = document.getElementById('new-how-open');
    if (!modal || !openBtn) return;

    function openHow() {
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        openBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        var closeBtn = document.getElementById('new-how-dismiss');
        if (closeBtn) closeBtn.focus();
    }

    function closeHow() {
        if (modal.classList.contains('hidden')) return;
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        openBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        openBtn.focus();
    }

    var panel = document.getElementById('new-how-panel');
    if (panel) {
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
    }
    openBtn.addEventListener('click', openHow);
    modal.querySelectorAll('[data-new-how-close]').forEach(function (el) {
        el.addEventListener('click', closeHow);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeHow();
    });
})();
</script>
<?php endif; ?>
