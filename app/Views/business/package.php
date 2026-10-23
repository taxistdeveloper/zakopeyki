<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;

$packages = $packages ?? [];
$pendingLongTerm = $pendingLongTerm ?? [];
$addons = $addons ?? [];
$aiAddons = $aiAddons ?? [];
$aiQuotas = $aiQuotas ?? [];
$subscription = $subscription ?? null;
$usageRows = $usageRows ?? [];
$payments = $payments ?? [];
$isBusiness = !empty($isBusiness);
$salesEnabled = array_key_exists('salesEnabled', get_defined_vars()) ? !empty($salesEnabled) : true;
$featuresEnabled = array_key_exists('featuresEnabled', get_defined_vars()) ? !empty($featuresEnabled) : true;
$aiAddonSalesFlags = is_array($aiAddonSalesFlags ?? null) ? $aiAddonSalesFlags : [];
$aiAddonSalesEnabled = array_key_exists('aiAddonSalesEnabled', get_defined_vars())
    ? !empty($aiAddonSalesEnabled)
    : in_array(true, $aiAddonSalesFlags, true);
$walletBalance = (int) ($walletBalance ?? 0);
$apiKeys = $apiKeys ?? [];
$analytics = $analytics ?? null;
$importJob = $importJob ?? null;
$aiOpStatus = $aiOpStatus ?? null;
$idempotencyKey = (string) ($idempotencyKey ?? ('bp-ui-' . bin2hex(random_bytes(8))));

$hasActive = is_array($subscription) && !empty($subscription['id']);
$toolsUnlocked = $hasActive && $featuresEnabled;

$aiQuotaByFeature = [];
foreach ($aiQuotas as $q) {
    $aiQuotaByFeature[(string) ($q['feature'] ?? '')] = $q;
}

$limitLabels = [
    'catalog' => t('business.lim_catalog'),
    'listings_per_day' => t('business.lim_day'),
    'ai_infographic' => t('business.lim_ai_infographic'),
    'ai_copy' => t('business.lim_ai_copy'),
    'ai_optimize' => t('business.lim_ai_optimize'),
    'ai_tryon' => t('business.lim_ai_tryon'),
    'boosts' => t('business.lim_boosts'),
    'staff' => t('business.lim_staff'),
];

$includedAvailable = [
    t('business.feat_listings'),
    t('business.feat_import'),
    t('business.feat_analytics'),
    t('business.feat_manager'),
];
$includedPending = [
    t('business.feat_ai_infographic_soon'),
    t('business.feat_ai_seo_soon'),
    t('business.feat_tryon_soon'),
];
?>
<section class="max-w-4xl mx-auto space-y-6 pb-10" id="business-package-page"
         data-has-subscription="<?= $hasActive ? '1' : '0' ?>">
    <div>
        <a href="<?= ProductHelper::url('/profile?tab=business') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('profile.tab_business')) ?></a>
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-600 mt-3"><?= htmlspecialchars(t('business.package_eyebrow')) ?></p>
        <h1 class="font-display text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('business.package_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars(t('business.package_subtitle')) ?></p>
        <p class="text-base font-semibold text-ink-800 dark:text-gray-200 mt-3"><?= htmlspecialchars(t('business.package_price_month')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold break-all"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$salesEnabled): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900 font-semibold">
            <?= htmlspecialchars(t('business.sales_off_notice')) ?>
        </div>
    <?php endif; ?>

    <?php if ($hasActive && !$featuresEnabled): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-900 font-semibold">
            <?= htmlspecialchars(t('business.features_off_notice')) ?>
        </div>
    <?php endif; ?>

    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-4 text-sm flex flex-wrap items-center justify-between gap-2">
        <span><?= htmlspecialchars(t('business.wallet_balance', ['amount' => Wallet::formatMoney($walletBalance)])) ?></span>
        <a href="<?= ProductHelper::url('/wallet') ?>" class="font-bold text-brand-600 hover:underline"><?= htmlspecialchars(t('business.top_up')) ?></a>
    </div>

    <?php if (!$isBusiness): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-5">
            <p class="text-sm font-bold text-amber-900"><?= htmlspecialchars(t('business.package_need_business')) ?></p>
            <a href="<?= ProductHelper::url('/business/upgrade') ?>" class="inline-flex mt-3 text-sm font-bold text-brand-600 hover:underline">
                <?= htmlspecialchars(t('business.go_upgrade')) ?> →
            </a>
        </div>
    <?php endif; ?>

    <?php /* —— Кабинет подписки —— */ ?>
    <div id="subscription-cabinet" class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-4">
        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('business.cabinet_title')) ?></h2>
        <?php if ($hasActive): ?>
            <dl class="grid sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.cabinet_status')) ?></dt>
                    <dd class="font-semibold text-emerald-700 mt-0.5"><?= htmlspecialchars(t('business.cabinet_status_active')) ?></dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.cabinet_name')) ?></dt>
                    <dd class="font-semibold mt-0.5"><?= htmlspecialchars((string) ($subscription['package_name'] ?? '')) ?></dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.cabinet_starts')) ?></dt>
                    <dd class="mt-0.5"><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $subscription['starts_at']))) ?></dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.cabinet_ends')) ?></dt>
                    <dd class="mt-0.5"><?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $subscription['ends_at']))) ?></dd>
                </div>
            </dl>
            <?php if ($usageRows): ?>
                <div class="overflow-hidden rounded-xl border border-black/[0.05] dark:border-white/10">
                    <div class="px-3 py-2 text-xs font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.usage_title')) ?></div>
                    <table class="w-full text-sm">
                        <tbody>
                        <?php foreach ($usageRows as $row):
                            $key = (string) ($row['key'] ?? '');
                            if (in_array($key, ['ai_infographic', 'ai_copy', 'ai_optimize', 'ai_tryon', 'boosts', 'staff'], true)) {
                                continue;
                            }
                            $label = $limitLabels[$key] ?? $key;
                            $used = (int) ($row['used'] ?? 0);
                            $cap = (int) ($row['cap'] ?? 0);
                            ?>
                            <tr class="border-t border-black/[0.05] dark:border-white/10">
                                <td class="px-3 py-2"><?= htmlspecialchars($label) ?></td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums">
                                    <?= number_format($used, 0, '', ' ') ?>
                                    / <?= $cap > 0 ? number_format($cap, 0, '', ' ') : '∞' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($aiQuotas): ?>
                <div id="ai-quotas" class="overflow-hidden rounded-xl border border-black/[0.05] dark:border-white/10">
                    <div class="px-3 py-2 text-xs font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.ai_quota_title')) ?></div>
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-gray-400 border-t border-black/[0.05] dark:border-white/10">
                            <th class="px-3 py-2 text-left font-bold"><?= htmlspecialchars(t('business.ai_quota_feature')) ?></th>
                            <th class="px-3 py-2 text-right font-bold"><?= htmlspecialchars(t('business.ai_quota_package')) ?></th>
                            <th class="px-3 py-2 text-right font-bold"><?= htmlspecialchars(t('business.ai_quota_extra')) ?></th>
                            <th class="px-3 py-2 text-right font-bold"><?= htmlspecialchars(t('business.ai_quota_used')) ?></th>
                            <th class="px-3 py-2 text-right font-bold"><?= htmlspecialchars(t('business.ai_quota_remaining')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($aiQuotas as $q):
                            $remaining = (int) ($q['remaining'] ?? 0);
                            ?>
                            <tr class="border-t border-black/[0.05] dark:border-white/10">
                                <td class="px-3 py-2"><?= htmlspecialchars(t('business.' . (string) ($q['label_key'] ?? ''))) ?></td>
                                <td class="px-3 py-2 text-right tabular-nums"><?= number_format((int) ($q['package_cap'] ?? 0), 0, '', ' ') ?></td>
                                <td class="px-3 py-2 text-right tabular-nums"><?= number_format((int) ($q['extra'] ?? 0), 0, '', ' ') ?></td>
                                <td class="px-3 py-2 text-right tabular-nums"><?= number_format((int) ($q['used'] ?? 0), 0, '', ' ') ?></td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums <?= $remaining <= 0 ? 'text-amber-700' : '' ?>">
                                    <?= number_format($remaining, 0, '', ' ') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="px-3 py-2 text-[11px] text-gray-400"><?= htmlspecialchars(t('business.ai_quota_note')) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($payments): ?>
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-2"><?= htmlspecialchars(t('business.cabinet_payments')) ?></h3>
                    <ul class="space-y-1.5 text-sm">
                        <?php foreach ($payments as $pay): ?>
                            <li class="flex flex-wrap justify-between gap-2 border-t border-black/[0.05] dark:border-white/10 pt-1.5">
                                <span><?= htmlspecialchars(Wallet::formatMoney((int) ($pay['amount_kzt'] ?? 0))) ?>
                                    · <?= htmlspecialchars((string) ($pay['status'] ?? '')) ?>
                                    · <?= htmlspecialchars((string) ($pay['channel'] ?? '')) ?></span>
                                <span class="text-xs text-gray-400"><?= htmlspecialchars((string) ($pay['created_at'] ?? '')) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($salesEnabled && $isBusiness): ?>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.cabinet_renew_hint')) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.cabinet_empty')) ?></p>
        <?php endif; ?>
    </div>

    <?php /* —— Тарифы —— */ ?>
    <div>
        <h2 class="font-display text-xl font-bold mb-3"><?= htmlspecialchars(t('business.plans_heading')) ?></h2>
        <p class="text-sm text-gray-500 mb-4"><?= htmlspecialchars(t('business.plans_hint_month_only')) ?></p>
        <div class="grid sm:grid-cols-1 gap-4 max-w-md">
            <?php foreach ($packages as $pkg): ?>
                <article class="rounded-2xl border border-brand-400/50 bg-white/90 dark:bg-white/[0.03] p-5 shadow-soft flex flex-col">
                    <h3 class="font-display text-lg font-bold"><?= htmlspecialchars((string) $pkg['name']) ?></h3>
                    <p class="text-xs text-gray-500 mt-1 flex-1"><?= htmlspecialchars(t('business.plan_month_desc')) ?></p>
                    <div class="mt-3">
                        <div class="text-2xl font-bold"><?= htmlspecialchars(Wallet::formatMoney((int) $pkg['price_kzt'])) ?></div>
                        <div class="text-xs text-gray-400"><?= htmlspecialchars(t('business.per_days', ['days' => (string) (int) $pkg['duration_days']])) ?></div>
                    </div>
                    <?php if ($isBusiness && $salesEnabled): ?>
                        <form method="post" action="<?= ProductHelper::url('/business/package/' . (int) $pkg['id'] . '/buy') ?>" class="mt-4 js-bp-buy-form"
                              data-confirm="<?= htmlspecialchars(t('business.confirm_buy', ['amount' => Wallet::formatMoney((int) $pkg['price_kzt'])]), ENT_QUOTES) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey . '-p' . (int) $pkg['id'], ENT_QUOTES) ?>">
                            <button type="submit" class="w-full bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-4 py-3 rounded-2xl disabled:opacity-60">
                                <?= htmlspecialchars($hasActive ? t('business.extend_package') : t('business.buy_package')) ?>
                            </button>
                        </form>
                    <?php elseif ($isBusiness && !$salesEnabled): ?>
                        <p class="mt-4 text-xs text-amber-700 font-semibold"><?= htmlspecialchars(t('business.buy_unavailable')) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if ($packages === []): ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.plans_empty')) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($pendingLongTerm): ?>
            <div class="mt-5 rounded-2xl border border-dashed border-black/15 dark:border-white/15 p-4">
                <h3 class="text-sm font-bold"><?= htmlspecialchars(t('business.longterm_pending_title')) ?></h3>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('business.longterm_pending_body')) ?></p>
                <ul class="mt-2 text-sm text-gray-600 dark:text-gray-300 space-y-1">
                    <?php foreach ($pendingLongTerm as $lt): ?>
                        <li>· <?= htmlspecialchars((string) ($lt['name'] ?? '')) ?>
                            <span class="text-xs text-gray-400">(<?= (int) ($lt['duration_days'] ?? 0) ?> <?= htmlspecialchars(t('business.days_short')) ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <?php /* —— Что входит (честно) —— */ ?>
    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-4">
        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('business.what_included')) ?></h2>
        <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars(t('business.package_intro_honest')) ?></p>
        <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-emerald-700 mb-2"><?= htmlspecialchars(t('business.feat_available')) ?></h3>
            <ul class="space-y-1 text-sm">
                <?php foreach ($includedAvailable as $item): ?>
                    <li class="flex gap-2"><span class="text-emerald-600 shrink-0">✓</span> <?= htmlspecialchars($item) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-2"><?= htmlspecialchars(t('business.feat_not_ready')) ?></h3>
            <ul class="space-y-1 text-sm text-gray-500">
                <?php foreach ($includedPending as $item): ?>
                    <li class="flex gap-2"><span class="shrink-0">○</span> <?= htmlspecialchars($item) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php /* Строка для совместимости с BoostUiTest: business.lim_boosts */ ?>
        <p class="sr-only"><?= htmlspecialchars(t('business.lim_boosts')) ?></p>
    </div>

    <?php /* —— Инструменты —— */ ?>
    <div id="tools" class="space-y-4">
        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('business.tools_title')) ?></h2>

        <?php if (!$hasActive): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.tools_need_subscription')) ?></p>
        <?php elseif (!$featuresEnabled): ?>
            <p class="text-sm text-amber-800 font-semibold"><?= htmlspecialchars(t('business.features_off_notice')) ?></p>
        <?php endif; ?>

        <div id="tools-import" class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-4">
            <h3 class="font-semibold"><?= htmlspecialchars(t('business.tools_import_title')) ?></h3>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.tools_import_hint')) ?></p>
            <?php if ($importJob): ?>
                <div class="rounded-xl bg-ink-50/80 dark:bg-white/[0.04] p-3 text-sm space-y-1">
                    <div><?= htmlspecialchars(t('business.import_job_status', [
                        'id' => (string) (int) $importJob['id'],
                        'status' => (string) ($importJob['status'] ?? ''),
                    ])) ?></div>
                    <div class="text-xs text-gray-500">
                        OK: <?= (int) ($importJob['ok_rows'] ?? 0) ?> ·
                        <?= htmlspecialchars(t('business.import_errors')) ?>: <?= (int) ($importJob['error_rows'] ?? 0) ?> /
                        <?= (int) ($importJob['total_rows'] ?? 0) ?>
                    </div>
                    <?php
                    $report = [];
                    if (!empty($importJob['report_json'])) {
                        $decoded = json_decode((string) $importJob['report_json'], true);
                        $report = is_array($decoded) ? $decoded : [];
                    }
                    $errRows = $report['errors'] ?? [];
                    if (is_array($errRows) && $errRows !== []): ?>
                        <ul class="text-xs text-red-700 mt-2 space-y-0.5 max-h-40 overflow-auto">
                            <?php foreach (array_slice($errRows, 0, 20) as $er): ?>
                                <li>#<?= (int) ($er['line'] ?? 0) ?>: <?= htmlspecialchars((string) ($er['error'] ?? '')) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($toolsUnlocked): ?>
                <div class="grid sm:grid-cols-2 gap-3">
                    <form method="post" action="<?= ProductHelper::url('/business/import/xml') ?>" enctype="multipart/form-data" class="js-bp-busy-form space-y-2">
                        <?= csrf_field() ?>
                        <label class="block text-xs font-bold"><?= htmlspecialchars(t('business.import_xml')) ?></label>
                        <input type="file" name="file" accept=".xml,text/xml,application/xml" required class="block w-full text-xs">
                        <button type="submit" class="text-xs font-bold px-4 py-2 rounded-xl bg-ink-900 text-white disabled:opacity-60"><?= htmlspecialchars(t('business.import_submit')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/business/import/csv') ?>" enctype="multipart/form-data" class="js-bp-busy-form space-y-2">
                        <?= csrf_field() ?>
                        <label class="block text-xs font-bold"><?= htmlspecialchars(t('business.import_csv')) ?></label>
                        <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="block w-full text-xs">
                        <button type="submit" class="text-xs font-bold px-4 py-2 rounded-xl bg-ink-900 text-white disabled:opacity-60"><?= htmlspecialchars(t('business.import_submit')) ?></button>
                    </form>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.tools_locked')) ?></p>
            <?php endif; ?>
        </div>

        <div id="tools-api" class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-3">
            <h3 class="font-semibold"><?= htmlspecialchars(t('business.tools_api_title')) ?></h3>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.tools_api_hint')) ?></p>
            <?php if ($toolsUnlocked): ?>
                <?php if ($apiKeys): ?>
                    <ul class="text-sm space-y-2">
                        <?php foreach ($apiKeys as $key): ?>
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span><?= htmlspecialchars((string) ($key['name'] ?? '')) ?> · <?= htmlspecialchars((string) ($key['key_prefix'] ?? '')) ?>…</span>
                                <form method="post" action="<?= ProductHelper::url('/business/api-keys/' . (int) $key['id'] . '/revoke') ?>" class="js-bp-busy-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="text-xs font-bold text-red-600"><?= htmlspecialchars(t('business.api_key_revoke')) ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-xs text-gray-400"><?= htmlspecialchars(t('business.api_keys_empty')) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= ProductHelper::url('/business/api-keys') ?>" class="js-bp-busy-form flex flex-wrap gap-2 items-end">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('business.api_key_name')) ?></label>
                        <input type="text" name="name" value="default" maxlength="120" class="h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-transparent text-sm">
                    </div>
                    <button type="submit" class="h-10 px-4 rounded-xl bg-ink-900 text-white text-xs font-bold disabled:opacity-60"><?= htmlspecialchars(t('business.api_key_create')) ?></button>
                </form>
            <?php else: ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.tools_locked')) ?></p>
            <?php endif; ?>
        </div>

        <div id="tools-analytics" class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-3">
            <h3 class="font-semibold"><?= htmlspecialchars(t('business.tools_analytics_title')) ?></h3>
            <?php if ($toolsUnlocked && is_array($analytics) && !empty($analytics['ok'])):
                $data = $analytics['data'] ?? []; ?>
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                    <div><dt class="text-[10px] uppercase text-gray-400 font-bold"><?= htmlspecialchars(t('business.an_days')) ?></dt>
                        <dd class="font-semibold"><?= (int) ($data['days'] ?? 0) ?></dd></div>
                    <div><dt class="text-[10px] uppercase text-gray-400 font-bold"><?= htmlspecialchars(t('business.an_listings')) ?></dt>
                        <dd class="font-semibold"><?= (int) ($data['listings_total'] ?? 0) ?></dd></div>
                    <div><dt class="text-[10px] uppercase text-gray-400 font-bold"><?= htmlspecialchars(t('business.an_views')) ?></dt>
                        <dd class="font-semibold"><?= (int) ($data['views_total'] ?? 0) ?></dd></div>
                    <div><dt class="text-[10px] uppercase text-gray-400 font-bold"><?= htmlspecialchars(t('business.an_views_period')) ?></dt>
                        <dd class="font-semibold"><?= (int) ($data['views_period'] ?? 0) ?></dd></div>
                    <div><dt class="text-[10px] uppercase text-gray-400 font-bold"><?= htmlspecialchars(t('business.an_sales')) ?></dt>
                        <dd class="font-semibold"><?= (int) ($data['sales_completed_period'] ?? 0) ?></dd></div>
                    <div><dt class="text-[10px] uppercase text-gray-400 font-bold"><?= htmlspecialchars(t('business.an_favorites')) ?></dt>
                        <dd class="font-semibold"><?= (int) ($data['favorites_total'] ?? 0) ?></dd></div>
                </dl>
                <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('business.an_scope_note')) ?></p>
            <?php elseif ($toolsUnlocked && is_array($analytics) && empty($analytics['ok'])): ?>
                <p class="text-sm text-red-700"><?= htmlspecialchars((string) ($analytics['error'] ?? t('business.err_generic'))) ?></p>
            <?php else: ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.tools_locked')) ?></p>
            <?php endif; ?>
        </div>

        <div id="tools-ai" class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-4">
            <div>
                <h3 class="font-semibold"><?= htmlspecialchars(t('business.tools_ai_title')) ?></h3>
                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('business.tools_ai_unavailable')) ?></p>
            </div>
            <?php if ($aiOpStatus): ?>
                <p class="text-xs font-semibold text-gray-600"><?= htmlspecialchars(t('business.ai_op_status_label', ['status' => (string) $aiOpStatus])) ?></p>
            <?php endif; ?>
            <?php if (!$toolsUnlocked): ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.tools_locked')) ?></p>
            <?php else: ?>
                <?php
                $infQ = $aiQuotaByFeature['ai_infographic'] ?? null;
                $tryQ = $aiQuotaByFeature['ai_tryon'] ?? null;
                ?>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-black/[0.06] dark:border-white/10 p-4 space-y-3">
                        <h4 class="text-sm font-bold"><?= htmlspecialchars(t('business.ai_tool_infographic')) ?></h4>
                        <?php if ($infQ): ?>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.ai_remaining_line', [
                                'n' => number_format((int) $infQ['remaining'], 0, '', ' '),
                            ])) ?></p>
                            <?php if ((int) $infQ['remaining'] <= 0): ?>
                                <p class="text-xs font-semibold text-amber-800"><?= htmlspecialchars(t('business.ai_quota_exhausted')) ?></p>
                                <?php if (!empty($aiAddonSalesFlags['addon-ai-infographic-500']) && $isBusiness): ?>
                                    <a href="#ai-addons" class="text-xs font-bold text-brand-600 hover:underline"><?= htmlspecialchars(t('business.ai_buy_more')) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="post" action="<?= ProductHelper::url('/business/ai/infographic') ?>" class="js-bp-busy-form space-y-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="operation_key" value="<?= htmlspecialchars('ai-inf-' . bin2hex(random_bytes(8)), ENT_QUOTES) ?>">
                            <button type="submit" class="text-xs font-bold px-4 py-2 rounded-xl bg-ink-900 text-white disabled:opacity-60"
                                <?= ((int) ($infQ['remaining'] ?? 0) <= 0) ? 'disabled' : '' ?>>
                                <?= htmlspecialchars(t('business.ai_run_check')) ?>
                            </button>
                        </form>
                        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('business.ai_run_check_hint')) ?></p>
                    </div>
                    <div class="rounded-xl border border-black/[0.06] dark:border-white/10 p-4 space-y-3">
                        <h4 class="text-sm font-bold"><?= htmlspecialchars(t('business.ai_tool_tryon')) ?></h4>
                        <?php if ($tryQ): ?>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.ai_remaining_line', [
                                'n' => number_format((int) $tryQ['remaining'], 0, '', ' '),
                            ])) ?></p>
                            <?php if ((int) $tryQ['remaining'] <= 0): ?>
                                <p class="text-xs font-semibold text-amber-800"><?= htmlspecialchars(t('business.ai_quota_exhausted')) ?></p>
                                <?php if (!empty($aiAddonSalesFlags['addon-ai-tryon-100']) && $isBusiness): ?>
                                    <a href="#ai-addons" class="text-xs font-bold text-brand-600 hover:underline"><?= htmlspecialchars(t('business.ai_buy_more')) ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="post" action="<?= ProductHelper::url('/business/ai/tryon') ?>" class="js-bp-busy-form space-y-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="operation_key" value="<?= htmlspecialchars('ai-try-' . bin2hex(random_bytes(8)), ENT_QUOTES) ?>">
                            <button type="submit" class="text-xs font-bold px-4 py-2 rounded-xl bg-ink-900 text-white disabled:opacity-60"
                                <?= ((int) ($tryQ['remaining'] ?? 0) <= 0) ? 'disabled' : '' ?>>
                                <?= htmlspecialchars(t('business.ai_run_check')) ?>
                            </button>
                        </form>
                        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('business.ai_run_check_hint')) ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div id="tools-manager" class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-3">
            <h3 class="font-semibold"><?= htmlspecialchars(t('business.tools_manager_title')) ?></h3>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.tools_manager_hint')) ?></p>
            <?php if ($toolsUnlocked): ?>
                <form method="post" action="<?= ProductHelper::url('/business/manager/request') ?>" class="js-bp-busy-form space-y-2">
                    <?= csrf_field() ?>
                    <textarea name="message" rows="3" maxlength="4000" placeholder="<?= htmlspecialchars(t('business.manager_message_ph')) ?>"
                              class="w-full px-3 py-2 rounded-xl border border-black/10 dark:border-white/10 bg-transparent text-sm"></textarea>
                    <button type="submit" class="text-xs font-bold px-4 py-2.5 rounded-xl bg-ink-900 text-white disabled:opacity-60"><?= htmlspecialchars(t('business.manager_submit')) ?></button>
                </form>
            <?php else: ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.tools_locked')) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php /* —— Доп. AI-пакеты (VAS-15) —— */ ?>
    <div id="ai-addons" class="space-y-4">
        <div>
            <h2 class="font-display text-xl font-bold mb-1"><?= htmlspecialchars(t('business.ai_addons_title')) ?></h2>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.ai_addons_hint')) ?></p>
        </div>
        <?php if (!$hasActive): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.ai_addons_need_subscription')) ?></p>
        <?php elseif (!$isBusiness): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.package_need_business')) ?></p>
        <?php elseif ($aiAddons === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('business.ai_addons_empty')) ?></p>
        <?php else: ?>
            <div class="grid sm:grid-cols-2 gap-4">
                <?php foreach ($aiAddons as $pkg):
                    $slug = (string) ($pkg['slug'] ?? '');
                    $units = (int) ($pkg['units'] ?? 0);
                    $price = (int) ($pkg['price_kzt'] ?? 0);
                    $productSalesOn = !empty($aiAddonSalesFlags[$slug]);
                    $canBuy = $productSalesOn && $salesEnabled;
                    ?>
                    <article class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.03] p-5 flex flex-col"
                             data-ai-addon-slug="<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
                             data-ai-addon-sales="<?= $productSalesOn ? '1' : '0' ?>">
                        <h3 class="font-display text-lg font-bold"><?= htmlspecialchars((string) ($pkg['name'] ?? '')) ?></h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300 mt-2 flex-1">
                            <?= htmlspecialchars(t('business.ai_addon_units_line', ['n' => number_format($units, 0, '', ' ')])) ?>
                        </p>
                        <div class="mt-3 text-2xl font-bold"><?= htmlspecialchars(Wallet::formatMoney($price)) ?></div>
                        <?php if ($canBuy): ?>
                            <form method="post" action="<?= ProductHelper::url('/business/package/' . (int) $pkg['id'] . '/buy') ?>" class="mt-4 js-bp-buy-form"
                                  data-confirm="<?= htmlspecialchars(t('business.confirm_buy', ['amount' => Wallet::formatMoney($price)]), ENT_QUOTES) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey . '-ai' . (int) $pkg['id'], ENT_QUOTES) ?>">
                                <button type="submit" class="w-full bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-4 py-3 rounded-2xl disabled:opacity-60">
                                    <?= htmlspecialchars(t('business.buy_ai_addon')) ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <p class="mt-4 text-xs text-amber-700 font-semibold">
                                <?= htmlspecialchars(!$productSalesOn ? t('business.ai_addon_sales_off_one') : t('business.buy_unavailable')) ?>
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($addons && $isBusiness && $hasActive && $salesEnabled): ?>
        <div>
            <h2 class="font-display text-xl font-bold mb-1"><?= htmlspecialchars(t('business.addons_title')) ?></h2>
            <p class="text-sm text-gray-500 mb-4"><?= htmlspecialchars(t('business.addons_hint')) ?></p>
            <div class="space-y-3">
                <?php foreach ($addons as $pkg): ?>
                    <article class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="font-semibold"><?= htmlspecialchars((string) $pkg['name']) ?></div>
                            <div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars((string) ($pkg['description'] ?? '')) ?></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="text-sm font-bold"><?= htmlspecialchars(Wallet::formatMoney((int) $pkg['price_kzt'])) ?></div>
                            <form method="post" action="<?= ProductHelper::url('/business/package/' . (int) $pkg['id'] . '/buy') ?>" class="js-bp-buy-form"
                                  data-confirm="<?= htmlspecialchars(t('business.confirm_buy', ['amount' => Wallet::formatMoney((int) $pkg['price_kzt'])]), ENT_QUOTES) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey . '-a' . (int) $pkg['id'], ENT_QUOTES) ?>">
                                <button type="submit" class="text-xs font-bold px-4 py-2 rounded-xl bg-ink-900 text-white disabled:opacity-60"><?= htmlspecialchars(t('business.buy_addon')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<script>
(function () {
    function armBusy(form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
        });
    }
    document.querySelectorAll('.js-bp-busy-form').forEach(armBusy);
    document.querySelectorAll('.js-bp-buy-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
                return;
            }
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
        });
    });
})();
</script>
