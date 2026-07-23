<?php
use App\Helpers\ProductHelper;
use App\Services\EscrowService;

$amount = number_format((int) $order['amount'], 0, '', ' ') . ' ₸';
$status = $order['status'] ?? '';
$imageUrl = null;
$fakeProduct = [
    'image' => $order['product_image'] ?? null,
    'images' => $order['product_images'] ?? null,
];
$urls = ProductHelper::imageUrls($fakeProduct);
$imageUrl = $urls[0] ?? null;
$evidence = [];
if (!empty($order['dispute_evidence'])) {
    $decoded = json_decode((string) $order['dispute_evidence'], true);
    if (is_array($decoded)) {
        $evidence = $decoded;
    }
}

$steps = ['escrowed', 'shipped', 'delivered', 'completed'];
$stepIndex = array_search($status, $steps, true);
if ($status === 'dispute' || $status === 'return_approved' || $status === 'return_shipped' || $status === 'return_delivered') {
    $stepIndex = 2;
}
if ($status === 'refunded') {
    $stepIndex = 3;
}
if ($stepIndex === false) {
    $stepIndex = 0;
}

$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$btn = 'inline-flex items-center justify-center w-full font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition';
?>
<section class="max-w-2xl mx-auto space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('escrow.safe_eyebrow')) ?></p>
            <h1 class="font-display text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('escrow.deal_title', ['id' => (int) $order['id']])) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('escrow.safe_hint')) ?></p>
        </div>
        <span class="inline-flex px-3 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-500/25">
            <?= htmlspecialchars(EscrowService::statusLabel($status)) ?>
        </span>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (($order['escrow_hold'] ?? '') === 'holding'): ?>
        <div class="rounded-2xl border border-amber-200/80 dark:border-amber-800/40 bg-amber-50/80 dark:bg-amber-950/20 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
            <span class="font-semibold"><?= htmlspecialchars(t('escrow.holding_title')) ?></span>
            — <?= htmlspecialchars(t('escrow.holding_text', ['amount' => $amount])) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft">
        <div class="flex gap-4 p-5 border-b border-black/[0.05] dark:border-white/10">
            <div class="w-20 h-20 rounded-2xl overflow-hidden bg-ink-100 dark:bg-white/5 flex-shrink-0 flex items-center justify-center">
                <?php if ($imageUrl): ?>
                    <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="w-full h-full object-cover">
                <?php else: ?>
                    <span class="text-2xl text-brand-500/50">◇</span>
                <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars($order['product_title']) ?></h2>
                <p class="text-xs text-gray-400 mt-1">
                    <?= htmlspecialchars(t('escrow.buyer')) ?>: <?= htmlspecialchars($order['buyer_name']) ?>
                    · <?= htmlspecialchars(t('escrow.seller')) ?>: <?= htmlspecialchars($order['seller_name']) ?>
                </p>
                <p class="font-display text-xl font-extrabold text-brand-600 mt-2"><?= htmlspecialchars($amount) ?></p>
            </div>
        </div>

        <div class="px-5 py-4 border-b border-black/[0.05] dark:border-white/10">
            <div class="grid grid-cols-4 gap-2 text-center">
                <?php foreach ($steps as $i => $s): ?>
                    <div class="space-y-1.5">
                        <div class="mx-auto w-2.5 h-2.5 rounded-full <?= $i <= $stepIndex ? 'bg-brand-500' : 'bg-gray-300 dark:bg-white/20' ?>"></div>
                        <p class="text-[10px] font-medium <?= $i <= $stepIndex ? 'text-ink-800 dark:text-gray-200' : 'text-gray-400' ?>"><?= htmlspecialchars(EscrowService::statusLabel($s)) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="p-5 space-y-3 text-sm">
            <div class="flex justify-between gap-3">
                <span class="text-gray-400"><?= htmlspecialchars(t('escrow.delivery')) ?></span>
                <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(EscrowService::deliveryLabel($order['delivery_method'] ?? 'kazpost')) ?></span>
            </div>
            <?php if (!empty($order['tracking_number'])): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.tracking')) ?></span>
                    <span class="font-mono font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['tracking_number']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($order['inspect_until']) && $status === 'delivered'): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.inspect_until')) ?></span>
                    <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['inspect_until']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($order['return_tracking'])): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.return_tracking')) ?></span>
                    <span class="font-mono font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['return_tracking']) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($status === 'dispute' && !empty($order['dispute_reason'])): ?>
                <div class="rounded-2xl bg-red-50/80 dark:bg-red-950/20 border border-red-100 dark:border-red-900/40 p-4 space-y-2">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-red-400"><?= htmlspecialchars(t('escrow.dispute_reason')) ?></p>
                    <p class="text-ink-800 dark:text-gray-200"><?= nl2br(htmlspecialchars($order['dispute_reason'])) ?></p>
                    <?php if ($evidence): ?>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <?php foreach ($evidence as $file): ?>
                                <a href="<?= ProductHelper::url('public/uploads/disputes/' . $file) ?>" target="_blank" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars($file) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-4">
        <?php if (!empty($isSeller) && $status === 'escrowed'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/ship') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.ship_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.ship_hint')) ?></p>
                <input type="text" name="tracking_number" required minlength="5" placeholder="<?= htmlspecialchars(t('escrow.tracking_placeholder')) ?>" class="<?= $input ?>">
                <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('escrow.ship_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($status === 'shipped' && (!empty($isBuyer) || !empty($isSeller))): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/delivered') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold"><?= htmlspecialchars(t('escrow.delivered_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.delivered_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-brand-600 hover:bg-brand-500 text-white"><?= htmlspecialchars(t('escrow.delivered_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && in_array($status, ['shipped', 'delivered'], true)): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/confirm') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-emerald-200/70 dark:border-emerald-900/40 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-emerald-800 dark:text-emerald-300"><?= htmlspecialchars(t('escrow.confirm_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.confirm_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-emerald-600 hover:bg-emerald-500 text-white"><?= htmlspecialchars(t('escrow.confirm_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && $status === 'delivered'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/dispute') ?>" enctype="multipart/form-data" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-red-200/70 dark:border-red-900/40 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-red-700 dark:text-red-300"><?= htmlspecialchars(t('escrow.dispute_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.dispute_hint')) ?></p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="<?= htmlspecialchars(t('escrow.dispute_placeholder')) ?>" class="<?= $input ?> h-auto py-3"></textarea>
                <input type="file" name="evidence[]" accept="image/*,video/mp4,video/webm" multiple class="block w-full text-xs text-gray-500">
                <button type="submit" class="<?= $btn ?> bg-red-600 hover:bg-red-500 text-white"><?= htmlspecialchars(t('escrow.dispute_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isAdmin) && $status === 'dispute'): ?>
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-violet-200/70 dark:border-violet-900/40 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-violet-800 dark:text-violet-300"><?= htmlspecialchars(t('escrow.arbiter_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.arbiter_hint')) ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/approve-return') ?>">
                        <button type="submit" class="<?= $btn ?> bg-violet-600 hover:bg-violet-500 text-white"><?= htmlspecialchars(t('escrow.approve_return')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/reject-dispute') ?>" onsubmit="return confirm('<?= htmlspecialchars(t('escrow.reject_confirm'), ENT_QUOTES) ?>');">
                        <button type="submit" class="<?= $btn ?> bg-ink-800 hover:bg-ink-900 text-white"><?= htmlspecialchars(t('escrow.reject_dispute')) ?></button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && $status === 'return_approved'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-ship') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold"><?= htmlspecialchars(t('escrow.return_ship_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.return_ship_hint')) ?></p>
                <input type="text" name="return_tracking" required minlength="5" placeholder="<?= htmlspecialchars(t('escrow.tracking_placeholder')) ?>" class="<?= $input ?>">
                <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('escrow.return_ship_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isSeller) && $status === 'return_shipped'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-received') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold"><?= htmlspecialchars(t('escrow.return_received_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.return_received_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-brand-600 hover:bg-brand-500 text-white"><?= htmlspecialchars(t('escrow.return_received_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($status === 'completed'): ?>
            <div class="rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 text-sm font-semibold text-emerald-800 dark:text-emerald-300">
                <?= htmlspecialchars(t('escrow.done_seller')) ?>
            </div>
        <?php endif; ?>
        <?php if ($status === 'refunded'): ?>
            <div class="rounded-2xl bg-sky-50 dark:bg-sky-900/20 border border-sky-100 dark:border-sky-800/40 px-4 py-3 text-sm font-semibold text-sky-800 dark:text-sky-300">
                <?= htmlspecialchars(t('escrow.done_refund')) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($isBuyer) || !empty($isSeller)): ?>
            <form method="post" action="<?= ProductHelper::url('/chat/start') ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white">
                    <?= htmlspecialchars(t('chat.write_party')) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <a href="<?= ProductHelper::url('/orders') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 font-medium transition"><?= htmlspecialchars(t('escrow.back_deals')) ?></a>
</section>
