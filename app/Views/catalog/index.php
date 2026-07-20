<?php
use App\Core\View;
use App\Helpers\ProductHelper;

$hasCategoryFilters = !empty($hasCategoryFilters);
$categoryTree = $categoryTree ?? ProductHelper::PRODUCT_CATEGORY_TREE;
$selectedParent = $selectedParent ?? '';
$selectedChild = $selectedChild ?? '';
$section = $section ?? '';
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('catalog.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars($heading) ?></h2>
    </div>

    <?php if ($hasCategoryFilters): ?>
        <form method="get" action="<?= ProductHelper::url('/catalog/' . rawurlencode($section)) ?>"
              id="catalog-category-filters"
              class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 sm:p-5 shadow-soft backdrop-blur">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                <div>
                    <label class="block text-xs font-bold mb-1.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('catalog.section')) ?></label>
                    <select name="parent" id="catalog-parent" class="<?= $input ?>">
                        <option value=""><?= htmlspecialchars(t('catalog.all_sections')) ?></option>
                        <?php foreach ($categoryTree as $parent => $children): ?>
                            <option value="<?= htmlspecialchars($parent) ?>" <?= $selectedParent === $parent ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ProductHelper::categoryLabel($parent)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold mb-1.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('catalog.subsection')) ?></label>
                    <select name="sub" id="catalog-sub" class="<?= $input ?>" <?= $selectedParent === '' ? 'disabled' : '' ?>>
                        <option value=""><?= htmlspecialchars(t('catalog.all_subsections')) ?></option>
                        <?php if ($selectedParent !== '' && isset($categoryTree[$selectedParent])): ?>
                            <?php foreach ($categoryTree[$selectedParent] as $child): ?>
                                <option value="<?= htmlspecialchars($child) ?>" <?= $selectedChild === $child ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ProductHelper::categoryLabel($child)) ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
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
            const tree = <?= json_encode($categoryTree, JSON_UNESCAPED_UNICODE) ?>;
            const labels = <?= json_encode(array_combine(array_keys($categoryTree), array_map(
                static fn ($parent) => ProductHelper::categoryLabel($parent),
                array_keys($categoryTree)
            )) + array_reduce($categoryTree, static function (array $labels, array $children): array {
                foreach ($children as $child) $labels[$child] = ProductHelper::categoryLabel($child);
                return $labels;
            }, []), JSON_UNESCAPED_UNICODE) ?>;
            const parentSelect = document.getElementById('catalog-parent');
            const subSelect = document.getElementById('catalog-sub');
            const form = document.getElementById('catalog-category-filters');
            if (!parentSelect || !subSelect || !tree) return;

            function fillSubs(keep) {
                const parent = parentSelect.value;
                const prev = keep || subSelect.value;
                subSelect.innerHTML = '<option value=""><?= htmlspecialchars(t('catalog.all_subsections'), ENT_QUOTES) ?></option>';
                if (!parent || !tree[parent]) {
                    subSelect.disabled = true;
                    subSelect.value = '';
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
            }

            parentSelect.addEventListener('change', function () {
                fillSubs('');
            });
        })();
        </script>
    <?php endif; ?>

    <?php if (empty($items)): ?>
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
