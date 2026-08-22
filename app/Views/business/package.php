<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;

$packages = $packages ?? [];
$addons = $addons ?? [];
$subscription = $subscription ?? null;
$usageRows = $usageRows ?? [];
$isBusiness = !empty($isBusiness);
$walletBalance = (int) ($walletBalance ?? 0);

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

$sections = [
    ['title' => t('business.sec1_title'), 'items' => [
        t('business.sec1_i1'), t('business.sec1_i2'), t('business.sec1_i3'),
        t('business.sec1_i4'), t('business.sec1_i5'), t('business.sec1_i6'),
    ]],
    ['title' => t('business.sec2_title'), 'items' => [
        t('business.sec2_i1'), t('business.sec2_i2'), t('business.sec2_i3'), t('business.sec2_i4'),
    ]],
    ['title' => t('business.sec3_title'), 'items' => [
        t('business.sec3_i1'), t('business.sec3_i2'), t('business.sec3_i3'),
    ]],
    ['title' => t('business.sec4_title'), 'items' => [
        t('business.sec4_i1'), t('business.sec4_i2'), t('business.sec4_i3'), t('business.sec4_i4'),
    ]],
    ['title' => t('business.sec5_title'), 'items' => [
        t('business.sec5_i1'), t('business.sec5_i2'), t('business.sec5_i3'), t('business.sec5_i4'),
    ]],
    ['title' => t('business.sec6_title'), 'items' => [
        t('business.sec6_i1'), t('business.sec6_i2'), t('business.sec6_i3'), t('business.sec6_i4'),
    ]],
    ['title' => t('business.sec7_title'), 'items' => [
        t('business.sec7_i1'), t('business.sec7_i2'), t('business.sec7_i3'), t('business.sec7_i4'),
    ]],
    ['title' => t('business.sec8_title'), 'items' => [
        t('business.sec8_i1'), t('business.sec8_i2'), t('business.sec8_i3'), t('business.sec8_i4'), t('business.sec8_i5'),
    ]],
    ['title' => t('business.sec9_title'), 'items' => [
        t('business.sec9_i1'), t('business.sec9_i2'), t('business.sec9_i3'), t('business.sec9_i4'), t('business.sec9_i5'), t('business.sec9_i6'),
    ]],
];
?>
<section class="max-w-4xl mx-auto space-y-6 pb-10">
    <div>
        <a href="<?= ProductHelper::url('/profile?tab=business') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('profile.tab_business')) ?></a>
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-600 mt-3"><?= htmlspecialchars(t('business.package_eyebrow')) ?></p>
        <h1 class="font-display text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('business.package_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars(t('business.package_subtitle')) ?></p>
        <p class="text-base font-semibold text-ink-800 dark:text-gray-200 mt-3"><?= htmlspecialchars(t('business.package_tagline')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
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

    <?php if ($subscription): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4 text-sm text-emerald-900">
            <?= htmlspecialchars(t('business.subscription_active', [
                'name' => (string) ($subscription['package_name'] ?? ''),
                'until' => date('d.m.Y', strtotime((string) $subscription['ends_at'])),
            ])) ?>
        </div>
        <?php if ($usageRows): ?>
            <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 overflow-hidden">
                <div class="px-4 py-3 text-xs font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('business.usage_title')) ?></div>
                <table class="w-full text-sm">
                    <tbody>
                    <?php foreach ($usageRows as $row):
                        $label = $limitLabels[$row['key'] ?? ''] ?? (string) ($row['key'] ?? '');
                        $used = (int) ($row['used'] ?? 0);
                        $cap = (int) ($row['cap'] ?? 0);
                        ?>
                        <tr class="border-t border-black/[0.05] dark:border-white/10">
                            <td class="px-4 py-2.5 text-ink-800 dark:text-gray-200"><?= htmlspecialchars($label) ?></td>
                            <td class="px-4 py-2.5 text-right font-semibold tabular-nums"><?= number_format($used, 0, '', ' ') ?> / <?= $cap > 0 ? number_format($cap, 0, '', ' ') : '∞' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div>
        <h2 class="font-display text-xl font-bold mb-3"><?= htmlspecialchars(t('business.plans_heading')) ?></h2>
        <p class="text-sm text-gray-500 mb-4"><?= htmlspecialchars(t('business.plans_hint')) ?></p>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($packages as $pkg):
                $isYear = (int) $pkg['duration_days'] >= 360;
                ?>
                <article class="rounded-2xl border <?= $isYear ? 'border-brand-400/60' : 'border-black/[0.06] dark:border-white/10' ?> bg-white/90 dark:bg-white/[0.03] p-5 shadow-soft flex flex-col">
                    <?php if ($isYear): ?>
                        <div class="text-[10px] font-bold uppercase tracking-wider text-brand-600 mb-1"><?= htmlspecialchars(t('business.best_value')) ?></div>
                    <?php endif; ?>
                    <h3 class="font-display text-lg font-bold"><?= htmlspecialchars((string) $pkg['name']) ?></h3>
                    <p class="text-xs text-gray-500 mt-1 flex-1"><?= htmlspecialchars((string) ($pkg['description'] ?? '')) ?></p>
                    <div class="mt-3">
                        <div class="text-2xl font-bold"><?= htmlspecialchars(Wallet::formatMoney((int) $pkg['price_kzt'])) ?></div>
                        <div class="text-xs text-gray-400"><?= htmlspecialchars(t('business.per_days', ['days' => (string) (int) $pkg['duration_days']])) ?></div>
                    </div>
                    <?php if ($isBusiness): ?>
                        <form method="post" action="<?= ProductHelper::url('/business/package/' . (int) $pkg['id'] . '/buy') ?>" class="mt-4"
                              onsubmit="return confirm(<?= json_encode(t('business.confirm_buy', ['amount' => Wallet::formatMoney((int) $pkg['price_kzt'])])) ?>)">
                            <button type="submit" class="w-full bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-4 py-3 rounded-2xl">
                                <?= htmlspecialchars($subscription ? t('business.extend_package') : t('business.buy_package')) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-5 space-y-5">
        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars(t('business.what_included')) ?></h2>
        <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars(t('business.package_intro')) ?></p>
        <?php foreach ($sections as $sec): ?>
            <div>
                <h3 class="text-sm font-bold text-ink-900 dark:text-white mb-2"><?= htmlspecialchars($sec['title']) ?></h3>
                <ul class="space-y-1 text-sm text-ink-800 dark:text-gray-200">
                    <?php foreach ($sec['items'] as $item): ?>
                        <li class="flex gap-2"><span class="text-emerald-600 shrink-0">✓</span> <?= htmlspecialchars($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 overflow-hidden">
        <div class="px-4 py-3 font-display font-bold"><?= htmlspecialchars(t('business.limits_table_title')) ?></div>
        <table class="w-full text-sm">
            <thead class="bg-black/[0.03] dark:bg-white/[0.04] text-xs uppercase tracking-wider text-gray-400">
                <tr>
                    <th class="text-left px-4 py-2 font-semibold"><?= htmlspecialchars(t('business.limits_col_feature')) ?></th>
                    <th class="text-right px-4 py-2 font-semibold"><?= htmlspecialchars(t('business.limits_col_value')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $table = [
                    [t('business.lim_active'), t('business.lim_unlimited')],
                    [t('business.lim_catalog'), '50 000'],
                    [t('business.lim_day'), '5 000'],
                    [t('business.lim_sync'), t('business.lim_sync_val')],
                    [t('business.lim_ai_infographic'), '1 000'],
                    [t('business.lim_ai_copy'), '5 000'],
                    [t('business.lim_ai_optimize'), '5 000'],
                    [t('business.lim_ai_tryon'), '300'],
                    [t('business.lim_boosts'), '100'],
                    [t('business.lim_staff'), '5'],
                    [t('business.lim_analytics'), t('business.lim_analytics_val')],
                    [t('business.lim_api'), t('business.lim_included')],
                ];
                foreach ($table as $tr): ?>
                    <tr class="border-t border-black/[0.05] dark:border-white/10">
                        <td class="px-4 py-2"><?= htmlspecialchars($tr[0]) ?></td>
                        <td class="px-4 py-2 text-right font-semibold"><?= htmlspecialchars($tr[1]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

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
                        <?php if ($isBusiness && $subscription): ?>
                            <form method="post" action="<?= ProductHelper::url('/business/package/' . (int) $pkg['id'] . '/buy') ?>"
                                  onsubmit="return confirm(<?= json_encode(t('business.confirm_buy', ['amount' => Wallet::formatMoney((int) $pkg['price_kzt'])])) ?>)">
                                <button type="submit" class="text-xs font-bold px-4 py-2 rounded-xl bg-ink-900 text-white"><?= htmlspecialchars(t('business.buy_addon')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            <article class="rounded-2xl border border-dashed border-black/15 dark:border-white/15 p-4">
                <div class="font-semibold"><?= htmlspecialchars(t('business.addon_api_title')) ?></div>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('business.addon_api_hint')) ?></p>
            </article>
        </div>
    </div>

    <div class="rounded-2xl bg-ink-50/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-5 text-sm text-gray-600 dark:text-gray-300 space-y-2">
        <p class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('business.demo_title')) ?></p>
        <p><?= htmlspecialchars(t('business.demo_body')) ?></p>
        <p><?= htmlspecialchars(t('business.positioning')) ?></p>
    </div>
</section>
