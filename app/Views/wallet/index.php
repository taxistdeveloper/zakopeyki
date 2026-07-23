<?php
use App\Helpers\ProductHelper;
use App\Models\Wallet;

$balanceFmt = Wallet::formatMoney((int) $balance);
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$btn = 'w-full font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition';
?>
<section class="max-w-2xl mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('wallet.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('wallet.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('wallet.subtitle')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="relative overflow-hidden rounded-[28px] bg-gradient-to-br from-ink-900 via-ink-800 to-brand-800 text-white p-6 sm:p-8 shadow-lift">
        <div class="absolute -right-8 -top-8 w-40 h-40 rounded-full bg-brand-500/20 blur-2xl pointer-events-none"></div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-white/50"><?= htmlspecialchars(t('wallet.available')) ?></p>
        <p class="font-display text-4xl sm:text-5xl font-extrabold tracking-tight mt-2 tabular-nums"><?= htmlspecialchars($balanceFmt) ?></p>
        <p class="text-sm text-white/60 mt-3 max-w-md"><?= htmlspecialchars(t('wallet.balance_hint')) ?></p>
        <div class="mt-5 flex flex-wrap gap-2">
            <a href="<?= ProductHelper::url('/orders') ?>" class="inline-flex text-xs font-semibold px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/15 transition"><?= htmlspecialchars(t('nav.deals')) ?></a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <form method="post" action="<?= ProductHelper::url('/wallet/deposit') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('wallet.deposit_title')) ?></h2>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('wallet.deposit_hint')) ?></p>
            <input type="text" name="amount" inputmode="numeric" required placeholder="<?= htmlspecialchars(t('wallet.amount_placeholder')) ?>" class="<?= $input ?>">
            <div class="flex gap-2">
                <label class="flex-1 flex items-center gap-2 p-2.5 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold cursor-pointer has-[:checked]:border-brand-500">
                    <input type="radio" name="source" value="card" checked class="accent-brand-600"> <?= htmlspecialchars(t('wallet.source_card')) ?>
                </label>
                <label class="flex-1 flex items-center gap-2 p-2.5 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold cursor-pointer has-[:checked]:border-brand-500">
                    <input type="radio" name="source" value="kaspi" class="accent-brand-600"> <?= htmlspecialchars(t('wallet.source_kaspi')) ?>
                </label>
            </div>
            <button type="submit" class="<?= $btn ?> bg-emerald-600 hover:bg-emerald-500 text-white"><?= htmlspecialchars(t('wallet.deposit_btn')) ?></button>
        </form>

        <form method="post" action="<?= ProductHelper::url('/wallet/withdraw') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('wallet.withdraw_title')) ?></h2>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('wallet.withdraw_hint')) ?></p>
            <input type="text" name="amount" inputmode="numeric" required placeholder="<?= htmlspecialchars(t('wallet.amount_placeholder')) ?>" class="<?= $input ?>">
            <div class="flex gap-2">
                <label class="flex-1 flex items-center gap-2 p-2.5 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold cursor-pointer has-[:checked]:border-brand-500">
                    <input type="radio" name="dest" value="card" checked class="accent-brand-600"> <?= htmlspecialchars(t('wallet.source_card')) ?>
                </label>
                <label class="flex-1 flex items-center gap-2 p-2.5 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold cursor-pointer has-[:checked]:border-brand-500">
                    <input type="radio" name="dest" value="kaspi" class="accent-brand-600"> <?= htmlspecialchars(t('wallet.source_kaspi')) ?>
                </label>
            </div>
            <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('wallet.withdraw_btn')) ?></button>
        </form>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft">
        <div class="px-5 py-4 border-b border-black/[0.05] dark:border-white/10">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('wallet.history')) ?></h2>
        </div>
        <?php if (empty($transactions)): ?>
            <p class="px-5 py-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('wallet.history_empty')) ?></p>
        <?php else: ?>
            <ul class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($transactions as $tx):
                    $amt = (int) $tx['amount'];
                    $positive = $amt >= 0;
                ?>
                    <li class="px-5 py-3.5 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(Wallet::typeLabel($tx['type'])) ?></p>
                            <p class="text-[11px] text-gray-400 mt-0.5">
                                <?= htmlspecialchars($tx['created_at'] ?? '') ?>
                                <?php if (!empty($tx['order_id'])): ?>
                                    · <a href="<?= ProductHelper::url('/orders/' . (int) $tx['order_id']) ?>" class="text-brand-600 hover:underline">#<?= (int) $tx['order_id'] ?></a>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="font-display font-bold tabular-nums <?= $positive ? 'text-emerald-600' : 'text-ink-900 dark:text-white' ?>">
                                <?= $positive ? '+' : '' ?><?= htmlspecialchars(Wallet::formatMoney($amt)) ?>
                            </p>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars(t('wallet.balance_after')) ?>: <?= htmlspecialchars(Wallet::formatMoney((int) $tx['balance_after'])) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
