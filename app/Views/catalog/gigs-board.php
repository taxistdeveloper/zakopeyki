<?php
use App\Core\Auth;
use App\Helpers\ProductHelper;
use App\Models\Wallet;

$microCategories = $microCategories ?? [];
$walletBalance = (int) ($walletBalance ?? 0);
$walletHeld = (int) ($walletHeld ?? 0);
$loggedIn = Auth::check();
$loginUrl = ProductHelper::url('/login');
$apiBase = ProductHelper::url('/api/v1/micro-tasks');
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<div id="gigs-board" class="space-y-5" data-api="<?= htmlspecialchars($apiBase) ?>" data-login="<?= htmlspecialchars($loginUrl) ?>" data-auth="<?= $loggedIn ? '1' : '0' ?>">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 shadow-soft">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-gray-500"><?= htmlspecialchars(t('gigs.wallet')) ?>:</span>
            <strong class="text-brand-600" id="gigs-balance"><?= htmlspecialchars(Wallet::formatMoney($walletBalance)) ?></strong>
            <?php if ($walletHeld > 0): ?>
                <span class="text-xs text-amber-700 dark:text-amber-300"><?= htmlspecialchars(t('gigs.held', ['amount' => Wallet::formatMoney($walletHeld)])) ?></span>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <select id="gigs-category" class="<?= $input ?> sm:w-64 h-10">
                <option value="0"><?= htmlspecialchars(t('gigs.all_categories')) ?></option>
                <?php foreach ($microCategories as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="gigs-refresh" class="bg-ink-100 dark:bg-white/10 hover:bg-ink-200 text-ink-800 dark:text-white font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl transition"><?= htmlspecialchars(t('gigs.refresh')) ?></button>
            <?php if ($loggedIn): ?>
                <button type="button" id="gigs-open-create" class="bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl transition"><?= htmlspecialchars(t('gigs.publish')) ?></button>
            <?php else: ?>
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="inline-flex items-center bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-xl transition"><?= htmlspecialchars(t('gigs.publish')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div id="gigs-mine" class="hidden space-y-2"></div>
    <div id="gigs-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
</div>

<div id="gigs-offer-modal" class="hidden fixed inset-0 z-[80] bg-ink-900/60 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-ink-900 rounded-2xl w-full max-w-lg p-5 relative border border-black/10 dark:border-white/10 shadow-lift">
        <button type="button" class="gigs-modal-close absolute top-3 right-3 text-2xl text-gray-400" aria-label="close">&times;</button>
        <h3 id="gigs-modal-title" class="font-display font-bold text-lg pr-8"></h3>
        <p id="gigs-modal-desc" class="text-sm text-gray-500 mt-2"></p>
        <p class="mt-3 text-sm"><?= htmlspecialchars(t('gigs.budget')) ?>: <strong id="gigs-modal-price"></strong></p>
        <div class="grid grid-cols-2 gap-2 mt-4">
            <label class="rounded-xl border-2 border-black/10 dark:border-white/10 p-3 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 dark:has-[:checked]:bg-brand-500/10">
                <input type="radio" name="gigs_bargain" value="accept" class="sr-only" checked>
                <span class="block text-xs font-bold"><?= htmlspecialchars(t('gigs.opt_accept')) ?></span>
                <span class="text-sm font-semibold" id="gigs-price-accept"></span>
            </label>
            <label class="rounded-xl border-2 border-violet-200 p-3 cursor-pointer has-[:checked]:border-violet-600 has-[:checked]:bg-violet-50 dark:has-[:checked]:bg-violet-500/10">
                <input type="radio" name="gigs_bargain" value="discount_20" class="sr-only">
                <span class="inline-block text-[10px] uppercase font-bold bg-violet-600 text-white px-1.5 py-0.5 rounded mb-1"><?= htmlspecialchars(t('gigs.instant_badge')) ?></span>
                <span class="block text-xs font-bold"><?= htmlspecialchars(t('gigs.opt_discount')) ?></span>
                <span class="text-sm font-semibold" id="gigs-price-discount"></span>
            </label>
            <label class="rounded-xl border-2 border-black/10 dark:border-white/10 p-3 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                <input type="radio" name="gigs_bargain" value="raise_20" class="sr-only">
                <span class="block text-xs font-bold"><?= htmlspecialchars(t('gigs.opt_raise')) ?></span>
                <span class="text-sm font-semibold" id="gigs-price-raise"></span>
            </label>
            <label class="rounded-xl border-2 border-black/10 dark:border-white/10 p-3 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                <input type="radio" name="gigs_bargain" value="custom" class="sr-only">
                <span class="block text-xs font-bold"><?= htmlspecialchars(t('gigs.opt_custom')) ?></span>
                <input type="number" id="gigs-custom-price" class="<?= $input ?> h-9 mt-1" min="100" step="50" disabled>
            </label>
        </div>
        <div class="mt-4 rounded-xl bg-ink-50 dark:bg-white/5 p-3 text-sm space-y-1">
            <div class="flex justify-between"><span><?= htmlspecialchars(t('gigs.fee_hold')) ?></span><span>50 ₸</span></div>
            <div class="flex justify-between"><span><?= htmlspecialchars(t('gigs.fee_platform')) ?></span><span id="gigs-calc-fee">0 ₸</span></div>
            <div class="flex justify-between font-bold"><span><?= htmlspecialchars(t('gigs.net_payout')) ?></span><span id="gigs-calc-net">0 ₸</span></div>
        </div>
        <button type="button" id="gigs-submit-offer" class="mt-4 w-full bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider py-3 rounded-xl"><?= htmlspecialchars(t('gigs.send_offer')) ?></button>
    </div>
</div>

<div id="gigs-pin-modal" class="hidden fixed inset-0 z-[80] bg-ink-900/60 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-ink-900 rounded-2xl w-full max-w-sm p-5 relative border border-black/10 dark:border-white/10">
        <button type="button" class="gigs-modal-close absolute top-3 right-3 text-2xl text-gray-400">&times;</button>
        <h3 class="font-display font-bold text-lg"><?= htmlspecialchars(t('gigs.complete_title')) ?></h3>
        <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars(t('gigs.complete_hint')) ?></p>
        <input type="text" id="gigs-pin-input" maxlength="4" inputmode="numeric" class="<?= $input ?> mt-4 text-center text-2xl tracking-[0.4em]" placeholder="0000">
        <button type="button" id="gigs-submit-pin" class="mt-4 w-full bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider py-3 rounded-xl"><?= htmlspecialchars(t('gigs.complete_btn')) ?></button>
    </div>
</div>

<div id="gigs-create-modal" class="hidden fixed inset-0 z-[80] bg-ink-900/60 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-ink-900 rounded-2xl w-full max-w-lg p-5 relative border border-black/10 dark:border-white/10 max-h-[90vh] overflow-y-auto">
        <button type="button" class="gigs-modal-close absolute top-3 right-3 text-2xl text-gray-400">&times;</button>
        <h3 class="font-display font-bold text-lg"><?= htmlspecialchars(t('gigs.publish')) ?></h3>
        <form id="gigs-create-form" class="mt-4 space-y-3">
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('gigs.field_category')) ?></label>
                <select name="category_id" required class="<?= $input ?>">
                    <?php foreach ($microCategories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('gigs.field_title')) ?></label>
                <input name="title" required minlength="5" maxlength="255" class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('gigs.field_desc')) ?></label>
                <textarea name="description" required minlength="10" rows="4" class="<?= $input ?> h-auto py-2"></textarea>
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('gigs.field_address')) ?></label>
                <input name="address" required class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('gigs.field_price')) ?></label>
                <input name="initial_price" type="number" min="100" step="50" required class="<?= $input ?>">
            </div>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('gigs.create_hint')) ?></p>
            <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-display font-bold text-xs uppercase tracking-wider py-3 rounded-xl"><?= htmlspecialchars(t('gigs.create_btn')) ?></button>
        </form>
    </div>
</div>

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
    const offerModal = document.getElementById('gigs-offer-modal');
    const pinModal = document.getElementById('gigs-pin-modal');
    const createModal = document.getElementById('gigs-create-modal');
    const customInput = document.getElementById('gigs-custom-price');
    let tasks = [];
    let selected = null;
    let bargain = 'accept';
    let pinTaskId = null;

    function money(n) {
        return new Intl.NumberFormat('ru-RU').format(Math.round(Number(n) || 0)) + ' ₸';
    }
    function closeModals() {
        offerModal.classList.add('hidden');
        pinModal.classList.add('hidden');
        createModal.classList.add('hidden');
    }
    document.querySelectorAll('.gigs-modal-close').forEach(function (btn) {
        btn.addEventListener('click', closeModals);
    });

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
                    const offers = (t.offers || []).map(function (o) {
                        return '<button type="button" class="text-xs bg-brand-600 text-white px-2 py-1 rounded-lg" data-select-offer="' + o.id + '"><?= htmlspecialchars(t('gigs.accept_offer'), ENT_QUOTES) ?> ' + money(o.proposed_price) + '</button>';
                    }).join(' ');
                    return '<div class="rounded-xl border border-black/10 dark:border-white/10 p-3 text-sm flex flex-wrap items-center justify-between gap-2">' +
                        '<span><strong>' + escapeHtml(t.title) + '</strong> · ' + escapeHtml(t.status) + pin + '</span>' +
                        '<span class="flex flex-wrap gap-2">' + offers + completeBtn + cancelBtn + '</span></div>';
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
                    alert(json.success ? json.data.message : json.error);
                    loadTasks();
                    loadMine();
                });
            });
            mineEl.querySelectorAll('[data-cancel-task]').forEach(function (btn) {
                btn.addEventListener('click', async function () {
                    if (!confirm('<?= htmlspecialchars(t('gigs.cancel_confirm'), ENT_QUOTES) ?>')) return;
                    const res = await fetch(api + '/' + btn.dataset.cancelTask + '/cancel', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: '{}'
                    });
                    const json = await res.json();
                    alert(json.success ? json.data.message : json.error);
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
            return '<article class="bg-white dark:bg-white/[0.04] rounded-2xl border border-black/[0.06] dark:border-white/10 p-4 flex flex-col gap-3 shadow-soft">' +
                '<div class="flex justify-between gap-2"><span class="text-[10px] font-bold uppercase tracking-wider bg-ink-100 dark:bg-white/10 px-2 py-1 rounded-lg">' + escapeHtml(task.category.name) + '</span>' +
                '<span class="font-display font-bold text-emerald-600">' + money(task.pricing.initial_price) + '</span></div>' +
                '<h3 class="font-display font-bold text-ink-900 dark:text-white">' + escapeHtml(task.title) + '</h3>' +
                '<p class="text-xs text-gray-500">' + escapeHtml(task.address || '') + '</p>' +
                '<div class="text-xs font-semibold text-violet-700 dark:text-violet-300 bg-violet-50 dark:bg-violet-500/10 rounded-lg p-2 text-center"><?= htmlspecialchars(t('gigs.instant_banner'), ENT_QUOTES) ?> ' + money(d) + '</div>' +
                '<button type="button" class="mt-auto bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider py-2.5 rounded-xl" data-offer="' + task.id + '"><?= htmlspecialchars(t('gigs.respond'), ENT_QUOTES) ?></button>' +
                '</article>';
        }).join('');
        grid.querySelectorAll('[data-offer]').forEach(function (btn) {
            btn.addEventListener('click', function () { openOffer(Number(btn.dataset.offer)); });
        });
    }

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
            alert(json.success ? (json.data.message || 'OK') : (json.error || 'Error'));
            if (json.success) {
                closeModals();
                loadTasks();
                loadMine();
            }
        } catch (e) {
            alert('<?= htmlspecialchars(t('gigs.err_network'), ENT_QUOTES) ?>');
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
        if (!/^\d{4}$/.test(pin)) { alert('<?= htmlspecialchars(t('gigs.err_pin_format'), ENT_QUOTES) ?>'); return; }
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
                alert(json.data.message + ' ' + money(json.data.payout_amount));
                closeModals();
                loadTasks();
                loadMine();
            } else {
                alert(json.error);
            }
        } catch (e) {
            alert('<?= htmlspecialchars(t('gigs.err_network'), ENT_QUOTES) ?>');
        }
        btn.disabled = false;
    });

    document.getElementById('gigs-refresh').addEventListener('click', function () { loadTasks(); loadMine(); });
    categorySelect.addEventListener('change', loadTasks);
    const openCreate = document.getElementById('gigs-open-create');
    if (openCreate) openCreate.addEventListener('click', function () { createModal.classList.remove('hidden'); });
    document.getElementById('gigs-create-form').addEventListener('submit', async function (e) {
        e.preventDefault();
        const form = e.target;
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.category_id = Number(payload.category_id);
        payload.initial_price = Number(payload.initial_price);
        try {
            const res = await fetch(api + '/create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            if (json.success) {
                alert(json.data.message + ' PIN: ' + json.data.completion_pin);
                form.reset();
                closeModals();
                loadTasks();
                loadMine();
            } else {
                alert(json.error);
            }
        } catch (err) {
            alert('<?= htmlspecialchars(t('gigs.err_network'), ENT_QUOTES) ?>');
        }
    });

    loadTasks();
    loadMine();
})();
</script>
