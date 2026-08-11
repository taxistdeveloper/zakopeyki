<?php
use App\Helpers\ProductHelper;
use App\Models\Bonus;

$balance = (int) ($balance ?? 0);
$canUseGym = (bool) ($canUseGym ?? false);
$loggedIn = (bool) ($loggedIn ?? false);
$earlyBird = $earlyBird ?? ['tier1_left' => 0, 'tier2_left' => 0, 'next_amount' => Bonus::REG_DEFAULT_AMOUNT];
$transactions = $transactions ?? [];
$partnerGyms = $partnerGyms ?? [];
$gymPass = $gymPass ?? null;
$typeLabels = [
    Bonus::TYPE_REGISTRATION => t('bonuses.tx_registration'),
    Bonus::TYPE_SALE => t('bonuses.tx_sale'),
    Bonus::TYPE_FOLLOWER => t('bonuses.tx_follower'),
    Bonus::TYPE_LISTING => t('bonuses.tx_listing'),
    Bonus::TYPE_REFERRAL => t('bonuses.tx_referral'),
];
?>
<section class="max-w-2xl mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('bonuses.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('bonuses.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('bonuses.subtitle')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($loggedIn): ?>
    <div class="relative overflow-hidden rounded-[28px] bg-gradient-to-br from-amber-600 via-orange-600 to-rose-700 text-white p-6 sm:p-8 shadow-lift">
        <div class="absolute -right-8 -top-8 w-40 h-40 rounded-full bg-white/10 blur-2xl pointer-events-none"></div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-white/50"><?= htmlspecialchars(t('bonuses.available')) ?></p>
        <p class="font-display text-4xl sm:text-5xl font-extrabold tracking-tight mt-2 tabular-nums"><?= htmlspecialchars(Bonus::format($balance)) ?></p>
        <p class="text-sm text-white/70 mt-3 max-w-md"><?= htmlspecialchars(t('bonuses.balance_hint')) ?></p>

        <?php if ($canUseGym): ?>
            <div class="mt-5 inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white/15 text-sm font-semibold">
                <span aria-hidden="true">✓</span>
                <?= htmlspecialchars(t('bonuses.gym_unlocked')) ?>
            </div>
        <?php else: ?>
            <?php
                $need = max(0, Bonus::GYM_THRESHOLD - $balance);
                $pct = min(100, (int) round(($balance / max(1, Bonus::GYM_THRESHOLD)) * 100));
            ?>
            <div class="mt-5 space-y-2">
                <p class="text-sm text-white/80"><?= htmlspecialchars(t('bonuses.gym_progress', [
                    'need' => Bonus::format($need),
                    'threshold' => Bonus::format(Bonus::GYM_THRESHOLD),
                ])) ?></p>
                <div class="h-2 rounded-full bg-white/15 overflow-hidden">
                    <div class="h-full rounded-full bg-white/90 transition-all" style="width: <?= $pct ?>%"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-5 shadow-soft">
        <p class="text-sm text-gray-600 dark:text-gray-300"><?= htmlspecialchars(t('bonuses.login_hint')) ?></p>
        <a href="<?= ProductHelper::url('/register') ?>" class="inline-flex mt-3 font-display font-bold text-xs uppercase tracking-wider px-4 py-2.5 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white transition">
            <?= htmlspecialchars(t('nav.register')) ?>
        </a>
    </div>
    <?php endif; ?>

    <?php if ($loggedIn && $canUseGym && is_array($gymPass)): ?>
    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-emerald-200/70 dark:border-emerald-800/40 p-5 sm:p-6 shadow-soft">
        <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('bonuses.pass_title')) ?></h2>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('bonuses.pass_hint')) ?></p>
        <div class="mt-5 flex flex-col sm:flex-row items-center gap-5">
            <div class="shrink-0 rounded-2xl bg-white p-3 border border-black/[0.06] shadow-soft">
                <img src="<?= htmlspecialchars($gymPass['qr_url']) ?>" alt="QR" width="220" height="220" class="w-[180px] h-[180px] sm:w-[200px] sm:h-[200px] rounded-xl">
            </div>
            <div class="text-center sm:text-left space-y-3 min-w-0">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('bonuses.pass_code')) ?></p>
                    <p class="font-display text-2xl sm:text-3xl font-extrabold tracking-widest text-ink-900 dark:text-white mt-1 tabular-nums"><?= htmlspecialchars($gymPass['display']) ?></p>
                </div>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('bonuses.pass_show')) ?></p>
                <a href="<?= htmlspecialchars($gymPass['verify_url']) ?>" class="inline-flex text-xs font-semibold text-brand-600 hover:underline">
                    <?= htmlspecialchars(t('bonuses.pass_preview')) ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft">
        <div class="px-5 py-4 border-b border-black/[0.05] dark:border-white/10">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('bonuses.partners_title')) ?></h2>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('bonuses.partners_subtitle', [
                'threshold' => Bonus::format(Bonus::GYM_THRESHOLD),
            ])) ?></p>
        </div>
        <?php if (empty($partnerGyms)): ?>
            <p class="px-5 py-8 text-center text-sm text-gray-400"><?= htmlspecialchars(t('bonuses.partners_empty')) ?></p>
        <?php else: ?>
            <ul class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($partnerGyms as $gym): ?>
                <li class="px-5 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars((string) ($gym['name'] ?? '')) ?></p>
                            <p class="text-sm text-gray-500 mt-0.5"><?= htmlspecialchars(trim(($gym['city'] ?? '') . ', ' . ($gym['address'] ?? ''), ' ,')) ?></p>
                            <?php if (!empty($gym['hours'])): ?>
                                <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('bonuses.partners_hours', ['hours' => $gym['hours']])) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($gym['perk'])): ?>
                                <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400 mt-1.5"><?= htmlspecialchars((string) $gym['perk']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($gym['phone'])): ?>
                            <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/', '', (string) $gym['phone']) ?? '') ?>" class="shrink-0 text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars((string) $gym['phone']) ?></a>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$canUseGym): ?>
                <div class="px-5 py-3.5 bg-black/[0.02] dark:bg-white/[0.03] border-t border-black/[0.05] dark:border-white/10">
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('bonuses.partners_locked')) ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="rounded-[20px] border border-amber-200/80 dark:border-amber-800/40 bg-amber-50/80 dark:bg-amber-950/20 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-700/70 dark:text-amber-300/70"><?= htmlspecialchars(t('bonuses.tier1_title')) ?></p>
            <p class="font-display text-xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(Bonus::format(Bonus::REG_TIER1_AMOUNT)) ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('bonuses.tier1_desc', [
                'left' => (int) ($earlyBird['tier1_left'] ?? 0),
            ])) ?></p>
        </div>
        <div class="rounded-[20px] border border-orange-200/80 dark:border-orange-800/40 bg-orange-50/80 dark:bg-orange-950/20 p-4">
            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-orange-700/70 dark:text-orange-300/70"><?= htmlspecialchars(t('bonuses.tier2_title')) ?></p>
            <p class="font-display text-xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(Bonus::format(Bonus::REG_TIER2_AMOUNT)) ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('bonuses.tier2_desc', [
                'left' => (int) ($earlyBird['tier2_left'] ?? 0),
            ])) ?></p>
        </div>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
        <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('bonuses.how_title')) ?></h2>
        <ul class="space-y-2.5 text-sm text-gray-600 dark:text-gray-300">
            <li class="flex gap-2.5"><span class="text-brand-600 font-bold shrink-0">+</span><?= htmlspecialchars(t('bonuses.rule_reg')) ?></li>
            <li class="flex gap-2.5"><span class="text-brand-600 font-bold shrink-0">+<?= Bonus::AMOUNT_SALE ?></span><?= htmlspecialchars(t('bonuses.rule_sale')) ?></li>
            <li class="flex gap-2.5"><span class="text-brand-600 font-bold shrink-0">+<?= Bonus::AMOUNT_FOLLOWER ?></span><?= htmlspecialchars(t('bonuses.rule_follower')) ?></li>
            <li class="flex gap-2.5"><span class="text-brand-600 font-bold shrink-0">+<?= Bonus::AMOUNT_LISTING ?></span><?= htmlspecialchars(t('bonuses.rule_listing')) ?></li>
            <li class="flex gap-2.5"><span class="text-brand-600 font-bold shrink-0">+<?= Bonus::AMOUNT_REFERRAL ?></span><?= htmlspecialchars(t('bonuses.rule_referral')) ?></li>
            <li class="flex gap-2.5"><span class="text-emerald-600 font-bold shrink-0"><?= Bonus::format(Bonus::GYM_THRESHOLD) ?></span><?= htmlspecialchars(t('bonuses.rule_gym')) ?></li>
        </ul>
    </div>

    <?php if ($loggedIn): ?>
    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft">
        <div class="px-5 py-4 border-b border-black/[0.05] dark:border-white/10">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('bonuses.history')) ?></h2>
        </div>
        <?php if (empty($transactions)): ?>
            <p class="px-5 py-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('bonuses.history_empty')) ?></p>
        <?php else: ?>
            <ul class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($transactions as $tx):
                    $label = $typeLabels[$tx['type'] ?? ''] ?? ($tx['type'] ?? '');
                    $amt = (int) ($tx['amount'] ?? 0);
                ?>
                <li class="px-5 py-3.5 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars($label) ?></p>
                        <p class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars((string) ($tx['created_at'] ?? '')) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold tabular-nums text-emerald-600 dark:text-emerald-400">+<?= htmlspecialchars(Bonus::format($amt)) ?></p>
                        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('bonuses.balance_after')) ?>: <?= htmlspecialchars(Bonus::format((int) ($tx['balance_after'] ?? 0))) ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
