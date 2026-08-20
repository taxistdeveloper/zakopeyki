<?php

use App\Helpers\ProductHelper;
use App\Models\BusinessPackage;
use App\Models\Wallet;

$packages = $packages ?? [];
$subscription = $subscription ?? null;
$isBusiness = !empty($isBusiness);
$walletBalance = (int) ($walletBalance ?? 0);
?>
<section class="max-w-3xl mx-auto space-y-5 pb-8">
    <div>
        <a href="<?= ProductHelper::url('/profile?tab=business') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('profile.tab_business')) ?></a>
        <h1 class="font-display text-2xl font-bold text-ink-900 dark:text-white mt-2"><?= htmlspecialchars(t('business.package_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('business.package_lead')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 p-4 text-sm">
        <?= htmlspecialchars(t('business.wallet_balance', ['amount' => Wallet::formatMoney($walletBalance)])) ?>
        <a href="<?= ProductHelper::url('/wallet') ?>" class="ml-2 font-bold text-brand-600 hover:underline"><?= htmlspecialchars(t('business.top_up')) ?></a>
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
    <?php endif; ?>

    <div class="space-y-4">
        <?php foreach ($packages as $pkg):
            $benefits = BusinessPackage::decodeBenefits($pkg['benefits_json'] ?? null);
            ?>
            <article class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.03] p-5 shadow-soft">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-display text-xl font-bold"><?= htmlspecialchars((string) $pkg['name']) ?></h2>
                        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars((string) ($pkg['description'] ?? '')) ?></p>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-bold"><?= htmlspecialchars(Wallet::formatMoney((int) $pkg['price_kzt'])) ?></div>
                        <div class="text-xs text-gray-400"><?= htmlspecialchars(t('business.per_days', ['days' => (string) (int) $pkg['duration_days']])) ?></div>
                    </div>
                </div>
                <?php if ($benefits): ?>
                    <ul class="mt-4 space-y-1.5 text-sm text-ink-800 dark:text-gray-200">
                        <?php foreach ($benefits as $b): ?>
                            <li class="flex gap-2"><span class="text-emerald-600">✓</span> <?= htmlspecialchars($b) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($isBusiness): ?>
                    <form method="post" action="<?= ProductHelper::url('/business/package/' . (int) $pkg['id'] . '/buy') ?>" class="mt-5"
                          onsubmit="return confirm(<?= json_encode(t('business.confirm_buy', ['amount' => Wallet::formatMoney((int) $pkg['price_kzt'])])) ?>)">
                        <button type="submit" class="bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-5 py-3 rounded-2xl">
                            <?= htmlspecialchars($subscription ? t('business.extend_package') : t('business.buy_package')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
