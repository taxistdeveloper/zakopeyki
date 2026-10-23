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
<section class="space-y-5 fade-up pb-8" id="finance-journal">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.finance_journal')) ?></h1>
            <p class="text-sm text-gray-500 mt-1 max-w-2xl"><?= htmlspecialchars(t('admin.finance_journal_hint')) ?></p>
        </div>
        <div class="text-xs font-semibold text-gray-400"><?= number_format($total, 0, '', ' ') ?> <?= htmlspecialchars(t('admin.finance_journal_total')) ?></div>
    </div>

    <div class="rounded-2xl border border-sky-200/80 dark:border-sky-800/40 bg-sky-50/70 dark:bg-sky-950/20 px-4 py-3 text-sm text-sky-900 dark:text-sky-200">
        <?= htmlspecialchars(t('admin.finance_journal_readonly_note')) ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/admin/finance-journal') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft p-4 sm:p-5 space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_search')) ?></span>
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>"
                       placeholder="<?= htmlspecialchars(t('admin.finance_journal_search_ph'), ENT_QUOTES) ?>"
                       class="mt-1 w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_stream')) ?></span>
                <select name="stream_type" class="mt-1 w-full h-11 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
                    <option value=""><?= htmlspecialchars(t('admin.finance_journal_all_streams')) ?></option>
                    <?php foreach ($streamLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $streamType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_service')) ?></span>
                <input type="text" name="service_code" value="<?= htmlspecialchars($serviceCode) ?>"
                       placeholder="boost, vip, service_listing_publish…"
                       class="mt-1 w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm font-mono">
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_status')) ?></span>
                <input type="text" name="status" value="<?= htmlspecialchars($status) ?>"
                       placeholder="pending_payment, paid, applied…"
                       class="mt-1 w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_confirmed')) ?></span>
                <select name="payment_confirmed" class="mt-1 w-full h-11 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
                    <option value=""><?= htmlspecialchars(t('admin.finance_journal_confirmed_any')) ?></option>
                    <option value="1" <?= $confirmed === true ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_confirmed_yes')) ?></option>
                    <option value="0" <?= $confirmed === false ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_confirmed_no')) ?></option>
                </select>
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_user')) ?></span>
                <input type="number" name="user_id" min="1" value="<?= $userId ? (int) $userId : '' ?>"
                       class="mt-1 w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_from')) ?></span>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                       class="mt-1 w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_to')) ?></span>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                       class="mt-1 w-full h-11 px-4 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
            </label>
            <label class="block">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_sort')) ?></span>
                <select name="sort" class="mt-1 w-full h-11 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 bg-white dark:bg-white/[0.06] text-sm">
                    <option value="created_at_desc" <?= $sort === 'created_at_desc' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_sort_newest')) ?></option>
                    <option value="created_at_asc" <?= $sort === 'created_at_asc' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_sort_oldest')) ?></option>
                    <option value="amount_desc" <?= $sort === 'amount_desc' ? 'selected' : '' ?>><?= htmlspecialchars(t('admin.finance_journal_sort_amount')) ?></option>
                </select>
            </label>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="h-10 px-4 rounded-xl text-xs font-bold text-white bg-brand-600 hover:bg-brand-700"><?= htmlspecialchars(t('admin.finance_journal_apply')) ?></button>
            <a href="<?= ProductHelper::url('/admin/finance-journal') ?>" class="h-10 px-4 rounded-xl text-xs font-bold border border-black/10 dark:border-white/15 inline-flex items-center"><?= htmlspecialchars(t('admin.finance_journal_reset')) ?></a>
        </div>
    </form>

    <?php if ($items === []): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-5 py-12 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('admin.finance_journal_empty')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-ink-50/80 dark:bg-white/[0.03] border-b border-black/[0.06] dark:border-white/10">
                        <tr class="text-[10px] uppercase tracking-wider text-gray-400">
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_when')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_stream')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_service')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_user')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_amount')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_order_status')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_payment_status')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_confirmed')) ?></th>
                            <th class="px-4 py-3 font-bold"><?= htmlspecialchars(t('admin.finance_col_pg')) ?></th>
                            <th class="px-4 py-3 font-bold"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-black/[0.04] dark:divide-white/5">
                        <?php foreach ($items as $row):
                            $entryId = (string) ($row['entry_id'] ?? '');
                            $stream = (string) ($row['stream_type'] ?? '');
                            $confirmedRow = !empty($row['payment_confirmed']);
                            $amount = $row['amount'] ?? null;
                            $detailHref = ProductHelper::url('/admin/finance-journal/' . rawurlencode($entryId));
                        ?>
                            <tr class="hover:bg-brand-50/30 dark:hover:bg-white/[0.02] transition">
                                <td class="px-4 py-3 whitespace-nowrap text-gray-500"><?= htmlspecialchars(substr((string) ($row['created_at'] ?? ''), 0, 16)) ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-lg bg-black/[0.04] dark:bg-white/10 text-[10px] font-bold">
                                        <?= htmlspecialchars($streamLabels[$stream] ?? $stream) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 min-w-[8rem]">
                                    <div class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars((string) ($row['service_title'] ?? $row['service_code'] ?? '—')) ?></div>
                                    <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?= htmlspecialchars((string) ($row['vas_id'] ?? $row['service_code'] ?? '')) ?></div>
                                </td>
                                <td class="px-4 py-3 font-mono"><?= isset($row['user_id']) ? (int) $row['user_id'] : '—' ?></td>
                                <td class="px-4 py-3 font-semibold whitespace-nowrap">
                                    <?= $amount !== null ? htmlspecialchars(Wallet::formatMoney((int) $amount)) : '—' ?>
                                </td>
                                <td class="px-4 py-3"><code class="text-[10px]"><?= htmlspecialchars((string) ($row['order_status'] ?? '—')) ?></code></td>
                                <td class="px-4 py-3"><code class="text-[10px]"><?= htmlspecialchars((string) ($row['payment_status'] ?? '—')) ?></code></td>
                                <td class="px-4 py-3">
                                    <?php if ($confirmedRow): ?>
                                        <span class="text-emerald-700 dark:text-emerald-300 font-bold"><?= htmlspecialchars(t('admin.finance_yes')) ?></span>
                                    <?php else: ?>
                                        <span class="text-amber-700 dark:text-amber-300 font-bold"><?= htmlspecialchars(t('admin.finance_no')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 font-mono text-[10px] text-gray-500 max-w-[9rem] truncate" title="<?= htmlspecialchars((string) ($row['pg_order_id'] ?? '')) ?>">
                                    <?= htmlspecialchars((string) ($row['pg_order_id'] ?? '—')) ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="<?= htmlspecialchars($detailHref) ?>" class="text-xs font-bold text-brand-600 hover:underline"><?= htmlspecialchars(t('admin.finance_open')) ?> →</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($pages > 1): ?>
            <div class="flex flex-wrap items-center justify-between gap-3 text-xs">
                <span class="text-gray-400"><?= htmlspecialchars(t('admin.finance_journal_page', ['page' => (string) $page, 'pages' => (string) $pages])) ?></span>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($buildUrl(['page' => $page - 1])) ?>" class="h-9 px-3 rounded-xl border border-black/10 dark:border-white/15 inline-flex items-center font-bold"><?= htmlspecialchars(t('admin.finance_prev')) ?></a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= htmlspecialchars($buildUrl(['page' => $page + 1])) ?>" class="h-9 px-3 rounded-xl border border-black/10 dark:border-white/15 inline-flex items-center font-bold"><?= htmlspecialchars(t('admin.finance_next')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
