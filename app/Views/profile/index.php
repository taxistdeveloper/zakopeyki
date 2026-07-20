<?php
use App\Helpers\ProductHelper;
use App\Helpers\AvatarHelper;

$user = $user ?? [];
$tab = $tab ?? 'personal';
$avatarUrl = AvatarHelper::url($user);

$first = $user['first_name'] ?? '';
$last = $user['last_name'] ?? '';
if ($first === '' && !empty($user['name'])) {
    $parts = preg_split('/\s+/', trim($user['name']), 2);
    $first = $parts[0] ?? '';
    $last = $parts[1] ?? $last;
}
$login = $user['login'] ?? '';
if ($login === '' && !empty($user['email'])) {
    $login = strstr($user['email'], '@', true) ?: '';
}

$tabs = [
    'personal' => ['label' => t('profile.tab_personal'), 'icon' => '👤'],
    'photo' => ['label' => t('profile.tab_photo'), 'icon' => '📷'],
    'bio' => ['label' => t('profile.tab_bio'), 'icon' => '📄'],
    'reviews' => ['label' => t('profile.tab_reviews'), 'icon' => '⭐'],
    'notifications' => ['label' => t('profile.tab_notifications'), 'icon' => '🔔'],
    'password' => ['label' => t('profile.tab_password'), 'icon' => '🔒'],
    'favorites' => ['label' => t('profile.tab_favorites'), 'icon' => '❤️'],
    'lots' => ['label' => t('profile.tab_lots'), 'icon' => '📦'],
];

$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="max-w-3xl mx-auto space-y-5 pb-8">
    <div class="flex items-end justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('profile.eyebrow')) ?></p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white tracking-tight"><?= htmlspecialchars(t('profile.title')) ?></h1>
        </div>
        <?php if ($avatarUrl || !empty($user['name'])): ?>
            <div class="hidden sm:flex items-center gap-3">
                <?= AvatarHelper::html($user, 'w-11 h-11', 'text-sm', 'rounded-2xl') ?>
                <div class="text-right">
                    <div class="text-sm font-semibold"><?= htmlspecialchars($user['name'] ?? '') ?></div>
                    <div class="text-[11px] text-gray-400">@<?= htmlspecialchars($login ?: 'user') ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold shadow-sm"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-600 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft backdrop-blur">
        <div class="p-3 sm:p-4 border-b border-black/[0.05] dark:border-white/10 bg-gradient-to-b from-brand-50/40 to-transparent dark:from-brand-500/5">
            <div class="flex overflow-x-auto scrollbar-hide gap-1.5 p-1 rounded-2xl bg-black/[0.03] dark:bg-white/[0.04]">
                <?php foreach ($tabs as $key => $meta):
                    $active = $tab === $key;
                ?>
                    <a href="<?= ProductHelper::url('/profile?tab=' . $key) ?>"
                       class="flex items-center gap-2 px-3.5 py-2.5 text-xs sm:text-[13px] font-semibold whitespace-nowrap rounded-xl transition
                       <?= $active
                           ? 'bg-white dark:bg-ink-800 text-ink-900 dark:text-white shadow-sm'
                           : 'text-gray-500 hover:text-ink-800 dark:hover:text-gray-200' ?>">
                        <span class="opacity-80"><?= $meta['icon'] ?></span>
                        <span><?= $meta['label'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="p-5 sm:p-8">
            <?php if ($tab === 'personal'): ?>
                <div class="mb-7">
                    <h2 class="font-display text-xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('profile.tab_personal')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.personal_hint')) ?></p>
                </div>

                <form method="post" action="<?= ProductHelper::url('/profile/personal') ?>" class="space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.first_name')) ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($first) ?>" class="<?= $input ?>">
                        </div>
                        <div>
                            <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.last_name')) ?></label>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($last) ?>" class="<?= $input ?>">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.login')) ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="login" required value="<?= htmlspecialchars($login) ?>" pattern="[A-Za-z0-9_]+" class="<?= $input ?>">
                        <p class="text-xs text-gray-400 mt-1.5"><?= htmlspecialchars(t('profile.login_hint')) ?></p>
                    </div>

                    <div class="rounded-2xl border border-black/[0.08] dark:border-white/10 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-ink-50/60 dark:bg-white/[0.03]">
                        <div>
                            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Email</div>
                            <div class="flex items-center gap-2 text-sm font-semibold text-ink-900 dark:text-white">
                                <?= htmlspecialchars($user['email'] ?? '') ?>
                                <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-500 text-white text-[10px]">✓</span>
                            </div>
                        </div>
                        <span class="text-xs text-gray-400 font-medium"><?= htmlspecialchars(t('profile.email_verified')) ?></span>
                    </div>

                    <div>
                        <label class="block text-[13px] font-semibold text-ink-800 dark:text-gray-200 mb-1.5"><?= htmlspecialchars(t('profile.phone')) ?></label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+7..." class="<?= $input ?>">
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4 border-t border-black/[0.05] dark:border-white/10">
                        <p class="text-xs text-gray-400"><span class="text-red-500">*</span> <?= htmlspecialchars(t('profile.required_note')) ?></p>
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition shadow-soft">
                            <?= htmlspecialchars(t('profile.save_changes')) ?>
                        </button>
                    </div>
                </form>

            <?php elseif ($tab === 'photo'): ?>
                <div class="mb-7 text-center sm:text-left">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.photo_title')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.photo_hint')) ?></p>
                </div>
                <form method="post" action="<?= ProductHelper::url('/profile/avatar') ?>" enctype="multipart/form-data" class="flex flex-col items-center gap-5 py-6">
                    <label class="relative group cursor-pointer">
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" onchange="this.form.submit()">
                        <div class="w-40 h-40 rounded-[2rem] overflow-hidden border-[3px] border-brand-400/60 bg-brand-50 dark:bg-white/5 flex items-center justify-center shadow-lift ring-4 ring-brand-100/50 dark:ring-brand-500/10">
                            <?php if ($avatarUrl): ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars(t('profile.photo_title')) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <span class="text-5xl font-display font-bold text-brand-500/70"><?= htmlspecialchars(AvatarHelper::initial($user)) ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="absolute inset-0 rounded-[2rem] bg-ink-900/55 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold uppercase tracking-wide"><?= htmlspecialchars(t('profile.change')) ?></span>
                    </label>
                    <p class="text-xs text-gray-400"><?= htmlspecialchars(t('profile.photo_formats')) ?></p>
                </form>

            <?php elseif ($tab === 'bio'): ?>
                <div class="mb-7">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_bio')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.bio_hint')) ?></p>
                </div>
                <form method="post" action="<?= ProductHelper::url('/profile/bio') ?>" class="space-y-5">
                    <textarea name="bio" rows="6" maxlength="2000" placeholder="<?= htmlspecialchars(t('profile.bio_placeholder')) ?>"
                              class="ui-input w-full p-4 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                    <div class="flex justify-end">
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition"><?= htmlspecialchars(t('profile.save')) ?></button>
                    </div>
                </form>

            <?php elseif ($tab === 'reviews'): ?>
                <div class="mb-4">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_reviews')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.reviews_hint')) ?></p>
                </div>
                <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm"><?= htmlspecialchars(t('profile.no_reviews')) ?></div>

            <?php elseif ($tab === 'notifications'): ?>
                <div class="mb-6 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_notifications')) ?></h2>
                        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.notifications_hint')) ?></p>
                    </div>
                    <?php if (!empty($notifications)): ?>
                        <a href="<?= ProductHelper::url('/notifications/clear') ?>" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(t('profile.clear')) ?></a>
                    <?php endif; ?>
                </div>
                <?php if (empty($notifications)): ?>
                    <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm"><?= htmlspecialchars(t('profile.no_notifications')) ?></div>
                <?php else: ?>
                    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 overflow-hidden divide-y divide-black/[0.04] dark:divide-white/5">
                        <?php foreach ($notifications as $n): ?>
                            <div class="px-4 py-3.5 text-sm <?= empty($n['is_read']) ? 'bg-brand-50/50 font-medium' : 'text-gray-600 dark:text-gray-300' ?>">
                                <?= htmlspecialchars($n['message']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'password'): ?>
                <div class="mb-6 flex items-start gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-ink-900 text-white flex items-center justify-center text-lg">🔒</div>
                    <div>
                        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.change_password')) ?></h2>
                        <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.change_password_hint')) ?></p>
                    </div>
                </div>
                <div class="mb-5 rounded-2xl border border-sky-200/80 bg-sky-50 text-sky-900 text-sm px-4 py-3.5 leading-relaxed">
                    <?= htmlspecialchars(t('profile.password_info')) ?>
                </div>
                <form method="post" action="<?= ProductHelper::url('/profile/password') ?>" class="space-y-5">
                    <div>
                        <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.new_password')) ?> <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="password" id="pass1" required minlength="8" class="<?= $input ?> pr-11">
                            <button type="button" onclick="togglePass('pass1', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-ink-800 text-sm">👁</button>
                        </div>
                        <p class="text-xs text-gray-400 mt-1.5"><?= htmlspecialchars(t('profile.min_8')) ?></p>
                    </div>
                    <div>
                        <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.confirm_password')) ?> <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="password_confirm" id="pass2" required minlength="8" class="<?= $input ?> pr-11">
                            <button type="button" onclick="togglePass('pass2', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-ink-800 text-sm">👁</button>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4 border-t border-black/[0.05]">
                        <p class="text-xs text-gray-400"><span class="text-red-500">*</span> <?= htmlspecialchars(t('profile.required_note')) ?></p>
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition"><?= htmlspecialchars(t('profile.change_password')) ?></button>
                    </div>
                </form>
                <script>
                function togglePass(id, btn) {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.type = el.type === 'password' ? 'text' : 'password';
                    btn.textContent = el.type === 'password' ? '👁' : '🙈';
                }
                </script>

                <div class="mt-10 pt-8 border-t border-red-200/60 dark:border-red-900/40">
                    <div class="mb-5 flex items-start gap-3">
                        <div class="w-11 h-11 rounded-2xl bg-red-600 text-white flex items-center justify-center text-lg">⚠</div>
                        <div>
                            <h2 class="font-display text-xl font-bold text-red-600 dark:text-red-400"><?= htmlspecialchars(t('profile.delete_account')) ?></h2>
                            <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.delete_account_hint')) ?></p>
                        </div>
                    </div>
                    <div class="rounded-[22px] border border-red-200 dark:border-red-900/50 bg-red-50/60 dark:bg-red-950/20 p-5 sm:p-6 space-y-4">
                        <form method="post" action="<?= ProductHelper::url('/profile/delete') ?>" class="space-y-4"
                              onsubmit="return confirm(<?= json_encode(t('profile.confirm_delete_account')) ?>)">
                            <div>
                                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.current_password')) ?> <span class="text-red-500">*</span></label>
                                <input type="password" name="password" required autocomplete="current-password" class="<?= $input ?>">
                            </div>
                            <div>
                                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('profile.type_delete')) ?> <span class="font-bold text-red-600"><?= htmlspecialchars(t('profile.delete_word')) ?></span> <span class="text-red-500">*</span></label>
                                <input type="text" name="confirm_text" required placeholder="<?= htmlspecialchars(t('profile.delete_word')) ?>" autocomplete="off" class="<?= $input ?>">
                            </div>
                            <button type="submit" class="w-full sm:w-auto bg-red-600 hover:bg-red-700 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition">
                                <?= htmlspecialchars(t('profile.delete_forever')) ?>
                            </button>
                        </form>
                    </div>
                </div>

            <?php elseif ($tab === 'favorites'): ?>
                <?php
                $favorites = $favorites ?? [];
                ?>
                <div class="mb-6">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('profile.tab_favorites')) ?></h2>
                    <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars(t('profile.favorites_hint')) ?></p>
                </div>
                <?php if (empty($favorites)): ?>
                    <div class="text-center py-20 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
                        <?= htmlspecialchars(t('profile.favorites_empty')) ?>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-favorites-grid>
                        <?php foreach ($favorites as $item) {
                            \App\Core\View::partial('partials/product-card', [
                                'item' => $item,
                                'favorited' => true,
                            ]);
                        } ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'lots'): ?>
                <?php $editing = $editProduct ?? null; ?>
                <div class="mb-6">
                    <h2 class="font-display text-xl font-bold"><?= htmlspecialchars($editing ? t('profile.edit_lot') : t('profile.create_lot')) ?></h2>
                </div>
                <form method="post" action="<?= $editing ? ProductHelper::url('/profile/lots/' . $editing['id'] . '/update') : ProductHelper::url('/profile/store') ?>" enctype="multipart/form-data" class="space-y-4 mb-8 p-5 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-brand-50/30 dark:bg-white/[0.03]">
                    <?php $noPriceTypes = ['free', 'exchange']; ?>
                    <?php
                    $productTypesWithCategory = ProductHelper::PRODUCT_TYPES_WITH_CATEGORY;
                    $categoryTree = $productCategoryTree ?? ProductHelper::PRODUCT_CATEGORY_TREE;
                    $currentType = $editing['type'] ?? 'used';
                    [$currentParent, $currentChild] = ProductHelper::parseCategory($editing['category'] ?? null);
                    $showCategory = in_array($currentType, $productTypesWithCategory, true);
                    ?>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.type')) ?></label>
                        <select name="type" id="lot-type" class="<?= $input ?>">
                            <?php foreach ($types as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($editing['type'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="lot-category-wrap" class="grid grid-cols-1 sm:grid-cols-2 gap-4 <?= $showCategory ? '' : 'hidden' ?>">
                        <div>
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.section')) ?></label>
                            <select id="lot-category-parent" class="<?= $input ?>" <?= $showCategory ? '' : 'disabled' ?>>
                                <?php foreach ($categoryTree as $parent => $children): ?>
                                    <option value="<?= htmlspecialchars($parent) ?>" <?= $currentParent === $parent ? 'selected' : '' ?>><?= htmlspecialchars(ProductHelper::categoryLabel($parent)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.subsection')) ?></label>
                            <select name="category" id="lot-category" class="<?= $input ?>" <?= $showCategory ? '' : 'disabled' ?>>
                                <?php foreach ($categoryTree[$currentParent] ?? [] as $child): ?>
                                    <option value="<?= htmlspecialchars(ProductHelper::formatCategory($currentParent, $child)) ?>" <?= $currentChild === $child ? 'selected' : '' ?>><?= htmlspecialchars(ProductHelper::categoryLabel($child)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.title_field')) ?></label>
                        <input type="text" name="title" required class="<?= $input ?>" value="<?= htmlspecialchars($editing['title'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.description')) ?></label>
                        <textarea name="description" rows="2" required class="ui-input w-full p-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm"><?= htmlspecialchars($editing['description'] ?? '') ?></textarea>
                    </div>
                    <div id="lot-exchange-wrap" class="<?= ($editing['type'] ?? '') === 'exchange' ? '' : 'hidden' ?>">
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.exchange_for')) ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="exchange_for" id="lot-exchange-for" maxlength="255" class="<?= $input ?>"
                               placeholder="<?= htmlspecialchars(t('profile.exchange_for_ph')) ?>"
                               value="<?= htmlspecialchars($editing['exchange_for'] ?? '') ?>"
                               <?= ($editing['type'] ?? '') === 'exchange' ? 'required' : '' ?>>
                        <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('profile.exchange_for_hint')) ?></p>
                    </div>
                    <div id="lot-free-note" class="<?= ($editing['type'] ?? '') === 'free' ? '' : 'hidden' ?> text-xs font-semibold text-violet-700 dark:text-violet-300 bg-violet-50 dark:bg-violet-900/20 border border-violet-100 dark:border-violet-800/40 rounded-xl px-3 py-2">
                        <?= htmlspecialchars(t('profile.free_price_note')) ?>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div id="lot-price-wrap">
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.price_kzt')) ?></label>
                            <input type="text" name="price" id="lot-price" required class="<?= $input ?>" value="<?= htmlspecialchars((string) ($editing['price'] ?? '')) ?>">
                        </div>
                        <div id="lot-location-wrap" class="<?= in_array($editing['type'] ?? '', $noPriceTypes, true) ? 'col-span-2' : '' ?>">
                            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('profile.location')) ?></label>
                            <input type="text" name="location" class="<?= $input ?>" value="<?= htmlspecialchars($editing['location'] ?? 'Караганда') ?>">
                        </div>
                    </div>
                    <script>
                    (function () {
                        const typeSelect = document.getElementById('lot-type');
                        const priceWrap = document.getElementById('lot-price-wrap');
                        const priceInput = document.getElementById('lot-price');
                        const locationWrap = document.getElementById('lot-location-wrap');
                        const exchangeWrap = document.getElementById('lot-exchange-wrap');
                        const exchangeInput = document.getElementById('lot-exchange-for');
                        const freeNote = document.getElementById('lot-free-note');
                        const categoryWrap = document.getElementById('lot-category-wrap');
                        const parentSelect = document.getElementById('lot-category-parent');
                        const categorySelect = document.getElementById('lot-category');
                        const noPrice = ['free', 'exchange'];
                        const withCategory = <?= json_encode($productTypesWithCategory, JSON_UNESCAPED_UNICODE) ?>;
                        const tree = <?= json_encode($categoryTree, JSON_UNESCAPED_UNICODE) ?>;
                        const labels = <?= json_encode(array_combine(array_keys($categoryTree), array_map(
                            static fn ($parent) => ProductHelper::categoryLabel($parent),
                            array_keys($categoryTree)
                        )) + array_reduce($categoryTree, static function (array $labels, array $children): array {
                            foreach ($children as $child) $labels[$child] = ProductHelper::categoryLabel($child);
                            return $labels;
                        }, []), JSON_UNESCAPED_UNICODE) ?>;
                        if (!typeSelect || !priceWrap || !priceInput) return;

                        function syncPriceField() {
                            const type = typeSelect.value;
                            const hide = noPrice.indexOf(type) !== -1;
                            priceWrap.classList.toggle('hidden', hide);
                            priceInput.required = !hide;
                            if (hide) priceInput.value = '';
                            if (locationWrap) locationWrap.classList.toggle('col-span-2', hide);

                            const isExchange = type === 'exchange';
                            if (exchangeWrap) exchangeWrap.classList.toggle('hidden', !isExchange);
                            if (exchangeInput) {
                                exchangeInput.required = isExchange;
                                if (!isExchange) exchangeInput.value = '';
                            }
                            if (freeNote) freeNote.classList.toggle('hidden', type !== 'free');
                        }

                        function fillSubcategories(keepValue) {
                            if (!parentSelect || !categorySelect || !tree) return;
                            const parent = parentSelect.value;
                            const children = tree[parent] || [];
                            const prev = keepValue || categorySelect.value;
                            categorySelect.innerHTML = '';
                            children.forEach(function (child) {
                                const value = parent + ' / ' + child;
                                const opt = document.createElement('option');
                                opt.value = value;
                                opt.textContent = labels[child] || child;
                                if (value === prev || child === prev || prev.indexOf(child) !== -1) {
                                    opt.selected = true;
                                }
                                categorySelect.appendChild(opt);
                            });
                            if (!categorySelect.value && categorySelect.options.length) {
                                categorySelect.selectedIndex = 0;
                            }
                        }

                        function syncCategoryField() {
                            if (!categoryWrap || !categorySelect || !parentSelect) return;
                            const show = withCategory.indexOf(typeSelect.value) !== -1;
                            categoryWrap.classList.toggle('hidden', !show);
                            categorySelect.disabled = !show;
                            parentSelect.disabled = !show;
                        }

                        if (parentSelect) {
                            parentSelect.addEventListener('change', function () {
                                fillSubcategories('');
                            });
                        }

                        typeSelect.addEventListener('change', function () {
                            syncPriceField();
                            syncCategoryField();
                        });
                        syncPriceField();
                        syncCategoryField();
                    })();
                    </script>
                    <div>
                        <label class="block text-xs font-bold mb-1">
                            <?= htmlspecialchars(t('profile.photos')) ?> <span class="text-red-500">*</span>
                            <span class="font-medium text-gray-400 normal-case">· до 3 шт.</span>
                        </label>
                        <p class="text-[11px] text-gray-400 mb-2">Кликните по фото, чтобы сделать его обложкой</p>
                        <?php
                        $existingFiles = $editing ? ProductHelper::decodeImages($editing) : [];
                        $existingCover = $editing['image'] ?? ($existingFiles[0] ?? '');
                        $existingPayload = [];
                        foreach ($existingFiles as $file) {
                            $existingPayload[] = [
                                'name' => $file,
                                'url' => ProductHelper::url('public/uploads/products/' . basename($file)),
                                'cover' => $file === $existingCover,
                            ];
                        }
                        ?>
                        <div id="lot-photos" class="space-y-3"
                             data-existing='<?= htmlspecialchars(json_encode($existingPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>'>
                            <div id="lot-photo-grid" class="grid grid-cols-3 gap-2"></div>
                            <input type="hidden" name="cover" id="lot-cover" value="">
                            <div id="lot-keep-inputs"></div>
                            <label id="lot-add-btn" class="inline-flex items-center gap-2 cursor-pointer text-xs font-bold text-ink-800 dark:text-gray-200">
                                <span class="px-3 py-2 rounded-xl bg-brand-500 text-ink-900">+ Добавить фото</span>
                                <input type="file" id="lot-images-input" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="hidden">
                            </label>
                            <p class="text-[11px] text-gray-400">JPG, PNG, WEBP, GIF · до 5 МБ каждое</p>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="submit" class="flex-1 bg-brand-500 hover:bg-brand-400 text-ink-900 font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft">
                            <?= htmlspecialchars($editing ? t('profile.update') : t('profile.publish')) ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="<?= ProductHelper::url('/profile?tab=lots') ?>" class="sm:w-auto text-center px-5 py-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 text-xs font-bold uppercase tracking-wider hover:bg-white/60 dark:hover:bg-white/5 transition">
                                <?= htmlspecialchars(t('profile.cancel_edit')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                <script>
                (function () {
                    const root = document.getElementById('lot-photos');
                    if (!root) return;
                    const grid = document.getElementById('lot-photo-grid');
                    const coverInput = document.getElementById('lot-cover');
                    const keepBox = document.getElementById('lot-keep-inputs');
                    const fileInput = document.getElementById('lot-images-input');
                    const addBtn = document.getElementById('lot-add-btn');
                    const MAX = 3;
                    let items = [];

                    try {
                        const existing = JSON.parse(root.dataset.existing || '[]');
                        items = existing.map(function (img) {
                            return { kind: 'existing', name: img.name, url: img.url, cover: !!img.cover };
                        });
                    } catch (e) {}

                    if (items.length && !items.some(function (i) { return i.cover; })) {
                        items[0].cover = true;
                    }

                    function syncFileInput() {
                        const dt = new DataTransfer();
                        items.filter(function (i) { return i.kind === 'new' && i.file; }).forEach(function (i) {
                            dt.items.add(i.file);
                        });
                        fileInput.files = dt.files;
                    }

                    function syncHidden() {
                        keepBox.innerHTML = '';
                        items.forEach(function (item) {
                            if (item.kind !== 'existing') return;
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'keep_images[]';
                            input.value = item.name;
                            keepBox.appendChild(input);
                        });

                        const coverItem = items.find(function (i) { return i.cover; }) || items[0];
                        if (!coverItem) {
                            coverInput.value = '';
                            return;
                        }
                        if (coverItem.kind === 'existing') {
                            coverInput.value = coverItem.name;
                        } else {
                            const newIndex = items.filter(function (i) { return i.kind === 'new'; }).indexOf(coverItem);
                            coverInput.value = '__new__' + Math.max(0, newIndex);
                        }
                    }

                    function render() {
                        grid.innerHTML = '';
                        items.forEach(function (item, idx) {
                            const card = document.createElement('button');
                            card.type = 'button';
                            card.className = 'relative aspect-square rounded-xl overflow-hidden border-2 bg-black/[0.03] dark:bg-white/5 transition ' +
                                (item.cover ? 'border-brand-500 shadow-soft' : 'border-black/[0.08] dark:border-white/10 hover:border-brand-300');
                            card.title = 'Сделать обложкой';
                            card.innerHTML =
                                '<img src="' + item.url + '" alt="" class="w-full h-full object-cover">' +
                                (item.cover ? '<span class="absolute top-1.5 left-1.5 text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-md bg-brand-500 text-ink-900">Обложка</span>' : '') +
                                '<span data-remove class="absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-ink-900/70 text-white text-sm leading-6 hover:bg-red-600">×</span>';
                            card.addEventListener('click', function (e) {
                                if (e.target.closest('[data-remove]')) {
                                    e.preventDefault();
                                    items.splice(idx, 1);
                                    if (items.length && !items.some(function (i) { return i.cover; })) {
                                        items[0].cover = true;
                                    }
                                    syncFileInput();
                                    render();
                                    return;
                                }
                                items.forEach(function (i) { i.cover = false; });
                                item.cover = true;
                                render();
                            });
                            grid.appendChild(card);
                        });
                        addBtn.classList.toggle('hidden', items.length >= MAX);
                        syncHidden();
                    }

                    fileInput.addEventListener('change', function () {
                        const files = Array.from(fileInput.files || []);
                        const room = MAX - items.length;
                        let added = 0;
                        files.forEach(function (file) {
                            if (added >= room) return;
                            if (!file.type || file.type.indexOf('image/') !== 0) return;
                            const dup = items.some(function (i) {
                                return i.kind === 'new' && i.file && i.file.name === file.name && i.file.size === file.size;
                            });
                            if (dup) return;
                            const url = URL.createObjectURL(file);
                            items.push({ kind: 'new', file: file, url: url, cover: items.length === 0 });
                            added++;
                        });
                        if (items.length && !items.some(function (i) { return i.cover; })) {
                            items[0].cover = true;
                        }
                        syncFileInput();
                        render();
                    });

                    const form = root.closest('form');
                    form?.addEventListener('submit', function (e) {
                        syncFileInput();
                        syncHidden();
                        if (!items.length) {
                            e.preventDefault();
                            alert('Добавьте хотя бы одно фото');
                        }
                    });

                    render();
                })();
                </script>

                <h3 class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-3">Опубликованные (<?= count($products) ?>)</h3>
                <?php if (empty($products)): ?>
                    <p class="text-sm text-gray-400"><?= htmlspecialchars(t('profile.no_lots')) ?></p>
                <?php else: ?>
                    <div class="space-y-2">
                        <?php foreach ($products as $p):
                            $thumb = ProductHelper::imageUrl($p);
                        ?>
                            <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-white dark:bg-white/5 border border-black/[0.06] dark:border-white/10 rounded-2xl px-4 py-3.5 <?= !empty($editing) && (int) $editing['id'] === (int) $p['id'] ? 'border-brand-400/60 shadow-soft' : '' ?>">
                                <a href="<?= ProductHelper::url('/product/' . $p['id']) ?>" class="flex items-center gap-3 flex-1 min-w-0 hover:opacity-80 transition">
                                    <div class="w-12 h-12 rounded-xl overflow-hidden bg-brand-50 dark:bg-white/5 flex-shrink-0 flex items-center justify-center text-lg">
                                        <?php if ($thumb): ?>
                                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <?= ProductHelper::icon($p['type']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-sm font-semibold truncate"><?= htmlspecialchars($p['title']) ?></div>
                                        <div class="text-[10px] text-gray-400 mt-0.5"><?= ProductHelper::label($p['type']) ?><?= in_array($p['type'], ProductHelper::PRODUCT_TYPES_WITH_CATEGORY, true) && !empty($p['category']) ? ' · ' . htmlspecialchars($p['category']) : '' ?> · <?= htmlspecialchars($p['status']) ?></div>
                                    </div>
                                </a>
                                <div class="flex items-center justify-between sm:justify-end gap-3 flex-shrink-0">
                                    <span class="text-sm font-display font-bold text-brand-600 whitespace-nowrap"><?= htmlspecialchars(ProductHelper::formatPrice($p)) ?></span>
                                    <div class="flex items-center gap-1.5">
                                        <a href="<?= ProductHelper::url('/profile?tab=lots&edit=' . $p['id']) ?>" class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold border border-black/[0.08] dark:border-white/10 hover:border-brand-400/50 hover:bg-brand-50/60 dark:hover:bg-white/5 transition" title="Редактировать">
                                            Изменить
                                        </a>
                                        <form method="post" action="<?= ProductHelper::url('/profile/lots/' . $p['id'] . '/delete') ?>" onsubmit="return confirm('Удалить объявление «<?= htmlspecialchars($p['title'], ENT_QUOTES) ?>»?');">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-xl text-[11px] font-bold text-red-600 border border-red-200/80 dark:border-red-500/30 hover:bg-red-50 dark:hover:bg-red-500/10 transition" title="Удалить">
                                                Удалить
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
