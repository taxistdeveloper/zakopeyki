<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;
use App\Models\Wallet;

$microCategories = $microCategories ?? [];
$walletBalance = (int) ($walletBalance ?? 0);
$walletHeld = (int) ($walletHeld ?? 0);
$flash = $flash ?? null;
$error = $error ?? null;
$loggedIn = Auth::check();
$loginUrl = ProductHelper::url('/login');
$apiBase = ProductHelper::url('/api/v1/micro-tasks');
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<div id="gigs-board" class="space-y-5" data-api="<?= htmlspecialchars($apiBase) ?>" data-login="<?= htmlspecialchars($loginUrl) ?>" data-auth="<?= $loggedIn ? '1' : '0' ?>">
    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 shadow-soft">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-gray-500"><?= htmlspecialchars(t('gigs.wallet')) ?>:</span>
            <strong class="text-brand-600" id="gigs-balance"><?= htmlspecialchars(Wallet::formatMoney($walletBalance)) ?></strong>
            <?php if ($walletHeld > 0): ?>
                <span class="text-xs text-amber-700 dark:text-amber-300"><?= htmlspecialchars(t('gigs.held', ['amount' => Wallet::formatMoney($walletHeld)])) ?></span>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <div class="relative sm:w-64" data-gigs-select-wrap>
                <select id="gigs-category" class="hidden">
                    <option value="0"><?= htmlspecialchars(t('gigs.all_categories')) ?></option>
                    <?php foreach ($microCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" data-gigs-select-trigger class="<?= $input ?> sm:w-64 h-10 flex items-center justify-between gap-2 text-left pr-3 cursor-pointer" aria-haspopup="listbox" aria-expanded="false">
                    <span data-gigs-select-label class="truncate"><?= htmlspecialchars(t('gigs.all_categories')) ?></span>
                    <svg class="w-4 h-4 text-gray-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div data-gigs-select-menu class="hidden absolute right-0 left-0 z-30 mt-1.5 max-h-64 overflow-y-auto bg-white dark:bg-ink-800 border border-black/[0.08] dark:border-white/10 rounded-2xl shadow-lift py-1.5" role="listbox"></div>
            </div>
            <button type="button" id="gigs-refresh" class="bg-ink-100 dark:bg-white/10 hover:bg-ink-200 text-ink-800 dark:text-white font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl transition"><?= htmlspecialchars(t('gigs.refresh')) ?></button>
            <?php if ($loggedIn): ?>
                <a href="<?= ProductHelper::url('/profile?tab=lots&type=gig') ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl transition"><?= htmlspecialchars(t('gigs.publish')) ?></a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl transition"><?= htmlspecialchars(t('gigs.publish')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div id="gigs-mine" class="hidden space-y-2"></div>
    <div id="gigs-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
</div>

<div id="gigs-detail-modal" class="hidden fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" role="dialog" aria-modal="true">
    <div class="gigs-modal-panel w-full sm:max-w-lg bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10 relative max-h-[92vh] flex flex-col">
        <div class="sm:hidden flex justify-center pt-3 pb-1" aria-hidden="true"><span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span></div>
        <button type="button" class="gigs-modal-close absolute top-4 right-4 z-10 w-9 h-9 rounded-xl bg-black/[0.06] dark:bg-white/10 text-gray-500 hover:text-ink-800" aria-label="<?= htmlspecialchars(t('gigs.modal_close')) ?>">✕</button>
        <div id="gigs-detail-photos" class="hidden relative bg-ink-100 dark:bg-white/5"></div>
        <div class="p-5 overflow-y-auto space-y-3">
            <div class="flex flex-wrap items-center gap-2 pr-10">
                <span id="gigs-detail-category" class="text-[10px] font-bold uppercase tracking-wider bg-ink-100 dark:bg-white/10 px-2 py-1 rounded-lg"></span>
                <span id="gigs-detail-price" class="ml-auto font-display font-bold text-emerald-600"></span>
            </div>
            <h3 id="gigs-detail-title" class="font-display font-bold text-xl text-ink-900 dark:text-white"></h3>
            <p id="gigs-detail-desc" class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed whitespace-pre-wrap"></p>
            <p id="gigs-detail-address" class="text-sm text-gray-500 dark:text-gray-400"></p>
            <p id="gigs-detail-expires" class="text-xs text-gray-400"></p>
            <div id="gigs-detail-instant" class="text-xs font-semibold text-violet-700 dark:text-violet-300 bg-violet-50 dark:bg-violet-500/10 rounded-lg p-2.5 text-center"></div>
            <button type="button" id="gigs-detail-respond" class="w-full bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider py-3.5 rounded-2xl shadow-soft"><?= htmlspecialchars(t('gigs.respond')) ?></button>
        </div>
    </div>
</div>

<div id="gigs-offer-modal" class="hidden fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" role="dialog" aria-modal="true">
    <div class="gigs-modal-panel w-full sm:max-w-lg bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10 p-5 relative max-h-[92vh] overflow-y-auto">
        <div class="sm:hidden flex justify-center pb-2" aria-hidden="true"><span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span></div>
        <button type="button" class="gigs-modal-close absolute top-4 right-4 w-9 h-9 rounded-xl bg-black/[0.04] dark:bg-white/10 text-gray-500 hover:text-ink-800" aria-label="<?= htmlspecialchars(t('gigs.modal_close')) ?>">✕</button>
        <h3 id="gigs-modal-title" class="font-display font-bold text-xl pr-10 text-ink-900 dark:text-white"></h3>
        <p id="gigs-modal-desc" class="text-sm text-gray-500 dark:text-gray-400 mt-2 leading-relaxed"></p>
        <p class="mt-4 text-sm text-gray-500"><?= htmlspecialchars(t('gigs.budget')) ?>: <strong id="gigs-modal-price" class="text-ink-900 dark:text-white"></strong></p>
        <div class="grid grid-cols-2 gap-2.5 mt-4">
            <label class="rounded-2xl border border-black/[0.08] dark:border-white/10 p-3 cursor-pointer transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-500/10 has-[:checked]:shadow-soft">
                <input type="radio" name="gigs_bargain" value="accept" class="sr-only" checked>
                <span class="block text-xs font-bold text-ink-800 dark:text-gray-100"><?= htmlspecialchars(t('gigs.opt_accept')) ?></span>
                <span class="text-sm font-display font-bold text-brand-600" id="gigs-price-accept"></span>
            </label>
            <label class="rounded-2xl border border-violet-200/80 dark:border-violet-500/30 p-3 cursor-pointer transition has-[:checked]:border-violet-600 has-[:checked]:bg-violet-50 dark:has-[:checked]:bg-violet-500/10 has-[:checked]:shadow-soft">
                <input type="radio" name="gigs_bargain" value="discount_20" class="sr-only">
                <span class="inline-block text-[10px] uppercase font-bold bg-violet-600 text-white px-1.5 py-0.5 rounded-md mb-1"><?= htmlspecialchars(t('gigs.instant_badge')) ?></span>
                <span class="block text-xs font-bold text-ink-800 dark:text-gray-100"><?= htmlspecialchars(t('gigs.opt_discount')) ?></span>
                <span class="text-sm font-display font-bold text-violet-700 dark:text-violet-300" id="gigs-price-discount"></span>
            </label>
            <label class="rounded-2xl border border-black/[0.08] dark:border-white/10 p-3 cursor-pointer transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-500/10">
                <input type="radio" name="gigs_bargain" value="raise_20" class="sr-only">
                <span class="block text-xs font-bold"><?= htmlspecialchars(t('gigs.opt_raise')) ?></span>
                <span class="text-sm font-display font-bold" id="gigs-price-raise"></span>
            </label>
            <label class="rounded-2xl border border-black/[0.08] dark:border-white/10 p-3 cursor-pointer transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-500/10">
                <input type="radio" name="gigs_bargain" value="custom" class="sr-only">
                <span class="block text-xs font-bold"><?= htmlspecialchars(t('gigs.opt_custom')) ?></span>
                <input type="number" id="gigs-custom-price" class="<?= $input ?> h-9 mt-1" min="100" step="50" disabled>
            </label>
        </div>
        <div class="mt-4 rounded-2xl bg-ink-50 dark:bg-white/5 p-3.5 text-sm space-y-1.5">
            <div class="flex justify-between text-gray-500"><span><?= htmlspecialchars(t('gigs.fee_hold')) ?></span><span>50 ₸</span></div>
            <div class="flex justify-between text-gray-500"><span><?= htmlspecialchars(t('gigs.fee_platform')) ?></span><span id="gigs-calc-fee">0 ₸</span></div>
            <div class="flex justify-between font-bold text-ink-900 dark:text-white pt-1.5 border-t border-black/[0.06] dark:border-white/10"><span><?= htmlspecialchars(t('gigs.net_payout')) ?></span><span id="gigs-calc-net">0 ₸</span></div>
        </div>
        <button type="button" id="gigs-submit-offer" class="mt-4 w-full bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider py-3.5 rounded-2xl shadow-soft"><?= htmlspecialchars(t('gigs.send_offer')) ?></button>
    </div>
</div>

<div id="gigs-pin-modal" class="hidden fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" role="dialog" aria-modal="true">
    <div class="gigs-modal-panel w-full sm:max-w-sm bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10 p-5 relative">
        <div class="sm:hidden flex justify-center pb-2" aria-hidden="true"><span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span></div>
        <button type="button" class="gigs-modal-close absolute top-4 right-4 w-9 h-9 rounded-xl bg-black/[0.04] dark:bg-white/10 text-gray-500">✕</button>
        <div class="mx-auto w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-500/15 flex items-center justify-center text-emerald-600 mb-3">
            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <h3 class="font-display font-bold text-xl text-center"><?= htmlspecialchars(t('gigs.complete_title')) ?></h3>
        <p class="text-sm text-gray-500 mt-2 text-center"><?= htmlspecialchars(t('gigs.complete_hint')) ?></p>
        <input type="text" id="gigs-pin-input" maxlength="4" inputmode="numeric" class="<?= $input ?> mt-4 text-center text-2xl tracking-[0.4em]" placeholder="0000">
        <button type="button" id="gigs-submit-pin" class="mt-4 w-full bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider py-3.5 rounded-2xl"><?= htmlspecialchars(t('gigs.complete_btn')) ?></button>
    </div>
</div>

<div id="gigs-confirm-modal" class="hidden fixed inset-0 z-[90] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4" role="dialog" aria-modal="true">
    <div class="gigs-modal-panel w-full sm:max-w-md bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10">
        <div class="sm:hidden flex justify-center pt-3" aria-hidden="true"><span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span></div>
        <div class="px-5 pt-5 pb-2 text-center space-y-3">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-500/15 flex items-center justify-center text-amber-600">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>
            </div>
            <h3 id="gigs-confirm-title" class="font-display text-xl font-bold text-ink-900 dark:text-white"></h3>
            <p id="gigs-confirm-body" class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed"></p>
        </div>
        <div class="p-5 pt-3 grid grid-cols-1 sm:grid-cols-2 gap-2.5">
            <button type="button" id="gigs-confirm-no" class="order-2 sm:order-1 py-3 rounded-2xl border border-black/10 dark:border-white/15 text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('gigs.modal_back')) ?></button>
            <button type="button" id="gigs-confirm-yes" class="order-1 sm:order-2 py-3 rounded-2xl bg-ink-900 hover:bg-ink-800 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('gigs.confirm_yes')) ?></button>
        </div>
    </div>
</div>

<div id="gigs-toast" class="hidden fixed bottom-5 left-1/2 -translate-x-1/2 z-[95] max-w-[92vw] sm:max-w-md px-4 py-3 rounded-2xl shadow-lift text-sm font-semibold text-white"></div>

<script>
(function () {
    const board = document.getElementById('gigs-board');
    if (!board) return;
    const api = board.dataset.api;
    const loginUrl = board.dataset.login;
    const isAuth = board.dataset.auth === '1';
    const grid = document.getElementById('gigs-grid');
    const mineEl = document.getElementById('gigs-mine');
    const categorySelect = document.getElementById('gigs-category');
    const detailModal = document.getElementById('gigs-detail-modal');
    const offerModal = document.getElementById('gigs-offer-modal');
    const pinModal = document.getElementById('gigs-pin-modal');
    const confirmModal = document.getElementById('gigs-confirm-modal');
    const toastEl = document.getElementById('gigs-toast');
    const customInput = document.getElementById('gigs-custom-price');
    let tasks = [];
    let selected = null;
    let bargain = 'accept';
    let pinTaskId = null;
    let confirmResolver = null;
    let toastTimer = null;

    function money(n) {
        return new Intl.NumberFormat('ru-RU').format(Math.round(Number(n) || 0)) + ' ₸';
    }
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }
    function closeModals() {
        detailModal.classList.add('hidden');
        offerModal.classList.add('hidden');
        pinModal.classList.add('hidden');
    }
    function showToast(message, isError) {
        if (!toastEl || !message) return;
        toastEl.textContent = message;
        toastEl.className = 'fixed bottom-5 left-1/2 -translate-x-1/2 z-[95] max-w-[92vw] sm:max-w-md px-4 py-3 rounded-2xl shadow-lift text-sm font-semibold text-white ' +
            (isError ? 'bg-red-600' : 'bg-ink-900');
        toastEl.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.add('hidden'); }, 4200);
    }
    function askConfirm(title, body) {
        return new Promise(function (resolve) {
            document.getElementById('gigs-confirm-title').textContent = title;
            document.getElementById('gigs-confirm-body').textContent = body;
            confirmResolver = resolve;
            confirmModal.classList.remove('hidden');
        });
    }
    document.getElementById('gigs-confirm-yes').addEventListener('click', function () {
        confirmModal.classList.add('hidden');
        if (confirmResolver) confirmResolver(true);
        confirmResolver = null;
    });
    document.getElementById('gigs-confirm-no').addEventListener('click', function () {
        confirmModal.classList.add('hidden');
        if (confirmResolver) confirmResolver(false);
        confirmResolver = null;
    });
    confirmModal.addEventListener('click', function (e) {
        if (e.target === confirmModal) {
            confirmModal.classList.add('hidden');
            if (confirmResolver) confirmResolver(false);
            confirmResolver = null;
        }
    });
    document.querySelectorAll('.gigs-modal-close').forEach(function (btn) {
        btn.addEventListener('click', closeModals);
    });
    [detailModal, offerModal, pinModal].forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModals();
        });
    });

    (function bindGigsSelect() {
        const wrap = document.querySelector('[data-gigs-select-wrap]');
        if (!wrap || !categorySelect) return;
        const btn = wrap.querySelector('[data-gigs-select-trigger]');
        const menu = wrap.querySelector('[data-gigs-select-menu]');
        const labelEl = wrap.querySelector('[data-gigs-select-label]');
        if (!btn || !menu || !labelEl) return;
        function renderMenu() {
            const opt = categorySelect.options[categorySelect.selectedIndex];
            labelEl.textContent = opt ? opt.textContent : '';
            menu.innerHTML = '';
            Array.from(categorySelect.options).forEach(function (option) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'w-full flex items-center gap-2 px-3.5 py-2.5 text-sm text-left transition ' +
                    (option.selected
                        ? 'bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 font-semibold'
                        : 'text-ink-800 dark:text-gray-200 hover:bg-black/[0.04] dark:hover:bg-white/5');
                item.textContent = option.textContent;
                item.addEventListener('click', function () {
                    categorySelect.value = option.value;
                    categorySelect.dispatchEvent(new Event('change'));
                    menu.classList.add('hidden');
                    btn.setAttribute('aria-expanded', 'false');
                    renderMenu();
                });
                menu.appendChild(item);
            });
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const open = menu.classList.contains('hidden');
            menu.classList.toggle('hidden', !open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) {
                menu.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
        categorySelect.addEventListener('change', renderMenu);
        renderMenu();
    })();

    async function loadTasks() {
        const cat = categorySelect.value;
        const url = api + '/list' + (Number(cat) > 0 ? ('?category_id=' + encodeURIComponent(cat)) : '');
        grid.innerHTML = '<p class="text-sm text-gray-400"><?= htmlspecialchars(t('gigs.loading'), ENT_QUOTES) ?></p>';
        try {
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            tasks = (json.success && json.data && json.data.tasks) ? json.data.tasks : [];
            renderTasks();
        } catch (e) {
            grid.innerHTML = '<p class="text-sm text-red-500"><?= htmlspecialchars(t('gigs.err_network'), ENT_QUOTES) ?></p>';
        }
    }

    async function loadMine() {
        if (!isAuth) return;
        try {
            const res = await fetch(api + '/mine', { headers: { 'Accept': 'application/json' } });
            const json = await res.json();
            if (!json.success || !json.data.tasks.length) {
                mineEl.classList.add('hidden');
                mineEl.innerHTML = '';
                return;
            }
            mineEl.classList.remove('hidden');
            mineEl.innerHTML = '<h3 class="font-display font-bold text-sm"><?= htmlspecialchars(t('gigs.my_tasks'), ENT_QUOTES) ?></h3>' +
                json.data.tasks.map(function (t) {
                    const pin = t.completion_pin ? (' · PIN ' + escapeHtml(t.completion_pin)) : '';
                    const completeBtn = (t.role === 'executor' && (t.status === 'locked' || t.status === 'in_progress'))
                        ? '<button type="button" class="text-xs font-bold text-emerald-600" data-pin-task="' + t.id + '"><?= htmlspecialchars(t('gigs.enter_pin'), ENT_QUOTES) ?></button>'
                        : '';
                    const cancelBtn = t.can_cancel
                        ? '<button type="button" class="text-xs font-bold text-red-600" data-cancel-task="' + t.id + '"><?= htmlspecialchars(t('gigs.cancel_btn'), ENT_QUOTES) ?></button>'
                        : '';
                    const deleteBtn = t.can_delete
                        ? '<button type="button" class="text-xs font-bold text-gray-500 hover:text-red-600" data-delete-task="' + t.id + '"><?= htmlspecialchars(t('gigs.delete_btn'), ENT_QUOTES) ?></button>'
                        : '';
                    const offers = (t.offers || []).map(function (o) {
                        return '<button type="button" class="text-xs bg-brand-600 text-white px-2 py-1 rounded-lg" data-select-offer="' + o.id + '"><?= htmlspecialchars(t('gigs.accept_offer'), ENT_QUOTES) ?> ' + money(o.proposed_price) + '</button>';
                    }).join(' ');
                    return '<div class="rounded-xl border border-black/10 dark:border-white/10 p-3 text-sm flex flex-wrap items-center justify-between gap-2">' +
                        '<span><strong>' + escapeHtml(t.title) + '</strong> · ' + escapeHtml(t.status) + pin + '</span>' +
                        '<span class="flex flex-wrap gap-2">' + offers + completeBtn + cancelBtn + deleteBtn + '</span></div>';
                }).join('');
            mineEl.querySelectorAll('[data-pin-task]').forEach(function (btn) {
                btn.addEventListener('click', function () { openPin(Number(btn.dataset.pinTask)); });
            });
            mineEl.querySelectorAll('[data-select-offer]').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const res = await fetch(api + '/offers/' + btn.dataset.selectOffer + '/select', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: '{}'
                    });
                    const json = await res.json();
                    showToast(json.success ? json.data.message : json.error, !json.success);
                    loadTasks();
                    loadMine();
                });
            });
            mineEl.querySelectorAll('[data-cancel-task]').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const ok = await askConfirm('<?= htmlspecialchars(t('gigs.cancel_btn'), ENT_QUOTES) ?>', '<?= htmlspecialchars(t('gigs.cancel_confirm'), ENT_QUOTES) ?>');
                    if (!ok) return;
                    const res = await fetch(api + '/' + btn.dataset.cancelTask + '/cancel', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: '{}'
                    });
                    const json = await res.json();
                    showToast(json.success ? json.data.message : json.error, !json.success);
                    loadTasks();
                    loadMine();
                });
            });
            mineEl.querySelectorAll('[data-delete-task]').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    const ok = await askConfirm('<?= htmlspecialchars(t('gigs.delete_btn'), ENT_QUOTES) ?>', '<?= htmlspecialchars(t('gigs.delete_confirm'), ENT_QUOTES) ?>');
                    if (!ok) return;
                    const res = await fetch(api + '/' + btn.dataset.deleteTask + '/delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: '{}'
                    });
                    const json = await res.json();
                    showToast(json.success ? json.data.message : json.error, !json.success);
                    loadTasks();
                    loadMine();
                });
            });
        } catch (e) {}
    }

    function renderTasks() {
        if (!tasks.length) {
            grid.innerHTML = '<p class="text-sm text-gray-400 col-span-full"><?= htmlspecialchars(t('gigs.empty'), ENT_QUOTES) ?></p>';
            return;
        }
        grid.innerHTML = tasks.map(function (task) {
            const d = task.pricing.bargain_options.discount_20.price;
            const imgs = Array.isArray(task.images) ? task.images : [];
            const photo = imgs.length
                ? '<div class="aspect-[4/3] rounded-xl overflow-hidden bg-ink-100 dark:bg-white/10">' +
                    imgs.map(function (src, i) {
                        return '<img src="' + escapeHtml(src) + '" alt="" class="' + (i === 0 ? 'w-full h-full object-cover' : 'hidden') + '">';
                    }).join('') +
                  '</div>'
                : '';
            const desc = String(task.description || '').trim();
            const descHtml = desc
                ? '<p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2">' + escapeHtml(desc) + '</p>'
                : '';
            return '<article class="bg-white dark:bg-white/[0.04] rounded-2xl border border-black/[0.06] dark:border-white/10 p-4 flex flex-col gap-3 shadow-soft cursor-pointer hover:border-brand-400/50 hover:shadow-lift transition" data-detail="' + task.id + '">' +
                photo +
                '<div class="flex justify-between gap-2"><span class="text-[10px] font-bold uppercase tracking-wider bg-ink-100 dark:bg-white/10 px-2 py-1 rounded-lg">' + escapeHtml(task.category.name) + '</span>' +
                '<span class="font-display font-bold text-emerald-600">' + money(task.pricing.initial_price) + '</span></div>' +
                '<h3 class="font-display font-bold text-ink-900 dark:text-white">' + escapeHtml(task.title || task.category.name) + '</h3>' +
                descHtml +
                '<p class="text-xs text-gray-500">' + escapeHtml(task.address || '') + '</p>' +
                '<div class="text-xs font-semibold text-violet-700 dark:text-violet-300 bg-violet-50 dark:bg-violet-500/10 rounded-lg p-2 text-center"><?= htmlspecialchars(t('gigs.instant_banner'), ENT_QUOTES) ?> ' + money(d) + '</div>' +
                '<span class="text-xs font-semibold text-brand-600"><?= htmlspecialchars(t('gigs.details'), ENT_QUOTES) ?></span>' +
                '<button type="button" class="mt-auto bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider py-2.5 rounded-xl" data-offer="' + task.id + '"><?= htmlspecialchars(t('gigs.respond'), ENT_QUOTES) ?></button>' +
                '</article>';
        }).join('');
        grid.querySelectorAll('[data-detail]').forEach(function (card) {
            card.addEventListener('click', function () { openDetail(Number(card.dataset.detail)); });
        });
        grid.querySelectorAll('[data-offer]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                openOffer(Number(btn.dataset.offer));
            });
        });
    }

    function formatExpires(iso) {
        if (!iso) return '';
        const d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        return '<?= htmlspecialchars(t('gigs.expires'), ENT_QUOTES) ?>: ' + d.toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    }

    function openDetail(id) {
        const task = tasks.find(function (t) { return t.id === id; });
        if (!task) return;
        selected = task;
        document.getElementById('gigs-detail-category').textContent = task.category.name || '';
        document.getElementById('gigs-detail-price').textContent = money(task.pricing.initial_price);
        document.getElementById('gigs-detail-title').textContent = task.title || task.category.name || '';
        document.getElementById('gigs-detail-desc').textContent = task.description || '';
        document.getElementById('gigs-detail-address').textContent = task.address || '';
        document.getElementById('gigs-detail-expires').textContent = formatExpires(task.expires_at);
        document.getElementById('gigs-detail-instant').textContent =
            '<?= htmlspecialchars(t('gigs.instant_banner'), ENT_QUOTES) ?> ' + money(task.pricing.bargain_options.discount_20.price);
        const photosEl = document.getElementById('gigs-detail-photos');
        const imgs = Array.isArray(task.images) ? task.images : [];
        if (!imgs.length) {
            photosEl.classList.add('hidden');
            photosEl.innerHTML = '';
        } else {
            photosEl.classList.remove('hidden');
            photosEl.innerHTML = '<div class="aspect-[16/10] overflow-hidden">' +
                imgs.map(function (src, i) {
                    return '<img src="' + escapeHtml(src) + '" alt="" data-slide="' + i + '" class="' + (i === 0 ? 'w-full h-full object-cover' : 'hidden w-full h-full object-cover') + '">';
                }).join('') + '</div>' +
                (imgs.length > 1
                    ? '<button type="button" class="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl bg-white/90 dark:bg-ink-800/90 text-ink-800 dark:text-white" data-photo-nav="-1">‹</button>' +
                      '<button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl bg-white/90 dark:bg-ink-800/90 text-ink-800 dark:text-white" data-photo-nav="1">›</button>' +
                      '<div class="absolute bottom-3 left-0 right-0 flex justify-center gap-1.5">' +
                      imgs.map(function (_, i) {
                          return '<span class="w-1.5 h-1.5 rounded-full ' + (i === 0 ? 'bg-white' : 'bg-white/40') + '" data-photo-dot="' + i + '"></span>';
                      }).join('') + '</div>'
                    : '');
            let slide = 0;
            function showSlide(next) {
                slide = (next + imgs.length) % imgs.length;
                photosEl.querySelectorAll('img[data-slide]').forEach(function (img) {
                    img.classList.toggle('hidden', Number(img.dataset.slide) !== slide);
                });
                photosEl.querySelectorAll('[data-photo-dot]').forEach(function (dot) {
                    dot.className = 'w-1.5 h-1.5 rounded-full ' + (Number(dot.dataset.photoDot) === slide ? 'bg-white' : 'bg-white/40');
                });
            }
            photosEl.querySelectorAll('[data-photo-nav]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    showSlide(slide + Number(btn.dataset.photoNav));
                });
            });
        }
        detailModal.classList.remove('hidden');
    }
    document.getElementById('gigs-detail-respond').addEventListener('click', function () {
        if (!selected) return;
        const id = selected.id;
        closeModals();
        openOffer(id);
    });

    function openOffer(id) {
        if (!isAuth) { location.href = loginUrl; return; }
        selected = tasks.find(function (t) { return t.id === id; });
        if (!selected) return;
        bargain = 'accept';
        document.querySelector('input[name="gigs_bargain"][value="accept"]').checked = true;
        customInput.disabled = true;
        customInput.value = '';
        document.getElementById('gigs-modal-title').textContent = selected.title;
        document.getElementById('gigs-modal-desc').textContent = selected.description;
        document.getElementById('gigs-modal-price').textContent = money(selected.pricing.initial_price);
        document.getElementById('gigs-price-accept').textContent = money(selected.pricing.initial_price);
        document.getElementById('gigs-price-discount').textContent = money(selected.pricing.bargain_options.discount_20.price);
        document.getElementById('gigs-price-raise').textContent = money(selected.pricing.bargain_options.raise_20.price);
        recalc();
        offerModal.classList.remove('hidden');
    }

    function recalc() {
        if (!selected) return;
        let price = selected.pricing.initial_price;
        if (bargain === 'discount_20') price = selected.pricing.bargain_options.discount_20.price;
        else if (bargain === 'raise_20') price = selected.pricing.bargain_options.raise_20.price;
        else if (bargain === 'custom') price = parseFloat(customInput.value) || 0;
        const fee = Math.round(price * 0.10 * 100) / 100;
        document.getElementById('gigs-calc-fee').textContent = money(fee);
        document.getElementById('gigs-calc-net').textContent = money(price - fee);
    }

    document.querySelectorAll('input[name="gigs_bargain"]').forEach(function (radio) {
        radio.addEventListener('change', function (e) {
            bargain = e.target.value;
            customInput.disabled = bargain !== 'custom';
            recalc();
        });
    });
    customInput.addEventListener('input', recalc);

    document.getElementById('gigs-submit-offer').addEventListener('click', async function () {
        if (!selected) return;
        const btn = this;
        btn.disabled = true;
        try {
            const res = await fetch(api + '/' + selected.id + '/offer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    offer_type: bargain,
                    custom_price: bargain === 'custom' ? parseFloat(customInput.value) : null
                })
            });
            const json = await res.json();
            showToast(json.success ? (json.data.message || 'OK') : (json.error || 'Error'), !json.success);
            if (json.success) {
                closeModals();
                loadTasks();
                loadMine();
            }
        } catch (e) {
            showToast('<?= htmlspecialchars(t('gigs.err_network'), ENT_QUOTES) ?>', true);
        }
        btn.disabled = false;
    });

    function openPin(id) {
        pinTaskId = id;
        document.getElementById('gigs-pin-input').value = '';
        pinModal.classList.remove('hidden');
    }
    document.getElementById('gigs-submit-pin').addEventListener('click', async function () {
        const pin = document.getElementById('gigs-pin-input').value.trim();
        if (!/^\d{4}$/.test(pin)) { showToast('<?= htmlspecialchars(t('gigs.err_pin_format'), ENT_QUOTES) ?>', true); return; }
        const btn = this;
        btn.disabled = true;
        try {
            const res = await fetch(api + '/' + pinTaskId + '/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ pin: pin })
            });
            const json = await res.json();
            if (json.success) {
                showToast(json.data.message + ' ' + money(json.data.payout_amount), false);
                closeModals();
                loadTasks();
                loadMine();
            } else {
                showToast(json.error, true);
            }
        } catch (e) {
            showToast('<?= htmlspecialchars(t('gigs.err_network'), ENT_QUOTES) ?>', true);
        }
        btn.disabled = false;
    });

    document.getElementById('gigs-refresh').addEventListener('click', function () { loadTasks(); loadMine(); });
    categorySelect.addEventListener('change', loadTasks);

    loadTasks();
    loadMine();
})();
</script>
