<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Helpers\ProductHelper;
use App\Helpers\IconHelper;

$lang = Lang::current();
$langSwitchUrl = static function (string $code) use ($lang): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/';
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['lang'] = $code;
    return $path . '?' . http_build_query($query);
};
?>
<header class="h-[72px] glass border-b border-black/[0.06] dark:border-white/10 flex items-center justify-between px-4 sm:px-6 z-10 flex-shrink-0 gap-3">
    <div class="flex items-center gap-2.5 flex-1 max-w-2xl">
        <button onclick="toggleSidebar()" class="p-2.5 rounded-xl bg-white/70 dark:bg-white/5 border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50 text-ink-700 dark:text-gray-200 transition flex-shrink-0 shadow-sm">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="relative flex-shrink-0" id="city-picker">
            <button type="button" id="city-picker-btn" onclick="toggleCityPicker()" class="hidden sm:inline-flex items-center gap-1.5 h-9 px-3.5 rounded-full bg-white dark:bg-white/5 border border-black/[0.08] dark:border-white/10 text-[13px] font-medium text-ink-700/70 dark:text-gray-300 whitespace-nowrap shadow-sm hover:border-brand-400/40 transition" title="<?= htmlspecialchars(t('header.city_choose')) ?>" aria-haspopup="listbox" aria-expanded="false">
                <?= IconHelper::svg('map-pin', 'w-3.5 h-3.5 text-brand-500') ?>
                <span id="city-picker-label"><?= htmlspecialchars(t('header.city')) ?></span>
            </button>
            <div id="city-picker-dropdown" class="hidden absolute left-0 mt-2 w-56 max-h-72 overflow-y-auto glass border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-1.5 z-30" role="listbox">
                <button type="button" onclick="detectUserCity(true)" class="w-full text-left px-3.5 py-2 text-xs font-semibold text-brand-600 hover:bg-brand-50/80 dark:hover:bg-white/5 transition flex items-center gap-2">
                    <?= IconHelper::svg('map-pin', 'w-3.5 h-3.5') ?>
                    <?= htmlspecialchars(t('header.city_detect')) ?>
                </button>
                <div class="my-1 border-t border-black/[0.06] dark:border-white/10"></div>
                <div id="city-picker-list" class="py-0.5"></div>
            </div>
        </div>
        <form method="get" action="<?= ProductHelper::url('/') ?>" class="relative w-full">
            <input type="text" name="q" value="<?= htmlspecialchars($search ?? '') ?>" class="ui-input w-full border border-black/[0.08] dark:border-white/10 bg-white/80 dark:bg-white/5 h-11 pl-4 pr-11 rounded-2xl text-sm placeholder:text-gray-400 shadow-sm" placeholder="<?= htmlspecialchars(t('header.search_placeholder')) ?>">
            <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-xl text-ink-700/50 dark:text-white/50 hover:text-accent-500 dark:hover:text-brand-400 flex items-center justify-center transition">
                <?= IconHelper::svg('search', 'w-4 h-4') ?>
            </button>
        </form>
    </div>

    <div class="flex items-center gap-2 flex-shrink-0">
        <div class="inline-flex items-center h-10 rounded-xl bg-white/70 dark:bg-white/5 border border-black/[0.06] dark:border-white/10 overflow-hidden text-[11px] font-bold tracking-wide shadow-sm">
            <a href="<?= htmlspecialchars($langSwitchUrl('kk')) ?>" class="px-2.5 py-2 transition <?= $lang === 'kk' ? 'bg-brand-500 text-white' : 'text-ink-700/70 dark:text-gray-400 hover:text-ink-900 dark:hover:text-white' ?>"><?= htmlspecialchars(t('header.lang_kk')) ?></a>
            <a href="<?= htmlspecialchars($langSwitchUrl('ru')) ?>" class="px-2.5 py-2 transition <?= $lang === 'ru' ? 'bg-brand-500 text-white' : 'text-ink-700/70 dark:text-gray-400 hover:text-ink-900 dark:hover:text-white' ?>"><?= htmlspecialchars(t('header.lang_ru')) ?></a>
        </div>
        <?php if (Auth::check()): ?>
            <a href="<?= ProductHelper::url('/profile?tab=lots') ?>" class="inline-flex items-center gap-1.5 h-10 px-3 sm:px-4 rounded-xl bg-accent-500 hover:bg-accent-400 text-white font-display font-bold text-xs sm:text-sm shadow-soft transition whitespace-nowrap" title="<?= htmlspecialchars(t('header.add_listing_title')) ?>">
                <span class="text-base leading-none">+</span>
                <span class="hidden sm:inline"><?= htmlspecialchars(t('header.add_listing')) ?></span>
            </a>
        <?php endif; ?>
        <button onclick="toggleDarkMode()" class="p-2.5 rounded-xl bg-white/70 dark:bg-white/5 border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50 transition shadow-sm text-ink-700 dark:text-gray-200" title="<?= htmlspecialchars(t('header.theme')) ?>">
            <span class="dark:hidden"><?= IconHelper::svg('moon', 'w-4 h-4') ?></span>
            <span class="hidden dark:inline"><?= IconHelper::svg('sun', 'w-4 h-4') ?></span>
        </button>
        <div class="relative">
            <button onclick="toggleNotifications()" class="p-2.5 rounded-xl bg-white/70 dark:bg-white/5 border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50 transition flex items-center gap-1 shadow-sm text-ink-700 dark:text-gray-200">
                <?= IconHelper::svg('bell', 'w-4 h-4') ?>
                <?php if (($unread ?? 0) > 0): ?>
                    <span id="notification-badge" class="bg-accent-500 text-white text-[10px] min-w-[18px] h-[18px] px-1 rounded-full font-extrabold inline-flex items-center justify-center"><?= (int) $unread ?></span>
                <?php endif; ?>
            </button>
            <div id="notification-dropdown" class="hidden absolute right-0 mt-3 w-72 sm:w-80 glass border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-2 z-30 overflow-hidden">
                <div class="px-4 py-2.5 border-b border-black/[0.06] dark:border-white/10 flex justify-between items-center">
                    <h5 class="font-display font-semibold text-sm"><?= htmlspecialchars(t('header.notifications')) ?></h5>
                    <?php if (Auth::check()): ?>
                        <a href="<?= ProductHelper::url('/notifications/clear') ?>" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(t('header.clear')) ?></a>
                    <?php endif; ?>
                </div>
                <div class="max-h-64 overflow-y-auto text-xs divide-y divide-black/[0.04] dark:divide-white/5">
                    <?php if (empty($notifications)): ?>
                        <div class="p-4 text-gray-400"><?= htmlspecialchars(Auth::check() ? t('header.no_notifications') : t('header.no_notifications_guest')) ?></div>
                    <?php else: ?>
                        <?php foreach ($notifications as $n): ?>
                            <div class="p-3.5 hover:bg-brand-50/60 dark:hover:bg-white/5 transition <?= empty($n['is_read']) ? 'font-semibold' : 'text-gray-600 dark:text-gray-300' ?>">
                                <?= htmlspecialchars($n['message']) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a href="<?= ProductHelper::url(Auth::check() ? '/profile' : '/login') ?>" class="p-2.5 rounded-xl bg-white/70 dark:bg-white/5 border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50 transition shadow-sm text-ink-700 dark:text-gray-200" title="<?= htmlspecialchars(t('header.profile')) ?>">
            <?= IconHelper::svg('user', 'w-4 h-4') ?>
        </a>
    </div>
</header>
