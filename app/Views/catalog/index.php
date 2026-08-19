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
        <?php if ($type === 'service'): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t('catalog.services_board_lead')) ?></p>
            <a href="<?= ProductHelper::url(Auth::check() ? '/profile?tab=lots&type=service' : '/login') ?>"
               class="mt-3 inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-5 py-2.5 rounded-xl transition shadow-soft">
                <?= htmlspecialchars(t('catalog.publish_service', ['amount' => Wallet::formatMoney(ProductHelper::SERVICE_LISTING_FEE)])) ?>
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
