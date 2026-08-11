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
<!-- LIVE SETUP — настройка стрима перед стартом -->
<div id="live-setup-modal" class="hidden fixed inset-0 z-[74] live-setup-shell" role="dialog" aria-modal="true" aria-labelledby="live-setup-heading">
    <div class="live-setup-panel">
        <header class="live-setup-top">
            <button type="button" class="live-setup-back" onclick="closeLiveSetup()" aria-label="<?= htmlspecialchars(t('home.live_setup_back')) ?>">
                <span aria-hidden="true">‹</span> <?= htmlspecialchars(t('home.live_setup_back')) ?>
            </button>
            <span class="live-setup-logo">za<span>kopeyki</span>.kz</span>
            <button type="button" class="live-setup-preview-btn" onclick="openLiveStartPreviewFromSetup()">
                <?= IconHelper::svg('eye', 'w-4 h-4') ?>
                <?= htmlspecialchars(t('home.live_setup_preview')) ?>
            </button>
        </header>

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
                        <span id="live-setup-host-name" class="live-setup-name"><?= htmlspecialchars($myName) ?></span>
                        <svg class="live-setup-verified" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <p class="live-setup-subs" id="live-setup-subs"><?= htmlspecialchars(t('home.live_setup_subs')) ?></p>
                </div>
                <label class="live-setup-cover-btn">
                    <input type="file" id="live-setup-cover-input" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="onLiveSetupCoverChange(event)">
                    <?= IconHelper::svg('camera', 'w-3.5 h-3.5') ?>
                    <?= htmlspecialchars(t('home.live_setup_cover')) ?>
                </label>
            </div>
            <div id="live-setup-cover-preview" class="hidden live-setup-cover-preview">
                <img id="live-setup-cover-img" src="" alt="">
                <button type="button" onclick="clearLiveSetupCover()" class="live-setup-cover-clear">✕</button>
            </div>

            <div class="live-setup-title-row">
                <div class="min-w-0">
                    <h2 id="live-setup-heading" class="live-setup-heading">
                        <span class="live-setup-live-badge">LIVE</span>
                        <?= htmlspecialchars(t('home.live_setup_title')) ?>
                    </h2>
                    <p class="live-setup-lead"><?= htmlspecialchars(t('home.live_setup_lead')) ?></p>
                </div>
                <button type="button" class="live-setup-draft-btn" onclick="saveLiveSetupDraft()">
                    <?= IconHelper::svg('file', 'w-3.5 h-3.5') ?>
                    <?= htmlspecialchars(t('home.live_setup_draft')) ?>
                </button>
            </div>

            <!-- Товары -->
            <section class="live-setup-card">
                <div class="live-setup-card-head">
                    <h3><?= htmlspecialchars(t('home.live_setup_products')) ?></h3>
                    <span class="live-setup-tag is-req"><?= htmlspecialchars(t('home.live_setup_required')) ?></span>
                </div>
                <p class="live-setup-card-hint"><?= htmlspecialchars(t('home.live_setup_products_hint')) ?></p>
                <button type="button" class="live-setup-dashed inline-flex items-center justify-center gap-1.5" onclick="openLiveProductPicker('products')">
                    <?= IconHelper::svg('plus', 'w-4 h-4') ?>
                    <?= htmlspecialchars(t('home.live_setup_add_product')) ?>
                </button>
                <div id="live-setup-products-list" class="live-setup-product-list"></div>
            </section>

            <!-- Товар дня -->
            <section class="live-setup-card">
                <div class="live-setup-card-head">
                    <h3><?= htmlspecialchars(t('home.live_setup_pod')) ?></h3>
                    <span class="live-setup-tag"><?= htmlspecialchars(t('home.live_setup_optional')) ?></span>
                </div>
                <p class="live-setup-card-hint"><?= htmlspecialchars(t('home.live_setup_pod_hint')) ?></p>
                <div id="live-setup-pod-empty">
                    <button type="button" class="live-setup-dashed inline-flex items-center justify-center gap-1.5" onclick="openLiveProductPicker('pod')">
                        <?= IconHelper::svg('plus', 'w-4 h-4') ?>
                        <?= htmlspecialchars(t('home.live_setup_add_pod')) ?>
                    </button>
                </div>
                <div id="live-setup-pod-card" class="hidden"></div>
            </section>

            <!-- Розыгрыш -->
            <section class="live-setup-card">
                <div class="live-setup-card-head">
                    <h3><?= htmlspecialchars(t('home.live_setup_giveaway')) ?></h3>
                    <span class="live-setup-tag"><?= htmlspecialchars(t('home.live_setup_optional')) ?></span>
                </div>
                <p class="live-setup-card-hint"><?= htmlspecialchars(t('home.live_setup_giveaway_hint')) ?></p>
                <div id="live-setup-give-empty">
                    <button type="button" class="live-setup-dashed inline-flex items-center justify-center gap-1.5" onclick="openLiveGiveawayEditor()">
                        <?= IconHelper::svg('gift', 'w-4 h-4') ?>
                        <?= htmlspecialchars(t('home.live_setup_add_giveaway')) ?>
                    </button>
                </div>
                <div id="live-setup-give-card" class="hidden live-setup-give-card"></div>
            </section>

            <!-- Настройки -->
            <section class="live-setup-card">
                <div class="live-setup-settings-grid">
                    <label class="live-setup-field">
                        <span class="live-setup-field-label">
                            <?= IconHelper::svg('clock', 'w-3.5 h-3.5') ?>
                            <?= htmlspecialchars(t('home.live_setup_duration')) ?>
                        </span>
                        <select id="live-setup-duration" class="live-setup-select" onchange="syncLiveSetupFromForm()">
                            <option value="1800">00:30:00</option>
                            <option value="3600">01:00:00</option>
                            <option value="7200" selected>02:00:00</option>
                            <option value="10800">03:00:00</option>
                            <option value="14400">04:00:00</option>
                        </select>
                    </label>
                    <label class="live-setup-field">
                        <span class="live-setup-field-label">
                            <?= IconHelper::svg('users', 'w-3.5 h-3.5') ?>
                            <?= htmlspecialchars(t('home.live_setup_who')) ?>
                        </span>
                        <select id="live-setup-visibility" class="live-setup-select" onchange="syncLiveSetupFromForm()">
                            <option value="all"><?= htmlspecialchars(t('home.live_setup_who_all')) ?></option>
                            <option value="followers"><?= htmlspecialchars(t('home.live_setup_who_followers')) ?></option>
                        </select>
                    </label>
                    <label class="live-setup-field">
                        <span class="live-setup-field-label">
                            <?= IconHelper::svg('message', 'w-3.5 h-3.5') ?>
                            <?= htmlspecialchars(t('home.live_setup_chat')) ?>
                        </span>
                        <select id="live-setup-chat" class="live-setup-select" onchange="syncLiveSetupFromForm()">
                            <option value="1"><?= htmlspecialchars(t('home.live_setup_chat_on')) ?></option>
                            <option value="0"><?= htmlspecialchars(t('home.live_setup_chat_off')) ?></option>
                        </select>
                    </label>
                </div>
                <div class="live-setup-notify">
                    <div class="live-setup-notify-icon text-[#7c3aed]" aria-hidden="true"><?= IconHelper::svg('bell', 'w-4 h-4') ?></div>
                    <div class="min-w-0 flex-1">
                        <p class="live-setup-notify-title"><?= htmlspecialchars(t('home.live_setup_notify')) ?></p>
                        <p class="live-setup-notify-sub"><?= htmlspecialchars(t('home.live_setup_notify_sub')) ?></p>
                    </div>
                    <button type="button" id="live-setup-notify-toggle" class="live-setup-toggle is-on" role="switch" aria-checked="true" onclick="toggleLiveSetupNotify()"></button>
                </div>
            </section>
        </div>

        <footer class="live-setup-footer">
            <button type="button" id="live-setup-start-btn" class="live-setup-start" onclick="submitLiveSetupStart()">
                <?= IconHelper::svg('mic', 'w-[18px] h-[18px]') ?>
                <span class="live-btn-label"><?= htmlspecialchars(t('home.start_stream')) ?></span>
            </button>
            <p class="live-setup-warn"><?= htmlspecialchars(t('home.live_setup_lock_hint')) ?></p>
            <p class="live-setup-tip inline-flex items-center justify-center gap-1.5">
                <?= IconHelper::svg('info', 'w-3.5 h-3.5') ?>
                <?= htmlspecialchars(t('home.live_setup_net_hint')) ?>
            </p>
        </footer>
    </div>
</div>

<!-- Picker товаров -->
<div id="live-product-picker" class="hidden fixed inset-0 z-[76] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" onclick="if(event.target===this)closeLiveProductPicker()">
    <div class="bg-white dark:bg-ink-800 w-full max-w-md rounded-t-[24px] sm:rounded-[24px] overflow-hidden shadow-lift max-h-[85vh] flex flex-col" onclick="event.stopPropagation()">
        <div class="p-4 border-b border-black/[0.06] dark:border-white/10 flex items-center justify-between gap-3">
            <h3 id="live-picker-title" class="font-display font-bold text-sm"><?= htmlspecialchars(t('home.live_setup_add_product')) ?></h3>
            <button type="button" onclick="closeLiveProductPicker()" class="w-8 h-8 rounded-xl text-gray-400 hover:bg-black/5">✕</button>
        </div>
        <div id="live-picker-list" class="flex-1 overflow-y-auto p-3 space-y-2"></div>
        <div id="live-picker-pod-price" class="hidden px-4 pb-2">
            <label class="block text-[12px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.live_setup_special_price')) ?></label>
            <input type="number" id="live-picker-price-input" min="0" step="1" class="ui-input w-full border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 h-11 px-3 rounded-xl text-sm" placeholder="₸">
        </div>
        <div class="p-4 border-t border-black/[0.06] dark:border-white/10">
            <button type="button" id="live-picker-confirm" onclick="confirmLiveProductPicker()" class="w-full bg-[#7c3aed] hover:bg-[#6d28d9] text-white font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('home.live_setup_apply')) ?></button>
        </div>
    </div>
</div>

<!-- Редактор розыгрыша -->
<div id="live-giveaway-editor" class="hidden fixed inset-0 z-[76] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" onclick="if(event.target===this)closeLiveGiveawayEditor()">
    <div class="bg-white dark:bg-ink-800 w-full max-w-md rounded-t-[24px] sm:rounded-[24px] overflow-hidden shadow-lift" onclick="event.stopPropagation()">
        <div class="p-4 border-b border-black/[0.06] dark:border-white/10 flex items-center justify-between gap-3">
            <h3 class="font-display font-bold text-sm"><?= htmlspecialchars(t('home.live_setup_add_giveaway')) ?></h3>
            <button type="button" onclick="closeLiveGiveawayEditor()" class="w-8 h-8 rounded-xl text-gray-400 hover:bg-black/5">✕</button>
        </div>
        <div class="p-4 space-y-3">
            <div>
                <label class="block text-[12px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.live_setup_giveaway_title_label')) ?></label>
                <input type="text" id="live-give-title-input" maxlength="120" class="ui-input w-full border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 h-11 px-3 rounded-xl text-sm" placeholder="<?= htmlspecialchars(t('live.giveaway_title')) ?>">
            </div>
            <div>
                <label class="block text-[12px] font-semibold mb-1.5"><?= htmlspecialchars(t('home.live_setup_giveaway_goal')) ?></label>
                <input type="number" id="live-give-goal-input" min="50" max="5000" value="500" class="ui-input w-full border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 h-11 px-3 rounded-xl text-sm">
            </div>
            <button type="button" onclick="confirmLiveGiveawayEditor()" class="w-full bg-[#7c3aed] hover:bg-[#6d28d9] text-white font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('home.live_setup_apply')) ?></button>
            <button type="button" onclick="clearLiveGiveaway()" class="w-full text-[12px] font-semibold text-gray-400 py-1"><?= htmlspecialchars(t('home.live_setup_remove_giveaway')) ?></button>
        </div>
    </div>
</div>
