<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;

$services = is_array($services ?? null) ? $services : [];
$priceEditable = !empty($priceEditable);

$billingLabels = [
    'one_shot' => t('admin.paid_services_billing_one_shot'),
    'timed_window' => t('admin.paid_services_billing_timed_window'),
    'cpm_thousands' => t('admin.paid_services_billing_cpm'),
    'exclusive_weeks' => t('admin.paid_services_billing_exclusive'),
    'push_send' => t('admin.paid_services_billing_push'),
    'subscription_month' => t('admin.paid_services_billing_subscription'),
    'addon_quota' => t('admin.paid_services_billing_addon'),
    'listing_per_item' => t('admin.paid_services_billing_listing'),
    'none' => t('admin.paid_services_billing_none'),
];

$statusLabels = [
    'full' => t('admin.paid_services_impl_full'),
    'flag_only' => t('admin.paid_services_impl_flag_only'),
    'absent' => t('admin.paid_services_impl_absent'),
    'sales_blocked' => t('admin.paid_services_impl_sales_blocked'),
];

$toggleUrlBase = ProductHelper::url('/admin/paid-services');
$logsUrl = ProductHelper::url('/admin/logs?action=paid_services');
$reloadUrl = ProductHelper::url('/admin/paid-services');
?>
<section class="space-y-5 fade-up pb-8" id="paid-services-panel"
    data-toggle-base="<?= htmlspecialchars($toggleUrlBase, ENT_QUOTES) ?>"
    data-reload="<?= htmlspecialchars($reloadUrl, ENT_QUOTES) ?>"
    data-msg-enable="<?= htmlspecialchars(t('admin.paid_services_enable_confirm'), ENT_QUOTES) ?>"
    data-msg-disable="<?= htmlspecialchars(t('admin.paid_services_disable_confirm'), ENT_QUOTES) ?>"
    data-msg-busy="<?= htmlspecialchars(t('admin.paid_services_busy'), ENT_QUOTES) ?>"
    data-msg-error="<?= htmlspecialchars(t('admin.paid_services_toggle_error'), ENT_QUOTES) ?>"
    data-msg-forbidden="<?= htmlspecialchars(t('admin.paid_services_forbidden'), ENT_QUOTES) ?>"
    data-msg-conflict="<?= htmlspecialchars(t('admin.paid_services_conflict'), ENT_QUOTES) ?>"
    data-msg-not-toggleable="<?= htmlspecialchars(t('admin.paid_services_not_toggleable'), ENT_QUOTES) ?>"
    data-msg-success-on="<?= htmlspecialchars(t('admin.paid_services_enabled_flash'), ENT_QUOTES) ?>"
    data-msg-success-off="<?= htmlspecialchars(t('admin.paid_services_disabled_flash'), ENT_QUOTES) ?>"
    data-label-on="<?= htmlspecialchars(t('admin.paid_services_status_on'), ENT_QUOTES) ?>"
    data-label-off="<?= htmlspecialchars(t('admin.paid_services_status_off'), ENT_QUOTES) ?>"
    data-btn-enable="<?= htmlspecialchars(t('admin.paid_services_enable'), ENT_QUOTES) ?>"
    data-btn-disable="<?= htmlspecialchars(t('admin.paid_services_disable'), ENT_QUOTES) ?>">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.paid_services')) ?></h1>
            <p class="text-sm text-gray-500 mt-1 max-w-2xl"><?= htmlspecialchars(t('admin.paid_services_hint')) ?></p>
        </div>
        <a href="<?= htmlspecialchars($logsUrl) ?>"
           class="h-10 px-4 rounded-xl text-xs font-bold border border-black/10 dark:border-white/15 inline-flex items-center hover:border-brand-400 transition">
            <?= htmlspecialchars(t('admin.paid_services_audit_link')) ?> →
        </a>
    </div>

    <div id="paid-services-banner" class="hidden rounded-2xl px-4 py-3 text-sm font-semibold border" role="status" aria-live="polite"></div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="rounded-2xl border border-amber-200/80 dark:border-amber-800/40 bg-amber-50/70 dark:bg-amber-950/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
        <?= htmlspecialchars(t('admin.paid_services_price_readonly_note')) ?>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 space-y-3">
        <div class="flex flex-col sm:flex-row gap-3">
            <label class="flex-1 min-w-0">
                <span class="sr-only"><?= htmlspecialchars(t('admin.paid_services_search')) ?></span>
                <input type="search" id="paid-services-search" autocomplete="off"
                       placeholder="<?= htmlspecialchars(t('admin.paid_services_search'), ENT_QUOTES) ?>"
                       class="w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
            </label>
            <label class="sm:w-48">
                <span class="sr-only"><?= htmlspecialchars(t('admin.paid_services_filter')) ?></span>
                <select id="paid-services-filter"
                        class="w-full h-11 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
                    <option value="all"><?= htmlspecialchars(t('admin.paid_services_filter_all')) ?></option>
                    <option value="on"><?= htmlspecialchars(t('admin.paid_services_filter_on')) ?></option>
                    <option value="off"><?= htmlspecialchars(t('admin.paid_services_filter_off')) ?></option>
                    <option value="locked"><?= htmlspecialchars(t('admin.paid_services_filter_locked')) ?></option>
                </select>
            </label>
        </div>
        <p class="text-[11px] text-gray-400" id="paid-services-count"></p>
    </div>

    <?php if ($services === []): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-5 py-12 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('admin.paid_services_empty')) ?>
        </div>
    <?php else: ?>
        <div class="space-y-3" id="paid-services-list">
            <?php foreach ($services as $row):
                $code = (string) ($row['code'] ?? '');
                $vasId = (string) ($row['vas_id'] ?? '');
                $title = (string) ($row['title'] ?? $code);
                $billing = (string) ($row['billing_model'] ?? 'none');
                $billingLabel = $billingLabels[$billing] ?? $billing;
                $impl = (string) ($row['implementation_status'] ?? '');
                $implLabel = $statusLabels[$impl] ?? $impl;
                $canToggle = !empty($row['can_toggle']);
                $salesEnabled = array_key_exists('sales_enabled', $row) ? $row['sales_enabled'] : null;
                $priceKzt = $row['price_kzt'] ?? null;
                $currency = (string) ($row['currency'] ?? 'KZT');
                $duration = $row['duration_days'] ?? null;
                $notes = trim((string) ($row['notes'] ?? ''));
                $category = (string) ($row['category'] ?? '');

                $availKey = 'na';
                if ($canToggle && $salesEnabled !== null) {
                    $availKey = $salesEnabled ? 'on' : 'off';
                } elseif (!$canToggle) {
                    $availKey = 'locked';
                }

                $searchBlob = mb_strtolower(implode(' ', [$code, $vasId, $title, $billingLabel, $category, $notes]));
            ?>
                <article
                    class="js-ps-row rounded-[22px] border shadow-soft overflow-hidden bg-white/90 dark:bg-white/[0.04] border-black/[0.06] dark:border-white/10"
                    data-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                    data-can-toggle="<?= $canToggle ? '1' : '0' ?>"
                    data-enabled="<?= $salesEnabled === true ? '1' : ($salesEnabled === false ? '0' : '') ?>"
                    data-avail="<?= htmlspecialchars($availKey, ENT_QUOTES) ?>"
                    data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>">
                    <div class="flex flex-col lg:flex-row lg:items-stretch gap-0">
                        <div class="flex-1 min-w-0 px-4 sm:px-5 py-4 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-brand-600"><?= htmlspecialchars($vasId) ?></span>
                                <span class="text-[10px] font-mono text-gray-400"><?= htmlspecialchars($code) ?></span>
                                <?php if ($category !== ''): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-lg bg-black/[0.04] dark:bg-white/10 text-gray-500"><?= htmlspecialchars($category) ?></span>
                                <?php endif; ?>
                                <span class="text-[10px] px-2 py-0.5 rounded-lg bg-black/[0.04] dark:bg-white/10 text-gray-500"><?= htmlspecialchars($implLabel) ?></span>
                            </div>
                            <h2 class="font-display font-bold text-ink-900 dark:text-white text-base sm:text-lg"><?= htmlspecialchars($title) ?></h2>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div>
                                    <dt class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.paid_services_col_billing')) ?></dt>
                                    <dd class="font-semibold mt-0.5"><?= htmlspecialchars($billingLabel) ?></dd>
                                </div>
                                <div>
                                    <dt class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.paid_services_col_price')) ?></dt>
                                    <dd class="font-semibold mt-0.5 flex flex-wrap items-center gap-2">
                                        <?php if ($priceKzt === null || $priceKzt === ''): ?>
                                            <span class="text-gray-400">—</span>
                                        <?php else: ?>
                                            <span><?= htmlspecialchars(Wallet::formatMoney((int) $priceKzt)) ?></span>
                                            <?php if ($currency !== '' && $currency !== 'KZT'): ?>
                                                <span class="text-gray-400"><?= htmlspecialchars($currency) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-300 bg-amber-100/80 dark:bg-amber-900/30 px-1.5 py-0.5 rounded">
                                            <?= htmlspecialchars(t('admin.paid_services_price_locked')) ?>
                                        </span>
                                    </dd>
                                </div>
                                <?php if ($duration !== null && $duration !== ''): ?>
                                    <div>
                                        <dt class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.paid_services_col_duration')) ?></dt>
                                        <dd class="font-semibold mt-0.5"><?= (int) $duration ?> <?= htmlspecialchars(t('admin.paid_services_days')) ?></dd>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <dt class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.paid_services_col_status')) ?></dt>
                                    <dd class="font-semibold mt-0.5 js-ps-status">
                                        <?php if ($availKey === 'on'): ?>
                                            <span class="text-emerald-700 dark:text-emerald-300"><?= htmlspecialchars(t('admin.paid_services_status_on')) ?></span>
                                        <?php elseif ($availKey === 'off'): ?>
                                            <span class="text-amber-700 dark:text-amber-300"><?= htmlspecialchars(t('admin.paid_services_status_off')) ?></span>
                                        <?php else: ?>
                                            <span class="text-gray-400"><?= htmlspecialchars(t('admin.paid_services_status_na')) ?></span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            </dl>
                            <?php if ($notes !== ''): ?>
                                <p class="text-[11px] text-gray-500 leading-relaxed"><?= htmlspecialchars($notes) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="lg:w-52 shrink-0 border-t lg:border-t-0 lg:border-l border-black/[0.06] dark:border-white/10 px-4 sm:px-5 py-4 flex flex-col justify-center gap-2 bg-black/[0.015] dark:bg-white/[0.02]">
                            <?php if ($canToggle && $salesEnabled !== null): ?>
                                <button type="button"
                                        class="js-ps-toggle h-10 px-4 rounded-xl text-xs font-bold text-white transition <?= $salesEnabled ? 'bg-amber-600 hover:bg-amber-700' : 'bg-emerald-600 hover:bg-emerald-700' ?>"
                                        data-next="<?= $salesEnabled ? '0' : '1' ?>"
                                        aria-busy="false">
                                    <?= htmlspecialchars($salesEnabled ? t('admin.paid_services_disable') : t('admin.paid_services_enable')) ?>
                                </button>
                                <p class="text-[10px] text-gray-400 leading-snug"><?= htmlspecialchars(t('admin.paid_services_toggle_hint')) ?></p>
                            <?php else: ?>
                                <p class="text-xs font-semibold text-gray-400"><?= htmlspecialchars(t('admin.paid_services_no_toggle')) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div id="paid-services-empty-filter" class="hidden rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-5 py-10 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('admin.paid_services_filter_empty')) ?>
        </div>
    <?php endif; ?>

    <p class="text-[11px] text-gray-400 max-w-3xl"><?= htmlspecialchars(t('admin.paid_services_audit_note')) ?></p>
</section>

<script>
(function () {
    var panel = document.getElementById('paid-services-panel');
    if (!panel) return;

    var search = document.getElementById('paid-services-search');
    var filter = document.getElementById('paid-services-filter');
    var list = document.getElementById('paid-services-list');
    var emptyFilter = document.getElementById('paid-services-empty-filter');
    var countEl = document.getElementById('paid-services-count');
    var banner = document.getElementById('paid-services-banner');
    var token = (window.__csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');
    var pending = Object.create(null);

    function showBanner(type, text) {
        if (!banner) return;
        banner.classList.remove('hidden', 'bg-emerald-50', 'dark:bg-emerald-900/25', 'text-emerald-800', 'dark:text-emerald-300', 'border-emerald-100', 'dark:border-emerald-800/40', 'bg-red-50', 'dark:bg-red-950/30', 'text-red-700', 'dark:text-red-300', 'border-red-100', 'dark:border-red-900/40');
        if (type === 'ok') {
            banner.classList.add('bg-emerald-50', 'dark:bg-emerald-900/25', 'text-emerald-800', 'dark:text-emerald-300', 'border-emerald-100', 'dark:border-emerald-800/40');
        } else {
            banner.classList.add('bg-red-50', 'dark:bg-red-950/30', 'text-red-700', 'dark:text-red-300', 'border-red-100', 'dark:border-red-900/40');
        }
        banner.textContent = text;
    }

    function applyFilters() {
        if (!list) return;
        var q = (search && search.value ? search.value : '').trim().toLowerCase();
        var f = filter ? filter.value : 'all';
        var rows = list.querySelectorAll('.js-ps-row');
        var visible = 0;
        rows.forEach(function (row) {
            var avail = row.getAttribute('data-avail') || '';
            var hay = row.getAttribute('data-search') || '';
            var okFilter = f === 'all' || avail === f;
            var okSearch = !q || hay.indexOf(q) !== -1;
            var show = okFilter && okSearch;
            row.classList.toggle('hidden', !show);
            if (show) visible++;
        });
        if (emptyFilter) emptyFilter.classList.toggle('hidden', visible !== 0);
        if (countEl) {
            countEl.textContent = visible + ' / ' + rows.length;
        }
    }

    function paintRow(row, enabled) {
        var status = row.querySelector('.js-ps-status');
        var btn = row.querySelector('.js-ps-toggle');
        row.setAttribute('data-enabled', enabled ? '1' : '0');
        row.setAttribute('data-avail', enabled ? 'on' : 'off');
        if (status) {
            status.innerHTML = enabled
                ? '<span class="text-emerald-700 dark:text-emerald-300">' + panel.getAttribute('data-label-on') + '</span>'
                : '<span class="text-amber-700 dark:text-amber-300">' + panel.getAttribute('data-label-off') + '</span>';
        }
        if (btn) {
            btn.setAttribute('data-next', enabled ? '0' : '1');
            btn.textContent = enabled ? panel.getAttribute('data-btn-disable') : panel.getAttribute('data-btn-enable');
            btn.classList.toggle('bg-amber-600', enabled);
            btn.classList.toggle('hover:bg-amber-700', enabled);
            btn.classList.toggle('bg-emerald-600', !enabled);
            btn.classList.toggle('hover:bg-emerald-700', !enabled);
        }
        applyFilters();
    }

    function mapError(code) {
        if (code === 'forbidden') return panel.getAttribute('data-msg-forbidden');
        if (code === 'conflict') return panel.getAttribute('data-msg-conflict');
        if (code === 'not_toggleable' || code === 'not_found') return panel.getAttribute('data-msg-not-toggleable');
        return panel.getAttribute('data-msg-error');
    }

    function setBusy(btn, busy) {
        btn.setAttribute('aria-busy', busy ? 'true' : 'false');
        btn.disabled = !!busy;
        if (busy) {
            btn.dataset.prevLabel = btn.textContent;
            btn.textContent = panel.getAttribute('data-msg-busy');
        } else if (btn.dataset.prevLabel) {
            btn.textContent = btn.dataset.prevLabel;
            delete btn.dataset.prevLabel;
        }
    }

    panel.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-ps-toggle');
        if (!btn || !panel.contains(btn)) return;
        var row = btn.closest('.js-ps-row');
        if (!row) return;
        var code = row.getAttribute('data-code') || '';
        if (!code || pending[code]) return;

        var next = btn.getAttribute('data-next') === '1';
        var confirmMsg = next ? panel.getAttribute('data-msg-enable') : panel.getAttribute('data-msg-disable');
        if (!window.confirm(confirmMsg)) return;

        var expectedRaw = row.getAttribute('data-enabled');
        var body = '_csrf=' + encodeURIComponent(token)
            + '&enabled=' + (next ? '1' : '0');
        if (expectedRaw === '0' || expectedRaw === '1') {
            body += '&expected=' + encodeURIComponent(expectedRaw);
        }

        pending[code] = true;
        setBusy(btn, true);

        fetch(panel.getAttribute('data-toggle-base') + '/' + encodeURIComponent(code) + '/availability', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: body
        }).then(function (res) {
            return res.json().then(function (data) {
                return { okHttp: res.ok, data: data || {} };
            }).catch(function () {
                return { okHttp: res.ok, data: {} };
            });
        }).then(function (pack) {
            var data = pack.data;
            if (!pack.okHttp || !data.ok) {
                showBanner('err', mapError(data.error_code || ''));
                if (data.error_code === 'conflict') {
                    window.location.href = panel.getAttribute('data-reload');
                }
                return;
            }
            var after = !!data.enabled;
            paintRow(row, after);
            showBanner('ok', after ? panel.getAttribute('data-msg-success-on') : panel.getAttribute('data-msg-success-off'));
        }).catch(function () {
            showBanner('err', panel.getAttribute('data-msg-error'));
        }).finally(function () {
            delete pending[code];
            setBusy(btn, false);
            // label already updated by paintRow on success; restore only if failed
            if (row.getAttribute('data-enabled') === '1' || row.getAttribute('data-enabled') === '0') {
                btn.textContent = row.getAttribute('data-enabled') === '1'
                    ? panel.getAttribute('data-btn-disable')
                    : panel.getAttribute('data-btn-enable');
            }
        });
    });

    if (search) search.addEventListener('input', applyFilters);
    if (filter) filter.addEventListener('change', applyFilters);
    applyFilters();
})();
</script>
