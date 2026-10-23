<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Finance\FinanceJournalPolicy;

$entry = is_array($entry ?? null) ? $entry : [];
$history = is_array($entry['history'] ?? null) ? $entry['history'] : [];
$links = is_array($entry['related_links'] ?? null) ? $entry['related_links'] : [];
$flags = is_array($entry['flags'] ?? null) ? $entry['flags'] : [];

$stream = (string) ($entry['stream_type'] ?? '');
$streamLabels = [
    FinanceJournalPolicy::STREAM_VAS => t('admin.finance_stream_vas'),
    FinanceJournalPolicy::STREAM_ESCROW => t('admin.finance_stream_escrow'),
    FinanceJournalPolicy::STREAM_ARBITRATION => t('admin.finance_stream_arbitration'),
    FinanceJournalPolicy::STREAM_COMMISSION_OTHER => t('admin.finance_stream_commission_other'),
    FinanceJournalPolicy::STREAM_DELIVERY => t('admin.finance_stream_delivery'),
    FinanceJournalPolicy::STREAM_WALLET => t('admin.finance_stream_wallet'),
    FinanceJournalPolicy::STREAM_OTHER => t('admin.finance_stream_other'),
];
$confirmed = !empty($entry['payment_confirmed']);
$amount = $entry['amount'] ?? null;
?>
<section class="space-y-5 fade-up pb-8">
    <div>
        <a href="<?= ProductHelper::url('/admin/finance-journal') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.finance_journal')) ?></a>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
        <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.finance_journal_detail')) ?></h1>
        <p class="text-xs text-gray-400 font-mono mt-1"><?= htmlspecialchars((string) ($entry['entry_id'] ?? '')) ?></p>
    </div>

    <div class="rounded-2xl border border-sky-200/80 dark:border-sky-800/40 bg-sky-50/70 dark:bg-sky-950/20 px-4 py-3 text-sm text-sky-900 dark:text-sky-200">
        <?= htmlspecialchars(t('admin.finance_journal_readonly_note')) ?>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($entry === []): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-5 py-12 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('admin.finance_journal_not_found')) ?>
        </div>
    <?php else: ?>
        <div class="grid lg:grid-cols-2 gap-4">
            <div class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 space-y-3">
                <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.finance_detail_summary')) ?></h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_stream')) ?></dt>
                        <dd class="font-semibold"><?= htmlspecialchars($streamLabels[$stream] ?? $stream) ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_service')) ?></dt>
                        <dd class="font-semibold text-right"><?= htmlspecialchars((string) ($entry['service_title'] ?? $entry['service_code'] ?? '—')) ?></dd>
                    </div>
                    <?php if (!empty($entry['vas_id'])): ?>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-400">VAS</dt>
                            <dd class="font-mono text-xs"><?= htmlspecialchars((string) $entry['vas_id']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_amount')) ?></dt>
                        <dd class="font-bold"><?= $amount !== null ? htmlspecialchars(Wallet::formatMoney((int) $amount)) : '—' ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_confirmed')) ?></dt>
                        <dd class="font-bold <?= $confirmed ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' ?>">
                            <?= htmlspecialchars($confirmed ? t('admin.finance_confirmed_label') : t('admin.finance_unconfirmed_label')) ?>
                        </dd>
                    </div>
                    <?php if (in_array('simulated', $flags, true)): ?>
                        <p class="text-[11px] text-amber-700 dark:text-amber-300"><?= htmlspecialchars(t('admin.finance_flag_simulated')) ?></p>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 space-y-3">
                <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.finance_detail_parties')) ?></h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_user')) ?></dt>
                        <dd class="font-mono">
                            <?php if (!empty($entry['user_id'])): ?>
                                <a class="text-brand-600 font-bold hover:underline" href="<?= ProductHelper::url('/admin/users/' . (int) $entry['user_id']) ?>">#<?= (int) $entry['user_id'] ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if (!empty($entry['counterparty_user_id'])): ?>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_counterparty')) ?></dt>
                            <dd class="font-mono">
                                <a class="text-brand-600 font-bold hover:underline" href="<?= ProductHelper::url('/admin/users/' . (int) $entry['counterparty_user_id']) ?>">#<?= (int) $entry['counterparty_user_id'] ?></a>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_source')) ?></dt>
                        <dd class="font-mono text-xs"><?= htmlspecialchars((string) ($entry['source_table'] ?? '')) ?> #<?= (int) ($entry['source_id'] ?? 0) ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_internal_ref')) ?></dt>
                        <dd class="font-mono text-xs break-all"><?= htmlspecialchars((string) ($entry['internal_ref'] ?? '—')) ?></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 space-y-3">
                <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.finance_detail_statuses')) ?></h2>
                <p class="text-[11px] text-gray-500"><?= htmlspecialchars(t('admin.finance_status_hint')) ?></p>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_order_status')) ?></dt>
                        <dd><code class="text-xs"><?= htmlspecialchars((string) ($entry['order_status'] ?? '—')) ?></code></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_payment_status')) ?></dt>
                        <dd><code class="text-xs"><?= htmlspecialchars((string) ($entry['payment_status'] ?? '—')) ?></code></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_col_when')) ?></dt>
                        <dd class="text-xs"><?= htmlspecialchars((string) ($entry['created_at'] ?? '—')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_paid_at')) ?></dt>
                        <dd class="text-xs"><?= htmlspecialchars((string) ($entry['paid_at'] ?? '—')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_updated_at')) ?></dt>
                        <dd class="text-xs"><?= htmlspecialchars((string) ($entry['updated_at'] ?? '—')) ?></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 space-y-3">
                <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.finance_detail_provider')) ?></h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400">pg_order_id</dt>
                        <dd class="font-mono text-xs break-all"><?= htmlspecialchars((string) ($entry['pg_order_id'] ?? '—')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400">pg_payment_id</dt>
                        <dd class="font-mono text-xs break-all"><?= htmlspecialchars((string) ($entry['pg_payment_id'] ?? '—')) ?></dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-400"><?= htmlspecialchars(t('admin.finance_channel')) ?></dt>
                        <dd class="text-xs font-semibold"><?= htmlspecialchars((string) ($entry['channel'] ?? '—')) ?></dd>
                    </div>
                    <?php if (!empty($entry['failure_reason'])): ?>
                        <div>
                            <dt class="text-gray-400 text-xs"><?= htmlspecialchars(t('admin.finance_failure')) ?></dt>
                            <dd class="mt-1 text-sm text-red-700 dark:text-red-300"><?= htmlspecialchars((string) $entry['failure_reason']) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 space-y-3">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.finance_history')) ?></h2>
            <p class="text-[11px] text-gray-500"><?= htmlspecialchars(t('admin.finance_history_hint')) ?></p>
            <?php if ($history === []): ?>
                <p class="text-sm text-gray-400"><?= htmlspecialchars(t('admin.finance_history_empty')) ?></p>
            <?php else: ?>
                <ol class="space-y-2">
                    <?php foreach ($history as $ev): ?>
                        <li class="flex gap-3 text-sm border-l-2 border-brand-300/60 pl-3">
                            <div class="min-w-0">
                                <div class="text-[10px] text-gray-400 font-mono"><?= htmlspecialchars((string) ($ev['at'] ?? '')) ?></div>
                                <div class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars((string) ($ev['label'] ?? $ev['type'] ?? '')) ?></div>
                                <div class="text-[10px] text-gray-400"><?= htmlspecialchars((string) ($ev['type'] ?? '')) ?></div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <?php if ($links !== []): ?>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($links as $link):
                    $path = (string) ($link['path'] ?? '');
                    if ($path === '') {
                        continue;
                    }
                ?>
                    <a href="<?= ProductHelper::url($path) ?>"
                       class="h-10 px-4 rounded-xl text-xs font-bold border border-black/10 dark:border-white/15 inline-flex items-center hover:border-brand-400 transition">
                        <?= htmlspecialchars((string) ($link['label'] ?? 'link')) ?> →
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
