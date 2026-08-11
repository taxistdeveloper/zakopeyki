<?php
use App\Core\Auth;
use App\Helpers\AvatarHelper;
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;

$me = Auth::user() ?? [];
$myAvatar = AvatarHelper::url($me);
$myName = (string) ($me['name'] ?? t('js.you'));
$myInitial = AvatarHelper::initial($me);
?>
<!-- CREATE STORY — публикация в стиле live-setup / shorts -->
<div id="story-create-modal" class="hidden fixed inset-0 z-[60] live-setup-shell" role="dialog" aria-modal="true" aria-labelledby="story-create-heading">
    <div class="live-setup-panel">
        <header class="live-setup-top">
            <button type="button" class="live-setup-back" onclick="closeStoryCreate()" aria-label="<?= htmlspecialchars(t('home.live_setup_back')) ?>">
                <span aria-hidden="true">‹</span> <?= htmlspecialchars(t('home.live_setup_back')) ?>
            </button>
            <span class="live-setup-logo">za<span>kopeyki</span>.kz</span>
            <button type="button" class="live-setup-preview-btn" onclick="previewStoryCreate()">
                <?= IconHelper::svg('eye', 'w-4 h-4') ?>
                <?= htmlspecialchars(t('home.live_setup_preview')) ?>
            </button>
        </header>

        <form id="story-create-form" method="post" action="<?= ProductHelper::url('/stories') ?>" enctype="multipart/form-data" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="notify_subs" id="story-create-notify-input" value="1">
            <div class="live-setup-scroll">
                <div class="live-setup-profile">
                    <div class="live-setup-avatar">
                        <?php if ($myAvatar): ?>
                            <img src="<?= htmlspecialchars($myAvatar) ?>" alt="">
                        <?php else: ?>
                            <span><?= htmlspecialchars($myInitial) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="live-setup-profile-meta min-w-0 flex-1">
                        <div class="live-setup-name-row">
                            <span class="live-setup-name"><?= htmlspecialchars($myName) ?></span>
                            <svg class="live-setup-verified" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <p class="live-setup-subs"><?= htmlspecialchars(t('home.story_create_subs')) ?></p>
                    </div>
                    <label class="live-setup-cover-btn" for="story-create-image">
                        <?= IconHelper::svg('camera', 'w-3.5 h-3.5') ?>
                        <?= htmlspecialchars(t('home.story_create_change_media')) ?>
                    </label>
                </div>

                <div class="live-setup-title-row">
                    <div class="min-w-0">
                        <h2 id="story-create-heading" class="live-setup-heading">
                            <span class="live-setup-live-badge">STORIES</span>
                            <?= htmlspecialchars(t('home.story_create_title')) ?>
                        </h2>
                        <p class="live-setup-lead"><?= htmlspecialchars(t('home.story_create_lead')) ?></p>
                    </div>
                    <button type="button" class="live-setup-draft-btn" onclick="saveStoryCreateDraft()">
                        <?= IconHelper::svg('file', 'w-3.5 h-3.5') ?>
                        <?= htmlspecialchars(t('home.live_setup_draft')) ?>
                    </button>
                </div>

                <!-- Фото / медиа -->
                <section class="live-setup-card">
                    <div class="live-setup-card-head">
                        <h3><?= htmlspecialchars(t('home.story_create_media')) ?></h3>
                        <span class="live-setup-tag is-req"><?= htmlspecialchars(t('home.live_setup_required')) ?></span>
                    </div>
                    <p class="live-setup-card-hint"><?= htmlspecialchars(t('home.story_create_media_hint')) ?></p>
                    <input type="file" id="story-create-image" name="image" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" onchange="onStoryCreateImageChange(event)">
                    <label id="story-create-upload-zone" for="story-create-image" class="story-create-upload">
                        <span class="story-create-upload-icon" aria-hidden="true">
                            <?= IconHelper::svg('upload', 'w-7 h-7') ?>
                        </span>
                        <span class="story-create-upload-link"><?= htmlspecialchars(t('home.story_create_upload')) ?></span>
                        <span class="story-create-upload-meta"><?= htmlspecialchars(t('home.story_create_media_meta')) ?></span>
                    </label>
                    <div id="story-create-media-preview" class="hidden story-create-media-preview">
                        <img id="story-create-media-img" src="" alt="">
                        <button type="button" class="live-setup-cover-clear" onclick="clearStoryCreateImage()" aria-label="✕">✕</button>
                    </div>
                </section>

                <!-- Заголовок -->
                <section class="live-setup-card">
                    <div class="live-setup-card-head">
                        <h3><?= htmlspecialchars(t('home.story_create_caption_label')) ?></h3>
                        <span class="live-setup-tag is-req"><?= htmlspecialchars(t('home.live_setup_required')) ?></span>
                    </div>
                    <div class="story-create-field-wrap">
                        <input type="text" id="story-create-caption" name="caption" maxlength="280" placeholder="<?= htmlspecialchars(t('home.story_create_caption_ph')) ?>" class="story-create-input" oninput="updateStoryCreateCounters()">
                        <span id="story-create-caption-count" class="story-create-counter">0/280</span>
                    </div>
                </section>

                <!-- Описание -->
                <section class="live-setup-card">
                    <div class="live-setup-card-head">
                        <h3><?= htmlspecialchars(t('home.story_create_desc_label')) ?></h3>
                        <span class="live-setup-tag"><?= htmlspecialchars(t('home.live_setup_optional')) ?></span>
                    </div>
                    <div class="story-create-field-wrap">
                        <textarea id="story-create-desc" rows="3" maxlength="200" placeholder="<?= htmlspecialchars(t('home.story_create_desc_ph')) ?>" class="story-create-input story-create-textarea" oninput="updateStoryCreateCounters()"></textarea>
                        <span id="story-create-desc-count" class="story-create-counter">0/200</span>
                    </div>
                </section>

                <!-- Товар -->
                <section class="live-setup-card">
                    <div class="live-setup-card-head">
                        <h3><?= htmlspecialchars(t('home.story_create_product')) ?></h3>
                        <span class="live-setup-tag"><?= htmlspecialchars(t('home.live_setup_optional')) ?></span>
                    </div>
                    <p class="live-setup-card-hint"><?= htmlspecialchars(t('home.story_create_product_hint')) ?></p>
                    <div class="story-create-product-note">
                        <?= IconHelper::svg('bag', 'w-4 h-4 flex-shrink-0') ?>
                        <span><?= htmlspecialchars(t('home.story_create_product_auto')) ?></span>
                    </div>
                </section>

                <!-- Настройки -->
                <section class="live-setup-card">
                    <div class="live-setup-card-head" style="margin-bottom:10px">
                        <h3><?= htmlspecialchars(t('home.story_create_settings')) ?></h3>
                    </div>
                    <div class="live-setup-settings-grid">
                        <label class="live-setup-field">
                            <span class="live-setup-field-label">
                                <?= IconHelper::svg('globe', 'w-3.5 h-3.5') ?>
                                <?= htmlspecialchars(t('home.story_create_who')) ?>
                            </span>
                            <select class="live-setup-select" disabled>
                                <option selected><?= htmlspecialchars(t('home.live_setup_who_all')) ?></option>
                            </select>
                        </label>
                        <label class="live-setup-field">
                            <span class="live-setup-field-label">
                                <?= IconHelper::svg('message', 'w-3.5 h-3.5') ?>
                                <?= htmlspecialchars(t('home.story_create_comments')) ?>
                            </span>
                            <select class="live-setup-select" disabled>
                                <option selected><?= htmlspecialchars(t('home.live_setup_chat_on')) ?></option>
                            </select>
                        </label>
                        <label class="live-setup-field">
                            <span class="live-setup-field-label">
                                <?= IconHelper::svg('eye', 'w-3.5 h-3.5') ?>
                                <?= htmlspecialchars(t('home.story_create_visibility')) ?>
                            </span>
                            <select class="live-setup-select" disabled>
                                <option selected><?= htmlspecialchars(t('home.story_create_public')) ?></option>
                            </select>
                        </label>
                    </div>
                    <div class="live-setup-notify">
                        <div class="live-setup-notify-icon text-[#7c3aed]" aria-hidden="true"><?= IconHelper::svg('bell', 'w-4 h-4') ?></div>
                        <div class="min-w-0 flex-1">
                            <p class="live-setup-notify-title"><?= htmlspecialchars(t('home.story_create_notify')) ?></p>
                            <p class="live-setup-notify-sub"><?= htmlspecialchars(t('home.story_ttl')) ?></p>
                        </div>
                        <button type="button" id="story-create-notify-toggle" class="live-setup-toggle is-on" role="switch" aria-checked="true" onclick="toggleStoryCreateNotify()"></button>
                    </div>
                </section>
            </div>

            <footer class="live-setup-footer">
                <button type="submit" class="live-setup-start" onclick="return prepareStoryCreateSubmit()">
                    <?= IconHelper::svg('upload', 'w-[18px] h-[18px]') ?>
                    <?= htmlspecialchars(t('home.story_create_publish')) ?>
                </button>
                <p class="live-setup-warn"><?= htmlspecialchars(t('home.story_create_publish_hint')) ?></p>
                <p class="live-setup-tip inline-flex items-center justify-center gap-1.5">
                    <?= IconHelper::svg('info', 'w-3.5 h-3.5') ?>
                    <?= htmlspecialchars(t('home.story_create_rules')) ?>
                </p>
            </footer>
        </form>
    </div>
</div>

<!-- Предпросмотр истории -->
<div id="story-create-preview" class="hidden fixed inset-0 z-[61] flex items-center justify-center bg-ink-900/70 backdrop-blur-sm p-4" onclick="if(event.target===this)closeStoryCreatePreview()">
    <div class="story-create-preview-card" onclick="event.stopPropagation()">
        <div class="story-create-preview-frame" id="story-create-preview-frame">
            <div id="story-create-preview-bg" class="absolute inset-0"></div>
            <img id="story-create-preview-img" src="" alt="" class="hidden absolute inset-0 w-full h-full object-cover">
            <div class="absolute inset-0 flex flex-col items-end justify-end px-5 pb-6 text-left z-[1] bg-gradient-to-t from-black/55 via-transparent to-transparent">
                <p id="story-create-preview-text" class="w-full text-white text-sm font-semibold drop-shadow leading-snug"></p>
            </div>
        </div>
        <button type="button" onclick="closeStoryCreatePreview()" class="mt-3 w-full text-[12px] font-semibold text-white/80 hover:text-white py-2"><?= htmlspecialchars(t('home.close_stream')) ?></button>
    </div>
</div>
