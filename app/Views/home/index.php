<?php
use App\Helpers\ProductHelper;
use App\Helpers\AvatarHelper;
use App\Helpers\IconHelper;
use App\Core\View;
use App\Core\Auth;

$storyGroups = $storyGroups ?? [];
$streams = $streams ?? [];
$changelog = $changelog ?? null;
?>

<section class="space-y-9 fade-up">
    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold shadow-sm"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- STORIES -->
    <div class="flex items-center gap-4 overflow-x-auto pb-1 scrollbar-hide">
        <?php if (Auth::check()):
            $me = Auth::user();
            $myAvatar = AvatarHelper::url($me);
        ?>
            <button type="button" onclick="openStoryCreate()" class="flex flex-col items-center flex-shrink-0 space-y-1.5 group">
                <div class="w-[58px] h-[58px] rounded-full p-[2px] border-2 border-dashed border-brand-500/80 flex items-center justify-center relative group-hover:bg-brand-50/80 dark:group-hover:bg-white/5 transition">
                    <div class="w-full h-full rounded-full bg-white dark:bg-white/10 flex items-center justify-center text-sm font-bold overflow-hidden shadow-sm">
                        <?php if ($myAvatar): ?>
                            <img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= htmlspecialchars(AvatarHelper::initial($me)) ?>
                        <?php endif; ?>
                    </div>
                    <span class="absolute -bottom-0.5 -right-0.5 w-5 h-5 rounded-full bg-accent-500 text-white text-xs font-bold flex items-center justify-center border-2 border-ink-50 dark:border-ink-900">+</span>
                </div>
                <span class="text-[10px] text-ink-700/70 dark:text-gray-300 truncate w-14 text-center font-semibold"><?= htmlspecialchars(t('home.your_story')) ?></span>
            </button>
        <?php else: ?>
            <a href="<?= ProductHelper::url('/login') ?>" class="flex flex-col items-center flex-shrink-0 space-y-1.5">
                <div class="w-[58px] h-[58px] rounded-full p-[2px] border-2 border-dashed border-brand-500/80 flex items-center justify-center">
                    <div class="w-full h-full rounded-full bg-white dark:bg-white/10 flex items-center justify-center text-2xl text-brand-500 font-bold">+</div>
                </div>
                <span class="text-[10px] text-gray-500 truncate w-14 text-center font-medium"><?= htmlspecialchars(t('nav.login')) ?></span>
            </a>
        <?php endif; ?>

        <?php foreach ($storyGroups as $gi => $group):
            $avatarUrl = AvatarHelper::url([
                'avatar_file' => $group['user_avatar_file'] ?? null,
            ]);
        ?>
            <button type="button"
                onclick='openStoryViewer(<?= (int) $gi ?>)'
                class="flex flex-col items-center flex-shrink-0 space-y-1.5">
                <div class="w-[58px] h-[58px] rounded-full p-[2.5px] bg-gradient-to-tr from-brand-500 via-accent-500 to-gold-500 shadow-soft">
                    <div class="w-full h-full rounded-full bg-white dark:bg-ink-800 p-[2px]">
                        <div class="w-full h-full rounded-full bg-ink-50 dark:bg-white/10 flex items-center justify-center text-sm font-bold overflow-hidden">
                            <?php if ($avatarUrl): ?>
                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="" class="w-full h-full object-cover">
                            <?php else: ?>
                                <?= htmlspecialchars($group['user_avatar']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <span class="text-[10px] text-ink-700/70 dark:text-gray-300 truncate w-14 text-center font-medium"><?= htmlspecialchars($group['user_name']) ?></span>
            </button>
        <?php endforeach; ?>

        <?php if (empty($storyGroups)): ?>
            <div class="flex items-center text-xs text-gray-400 pl-1"><?= htmlspecialchars(t('home.no_stories')) ?></div>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-2.5 sm:gap-3">
        <?php
        $cats = [
            ['url' => '/catalog/new', 'label' => t('home.cat_new'), 'tone' => 'from-blue-50 to-indigo-50', 'color' => 'text-blue-500', 'icon' => 'bag'],
            ['url' => '/catalog/used', 'label' => t('home.cat_used'), 'tone' => 'from-orange-50 to-amber-50', 'color' => 'text-orange-500', 'icon' => 'package'],
            ['url' => '/auctions', 'label' => t('home.cat_auctions'), 'tone' => 'from-accent-50 to-orange-50', 'color' => 'text-accent-500', 'icon' => 'gavel'],
            ['url' => '/catalog/services', 'label' => t('home.cat_services'), 'tone' => 'from-slate-50 to-brand-50', 'color' => 'text-slate-600 dark:text-slate-300', 'icon' => 'wrench'],
            ['url' => '/catalog/gigs', 'label' => t('home.cat_gigs'), 'tone' => 'from-teal-50 to-emerald-50', 'color' => 'text-teal-600 dark:text-teal-300', 'icon' => 'briefcase'],
            ['url' => '/catalog/courses', 'label' => t('home.cat_courses'), 'tone' => 'from-violet-50 to-indigo-50', 'color' => 'text-violet-500', 'icon' => 'graduation'],
            ['url' => '/catalog/exchange', 'label' => t('home.cat_exchange'), 'tone' => 'from-brand-50 to-sky-50', 'color' => 'text-brand-500', 'icon' => 'exchange'],
            ['url' => '/catalog/free', 'label' => t('home.cat_free'), 'tone' => 'from-sky-50 to-blue-50', 'color' => 'text-sky-500', 'icon' => 'gift'],




        ];
        foreach ($cats as $c): ?>
            <a href="<?= ProductHelper::url($c['url']) ?>" class="group bg-gradient-to-br <?= $c['tone'] ?> dark:from-white/[0.06] dark:to-white/[0.02] p-3.5 sm:p-4 rounded-2xl border border-black/[0.05] dark:border-white/10 text-center hover:border-brand-400/50 hover:shadow-soft hover:-translate-y-0.5 transition duration-300 block">
                <span class="flex items-center justify-center mb-1.5 <?= $c['color'] ?> transition duration-300 group-hover:scale-110">
                    <?= IconHelper::svg($c['icon'], 'h-8 w-8 sm:h-9 sm:w-9') ?>
                </span>
                <span class="text-[11px] font-semibold text-ink-800 dark:text-gray-200"><?= $c['label'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="space-y-3">
        <div class="flex items-end justify-between gap-3 flex-wrap">
            <div>
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500 mb-1">Live</p>
                <h2 class="font-display text-lg sm:text-xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars(t('home.streams')) ?></h2>
                <p class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars(t('home.streams_hint')) ?></p>
            </div>
            <?php if (Auth::check()): ?>
                <button type="button" onclick="startLiveStream()" class="text-[10px] sm:text-xs font-display font-bold uppercase tracking-wider bg-red-500 text-white px-4 py-2.5 rounded-2xl hover:bg-red-600 transition shadow-soft"><?= htmlspecialchars(t('home.start_stream')) ?></button>
            <?php else: ?>
                <a href="<?= ProductHelper::url('/login') ?>" class="text-[11px] font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(t('home.login_to_stream')) ?></a>
            <?php endif; ?>
        </div>

        <div class="flex gap-3 overflow-x-auto pb-1 scrollbar-hide">
            <?php if (empty($streams)): ?>
                <div class="w-full rounded-2xl border border-dashed border-black/10 dark:border-white/15 bg-white/40 dark:bg-white/[0.03] px-5 py-10 text-center text-xs text-gray-400">
                    <?= htmlspecialchars(t('home.no_streams')) ?>
                </div>
            <?php else: ?>
                <?php foreach ($streams as $si => $st): ?>
                    <button type="button" onclick="openStreamViewer(<?= (int) $si ?>)"
                        class="flex-shrink-0 w-[132px] sm:w-[150px] aspect-[9/16] rounded-[22px] overflow-hidden relative ring-2 ring-red-500/50 bg-black text-left group shadow-soft hover:shadow-lift hover:-translate-y-0.5 transition duration-300">
                        <div class="absolute inset-0 bg-gradient-to-br from-red-600 via-orange-600 to-ink-900"></div>
                        <div class="absolute inset-0 flex items-center justify-center z-[5]">
                            <span class="text-4xl font-display font-bold text-white/90 drop-shadow"><?= htmlspecialchars(($st['author_avatar'] ?: mb_substr($st['author_name'] ?? 'L', 0, 1))) ?></span>
                        </div>
                        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/10 to-black/30 z-10"></div>
                        <span class="absolute top-2.5 left-2.5 text-[8px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md text-white z-20 bg-red-500 animate-pulse">● Live</span>
                        <div class="absolute bottom-2.5 left-2.5 right-2.5 text-white z-20">
                            <h4 class="text-[11px] font-semibold line-clamp-2 leading-tight"><?= htmlspecialchars($st['title']) ?></h4>
                            <p class="text-[9px] text-white/70 truncate mt-0.5"><?= htmlspecialchars($st['author_name'] ?? '') ?></p>
                        </div>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-4">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('home.feed')) ?></p>
            <h2 class="font-display text-lg sm:text-xl font-bold tracking-tight text-ink-900 dark:text-white">
                <?= $search ? htmlspecialchars(t('home.search_results', ['q' => $search])) : htmlspecialchars(t('home.fresh')) ?>
            </h2>
        </div>
        <?php if (empty($items)): ?>
            <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/15 px-5 py-12 text-center text-sm text-gray-400"><?= htmlspecialchars(t('home.nothing_found')) ?></div>
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
    </div>
</section>

<?php if (!empty($changelog)): ?>
<div id="whats-new-modal" class="hidden fixed inset-0 z-[80] flex items-center justify-center bg-ink-900/55 backdrop-blur-sm p-4" role="dialog" aria-modal="true" aria-labelledby="whats-new-title">
    <div class="bg-white dark:bg-ink-800 w-full max-w-md rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10" onclick="event.stopPropagation()">
        <div class="p-4 sm:p-5 border-b border-black/[0.06] dark:border-white/10 flex justify-between items-start gap-3">
            <div>
                <h3 id="whats-new-title" class="font-display font-bold text-sm"><?= htmlspecialchars(t('home.whats_new_title')) ?></h3>
                <?php if (!empty($changelog['date'])): ?>
                    <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('home.whats_new_hint', ['date' => $changelog['date']])) ?></p>
                <?php endif; ?>
            </div>
            <button type="button" onclick="closeWhatsNew()" class="w-8 h-8 rounded-xl text-gray-400 hover:bg-black/5 hover:text-ink-800 dark:hover:bg-white/10 transition flex-shrink-0" aria-label="<?= htmlspecialchars(t('home.whats_new_ok')) ?>">✕</button>
        </div>
        <ul class="p-5 sm:p-6 space-y-2.5 max-h-[50vh] overflow-y-auto text-sm text-ink-800 dark:text-gray-200">
            <?php foreach ($changelog['items'] as $item): ?>
                <li class="flex gap-2.5 leading-snug">
                    <span class="mt-1.5 w-1.5 h-1.5 rounded-full bg-accent-500 flex-shrink-0" aria-hidden="true"></span>
                    <span><?= htmlspecialchars($item) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="px-5 sm:px-6 pb-5 sm:pb-6">
            <button type="button" onclick="closeWhatsNew()" class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('home.whats_new_ok')) ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (Auth::check()): ?>
<?php View::partial('partials/story-create-modal'); ?>

<!-- LIVE START PREVIEW — камера → подтверждение → старт -->
<div id="live-start-preview-modal" class="hidden fixed inset-0 z-[75] flex items-center justify-center bg-ink-900/70 backdrop-blur-sm p-3 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="live-preview-title" onclick="if(event.target===this)closeLiveStartPreview()">
    <div class="bg-white dark:bg-ink-800 w-full max-w-[380px] rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10" onclick="event.stopPropagation()">
        <div class="p-4 sm:p-5 border-b border-black/[0.06] dark:border-white/10 flex justify-between items-center gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.14em] text-red-500">● Live</p>
                <h3 id="live-preview-title" class="font-display font-bold text-sm mt-0.5 truncate"><?= htmlspecialchars(t('home.live_preview_title')) ?></h3>
            </div>
            <button type="button" onclick="closeLiveStartPreview()" class="w-8 h-8 rounded-xl text-gray-400 hover:bg-black/5 hover:text-ink-800 dark:hover:bg-white/10 transition flex-shrink-0" aria-label="<?= htmlspecialchars(t('home.close_stream')) ?>">✕</button>
        </div>
        <div class="relative bg-black aspect-[9/16] max-h-[58vh] overflow-hidden">
            <video id="live-preview-cam" class="absolute inset-0 w-full h-full object-cover" playsinline webkit-playsinline muted autoplay></video>
            <div id="live-preview-placeholder" class="absolute inset-0 flex flex-col items-center justify-center text-white/80 p-6 text-center bg-gradient-to-br from-red-700 via-orange-700 to-gray-900">
                <span class="mb-3 opacity-80" aria-hidden="true"><?= IconHelper::svg('camera', 'w-8 h-8') ?></span>
                <p id="live-preview-status" class="text-xs font-semibold max-w-[220px]"><?= htmlspecialchars(t('home.live_preview_waiting')) ?></p>
            </div>
            <span class="absolute top-3 left-3 z-10 text-[9px] font-black uppercase tracking-wider bg-red-500 text-white px-2 py-1 rounded-md shadow">● Preview</span>
        </div>
        <div class="p-4 sm:p-5 space-y-3">
            <p class="text-[12px] text-gray-500 dark:text-gray-400 leading-snug"><?= htmlspecialchars(t('home.live_preview_hint')) ?></p>
            <button type="button" id="live-preview-confirm-btn" onclick="confirmStartLiveStream()" class="w-full bg-[#7c3aed] hover:bg-[#6d28d9] disabled:opacity-60 disabled:pointer-events-none text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft inline-flex items-center justify-center gap-2">
                <?= IconHelper::svg('mic', 'w-4 h-4') ?>
                <span class="live-btn-label"><?= htmlspecialchars(t('home.start_stream')) ?></span>
            </button>
            <button type="button" onclick="closeLiveStartPreview()" class="w-full text-[12px] font-semibold text-gray-400 hover:text-ink-800 dark:hover:text-white py-1 transition"><?= htmlspecialchars(t('home.live_preview_cancel')) ?></button>
        </div>
    </div>
</div>

<?php View::partial('partials/live-setup-modal'); ?>
<?php endif; ?>

<!-- STORY VIEWER — Instagram web -->
<div id="story-viewer" class="hidden fixed inset-0 z-[60] story-viewer-shell" onclick="if(event.target===this||event.target.classList.contains('story-stage'))closeStoryViewer()">
    <a href="<?= ProductHelper::url('/') ?>" class="story-brand" onclick="event.stopPropagation()">za<span>kopeyki</span>.kz</a>
    <button type="button" class="story-close-outer" onclick="closeStoryViewer()" aria-label="Close">✕</button>
    <div class="story-stage">
        <button type="button" id="story-nav-prev" class="story-nav-btn" onclick="event.stopPropagation(); prevStory()" aria-label="Previous">‹</button>
        <div class="story-frame" onclick="event.stopPropagation()">
            <div class="absolute inset-0" id="story-slide">
                <div id="story-bg" class="absolute inset-0 story-text-bg"></div>
                <img id="story-image" src="" alt="" class="hidden absolute inset-0 w-full h-full object-cover">
                <div class="absolute inset-0 story-vignette z-[1]"></div>
                <div id="story-emoji" class="absolute inset-0 z-[2] flex flex-col items-center justify-center px-8 pointer-events-none">
                    <span id="story-emoji-icon" class="leading-none select-none"></span>
                    <p id="story-caption-center" class="hidden"></p>
                </div>
                <div class="absolute inset-y-0 left-0 w-[30%] z-10 cursor-pointer" onclick="event.stopPropagation(); prevStory()"></div>
                <div class="absolute inset-y-0 right-0 w-[30%] z-10 cursor-pointer" onclick="event.stopPropagation(); nextStory()"></div>
            </div>

            <div class="absolute top-0 left-0 right-0 z-20 pt-3 px-3 pb-2 space-y-2 pointer-events-none">
                <div id="story-progress" class="flex gap-[3px]"></div>
                <div class="flex items-center gap-2 text-white px-0.5 pointer-events-auto">
                    <div id="story-viewer-avatar"></div>
                    <span id="story-viewer-name" class="text-[13px] font-semibold truncate drop-shadow max-w-[55%]"></span>
                    <span id="story-viewer-time" class="text-[12px] text-white/65 flex-shrink-0"></span>
                    <button type="button" class="sm:hidden ml-auto w-8 h-8 text-white text-xl" onclick="closeStoryViewer()" aria-label="Close">✕</button>
                </div>
            </div>

            <div class="story-footer">
                <a id="story-product-card" href="#" class="hidden story-product-card">
                    <img id="story-product-img" src="" alt="" class="hidden">
                    <div id="story-product-ph" class="story-product-ph">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p id="story-product-title" class="text-[12px] font-semibold leading-tight line-clamp-2"></p>
                        <p id="story-product-price" class="text-[12px] text-gray-500 mt-0.5"></p>
                    </div>
                    <span class="text-gray-400 text-lg flex-shrink-0">›</span>
                </a>
                <div class="story-reply-bar">
                    <input id="story-reply-input" type="text" class="story-reply-input" placeholder="<?= htmlspecialchars(t('home.story_reply')) ?>" maxlength="200" autocomplete="off">
                    <button type="button" id="story-share-btn" class="story-action-btn" title="<?= htmlspecialchars(t('home.story_share')) ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                    </button>
                    <button type="button" id="story-like-btn" class="story-action-btn" title="<?= htmlspecialchars(t('home.story_like')) ?>">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </button>
                </div>
                <div id="story-delete-wrap" class="hidden flex justify-center">
                    <form id="story-delete-form" method="post" action="">
                        <button type="submit" class="bg-black/50 hover:bg-red-600 text-white text-[11px] font-semibold px-3.5 py-1.5 rounded-full" onclick="return confirm(<?= json_encode(t('home.confirm_delete_story')) ?>)"><?= htmlspecialchars(t('home.delete_story')) ?></button>
                    </form>
                </div>
            </div>
        </div>
        <button type="button" id="story-nav-next" class="story-nav-btn" onclick="event.stopPropagation(); nextStory()" aria-label="Next">›</button>
    </div>
</div>

<!-- STREAM VIEWER — Live Shopping -->
<div id="stream-viewer" class="hidden fixed inset-0 z-[70] story-viewer-shell" onclick="if(event.target===this||event.target.classList.contains('story-stage'))closeStreamViewer()">
    <a href="<?= ProductHelper::url('/') ?>" class="story-brand" onclick="event.stopPropagation()">za<span>kopeyki</span>.kz</a>
    <button type="button" class="story-close-outer" onclick="closeStreamViewer()" aria-label="Close">✕</button>
    <div class="story-stage">
        <button type="button" id="stream-nav-prev" class="story-nav-btn" onclick="event.stopPropagation(); prevStream()" aria-label="Previous">‹</button>
        <div class="story-frame live-shop-frame" onclick="event.stopPropagation()">
            <!-- legacy header (non-live / fallback) -->
            <div id="stream-classic-header" class="absolute top-0 left-0 right-0 z-30 pt-3 px-3 pb-2 space-y-2 pointer-events-none">
                <div id="stream-progress" class="flex gap-[3px]"></div>
                <div class="flex items-center justify-between text-white pointer-events-auto gap-2">
                    <div class="flex items-center gap-2 min-w-0">
                        <div id="stream-viewer-avatar" class="w-8 h-8 rounded-full bg-[#efefef] text-[#262626] font-black text-[10px] flex items-center justify-center flex-shrink-0 ring-[1.5px] ring-white"></div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span id="stream-viewer-name" class="text-[13px] font-semibold truncate"></span>
                                <span id="stream-live-badge" class="hidden text-[9px] font-black uppercase bg-red-500 px-1.5 py-0.5 rounded animate-pulse">Live</span>
                            </div>
                            <span id="stream-viewer-title" class="text-[11px] text-white/70 truncate block"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" id="stream-mute-btn" onclick="toggleStreamMute()" class="text-white w-8 h-8 flex items-center justify-center" aria-label="Mute">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>
                        </button>
                        <button type="button" class="sm:hidden text-white text-xl w-8 h-8" onclick="closeStreamViewer()">✕</button>
                    </div>
                </div>
            </div>

            <div class="absolute inset-0 bg-black">
                <video id="stream-video" class="absolute inset-0 w-full h-full object-cover" playsinline webkit-playsinline></video>
                <iframe id="stream-iframe" class="hidden absolute inset-0 w-full h-full" src="" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                <div id="stream-live-panel" class="hidden absolute inset-0 z-[15] flex flex-col items-center justify-center live-v2-waiting text-white p-6 text-center">
                    <span class="live-v2-live-badge mb-5"><i></i> LIVE</span>
                    <div id="stream-live-avatar" class="live-v2-waiting-avatar"></div>
                    <p id="stream-live-host" class="font-display font-bold text-lg mt-3 drop-shadow"></p>
                    <p id="stream-live-hint" class="text-xs text-white/70 mt-2 max-w-[220px]"><?= htmlspecialchars(t('home.live_hint')) ?></p>
                    <video id="stream-live-cam" class="hidden absolute inset-0 w-full h-full object-cover z-[16]" playsinline webkit-playsinline autoplay></video>
                    <audio id="stream-live-audio" autoplay playsinline></audio>
                </div>
            </div>

            <!-- LIVE SHOP OVERLAY v2 — face-safe dock + right rail -->
            <div id="live-shop-ui" class="hidden absolute inset-0 z-[35] flex flex-col pointer-events-none text-white">
                <div class="live-v2-scrim live-v2-scrim--top pointer-events-none" aria-hidden="true"></div>
                <div class="live-v2-scrim live-v2-scrim--bottom pointer-events-none" aria-hidden="true"></div>

                <!-- TOP -->
                <header class="live-v2-top pointer-events-auto relative z-[2]">
                    <div class="live-v2-host">
                        <div id="live-shop-avatar" class="live-v2-avatar"></div>
                        <div class="live-v2-host-meta">
                            <div class="live-v2-host-row">
                                <span id="live-shop-name" class="live-v2-host-name"></span>
                                <svg class="live-v2-star" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 14.8 7.2 17l.9-5.4L4.2 7.7l5.4-.8L12 2z"/></svg>
                                <button type="button" id="live-shop-follow" class="hidden live-v2-follow"><?= htmlspecialchars(t('live.subscribe')) ?></button>
                            </div>
                            <p id="live-shop-followers" class="live-v2-followers">—</p>
                        </div>
                        <div class="live-v2-live-block">
                            <span class="live-v2-live-badge"><i></i> LIVE</span>
                            <span id="live-shop-timer" class="live-v2-timer">00:00:00</span>
                        </div>
                    </div>
                    <div class="live-v2-top-actions">
                        <span class="live-v2-chip" title="viewers">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>
                            <span id="live-shop-viewers">0</span>
                        </span>
                        <button type="button" onclick="toggleStreamMute()" id="live-shop-mute" class="live-v2-icon-round" aria-label="Mute">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                        </button>
                        <button type="button" onclick="closeStreamViewer()" class="live-v2-icon-round" aria-label="Close">✕</button>
                    </div>
                    <div id="live-shop-top-support" class="live-v2-support">
                        <span class="live-v2-support-label"><?= htmlspecialchars(t('live.top_support')) ?></span>
                        <span id="live-shop-top-list" class="live-v2-support-list"><?= htmlspecialchars(t('live.top_empty')) ?></span>
                    </div>
                </header>

                <!-- STAGE -->
                <div class="live-v2-stage relative z-[2] flex-1 min-h-0">
                    <div class="live-v2-chat pointer-events-none">
                        <div id="live-shop-comments" class="live-shop-comments"></div>
                        <div id="live-shop-purchase-toast" class="hidden live-shop-toast"></div>
                    </div>

                    <div class="live-v2-deal-col">
                        <div id="live-shop-giveaway" class="hidden live-v2-give pointer-events-auto">
                            <div class="live-v2-give-head">
                                <span class="live-v2-give-tag"><?= htmlspecialchars(t('live.giveaway')) ?></span>
                                <span class="live-v2-give-count"><span id="live-shop-give-count">0</span> <?= htmlspecialchars(t('live.participants')) ?></span>
                            </div>
                            <p id="live-shop-give-title" class="live-v2-give-title"><?= htmlspecialchars(t('live.giveaway_title')) ?></p>
                            <div class="live-v2-give-track"><div id="live-shop-give-bar" style="width:0%"></div></div>
                            <div class="live-v2-give-foot">
                                <span><span id="live-shop-give-prog">0</span> / <span id="live-shop-give-goal">500</span> ♥</span>
                                <button type="button" id="live-shop-give-btn" onclick="joinLiveGiveaway()" class="live-v2-give-btn"><?= htmlspecialchars(t('live.participate')) ?></button>
                            </div>
                        </div>
                    </div>

                    <aside class="live-v2-rail pointer-events-auto">
                        <div id="live-shop-hearts" class="live-shop-hearts" aria-hidden="true"></div>
                        <button type="button" onclick="sendLiveHeart()" class="live-v2-rail-btn live-v2-rail-btn--heart" title="Like">
                            <span class="live-v2-rail-ico">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                            </span>
                            <span id="live-shop-likes" class="live-v2-rail-count">0</span>
                            <span id="live-shop-hearts-total" class="sr-only">0</span>
                        </button>
                        <button type="button" id="live-shop-products-btn" onclick="toggleLiveShopShelf()" class="live-v2-rail-btn" title="<?= htmlspecialchars(t('live.products_in_stream')) ?>">
                            <span class="live-v2-rail-ico">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                                <span id="live-shop-products-badge" class="live-v2-rail-badge hidden">0</span>
                            </span>
                            <span class="live-v2-rail-label"><?= htmlspecialchars(t('live.shop')) ?></span>
                        </button>
                        <a href="<?= ProductHelper::url('/cart') ?>" class="live-v2-rail-btn" title="<?= htmlspecialchars(t('nav.cart')) ?>">
                            <span class="live-v2-rail-ico">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
                                <span id="live-shop-cart-badge" class="live-v2-rail-badge hidden">0</span>
                            </span>
                            <span class="live-v2-rail-label"><?= htmlspecialchars(t('nav.cart')) ?></span>
                        </a>
                        <button type="button" onclick="liveShopShare()" class="live-v2-rail-btn" title="<?= htmlspecialchars(t('live.share')) ?>">
                            <span class="live-v2-rail-ico">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
                            </span>
                            <span class="live-v2-rail-label"><?= htmlspecialchars(t('live.share')) ?></span>
                        </button>
                        <button type="button" onclick="liveShopAsk()" class="live-v2-rail-btn" title="<?= htmlspecialchars(t('live.question')) ?>">
                            <span class="live-v2-rail-ico">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </span>
                            <span class="live-v2-rail-label"><?= htmlspecialchars(t('live.question')) ?></span>
                        </button>
                    </aside>
                </div>

                <!-- DOCK -->
                <footer class="live-v2-dock pointer-events-auto relative z-[2]">
                    <div id="live-shop-featured" class="hidden live-v2-deal" onclick="openLiveProductFromFeatured()">
                        <div id="live-shop-feat-img" class="live-v2-deal-img bg-cover bg-center"></div>
                        <div class="live-v2-deal-body">
                            <span class="live-v2-deal-tag"><?= htmlspecialchars(t('live.product_of_day')) ?></span>
                            <p id="live-shop-feat-title" class="live-v2-deal-title"></p>
                            <p class="live-v2-deal-price">
                                <span id="live-shop-feat-price"></span>
                                <span id="live-shop-feat-old" class="hidden"></span>
                            </p>
                            <div id="live-shop-feat-stock-wrap" class="hidden live-v2-deal-stock">
                                <span><?= htmlspecialchars(t('live.left')) ?>: <span id="live-shop-feat-stock">0</span></span>
                                <div class="live-v2-deal-stock-track"><div id="live-shop-feat-stock-bar" style="width:40%"></div></div>
                            </div>
                        </div>
                        <button type="button" id="live-shop-feat-buy" class="live-v2-deal-buy" onclick="event.stopPropagation(); openLiveProductFromFeatured()">
                            <span><?= htmlspecialchars(t('live.buy_short')) ?></span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </div>
                    <div id="live-shop-shelf-wrap" class="live-v2-shelf hidden">
                        <div class="live-v2-shelf-head">
                            <p><?= htmlspecialchars(t('live.products_in_stream')) ?> <span id="live-shop-shelf-count">0</span></p>
                            <button type="button" id="live-shop-see-all" onclick="toggleLiveShopShelf(false)" class="live-v2-shelf-close"><?= htmlspecialchars(t('live.hide_products')) ?></button>
                        </div>
                        <div id="live-shop-shelf" class="live-shop-shelf"></div>
                    </div>
                    <div class="live-v2-composer">
                        <form id="live-shop-comment-form" class="live-v2-input" onsubmit="return sendLiveComment(event)">
                            <input id="live-shop-comment-input" type="text" maxlength="280" autocomplete="off" placeholder="<?= htmlspecialchars(t('live.comment_placeholder')) ?>">
                        </form>
                        <button type="button" id="stream-end-live-btn" onclick="endLiveStream()" class="hidden live-v2-end" title="<?= htmlspecialchars(t('home.end_live')) ?>">
                            <span class="live-v2-end-full"><?= htmlspecialchars(t('home.end_live')) ?></span>
                            <span class="live-v2-end-short"><?= htmlspecialchars(t('live.end_short')) ?></span>
                        </button>
                    </div>
                </footer>
            </div>

            <button type="button" id="stream-live-unmute" onclick="event.stopPropagation(); unmuteLiveStream()" class="hidden absolute left-1/2 -translate-x-1/2 z-[45] bg-white text-gray-900 text-sm font-bold px-5 py-3 rounded-full shadow-lg pointer-events-auto">
                <?= htmlspecialchars(t('home.unmute_live')) ?>
            </button>

            <!-- Товар без ухода из эфира -->
            <div id="live-product-sheet" class="hidden absolute inset-0 z-[50] flex flex-col justify-end pointer-events-auto">
                <button type="button" class="absolute inset-0 bg-black/55 border-0 cursor-pointer backdrop-blur-[2px]" onclick="closeLiveProductSheet()" aria-label="Close"></button>
                <div class="relative live-v2-sheet max-h-[72%] overflow-y-auto">
                    <div class="live-v2-sheet-handle"></div>
                    <div class="live-v2-sheet-row">
                        <div id="live-sheet-img" class="live-v2-sheet-img bg-cover bg-center"></div>
                        <div class="min-w-0 flex-1">
                            <p id="live-sheet-title" class="live-v2-sheet-title"></p>
                            <p id="live-sheet-price" class="live-v2-sheet-price"></p>
                        </div>
                    </div>
                    <p class="live-v2-sheet-hint"><?= htmlspecialchars(t('live.stay_in_stream_hint')) ?></p>
                    <div class="live-v2-sheet-actions">
                        <button type="button" id="live-sheet-cart" onclick="liveSheetAddToCart()" class="live-v2-sheet-secondary"><?= htmlspecialchars(t('live.add_cart')) ?></button>
                        <button type="button" id="live-sheet-buy" onclick="liveSheetBuyNow()" class="live-v2-sheet-primary"><?= htmlspecialchars(t('live.buy_now')) ?></button>
                    </div>
                    <button type="button" onclick="closeLiveProductSheet()" class="live-v2-sheet-back"><?= htmlspecialchars(t('live.back_to_stream')) ?></button>
                    <p id="live-sheet-status" class="hidden live-v2-sheet-status"></p>
                </div>
            </div>
            <p id="stream-viewer-desc" class="absolute bottom-16 left-3 right-3 z-20 text-white text-sm font-semibold drop-shadow-md line-clamp-3"></p>
            <div class="absolute inset-y-0 left-0 w-[18%] z-20" id="stream-tap-prev"></div>
            <div class="absolute inset-y-0 right-0 w-[18%] z-20" id="stream-tap-next"></div>
            <div class="absolute inset-0 z-10" id="stream-hold-zone"></div>
            <div id="stream-delete-wrap" class="hidden absolute bottom-5 left-0 right-0 z-30 flex justify-center">
                <form id="stream-delete-form" method="post" action="">
                    <button type="submit" class="bg-black/50 hover:bg-red-600 text-white text-xs font-semibold px-4 py-2 rounded-full" onclick="return confirm(<?= json_encode(t('home.confirm_close_stream')) ?>)"><?= htmlspecialchars(t('home.close_stream')) ?></button>
                </form>
            </div>
            <div id="stream-paused" class="hidden absolute inset-0 z-[25] flex items-center justify-center pointer-events-none">
                <span class="w-14 h-14 rounded-full bg-black/40 backdrop-blur flex items-center justify-center text-white">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                </span>
            </div>
        </div>
        <button type="button" id="stream-nav-next" class="story-nav-btn" onclick="event.stopPropagation(); nextStream()" aria-label="Next">›</button>
    </div>
</div>

<script>
<?php
$storyGroupsForJs = array_map(static function ($g) {
    $g['avatar_url'] = AvatarHelper::url([
        'avatar_file' => $g['user_avatar_file'] ?? null,
    ]);
    $product = $g['product'] ?? null;
    if ($product) {
        $g['product'] = [
            'id' => (int) $product['id'],
            'title' => $product['title'],
            'price' => ProductHelper::formatPrice($product),
            'url' => ProductHelper::url('/product/' . (int) $product['id']),
            'image' => ProductHelper::imageUrl($product),
        ];
    } else {
        $g['product'] = null;
    }
    return $g;
}, $storyGroups);

$streamsForJs = array_map(static function ($s) {
    $avatar = $s['author_avatar'] ?? '';
    if ($avatar === '' && !empty($s['author_name'])) {
        $avatar = mb_strtoupper(mb_substr($s['author_name'], 0, 1));
    }
    return [
        'id' => (int) $s['id'],
        'user_id' => (int) $s['user_id'],
        'title' => $s['title'],
        'description' => $s['description'],
        'author_name' => $s['author_name'] ?? '',
        'author_avatar' => $avatar,
        'is_live' => true,
    ];
}, $streams);
?>
window.__storyGroups = <?= js_encode($storyGroupsForJs) ?>;
window.__storyUploadBase = <?= js_encode(ProductHelper::url('public/uploads/stories/')) ?>;
window.__storyDeleteBase = <?= js_encode(ProductHelper::url('/stories/')) ?>;
window.__streams = <?= js_encode($streamsForJs) ?>;
window.__streamDeleteBase = <?= js_encode(ProductHelper::url('/streams/')) ?>;
window.__streamLiveStart = <?= js_encode(ProductHelper::url('/streams/live/start')) ?>;
window.__streamLiveMyProducts = <?= js_encode(ProductHelper::url('/streams/live/my-products')) ?>;
window.__streamCoverBase = <?= js_encode(ProductHelper::url('public/uploads/streams/')) ?>;
window.__streamLiveHeartbeat = <?= js_encode(ProductHelper::url('/streams/live/heartbeat')) ?>;
window.__streamLiveEnd = <?= js_encode(ProductHelper::url('/streams/live/end')) ?>;
window.__streamLiveSignal = <?= js_encode(ProductHelper::url('/streams/live/signal')) ?>;
window.__streamLiveSignalPoll = <?= js_encode(ProductHelper::url('/streams/live/signal/poll')) ?>;
window.__streamLiveShop = <?= js_encode(ProductHelper::url('/streams/live/shop')) ?>;
window.__streamLiveComments = <?= js_encode(ProductHelper::url('/streams/live/comments')) ?>;
window.__streamLiveComment = <?= js_encode(ProductHelper::url('/streams/live/comment')) ?>;
window.__streamLiveLike = <?= js_encode(ProductHelper::url('/streams/live/like')) ?>;
window.__streamLiveFeature = <?= js_encode(ProductHelper::url('/streams/live/feature')) ?>;
window.__currentUserId = <?= (int) (Auth::id() ?? 0) ?>;
window.__isAdmin = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
window.__whatsNew = <?= js_encode($changelog) ?>;

(function resumeLiveFromHash() {
    function tryOpen() {
        const m = (location.hash || '').match(/resume-live=(\d+)/);
        if (!m) return;
        const id = Number(m[1]);
        const streams = window.__streams || [];
        const idx = streams.findIndex(function (s) { return Number(s.id) === id; });
        if (idx >= 0 && typeof openStreamViewer === 'function') {
            history.replaceState(null, '', location.pathname + location.search);
            openStreamViewer(idx);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryOpen);
    } else {
        setTimeout(tryOpen, 50);
    }
})();
</script>
