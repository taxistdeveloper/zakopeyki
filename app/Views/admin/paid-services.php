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
<div class="adm" id="paid-services-panel"
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
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= htmlspecialchars(t('admin.paid_services')) ?></h1>
            <p class="adm-sub"><?= htmlspecialchars(t('admin.paid_services_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <a href="<?= htmlspecialchars($logsUrl) ?>" class="adm-btn"><?= htmlspecialchars(t('admin.paid_services_audit_link')) ?></a>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <div id="paid-services-banner" class="adm-alert hidden" role="status" aria-live="polite"></div>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="adm-alert adm-alert--warn"><?= htmlspecialchars(t('admin.paid_services_price_readonly_note')) ?></div>

    <section class="adm-card">
        <div class="adm-card-head">
            <div class="adm-toolbar grow" style="flex:1">
                <label class="grow" style="min-width:200px">
                    <span class="sr-only"><?= htmlspecialchars(t('admin.paid_services_search')) ?></span>
                    <input type="search" id="paid-services-search" autocomplete="off"
                           placeholder="<?= htmlspecialchars(t('admin.paid_services_search'), ENT_QUOTES) ?>"
                           class="adm-input" style="width:100%">
                </label>
                <label>
                    <span class="sr-only"><?= htmlspecialchars(t('admin.paid_services_filter')) ?></span>
                    <select id="paid-services-filter" class="adm-select">
                        <option value="all"><?= htmlspecialchars(t('admin.paid_services_filter_all')) ?></option>
                        <option value="on"><?= htmlspecialchars(t('admin.paid_services_filter_on')) ?></option>
                        <option value="off"><?= htmlspecialchars(t('admin.paid_services_filter_off')) ?></option>
                        <option value="locked"><?= htmlspecialchars(t('admin.paid_services_filter_locked')) ?></option>
                    </select>
                </label>
                <span class="subtle nowrap" style="font-size:12px" id="paid-services-count"></span>
            </div>
        </div>

        <?php if ($services === []): ?>
            <div class="adm-empty"><?= htmlspecialchars(t('admin.paid_services_empty')) ?></div>
        <?php else: ?>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars(t('admin.paid_services')) ?></th>
                            <th><?= htmlspecialchars(t('admin.paid_services_col_billing')) ?></th>
                            <th class="num"><?= htmlspecialchars(t('admin.paid_services_col_price')) ?></th>
                            <th class="num"><?= htmlspecialchars(t('admin.paid_services_col_duration')) ?></th>
                            <th><?= htmlspecialchars(t('admin.paid_services_col_status')) ?></th>
                            <th class="num"></th>
                        </tr>
                    </thead>
                    <tbody id="paid-services-list">
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
                        <tr
                            class="js-ps-row"
                            data-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                            data-can-toggle="<?= $canToggle ? '1' : '0' ?>"
                            data-enabled="<?= $salesEnabled === true ? '1' : ($salesEnabled === false ? '0' : '') ?>"
                            data-avail="<?= htmlspecialchars($availKey, ENT_QUOTES) ?>"
                            data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>">
                            <td style="min-width:280px;max-width:460px">
                                <div class="strong"><?= htmlspecialchars($title) ?></div>
                                <div class="muted" style="font-size:12px;margin-top:2px">
                                    <span class="mono"><?= htmlspecialchars($vasId) ?></span>
                                    · <span class="mono"><?= htmlspecialchars($code) ?></span>
                                    <?php if ($category !== ''): ?> · <?= htmlspecialchars($category) ?><?php endif; ?>
                                    · <?= htmlspecialchars($implLabel) ?>
                                </div>
                                <?php if ($notes !== ''): ?>
                                    <div class="subtle adm-clamp-2" style="font-size:12px;margin-top:4px" title="<?= htmlspecialchars($notes, ENT_QUOTES) ?>"><?= htmlspecialchars($notes) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= htmlspecialchars($billingLabel) ?></td>
                            <td class="num">
                                <?php if ($priceKzt === null || $priceKzt === ''): ?>
                                    <span class="subtle">—</span>
                                <?php else: ?>
                                    <span class="strong"><?= htmlspecialchars(Wallet::formatMoney((int) $priceKzt)) ?></span>
                                    <?php if ($currency !== '' && $currency !== 'KZT'): ?>
                                        <span class="subtle"><?= htmlspecialchars($currency) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="subtle" style="font-size:11px"><?= htmlspecialchars(t('admin.paid_services_price_locked')) ?></div>
                            </td>
                            <td class="num">
                                <?php if ($duration !== null && $duration !== ''): ?>
                                    <?= (int) $duration ?> <?= htmlspecialchars(t('admin.paid_services_days')) ?>
                                <?php else: ?>
                                    <span class="subtle">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap js-ps-status">
                                <?php if ($availKey === 'on'): ?>
                                    <span class="adm-badge adm-badge--ok"><span class="adm-dot"></span><?= htmlspecialchars(t('admin.paid_services_status_on')) ?></span>
                                <?php elseif ($availKey === 'off'): ?>
                                    <span class="adm-badge adm-badge--warn"><span class="adm-dot"></span><?= htmlspecialchars(t('admin.paid_services_status_off')) ?></span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge--neutral"><span class="adm-dot"></span><?= htmlspecialchars(t('admin.paid_services_status_na')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($canToggle && $salesEnabled !== null): ?>
                                        <button type="button"
                                                class="js-ps-toggle adm-btn adm-btn--sm<?= $salesEnabled ? ' adm-btn--danger' : '' ?>"
                                                data-next="<?= $salesEnabled ? '0' : '1' ?>"
                                                title="<?= htmlspecialchars(t('admin.paid_services_toggle_hint'), ENT_QUOTES) ?>"
                                                aria-busy="false">
                                            <?= htmlspecialchars($salesEnabled ? t('admin.paid_services_disable') : t('admin.paid_services_enable')) ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="subtle" style="font-size:12px"><?= htmlspecialchars(t('admin.paid_services_no_toggle')) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
            <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div id="paid-services-empty-filter" class="adm-empty hidden">
                <?= htmlspecialchars(t('admin.paid_services_filter_empty')) ?>
            </div>
        <?php endif; ?>
        <div class="adm-card-foot">
            <?= htmlspecialchars(t('admin.paid_services_toggle_hint')) ?>
            <?= htmlspecialchars(t('admin.paid_services_audit_note')) ?>
        </div>
    </section>
</div>

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
        banner.classList.remove('hidden', 'adm-alert--ok', 'adm-alert--error');
        banner.classList.add(type === 'ok' ? 'adm-alert--ok' : 'adm-alert--error');
        banner.setAttribute('role', type === 'ok' ? 'status' : 'alert');
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
            var badge = document.createElement('span');
            var dot = document.createElement('span');
            badge.className = 'adm-badge ' + (enabled ? 'adm-badge--ok' : 'adm-badge--warn');
            dot.className = 'adm-dot';
            badge.appendChild(dot);
            badge.appendChild(document.createTextNode(panel.getAttribute(enabled ? 'data-label-on' : 'data-label-off') || ''));
            status.replaceChildren(badge);
        }
        if (btn) {
            btn.setAttribute('data-next', enabled ? '0' : '1');
            btn.textContent = enabled ? panel.getAttribute('data-btn-disable') : panel.getAttribute('data-btn-enable');
            btn.classList.toggle('adm-btn--danger', enabled);
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
