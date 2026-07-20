<?php
use App\Helpers\ProductHelper;
use App\Helpers\AvatarHelper;
use App\Core\View;
use App\Core\Auth;

$storyGroups = $storyGroups ?? [];
$streams = $streams ?? [];
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
                    <span class="absolute -bottom-0.5 -right-0.5 w-5 h-5 rounded-full bg-brand-500 text-ink-900 text-xs font-bold flex items-center justify-center border-2 border-[#f7f5f1] dark:border-ink-900">+</span>
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
                <div class="w-[58px] h-[58px] rounded-full p-[2.5px] bg-gradient-to-tr from-brand-400 via-orange-400 to-brand-600 shadow-soft">
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

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2.5 sm:gap-3">
        <?php
        $cats = [
            ['url' => '/catalog/used', 'icon' => '📦', 'label' => t('home.cat_used'), 'tone' => 'from-amber-50 to-orange-50'],
            ['url' => '/auctions', 'icon' => '🔥', 'label' => t('home.cat_auctions'), 'tone' => 'from-red-50 to-orange-50'],
            ['url' => '/catalog/free', 'icon' => '🎁', 'label' => t('home.cat_free'), 'tone' => 'from-violet-50 to-fuchsia-50'],
            ['url' => '/catalog/exchange', 'icon' => '🔄', 'label' => t('home.cat_exchange'), 'tone' => 'from-sky-50 to-indigo-50'],
            ['url' => '/catalog/services', 'icon' => '💼', 'label' => t('home.cat_services'), 'tone' => 'from-emerald-50 to-teal-50'],
            ['url' => '/catalog/new', 'icon' => '🛍️', 'label' => t('home.cat_new'), 'tone' => 'from-brand-50 to-yellow-50'],
        ];
        foreach ($cats as $c): ?>
            <a href="<?= ProductHelper::url($c['url']) ?>" class="group bg-gradient-to-br <?= $c['tone'] ?> dark:from-white/[0.06] dark:to-white/[0.02] p-3.5 sm:p-4 rounded-2xl border border-black/[0.05] dark:border-white/10 text-center hover:border-brand-400/50 hover:shadow-soft hover:-translate-y-0.5 transition duration-300 block">
                <span class="text-2xl block mb-1.5 transition group-hover:scale-110"><?= $c['icon'] ?></span>
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

<!-- CREATE STORY MODAL -->
<?php if (Auth::check()): ?>
<div id="story-create-modal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-ink-900/55 backdrop-blur-sm p-4">
    <div class="bg-white dark:bg-ink-800 w-full max-w-md rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10">
        <div class="p-4 sm:p-5 border-b border-black/[0.06] dark:border-white/10 flex justify-between items-center">
            <h3 class="font-display font-bold text-sm"><?= htmlspecialchars(t('home.new_story')) ?></h3>
            <button type="button" onclick="closeStoryCreate()" class="w-8 h-8 rounded-xl text-gray-400 hover:bg-black/5 hover:text-ink-800 transition">✕</button>
        </div>
        <form method="post" action="<?= ProductHelper::url('/stories') ?>" enctype="multipart/form-data" class="p-5 sm:p-6 space-y-4">
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.story_text')) ?></label>
                <textarea name="caption" rows="3" maxlength="280" placeholder="<?= htmlspecialchars(t('home.story_placeholder')) ?>" class="ui-input w-full border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 p-3 rounded-xl text-sm"></textarea>
            </div>
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.story_photo')) ?></label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" class="w-full text-xs file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-brand-500 file:text-ink-900 file:font-bold file:text-xs">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.story_emoji')) ?></label>
                    <input type="text" name="emoji" value="✨" maxlength="4" class="ui-input w-full border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 h-11 px-3 rounded-xl text-sm text-center text-xl">
                </div>
                <div>
                    <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.story_color')) ?></label>
                    <input type="color" name="bg_color" value="#f5a524" class="w-full h-11 rounded-xl border border-black/10 dark:border-white/10 cursor-pointer bg-transparent">
                </div>
            </div>
            <p class="text-[10px] text-gray-400"><?= htmlspecialchars(t('home.story_ttl')) ?></p>
            <button type="submit" class="w-full bg-brand-500 hover:bg-brand-400 text-ink-900 font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('home.publish')) ?></button>
        </form>
    </div>
</div>
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
                    <div id="story-product-ph" class="story-product-ph">🛍</div>
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

<!-- STREAM VIEWER — Instagram web -->
<div id="stream-viewer" class="hidden fixed inset-0 z-[70] story-viewer-shell" onclick="if(event.target===this||event.target.classList.contains('story-stage'))closeStreamViewer()">
    <a href="<?= ProductHelper::url('/') ?>" class="story-brand" onclick="event.stopPropagation()">za<span>kopeyki</span>.kz</a>
    <button type="button" class="story-close-outer" onclick="closeStreamViewer()" aria-label="Close">✕</button>
    <div class="story-stage">
        <button type="button" id="stream-nav-prev" class="story-nav-btn" onclick="event.stopPropagation(); prevStream()" aria-label="Previous">‹</button>
        <div class="story-frame" onclick="event.stopPropagation()">
            <div class="absolute top-0 left-0 right-0 z-30 pt-3 px-3 pb-2 space-y-2 pointer-events-none">
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
                        <button type="button" id="stream-mute-btn" onclick="toggleStreamMute()" class="text-white text-base w-8 h-8">🔊</button>
                        <button type="button" class="sm:hidden text-white text-xl w-8 h-8" onclick="closeStreamViewer()">✕</button>
                    </div>
                </div>
            </div>
            <div class="absolute inset-0 bg-black">
                <video id="stream-video" class="absolute inset-0 w-full h-full object-cover" playsinline webkit-playsinline></video>
                <iframe id="stream-iframe" class="hidden absolute inset-0 w-full h-full" src="" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
                <div id="stream-live-panel" class="hidden absolute inset-0 z-[15] flex flex-col items-center justify-center bg-gradient-to-br from-red-700 via-orange-700 to-gray-900 text-white p-6 text-center">
                    <span class="text-[10px] font-black uppercase bg-red-500 px-2 py-1 rounded animate-pulse mb-4">● Live</span>
                    <div id="stream-live-avatar" class="w-20 h-20 rounded-full bg-white/20 flex items-center justify-center text-3xl font-black mb-3"></div>
                    <p id="stream-live-host" class="font-bold text-lg"></p>
                    <p class="text-xs text-white/70 mt-2 max-w-[220px]"><?= htmlspecialchars(t('home.live_hint')) ?></p>
                    <video id="stream-live-cam" class="hidden absolute inset-0 w-full h-full object-cover" playsinline muted autoplay></video>
                    <button type="button" id="stream-end-live-btn" onclick="endLiveStream()" class="hidden mt-6 relative z-20 bg-white text-red-600 font-black text-xs uppercase px-5 py-2.5 rounded-full"><?= htmlspecialchars(t('home.end_live')) ?></button>
                </div>
            </div>
            <p id="stream-viewer-desc" class="absolute bottom-16 left-3 right-3 z-20 text-white text-sm font-semibold drop-shadow-md line-clamp-3"></p>
            <div class="absolute inset-y-0 left-0 w-[28%] z-20" id="stream-tap-prev"></div>
            <div class="absolute inset-y-0 right-0 w-[28%] z-20" id="stream-tap-next"></div>
            <div class="absolute inset-0 z-10" id="stream-hold-zone"></div>
            <div id="stream-delete-wrap" class="hidden absolute bottom-5 left-0 right-0 z-30 flex justify-center">
                <form id="stream-delete-form" method="post" action="">
                    <button type="submit" class="bg-black/50 hover:bg-red-600 text-white text-xs font-semibold px-4 py-2 rounded-full" onclick="return confirm(<?= json_encode(t('home.confirm_close_stream')) ?>)"><?= htmlspecialchars(t('home.close_stream')) ?></button>
                </form>
            </div>
            <div id="stream-paused" class="hidden absolute inset-0 z-[25] flex items-center justify-center pointer-events-none">
                <span class="w-14 h-14 rounded-full bg-black/40 backdrop-blur flex items-center justify-center text-white text-2xl">▶</span>
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
window.__storyGroups = <?= json_encode($storyGroupsForJs, JSON_UNESCAPED_UNICODE) ?>;
window.__storyUploadBase = <?= json_encode(ProductHelper::url('public/uploads/stories/')) ?>;
window.__storyDeleteBase = <?= json_encode(ProductHelper::url('/stories/')) ?>;
window.__streams = <?= json_encode($streamsForJs, JSON_UNESCAPED_UNICODE) ?>;
window.__streamDeleteBase = <?= json_encode(ProductHelper::url('/streams/')) ?>;
window.__streamLiveStart = <?= json_encode(ProductHelper::url('/streams/live/start')) ?>;
window.__streamLiveHeartbeat = <?= json_encode(ProductHelper::url('/streams/live/heartbeat')) ?>;
window.__streamLiveEnd = <?= json_encode(ProductHelper::url('/streams/live/end')) ?>;
window.__currentUserId = <?= (int) (Auth::id() ?? 0) ?>;
window.__isAdmin = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
</script>
