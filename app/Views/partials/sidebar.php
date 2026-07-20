<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;
use App\Helpers\AvatarHelper;
use App\Helpers\IconHelper;
use App\Models\Product;
use App\Models\User;

$nav = $currentNav ?? '';
$user = Auth::user();

function navClass(string $key, string $nav): string
{
    $active = 'nav-pill-active font-semibold';
    $idle = 'text-ink-700/70 dark:text-gray-400 hover:bg-black/[0.04] dark:hover:bg-white/[0.06] font-medium';
    return $nav === $key ? $active : $idle;
}

$navIcon = static fn (string $name): string => IconHelper::svg($name, 'w-[18px] h-[18px] flex-shrink-0 opacity-80');
?>
<aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-[272px] glass border-r border-black/[0.06] dark:border-white/10 flex flex-col h-full transition-transform duration-300 transform -translate-x-full lg:translate-x-0 shadow-soft">
    <div class="relative h-[72px] flex items-center justify-center px-5 border-b border-black/[0.06] dark:border-white/10">
        <a href="<?= ProductHelper::url('/') ?>" class="flex items-baseline gap-0.5 flex-shrink-0 group">
            <span class="font-display text-3xl font-extrabold tracking-tight text-brand-500 group-hover:text-brand-600 transition">za</span>
            <span class="font-display text-2xl font-bold tracking-tight text-ink-900 dark:text-white">kopeyki<span class="text-brand-500"></span></span>
        </a>
        <button onclick="toggleSidebar()" class="lg:hidden absolute right-3 top-1/2 -translate-y-1/2 p-2 rounded-xl text-gray-400 hover:bg-black/5 hover:text-ink-800">✕</button>
    </div>

    <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto scrollbar-hide">
        <p class="px-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('nav.menu')) ?></p>
        <a href="<?= ProductHelper::url('/') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('home', $nav) ?>">
            <?= $navIcon('home') ?> <span><?= htmlspecialchars(t('nav.home')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/catalog/new') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('new', $nav) ?>">
            <?= $navIcon('bag') ?> <span><?= htmlspecialchars(t('nav.new')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/catalog/used') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('used', $nav) ?>">
            <?= $navIcon('package') ?> <span><?= htmlspecialchars(t('nav.used')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/auctions') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('auctions', $nav) ?>">
            <span class="flex items-center gap-3 flex-1 min-w-0"><?= $navIcon('gavel') ?> <span><?= htmlspecialchars(t('nav.auctions')) ?></span></span>
            <span class="bg-red-500 text-white text-[9px] px-1.5 py-0.5 rounded-md font-bold uppercase tracking-wide"><?= htmlspecialchars(t('nav.live')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/catalog/free') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('free', $nav) ?>">
            <?= $navIcon('gift') ?> <span><?= htmlspecialchars(t('nav.free')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/catalog/exchange') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('exchange', $nav) ?>">
            <?= $navIcon('exchange') ?> <span><?= htmlspecialchars(t('nav.exchange')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/catalog/services') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('services', $nav) ?>">
            <?= $navIcon('wrench') ?> <span><?= htmlspecialchars(t('nav.services')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/catalog/courses') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('courses', $nav) ?>">
            <?= $navIcon('graduation') ?> <span><?= htmlspecialchars(t('nav.courses')) ?></span>
        </a>
        <a href="<?= ProductHelper::url('/profile') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm rounded-xl transition <?= navClass('profile', $nav) ?>">
            <?= $navIcon('user') ?> <span><?= htmlspecialchars(t('nav.profile')) ?></span>
        </a>

        <?php
        $fmtStat = static fn (int $n): string => number_format($n, 0, '', ' ');
        $productCount = (new Product())->countActive();
        $userCount = (new User())->countAll();
        ?>
        <div class="mt-3 mx-0.5 rounded-2xl border border-black/[0.05] dark:border-white/10 bg-white/60 dark:bg-white/[0.04] shadow-soft px-3 py-3 flex items-center justify-between gap-2.5">
            <div class="space-y-2 min-w-0">
                <p class="text-[8px] font-semibold uppercase tracking-[0.12em] text-brand-600 leading-tight"><?= htmlspecialchars(t('nav.stats_title')) ?></p>
                <div class="space-y-1.5">
                    <div>
                        <p class="font-display text-base font-bold tracking-tight tabular-nums leading-none text-ink-900 dark:text-white"><?= $fmtStat($productCount) ?></p>
                        <p class="text-[8px] font-semibold uppercase tracking-[0.08em] text-gray-400 mt-0.5"><?= htmlspecialchars(t('nav.listings')) ?></p>
                    </div>
                    <div>
                        <p class="font-display text-base font-bold tracking-tight tabular-nums leading-none text-ink-900 dark:text-white"><?= $fmtStat($userCount) ?></p>
                        <p class="text-[8px] font-semibold uppercase tracking-[0.08em] text-gray-400 mt-0.5"><?= htmlspecialchars(t('nav.users')) ?></p>
                    </div>
                </div>
            </div>
            <div class="flex flex-col gap-1 w-[98px] flex-shrink-0">
                <a href="#" class="inline-flex items-center gap-1 h-6 px-2 rounded-full border border-black/[0.08] dark:border-white/10 bg-white/70 dark:bg-white/[0.04] text-[10px] font-medium text-ink-800 dark:text-gray-200 hover:border-brand-400/50 transition">
                    <svg class="w-2.5 h-2.5 flex-shrink-0 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r="0.7" fill="currentColor" stroke="none"/></svg>
                    <?= htmlspecialchars(t('nav.about')) ?>
                </a>
                <a href="#" class="inline-flex items-center gap-1 h-6 px-2 rounded-full border border-black/[0.08] dark:border-white/10 bg-white/70 dark:bg-white/[0.04] text-[10px] font-medium text-ink-800 dark:text-gray-200 hover:border-brand-400/50 transition">
                    <svg class="w-2.5 h-2.5 flex-shrink-0 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a5.5 5.5 0 0 0-3.5 9.7c.7.6 1.1 1.3 1.2 2.3h4.6c.1-1 .5-1.7 1.2-2.3A5.5 5.5 0 0 0 12 3z"/></svg>
                    <?= htmlspecialchars(t('nav.idea')) ?>
                </a>
                <a href="#" class="inline-flex items-center gap-1 h-6 px-2 rounded-full border border-black/[0.08] dark:border-white/10 bg-white/70 dark:bg-white/[0.04] text-[10px] font-medium text-ink-800 dark:text-gray-200 hover:border-brand-400/50 transition">
                    <svg class="w-2.5 h-2.5 flex-shrink-0 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 5.6a4.6 4.6 0 0 0-6.5 0L12 7.9l-2.3-2.3a4.6 4.6 0 0 0-6.5 6.5l2.3 2.3L12 21l6.5-6.6 2.3-2.3a4.6 4.6 0 0 0 0-6.5z"/></svg>
                    <?= htmlspecialchars(t('nav.support')) ?>
                </a>
            </div>
        </div>

        <?php if (Auth::isAdmin()): ?>
        <div class="pt-3 mt-3 border-t border-black/[0.06] dark:border-white/10">
            <a href="<?= ProductHelper::url('/admin') ?>" class="nav-item w-full flex items-center gap-3 px-3.5 py-2.5 text-sm font-semibold rounded-xl text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30 transition <?= $nav === 'admin' ? 'bg-red-50 dark:bg-red-950/30' : '' ?>">
                <?= IconHelper::svg('shield', 'w-[18px] h-[18px] flex-shrink-0 opacity-80') ?> <span><?= htmlspecialchars(t('nav.admin')) ?></span>
            </a>
        </div>
        <?php endif; ?>
    </nav>

    <?php if ($user): ?>
    <div class="p-3 m-3 rounded-2xl bg-ink-900 text-white dark:bg-white/5 dark:border dark:border-white/10">
        <div class="flex items-center gap-3 overflow-hidden mb-3">
            <?= AvatarHelper::html($user, 'w-10 h-10', 'text-sm', 'rounded-xl') ?>
            <div class="min-w-0">
                <h4 class="text-sm font-semibold truncate"><?= htmlspecialchars($user['name']) ?></h4>
                <span class="text-[11px] text-white/50"><?= htmlspecialchars($user['role'] === 'admin' ? t('nav.role_admin') : t('nav.role_user')) ?></span>
            </div>
        </div>
        <a href="<?= ProductHelper::url('/logout') ?>" class="block text-center text-[11px] font-semibold text-white/60 hover:text-white transition py-2 rounded-xl hover:bg-white/10"><?= htmlspecialchars(t('nav.logout')) ?></a>
    </div>
    <?php else: ?>
    <div class="p-3 m-3 space-y-2">
        <a href="<?= ProductHelper::url('/login') ?>" class="block w-full text-center bg-brand-500 hover:bg-brand-600 text-white font-display font-bold py-2.5 rounded-xl text-xs uppercase tracking-wide transition"><?= htmlspecialchars(t('nav.login')) ?></a>
        <a href="<?= ProductHelper::url('/register') ?>" class="block w-full text-center text-[11px] font-semibold text-ink-700/60 dark:text-white/60 hover:text-accent-500"><?= htmlspecialchars(t('nav.register')) ?></a>
    </div>
    <?php endif; ?>
</aside>
