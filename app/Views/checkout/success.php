<?php
use App\Helpers\ProductHelper;

$amount = number_format((int) $order['amount'], 0, '', ' ') . ' ₸';
$methodKey = 'checkout.method_' . ($order['payment_method'] ?? 'card');
$methodLabel = t($methodKey);
if ($methodLabel === $methodKey) {
    $methodLabel = $order['payment_method'] ?? '';
}
?>
<section class="max-w-lg mx-auto space-y-5 fade-up pb-8 text-center">
    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 p-8 shadow-soft backdrop-blur space-y-4">
        <div class="mx-auto w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <h1 class="font-display text-2xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('checkout.success_title')) ?></h1>
        <p class="text-sm text-gray-500 leading-relaxed"><?= htmlspecialchars(t('checkout.success_text')) ?></p>

        <div class="text-left rounded-2xl bg-ink-50/80 dark:bg-white/[0.03] border border-black/[0.04] dark:border-white/10 p-4 space-y-2.5 text-sm">
            <div class="flex justify-between gap-3">
                <span class="text-gray-400"><?= htmlspecialchars(t('checkout.order_id')) ?></span>
                <span class="font-semibold text-ink-800 dark:text-gray-200">#<?= (int) $order['id'] ?></span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="text-gray-400"><?= htmlspecialchars(t('checkout.product')) ?></span>
                <span class="font-semibold text-ink-800 dark:text-gray-200 text-right"><?= htmlspecialchars($order['product_title']) ?></span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="text-gray-400"><?= htmlspecialchars(t('checkout.amount')) ?></span>
                <span class="font-display font-bold text-brand-600"><?= htmlspecialchars($amount) ?></span>
            </div>
            <div class="flex justify-between gap-3">
                <span class="text-gray-400"><?= htmlspecialchars(t('checkout.method')) ?></span>
                <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($methodLabel) ?></span>
            </div>
            <?php if (!empty($order['seller_phone'])): ?>
                <div class="flex justify-between gap-3 pt-2 border-t border-black/[0.05] dark:border-white/10">
                    <span class="text-gray-400"><?= htmlspecialchars(t('checkout.seller_phone')) ?></span>
                    <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['seller_phone']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <a href="<?= ProductHelper::url('/') ?>" class="inline-flex items-center justify-center w-full bg-ink-900 hover:bg-ink-800 text-white font-display font-bold py-3.5 rounded-2xl text-sm uppercase tracking-wider transition">
            <?= htmlspecialchars(t('checkout.to_home')) ?>
        </a>
    </div>
</section>
