<?php
use App\Helpers\ProductHelper;
use App\Models\Wallet;

$price = ProductHelper::formatPrice($item);
$imageUrl = ProductHelper::imageUrl($item);
$checkoutPayUrl = ProductHelper::url('/checkout/' . (int) $item['id'] . '/pay');
$walletBalance = (int) ($walletBalance ?? 0);
$need = (int) ($item['price'] ?? 0);
$canWallet = $walletBalance >= $need;
?>
<section class="max-w-lg mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('checkout.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('checkout.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('checkout.subtitle')) ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft backdrop-blur">
        <div class="flex gap-4 p-5 border-b border-black/[0.05] dark:border-white/10">
            <div class="w-20 h-20 rounded-2xl overflow-hidden bg-gradient-to-br from-ink-100 via-brand-50 to-accent-50 dark:from-white/10 dark:via-brand-900/20 dark:to-transparent flex-shrink-0 flex items-center justify-center">
                <?php if ($imageUrl): ?>
                    <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="w-full h-full object-cover">
                <?php else: ?>
                    <?= ProductHelper::icon($item['type'], 'w-10 h-10 text-brand-500/70') ?>
                <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-ink-900 dark:text-white text-sm leading-snug line-clamp-2"><?= htmlspecialchars($item['title']) ?></h2>
                <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($item['seller_name']) ?> · <?= htmlspecialchars($item['location']) ?></p>
                <p class="font-display text-xl font-extrabold text-brand-600 mt-2"><?= htmlspecialchars($price) ?></p>
            </div>
        </div>

        <form method="post" action="<?= $checkoutPayUrl ?>" class="p-5 sm:p-6 space-y-5">
            <div class="rounded-2xl bg-amber-50/90 dark:bg-amber-950/20 border border-amber-200/70 dark:border-amber-800/40 px-4 py-3 text-xs text-amber-900 dark:text-amber-200 leading-relaxed">
                <?= htmlspecialchars(t('checkout.escrow_notice')) ?>
            </div>

            <div class="flex items-center justify-between gap-3 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-ink-50/60 dark:bg-white/[0.03] px-4 py-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('wallet.available')) ?></p>
                    <p class="font-display font-bold text-ink-900 dark:text-white mt-0.5"><?= htmlspecialchars(Wallet::formatMoney($walletBalance)) ?></p>
                </div>
                <a href="<?= ProductHelper::url('/wallet') ?>" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(t('wallet.top_up')) ?></a>
            </div>

            <div class="space-y-2">
                <h3 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('checkout.delivery')) ?></h3>
                <?php
                $deliveries = ['kazpost', 'cdek', 'courier', 'other'];
                foreach ($deliveries as $i => $dm):
                ?>
                    <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                        <input type="radio" name="delivery_method" value="<?= $dm ?>" <?= $i === 0 ? 'checked' : '' ?> class="accent-brand-600">
                        <span class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('escrow.delivery_' . $dm)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="space-y-2">
                <h3 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('checkout.method')) ?></h3>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition <?= !$canWallet ? 'opacity-60' : '' ?>">
                    <input type="radio" name="payment_method" value="wallet" <?= $canWallet ? 'checked' : 'disabled' ?> class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200 flex-1">
                        <?= htmlspecialchars(t('checkout.method_wallet')) ?>
                        <?php if (!$canWallet): ?>
                            <span class="block text-[11px] font-medium text-red-500 mt-0.5"><?= htmlspecialchars(t('wallet.need_more', ['need' => Wallet::formatMoney(max(0, $need - $walletBalance))])) ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                    <input type="radio" name="payment_method" value="card" <?= !$canWallet ? 'checked' : '' ?> class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('checkout.method_card')) ?></span>
                </label>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                    <input type="radio" name="payment_method" value="kaspi" class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('checkout.method_kaspi')) ?></span>
                </label>
            </div>

            <div class="flex justify-between items-center pt-2 border-t border-black/[0.05] dark:border-white/10">
                <span class="text-sm text-gray-500"><?= htmlspecialchars(t('checkout.to_pay')) ?></span>
                <span class="font-display text-2xl font-extrabold text-ink-900 dark:text-white"><?= htmlspecialchars($price) ?></span>
            </div>

            <button type="submit" class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-sm uppercase tracking-wider transition shadow-soft">
                <?= htmlspecialchars(t('checkout.pay_escrow_btn')) ?>
            </button>
            <a href="<?= ProductHelper::url('/product/' . (int) $item['id']) ?>" class="block w-full text-center text-sm text-gray-400 hover:text-brand-600 font-medium transition">
                <?= htmlspecialchars(t('checkout.cancel')) ?>
            </a>
        </form>
    </div>
</section>
