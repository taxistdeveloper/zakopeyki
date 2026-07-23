<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;

if (!Auth::check()) {
    return;
}
?>
<div id="chat-drawer-root" class="fixed inset-0 z-[95] pointer-events-none" aria-hidden="true">
    <div id="chat-drawer-overlay" class="absolute inset-0 bg-ink-900/40 backdrop-blur-sm opacity-0 transition-opacity duration-300 pointer-events-none"></div>
    <aside id="chat-drawer"
           class="absolute right-0 top-0 h-full w-full max-w-[420px] bg-white dark:bg-ink-900 border-l border-black/[0.06] dark:border-white/10 shadow-lift flex flex-col translate-x-full transition-transform duration-300 ease-out pointer-events-auto"
           role="dialog"
           aria-modal="true"
           aria-label="<?= htmlspecialchars(t('chat.title')) ?>">
        <div class="flex items-center gap-3 px-4 py-3.5 border-b border-black/[0.06] dark:border-white/10 shrink-0">
            <div class="min-w-0 flex-1">
                <p id="chat-drawer-peer" class="font-display font-bold text-sm text-ink-900 dark:text-white truncate"><?= htmlspecialchars(t('chat.title')) ?></p>
                <p id="chat-drawer-product" class="text-[11px] text-brand-600 dark:text-brand-400 truncate hidden"></p>
            </div>
            <button type="button" id="chat-drawer-close" class="w-9 h-9 rounded-xl hover:bg-black/[0.05] dark:hover:bg-white/10 text-gray-500 hover:text-ink-900 dark:hover:text-white transition" aria-label="<?= htmlspecialchars(t('chat.close')) ?>">✕</button>
        </div>

        <div id="chat-drawer-messages" class="flex-1 overflow-y-auto p-4 space-y-2.5 select-text">
            <p id="chat-drawer-empty" class="text-center text-sm text-gray-400 py-12"><?= htmlspecialchars(t('chat.start_hint')) ?></p>
        </div>

        <form id="chat-drawer-form" class="p-3 border-t border-black/[0.06] dark:border-white/10 flex gap-2 shrink-0">
            <textarea id="chat-drawer-input" rows="1" maxlength="2000" placeholder="<?= htmlspecialchars(t('chat.placeholder')) ?>"
                      class="ui-input flex-1 min-h-[44px] max-h-28 px-3.5 py-2.5 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm resize-none"></textarea>
            <button type="submit" class="h-11 px-4 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider transition flex-shrink-0">
                <?= htmlspecialchars(t('chat.send')) ?>
            </button>
        </form>
    </aside>
</div>
