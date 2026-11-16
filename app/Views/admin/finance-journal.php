<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Finance\FinanceJournalPolicy;

$items = is_array($items ?? null) ? $items : [];
$filters = is_array($filters ?? null) ? $filters : [];
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$total = (int) ($total ?? 0);

$q = (string) ($filters['q'] ?? ($_GET['q'] ?? ''));
$streamType = (string) ($filters['stream_type'] ?? ($_GET['stream_type'] ?? ''));
$serviceCode = (string) ($filters['service_code'] ?? ($_GET['service_code'] ?? ''));
$status = (string) ($filters['status'] ?? ($_GET['status'] ?? ''));
$dateFrom = (string) ($filters['date_from'] ?? ($_GET['date_from'] ?? ''));
$dateTo = (string) ($filters['date_to'] ?? ($_GET['date_to'] ?? ''));
$userId = $filters['user_id'] ?? ($_GET['user_id'] ?? null);
$confirmed = $filters['payment_confirmed'] ?? null;
if (array_key_exists('payment_confirmed', $_GET) && $confirmed === null) {
    $raw = $_GET['payment_confirmed'];
    if ($raw === '1') {
        $confirmed = true;
    } elseif ($raw === '0') {
        $confirmed = false;
    }
}
$sort = (string) ($filters['sort'] ?? ($_GET['sort'] ?? 'created_at_desc'));

$streamLabels = [
    FinanceJournalPolicy::STREAM_VAS => t('admin.finance_stream_vas'),
    FinanceJournalPolicy::STREAM_ESCROW => t('admin.finance_stream_escrow'),
    FinanceJournalPolicy::STREAM_ARBITRATION => t('admin.finance_stream_arbitration'),
    FinanceJournalPolicy::STREAM_COMMISSION_OTHER => t('admin.finance_stream_commission_other'),
    FinanceJournalPolicy::STREAM_DELIVERY => t('admin.finance_stream_delivery'),
    FinanceJournalPolicy::STREAM_WALLET => t('admin.finance_stream_wallet'),
    FinanceJournalPolicy::STREAM_OTHER => t('admin.finance_stream_other'),
];

$buildUrl = static function (array $overrides = []) use ($q, $streamType, $serviceCode, $status, $dateFrom, $dateTo, $userId, $confirmed, $sort): string {
    $params = [
        'q' => array_key_exists('q', $overrides) ? $overrides['q'] : $q,
        'stream_type' => array_key_exists('stream_type', $overrides) ? $overrides['stream_type'] : $streamType,
        'service_code' => array_key_exists('service_code', $overrides) ? $overrides['service_code'] : $serviceCode,
        'status' => array_key_exists('status', $overrides) ? $overrides['status'] : $status,
        'date_from' => array_key_exists('date_from', $overrides) ? $overrides['date_from'] : $dateFrom,
        'date_to' => array_key_exists('date_to', $overrides) ? $overrides['date_to'] : $dateTo,
        'sort' => array_key_exists('sort', $overrides) ? $overrides['sort'] : $sort,
    ];
    $uid = array_key_exists('user_id', $overrides) ? $overrides['user_id'] : $userId;
    $conf = array_key_exists('payment_confirmed', $overrides) ? $overrides['payment_confirmed'] : $confirmed;
    $pageNum = $overrides['page'] ?? null;

    $out = [];
    foreach ($params as $k => $v) {
        if ($v !== null && $v !== '') {
            $out[$k] = $v;
        }
    }
    if ($uid) {
        $out['user_id'] = (int) $uid;
    }
    if ($conf === true) {
        $out['payment_confirmed'] = '1';
    } elseif ($conf === false) {
        $out['payment_confirmed'] = '0';
    }
    if ($pageNum && (int) $pageNum > 1) {
        $out['page'] = (int) $pageNum;
    }

    return ProductHelper::url('/admin/finance-journal' . ($out ? '?' . http_build_query($out) : ''));
};
?>
<div class="adm" id="finance-journal">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= htmlspecialchars(t('admin.finance_journal')) ?></h1>
            <p class="adm-sub"><?= htmlspecialchars(t('admin.finance_journal_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <span class="muted" style="font-size:13px"><span class="strong"><?= number_format($total, 0, '', ' ') ?></span> <?= htmlspecialchars(t('admin.finance_journal_total')) ?></span>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <div class="adm-alert adm-alert--info"><?= htmlspecialchars(t('admin.finance_journal_readonly_note')) ?></div>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/admin/finance-journal') ?>" class="adm-card">
        <div class="adm-card-body" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(190px,1fr))">
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_search')) ?></span>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
                       placeholder="<?= htmlspecialchars(t('admin.finance_journal_search_ph'), ENT_QUOTES) ?>"
                       class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_stream')) ?></span>
                <select name="stream_type" class="adm-select">
                    <option value=""><?= htmlspecialchars(t('admin.finance_journal_all_streams')) ?></option>
                    <?php foreach ($streamLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $streamType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_service')) ?></span>
                <input type="text" name="service_code" value="<?= htmlspecialchars($serviceCode) ?>"
                       placeholder="boost, vip, service_listing_publish…"
                       class="adm-input mono">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_status')) ?></span>
                <input type="text" name="status" value="<?= htmlspecialchars($status) ?>"
                       placeholder="pending_payment, paid, applied…"
                       class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_confirmed')) ?></span>
                <select name="payment_confirmed" class="adm-select">
                    <option value=""><?= htmlspecialchars(t('admin.finance_journal_confirmed_any')) ?></option>
                    <option value="1" <?= $confirmed === true ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_confirmed_yes')) ?></option>
                    <option value="0" <?= $confirmed === false ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_confirmed_no')) ?></option>
                </select>
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_user')) ?></span>
                <input type="number" name="user_id" min="1" value="<?= $userId ? (int) $userId : '' ?>"
                       class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_from')) ?></span>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                       class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_to')) ?></span>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                       class="adm-input">
            </label>
            <label class="adm-field">
                <span class="adm-label"><?= htmlspecialchars(t('admin.finance_journal_sort')) ?></span>
                <select name="sort" class="adm-select">
                    <option value="created_at_desc" <?= $sort === 'created_at_desc' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_sort_newest')) ?></option>
                    <option value="created_at_asc" <?= $sort === 'created_at_asc' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_sort_oldest')) ?></option>
                    <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_sort_amount')) ?></option>
                </select>
            </label>
        </div>
        <div class="adm-card-foot adm-form-actions">
            <a href="<?= ProductHelper::url('/admin/finance-journal') ?>" class="adm-btn adm-btn--ghost"><?= htmlspecialchars(t('admin.finance_journal_reset')) ?></a>
            <button type="submit" class="adm-btn adm-btn--primary"><?= htmlspecialchars(t('admin.finance_journal_apply')) ?></button>
        </div>
    </form>

    <section class="adm-card">
    <?php if ($items === []): ?>
        <div class="adm-empty"><?= htmlspecialchars(t('admin.finance_journal_empty')) ?></div>
    <?php else: ?>
        <div class="adm-table-wrap">
            <table class="adm-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars(t('admin.finance_col_when')) ?></th>
                        <th><?= htmlspecialchars(t('admin.finance_col_stream')) ?></th>
                        <th><?= htmlspecialchars(t('admin.finance_col_service')) ?></th>
                        <th class="num"><?= htmlspecialchars(t('admin.finance_col_user')) ?></th>
                        <th class="num"><?= htmlspecialchars(t('admin.finance_col_amount')) ?></th>
                        <th><?= htmlspecialchars(t('admin.finance_col_order_status')) ?></th>
                        <th><?= htmlspecialchars(t('admin.finance_col_payment_status')) ?></th>
                        <th><?= htmlspecialchars(t('admin.finance_col_confirmed')) ?></th>
                        <th><?= htmlspecialchars(t('admin.finance_col_pg')) ?></th>
                        <th class="num"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $row):
                        $entryId = (string) ($row['entry_id'] ?? '');
                        $stream = (string) ($row['stream_type'] ?? '');
                        $confirmedRow = !empty($row['payment_confirmed']);
                        $amount = $row['amount'] ?? null;
                        $detailHref = ProductHelper::url('/admin/finance-journal/' . rawurlencode($entryId));
                    ?>
                        <tr>
                            <td class="nowrap muted"><?= htmlspecialchars(substr((string) ($row['created_at'] ?? ''), 0, 16)) ?></td>
                            <td class="nowrap"><?= htmlspecialchars($streamLabels[$stream] ?? $stream) ?></td>
                            <td style="min-width:10rem">
                                <div class="strong"><?= htmlspecialchars((string) ($row['service_title'] ?? $row['service_code'] ?? '—')) ?></div>
                                <div class="mono subtle" style="font-size:11px;margin-top:1px"><?= htmlspecialchars((string) ($row['vas_id'] ?? $row['service_code'] ?? '')) ?></div>
                            </td>
                            <td class="num mono"><?= isset($row['user_id']) ? (int) $row['user_id'] : '—' ?></td>
                            <td class="num strong">
                                <?= $amount !== null ? htmlspecialchars(Wallet::formatMoney((int) $amount)) : '—' ?>
                            </td>
                            <td class="mono nowrap"><?= htmlspecialchars((string) ($row['order_status'] ?? '—')) ?></td>
                            <td class="mono nowrap"><?= htmlspecialchars((string) ($row['payment_status'] ?? '—')) ?></td>
                            <td class="nowrap">
                                <?php if ($confirmedRow): ?>
                                    <span class="adm-badge adm-badge--ok"><span class="adm-dot"></span><?= htmlspecialchars(t('admin.finance_yes')) ?></span>
                                <?php else: ?>
                                    <span class="adm-badge adm-badge--warn"><span class="adm-dot"></span><?= htmlspecialchars(t('admin.finance_no')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mono subtle truncate" style="max-width:9rem" title="<?= htmlspecialchars((string) ($row['pg_order_id'] ?? '')) ?>">
                                <?= htmlspecialchars((string) ($row['pg_order_id'] ?? '—')) ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="<?= htmlspecialchars($detailHref) ?>" class="adm-link"><?= htmlspecialchars(t('admin.finance_open')) ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="adm-pager">
                <span><?= htmlspecialchars(t('admin.finance_journal_page', ['page' => (string) $page, 'pages' => (string) $pages])) ?></span>
                <div class="adm-toolbar">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($buildUrl(['page' => $page - 1])) ?>" class="adm-btn adm-btn--sm"><?= htmlspecialchars(t('admin.finance_prev')) ?></a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= htmlspecialchars($buildUrl(['page' => $page + 1])) ?>" class="adm-btn adm-btn--sm"><?= htmlspecialchars(t('admin.finance_next')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </section>
</div>
