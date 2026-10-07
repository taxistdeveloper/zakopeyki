<?php
use App\Helpers\ProductHelper;
?>
<!-- STORY VIEWER — Instagram web -->
<div id="story-viewer" class="hidden fixed inset-0 z-[110] story-viewer-shell" onclick="if(event.target===this||event.target.classList.contains('story-stage'))closeStoryViewer()">
    <a href="<?= ProductHelper::url('/') ?>" class="story-brand" onclick="event.stopPropagation()">
        <img src="<?= ProductHelper::url('/public/assets/img/logo-dark.png') ?>" alt="Zakopeyki" width="160" height="51">
    </a>
    <button type="button" class="story-close-outer" onclick="closeStoryViewer()" aria-label="Close">✕</button>
    <div class="story-stage">
        <button type="button" id="story-nav-prev" class="story-nav-btn" onclick="event.stopPropagation(); prevStory()" aria-label="Previous">↑</button>
        <div class="story-frame" onclick="event.stopPropagation()">
            <div class="absolute inset-0" id="story-slide">
                <div id="story-bg" class="absolute inset-0 story-text-bg"></div>
                <img id="story-image" src="" alt="" class="hidden absolute inset-0 w-full h-full object-cover">
                <video id="story-video" class="hidden absolute inset-0 w-full h-full object-cover" playsinline preload="auto"></video>
                <div class="absolute inset-0 story-vignette z-[1]"></div>
                <div id="story-emoji" class="absolute inset-0 z-[2] flex flex-col items-center justify-center px-8 pointer-events-none">
                    <span id="story-emoji-icon" class="leading-none select-none"></span>
                    <p id="story-caption-center" class="hidden"></p>
                </div>
            </div>

            <div class="absolute top-0 left-0 right-0 z-20 pt-3 px-3 pb-2 space-y-2 pointer-events-none">
                <div id="story-progress" class="flex gap-[3px]"></div>
                <div class="flex items-center gap-2 text-white px-0.5 pointer-events-auto min-w-0">
                    <div id="story-viewer-avatar"></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <span id="story-viewer-name" class="text-[13px] font-semibold drop-shadow"></span>
                            <button type="button" id="story-follow-btn" class="hidden story-follow-btn"><?= htmlspecialchars(t('live.subscribe')) ?></button>
                        </div>
                        <span id="story-viewer-time" class="text-[12px] text-white/65"></span>
                    </div>
                    <button type="button" class="sm:hidden flex-shrink-0 w-8 h-8 text-white text-xl" onclick="closeStoryViewer()" aria-label="Close">✕</button>
                </div>
            </div>

            <div class="story-footer">
                <p id="story-caption-overlay" class="story-caption-overlay hidden"></p>
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
                    <button type="button" id="story-message-btn" class="story-action-btn" title="<?= htmlspecialchars(t('home.story_message')) ?>" aria-label="<?= htmlspecialchars(t('home.story_message')) ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                    </button>
                    <button type="button" id="story-share-btn" class="story-action-btn" title="<?= htmlspecialchars(t('home.story_share')) ?>" aria-label="<?= htmlspecialchars(t('home.story_share')) ?>">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98"/></svg>
                    </button>
                    <button type="button" id="story-favorite-btn" class="story-action-btn favorite-btn hidden" title="<?= htmlspecialchars(t('card.favorite')) ?>" aria-label="<?= htmlspecialchars(t('card.favorite')) ?>" data-product-id="">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </button>
                </div>
                <div id="story-delete-wrap" class="hidden flex justify-center">
                    <form id="story-delete-form" method="post" action="">
                        <button type="submit" class="bg-black/50 hover:bg-red-600 text-white text-[11px] font-semibold px-3.5 py-1.5 rounded-full" onclick="return confirm(<?= json_encode(t('home.confirm_delete_story')) ?>)"><?= htmlspecialchars(t('home.delete_story')) ?></button>
                    </form>
                </div>
            </div>
        </div>
        <button type="button" id="story-nav-next" class="story-nav-btn" onclick="event.stopPropagation(); nextStory()" aria-label="Next">↓</button>
    </div>
</div>
