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
<div class="adm">
    <header class="adm-head">
        <div>
            <nav class="adm-crumbs">
                <a href="<?= ProductHelper::url('/admin/finance-journal') ?>"><?= htmlspecialchars(t('admin.finance_journal')) ?></a>
                <span class="sep">/</span>
                <span class="mono"><?= htmlspecialchars((string) ($entry['entry_id'] ?? '')) ?></span>
            </nav>
            <h1 class="adm-title"><?= htmlspecialchars(t('admin.finance_journal_detail')) ?></h1>
        </div>
        <?php if ($entry !== [] && $links !== []): ?>
            <div class="adm-head-actions">
                <?php foreach ($links as $link):
                    $path = (string) ($link['path'] ?? '');
                    if ($path === '') {
                        continue;
                    }
                ?>
                    <a href="<?= ProductHelper::url($path) ?>" class="adm-btn"><?= htmlspecialchars((string) ($link['label'] ?? 'link')) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <div class="adm-alert adm-alert--info"><?= htmlspecialchars(t('admin.finance_journal_readonly_note')) ?></div>

    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($entry === []): ?>
        <div class="adm-card adm-empty"><?= htmlspecialchars(t('admin.finance_journal_not_found')) ?></div>
    <?php else: ?>
        <div class="adm-grid-2">
            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.finance_detail_summary')) ?></h2>
                </div>
                <dl class="adm-kv">
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_stream')) ?></dt>
                        <dd><?= htmlspecialchars($streamLabels[$stream] ?? $stream) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_service')) ?></dt>
                        <dd><?= htmlspecialchars((string) ($entry['service_title'] ?? $entry['service_code'] ?? '—')) ?></dd>
                    </div>
                    <?php if (!empty($entry['vas_id'])): ?>
                        <div>
                            <dt>VAS</dt>
                            <dd class="mono"><?= htmlspecialchars((string) $entry['vas_id']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_amount')) ?></dt>
                        <dd class="strong"><?= $amount !== null ? htmlspecialchars(Wallet::formatMoney((int) $amount)) : '—' ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_confirmed')) ?></dt>
                        <dd>
                            <span class="adm-badge adm-badge--<?= $confirmed ? 'ok' : 'warn' ?>"><span class="adm-dot"></span><?= htmlspecialchars($confirmed ? t('admin.finance_confirmed_label') : t('admin.finance_unconfirmed_label')) ?></span>
                        </dd>
                    </div>
                </dl>
                <?php if (in_array('simulated', $flags, true)): ?>
                    <div class="adm-card-foot" style="color:var(--adm-warn)"><?= htmlspecialchars(t('admin.finance_flag_simulated')) ?></div>
                <?php endif; ?>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.finance_detail_parties')) ?></h2>
                </div>
                <dl class="adm-kv">
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_user')) ?></dt>
                        <dd class="mono">
                            <?php if (!empty($entry['user_id'])): ?>
                                <a class="adm-link" href="<?= ProductHelper::url('/admin/users/' . (int) $entry['user_id']) ?>">#<?= (int) $entry['user_id'] ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if (!empty($entry['counterparty_user_id'])): ?>
                        <div>
                            <dt><?= htmlspecialchars(t('admin.finance_counterparty')) ?></dt>
                            <dd class="mono">
                                <a class="adm-link" href="<?= ProductHelper::url('/admin/users/' . (int) $entry['counterparty_user_id']) ?>">#<?= (int) $entry['counterparty_user_id'] ?></a>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_source')) ?></dt>
                        <dd class="mono"><?= htmlspecialchars((string) ($entry['source_table'] ?? '')) ?> #<?= (int) ($entry['source_id'] ?? 0) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_internal_ref')) ?></dt>
                        <dd class="mono"><?= htmlspecialchars((string) ($entry['internal_ref'] ?? '—')) ?></dd>
                    </div>
                </dl>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <div>
                        <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.finance_detail_statuses')) ?></h2>
                        <p class="adm-card-sub"><?= htmlspecialchars(t('admin.finance_status_hint')) ?></p>
                    </div>
                </div>
                <dl class="adm-kv">
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_order_status')) ?></dt>
                        <dd class="mono"><?= htmlspecialchars((string) ($entry['order_status'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_payment_status')) ?></dt>
                        <dd class="mono"><?= htmlspecialchars((string) ($entry['payment_status'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_col_when')) ?></dt>
                        <dd><?= htmlspecialchars((string) ($entry['created_at'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_paid_at')) ?></dt>
                        <dd><?= htmlspecialchars((string) ($entry['paid_at'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_updated_at')) ?></dt>
                        <dd><?= htmlspecialchars((string) ($entry['updated_at'] ?? '—')) ?></dd>
                    </div>
                </dl>
            </section>

            <section class="adm-card">
                <div class="adm-card-head">
                    <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.finance_detail_provider')) ?></h2>
                </div>
                <dl class="adm-kv">
                    <div>
                        <dt class="mono">pg_order_id</dt>
                        <dd class="mono"><?= htmlspecialchars((string) ($entry['pg_order_id'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt class="mono">pg_payment_id</dt>
                        <dd class="mono"><?= htmlspecialchars((string) ($entry['pg_payment_id'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt><?= htmlspecialchars(t('admin.finance_channel')) ?></dt>
                        <dd><?= htmlspecialchars((string) ($entry['channel'] ?? '—')) ?></dd>
                    </div>
                    <?php if (!empty($entry['failure_reason'])): ?>
                        <div>
                            <dt><?= htmlspecialchars(t('admin.finance_failure')) ?></dt>
                            <dd style="color:var(--adm-danger)"><?= htmlspecialchars((string) $entry['failure_reason']) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>
        </div>

        <section class="adm-card">
            <div class="adm-card-head">
                <div>
                    <h2 class="adm-card-title"><?= htmlspecialchars(t('admin.finance_history')) ?></h2>
                    <p class="adm-card-sub"><?= htmlspecialchars(t('admin.finance_history_hint')) ?></p>
                </div>
            </div>
            <?php if ($history === []): ?>
                <div class="adm-empty"><?= htmlspecialchars(t('admin.finance_history_empty')) ?></div>
            <?php else: ?>
                <ol class="adm-list" style="margin:0;padding:0;list-style:none">
                    <?php foreach ($history as $ev): ?>
                        <li class="adm-row">
                            <div class="adm-row-main">
                                <div class="adm-row-title"><?= htmlspecialchars((string) ($ev['label'] ?? $ev['type'] ?? '')) ?></div>
                                <div class="adm-row-sub mono"><?= htmlspecialchars((string) ($ev['type'] ?? '')) ?></div>
                            </div>
                            <div class="adm-row-side mono nowrap"><?= htmlspecialchars((string) ($ev['at'] ?? '')) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
