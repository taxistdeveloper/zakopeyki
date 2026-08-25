<?php
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;
use App\Services\EscrowService;
use App\Services\ReturnService;

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
$returnEvents = $returnEvents ?? [];
$myReview = $myReview ?? null;
$counterpartReview = $counterpartReview ?? null;

$isDigital = (($order['product_type'] ?? '') === 'course') || (($order['delivery_method'] ?? '') === 'digital');
$steps = $isDigital ? ['escrowed', 'delivered', 'completed'] : ['escrowed', 'shipped', 'delivered', 'completed'];
$stepIndex = array_search($status, $steps, true);
$returnFlow = [
    'return_requested', 'dispute', 'return_approved', 'return_shipped', 'return_delivered',
];
if (in_array($status, $returnFlow, true)) {
    $stepIndex = 2;
}
if (in_array($status, ['refunded', 'cancelled', 'partial_refunded'], true)) {
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
            <?php if (!empty($order['return_reason'])): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.return_reason_label')) ?></span>
                    <span class="font-semibold text-ink-800 dark:text-gray-200 text-right"><?= htmlspecialchars(ReturnService::reasonLabel((string) $order['return_reason'])) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($order['return_shipping_payer'])): ?>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(ReturnService::shippingPayerLabel((string) $order['return_shipping_payer'])) ?></p>
            <?php endif; ?>
            <?php if (!empty($order['seller_response_until']) && $status === 'return_requested'): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.seller_respond_until')) ?></span>
                    <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['seller_response_until']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($order['return_ship_until']) && $status === 'return_approved'): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.return_ship_until')) ?></span>
                    <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['return_ship_until']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (!empty($order['return_confirm_until']) && $status === 'return_shipped'): ?>
                <div class="flex justify-between gap-3">
                    <span class="text-gray-400"><?= htmlspecialchars(t('escrow.return_confirm_until')) ?></span>
                    <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($order['return_confirm_until']) ?></span>
                </div>
            <?php endif; ?>
            <?php if (in_array($status, $returnFlow, true) && !empty($order['dispute_reason'])): ?>
                <div class="rounded-2xl bg-red-50/80 dark:bg-red-950/20 border border-red-100 dark:border-red-900/40 p-4 space-y-2">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-red-400"><?= htmlspecialchars(t('escrow.dispute_reason')) ?></p>
                    <p class="text-ink-800 dark:text-gray-200"><?= nl2br(htmlspecialchars($order['dispute_reason'])) ?></p>
                    <?php if ($evidence): ?>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <?php foreach ($evidence as $file): ?>
                                <a href="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/evidence/' . rawurlencode(basename((string) $file))) ?>" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(basename((string) $file)) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-4">
        <?php if (!empty($isDigital) && !empty($isBuyer)): ?>
            <a href="<?= ProductHelper::url('/digital/' . (int) $order['product_id'] . '/watch') ?>" class="<?= $btn ?> bg-violet-700 hover:bg-violet-600 text-white"><?= htmlspecialchars(t('digital.open_player')) ?></a>
        <?php endif; ?>

        <?php if (!empty($isSeller) && $status === 'escrowed' && empty($isDigital)): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/ship') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.ship_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.ship_hint')) ?></p>
                <input type="text" name="tracking_number" required minlength="5" placeholder="<?= htmlspecialchars(t('escrow.tracking_placeholder')) ?>" class="<?= $input ?>">
                <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('escrow.ship_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && in_array($status, ['escrowed', 'awaiting_payment'], true)): ?>
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.cancel_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.cancel_hint')) ?></p>
                <button type="button"
                        id="order-cancel-open"
                        class="<?= $btn ?> bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 border border-black/10 dark:border-white/15 hover:bg-black/[0.03] dark:hover:bg-white/10">
                    <?= htmlspecialchars(t('escrow.cancel_btn')) ?>
                </button>
            </div>

            <div id="order-cancel-modal"
                 class="hidden fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-ink-900/55 backdrop-blur-sm p-0 sm:p-4"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="order-cancel-title"
                 aria-hidden="true">
                <div class="w-full sm:max-w-md bg-white dark:bg-ink-800 rounded-t-[28px] sm:rounded-[28px] overflow-hidden shadow-lift border border-white/60 dark:border-white/10 translate-y-3 sm:translate-y-2 opacity-0 transition duration-200 ease-out"
                     data-cancel-panel
                     onclick="event.stopPropagation()">
                    <div class="sm:hidden flex justify-center pt-3 pb-1" aria-hidden="true">
                        <span class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/15"></span>
                    </div>
                    <div class="px-5 pt-4 sm:pt-6 pb-2 text-center space-y-3">
                        <div class="mx-auto w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-500/15 border border-amber-100 dark:border-amber-500/25 flex items-center justify-center text-amber-600 dark:text-amber-300">
                            <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 id="order-cancel-title" class="font-display text-xl font-bold text-ink-900 dark:text-white">
                                <?= htmlspecialchars(t('escrow.cancel_modal_title')) ?>
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 leading-relaxed">
                                <?= htmlspecialchars(t('escrow.cancel_confirm')) ?>
                            </p>
                        </div>
                        <?php if (($order['escrow_hold'] ?? '') === 'holding'): ?>
                            <div class="rounded-2xl bg-emerald-50/90 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 text-left">
                                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-600/80 dark:text-emerald-400/80"><?= htmlspecialchars(t('escrow.holding_short')) ?></p>
                                <p class="font-display text-lg font-extrabold text-emerald-700 dark:text-emerald-300 mt-0.5"><?= htmlspecialchars($amount) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <form method="post"
                          action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/cancel') ?>"
                          class="p-5 pt-3 grid grid-cols-1 sm:grid-cols-2 gap-2.5"
                          data-cancel-form>
                        <?= csrf_field() ?>
                        <button type="button"
                                data-cancel-close
                                class="<?= $btn ?> bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 border border-black/10 dark:border-white/15 hover:bg-black/[0.03] dark:hover:bg-white/10 order-2 sm:order-1">
                            <?= htmlspecialchars(t('escrow.cancel_modal_keep')) ?>
                        </button>
                        <button type="submit"
                                class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white order-1 sm:order-2">
                            <?= htmlspecialchars(t('escrow.cancel_modal_submit')) ?>
                        </button>
                    </form>
                </div>
            </div>
            <script>
            (function () {
                var modal = document.getElementById('order-cancel-modal');
                var openBtn = document.getElementById('order-cancel-open');
                var panel = modal && modal.querySelector('[data-cancel-panel]');
                if (!modal || !openBtn || !panel) return;

                var closeBtns = modal.querySelectorAll('[data-cancel-close]');
                var lastFocus = null;

                function openModal() {
                    lastFocus = document.activeElement;
                    modal.classList.remove('hidden');
                    modal.setAttribute('aria-hidden', 'false');
                    document.documentElement.style.overflow = 'hidden';
                    requestAnimationFrame(function () {
                        panel.classList.remove('translate-y-3', 'sm:translate-y-2', 'opacity-0');
                        panel.classList.add('translate-y-0', 'opacity-100');
                    });
                    var keep = modal.querySelector('[data-cancel-close]');
                    if (keep) keep.focus({ preventScroll: true });
                }

                function closeModal() {
                    panel.classList.add('translate-y-3', 'sm:translate-y-2', 'opacity-0');
                    panel.classList.remove('translate-y-0', 'opacity-100');
                    window.setTimeout(function () {
                        modal.classList.add('hidden');
                        modal.setAttribute('aria-hidden', 'true');
                        document.documentElement.style.overflow = '';
                        if (lastFocus && typeof lastFocus.focus === 'function') {
                            lastFocus.focus({ preventScroll: true });
                        }
                    }, 180);
                }

                openBtn.addEventListener('click', openModal);
                closeBtns.forEach(function (btn) {
                    btn.addEventListener('click', closeModal);
                });
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) closeModal();
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                        e.preventDefault();
                        closeModal();
                    }
                });
            })();
            </script>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && in_array($status, ['escrowed', 'awaiting_payment'], true)): ?>
            <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                <p class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.return_now_title')) ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('escrow.return_escrowed_hint')) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($isSeller) && in_array($status, ['escrowed', 'shipped', 'delivered'], true)): ?>
            <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                <p class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.return_now_title')) ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('escrow.return_seller_wait')) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($status === 'shipped' && !empty($isBuyer)): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/delivered') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <h3 class="font-display font-bold"><?= htmlspecialchars(t('escrow.delivered_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.delivered_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-brand-600 hover:bg-brand-500 text-white"><?= htmlspecialchars(t('escrow.delivered_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php
        $canQualityReturn = !empty($isBuyer)
            && in_array($status, ['shipped', 'delivered'], true)
            && (empty($isDigital) || $status === 'delivered');
        $shippedAtTs = !empty($order['shipped_at']) ? strtotime((string) $order['shipped_at']) : 0;
        $canInr = !empty($isBuyer)
            && $status === 'shipped'
            && empty($isDigital)
            && $shippedAtTs > 0
            && $shippedAtTs <= strtotime('-' . ReturnService::INR_WAIT_DAYS . ' days');
        ?>

        <?php if ($canQualityReturn): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/dispute') ?>" enctype="multipart/form-data" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-red-200/70 dark:border-red-900/40 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <h3 class="font-display font-bold text-red-700 dark:text-red-300"><?= htmlspecialchars(t('escrow.dispute_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.dispute_hint')) ?></p>
                <?php if (!empty($isDigital)): ?>
                    <input type="hidden" name="return_reason" value="<?= htmlspecialchars(ReturnService::REASON_DIGITAL_DEFECT) ?>">
                    <p class="text-sm font-semibold"><?= htmlspecialchars(t('escrow.reason_digital_defect')) ?></p>
                <?php else: ?>
                    <label class="block text-xs font-semibold text-gray-500"><?= htmlspecialchars(t('escrow.return_reason_label')) ?></label>
                    <select name="return_reason" required class="<?= $input ?>">
                        <option value="<?= htmlspecialchars(ReturnService::REASON_NOT_AS_DESCRIBED) ?>"><?= htmlspecialchars(t('escrow.reason_not_as_described')) ?></option>
                        <option value="<?= htmlspecialchars(ReturnService::REASON_CHANGED_MIND) ?>"><?= htmlspecialchars(t('escrow.reason_changed_mind')) ?></option>
                    </select>
                <?php endif; ?>
                <textarea name="reason" rows="3" required minlength="10" placeholder="<?= htmlspecialchars(t('escrow.dispute_placeholder')) ?>" class="<?= $input ?> h-auto py-3"></textarea>
                <input type="file" name="evidence[]" accept="image/*,video/mp4,video/webm" multiple class="block w-full text-xs text-gray-500">
                <button type="submit" class="<?= $btn ?> bg-red-600 hover:bg-red-500 text-white"><?= htmlspecialchars(t('escrow.dispute_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && $status === 'shipped' && ($order['delivery_method'] ?? '') === 'courier'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/dispute') ?>" enctype="multipart/form-data" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-amber-200/70 dark:border-amber-900/40 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <input type="hidden" name="return_reason" value="<?= htmlspecialchars(ReturnService::REASON_COURIER_VOID) ?>">
                <h3 class="font-display font-bold text-amber-800 dark:text-amber-300"><?= htmlspecialchars(t('escrow.courier_void_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.courier_void_hint')) ?></p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="<?= htmlspecialchars(t('escrow.dispute_placeholder')) ?>" class="<?= $input ?> h-auto py-3"></textarea>
                <button type="submit" class="<?= $btn ?> bg-amber-600 hover:bg-amber-500 text-white"><?= htmlspecialchars(t('escrow.courier_void_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($canInr): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/dispute') ?>" enctype="multipart/form-data" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-red-200/70 dark:border-red-900/40 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <input type="hidden" name="return_reason" value="<?= htmlspecialchars(ReturnService::REASON_NOT_RECEIVED) ?>">
                <h3 class="font-display font-bold text-red-700 dark:text-red-300"><?= htmlspecialchars(t('escrow.inr_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.inr_hint')) ?></p>
                <textarea name="reason" rows="3" required minlength="10" placeholder="<?= htmlspecialchars(t('escrow.dispute_placeholder')) ?>" class="<?= $input ?> h-auto py-3"></textarea>
                <input type="file" name="evidence[]" accept="image/*,video/mp4,video/webm" multiple class="block w-full text-xs text-gray-500">
                <button type="submit" class="<?= $btn ?> bg-red-600 hover:bg-red-500 text-white"><?= htmlspecialchars(t('escrow.dispute_btn')) ?></button>
            </form>
        <?php elseif (!empty($isBuyer) && $status === 'shipped' && empty($isDigital)): ?>
            <p class="text-xs text-gray-400 px-1"><?= htmlspecialchars(t('escrow.return_inr_wait', ['days' => ReturnService::INR_WAIT_DAYS])) ?></p>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && in_array($status, ['shipped', 'delivered'], true)): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/confirm') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-emerald-200/70 dark:border-emerald-900/40 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <h3 class="font-display font-bold text-emerald-800 dark:text-emerald-300"><?= htmlspecialchars(t('escrow.confirm_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.confirm_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-emerald-600 hover:bg-emerald-500 text-white"><?= htmlspecialchars(t('escrow.confirm_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && $status === 'completed'): ?>
            <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] px-4 py-3 text-sm text-gray-600 dark:text-gray-300">
                <p class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.return_now_title')) ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('escrow.return_completed_hint')) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && $status === 'return_requested' && ($order['return_offer_status'] ?? '') === 'pending'): ?>
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-sky-200/70 dark:border-sky-900/40 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-sky-800 dark:text-sky-300"><?= htmlspecialchars(t('escrow.partial_offer_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.partial_offer_hint')) ?></p>
                <p class="font-display text-xl font-extrabold text-brand-600"><?= htmlspecialchars(number_format((int) ($order['return_offer_amount'] ?? 0), 0, '', ' ')) ?> ₸</p>
                <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-partial-accept') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $btn ?> bg-emerald-600 hover:bg-emerald-500 text-white"><?= htmlspecialchars(t('escrow.partial_accept_btn')) ?></button>
                </form>
                <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-partial-decline') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $btn ?> bg-ink-800 hover:bg-ink-900 text-white"><?= htmlspecialchars(t('escrow.partial_decline_btn')) ?></button>
                </form>
            </div>
        <?php elseif (!empty($isBuyer) && $status === 'return_requested'): ?>
            <div class="rounded-2xl bg-amber-50/80 dark:bg-amber-950/20 border border-amber-100 dark:border-amber-900/40 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
                <?= htmlspecialchars(t('escrow.waiting_seller')) ?>
            </div>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-escalate') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-violet-200/70 dark:border-violet-900/40 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.escalate_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-violet-600 hover:bg-violet-500 text-white"><?= htmlspecialchars(t('escrow.escalate_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isSeller) && $status === 'return_requested' && ($order['return_offer_status'] ?? '') !== 'pending'): ?>
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-violet-200/70 dark:border-violet-900/40 p-5 space-y-4 shadow-soft">
                <div>
                    <h3 class="font-display font-bold text-violet-800 dark:text-violet-300"><?= htmlspecialchars(t('escrow.seller_return_title')) ?></h3>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('escrow.seller_return_hint')) ?></p>
                </div>
                <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-accept') ?>" class="space-y-3">
                    <?= csrf_field() ?>
                    <label class="flex items-start gap-2 text-sm text-ink-800 dark:text-gray-200">
                        <input type="checkbox" name="keep_item" value="1" class="mt-1">
                        <span><?= htmlspecialchars(t('escrow.seller_keep_item')) ?></span>
                    </label>
                    <button type="submit" class="<?= $btn ?> bg-violet-600 hover:bg-violet-500 text-white"><?= htmlspecialchars(t('escrow.seller_accept_btn')) ?></button>
                </form>
                <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-partial') ?>" class="space-y-2 pt-2 border-t border-black/[0.05] dark:border-white/10">
                    <?= csrf_field() ?>
                    <h4 class="font-semibold text-sm"><?= htmlspecialchars(t('escrow.seller_partial_title')) ?></h4>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.seller_partial_hint')) ?></p>
                    <input type="number" name="partial_amount" min="1" max="<?= max(1, (int) $order['amount'] - 1) ?>" required placeholder="₸" class="<?= $input ?>">
                    <button type="submit" class="<?= $btn ?> bg-sky-600 hover:bg-sky-500 text-white"><?= htmlspecialchars(t('escrow.seller_partial_btn')) ?></button>
                </form>
                <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-decline') ?>" class="space-y-2 pt-2 border-t border-black/[0.05] dark:border-white/10">
                    <?= csrf_field() ?>
                    <h4 class="font-semibold text-sm"><?= htmlspecialchars(t('escrow.seller_decline_title')) ?></h4>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.seller_decline_hint')) ?></p>
                    <textarea name="decline_note" rows="3" required minlength="10" class="<?= $input ?> h-auto py-3"></textarea>
                    <button type="submit" class="<?= $btn ?> bg-ink-800 hover:bg-ink-900 text-white"><?= htmlspecialchars(t('escrow.seller_decline_btn')) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($isAdmin) && $status === 'dispute'): ?>
            <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-violet-200/70 dark:border-violet-900/40 p-5 space-y-3 shadow-soft">
                <h3 class="font-display font-bold text-violet-800 dark:text-violet-300"><?= htmlspecialchars(t('escrow.arbiter_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.arbiter_hint')) ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/approve-return') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="<?= $btn ?> bg-violet-600 hover:bg-violet-500 text-white"><?= htmlspecialchars(t('escrow.approve_return')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/arbiter-refund') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="<?= $btn ?> bg-sky-600 hover:bg-sky-500 text-white"><?= htmlspecialchars(t('escrow.arbiter_full_refund')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/reject-dispute') ?>" onsubmit="return confirm('<?= htmlspecialchars(t('escrow.reject_confirm'), ENT_QUOTES) ?>');">
                        <?= csrf_field() ?>
                        <button type="submit" class="<?= $btn ?> bg-ink-800 hover:bg-ink-900 text-white"><?= htmlspecialchars(t('escrow.reject_dispute')) ?></button>
                    </form>
                </div>
                <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/arbiter-partial') ?>" class="space-y-2 pt-2 border-t border-black/[0.05] dark:border-white/10">
                    <?= csrf_field() ?>
                    <input type="number" name="partial_amount" min="1" max="<?= max(1, (int) $order['amount'] - 1) ?>" required placeholder="₸" class="<?= $input ?>">
                    <button type="submit" class="<?= $btn ?> bg-sky-700 hover:bg-sky-600 text-white"><?= htmlspecialchars(t('escrow.arbiter_partial_btn')) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($isBuyer) && $status === 'return_approved'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-ship') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <h3 class="font-display font-bold"><?= htmlspecialchars(t('escrow.return_ship_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.return_ship_hint')) ?></p>
                <input type="text" name="return_tracking" required minlength="5" placeholder="<?= htmlspecialchars(t('escrow.tracking_placeholder')) ?>" class="<?= $input ?>">
                <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('escrow.return_ship_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if (!empty($isSeller) && $status === 'return_shipped'): ?>
            <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/return-received') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
                <?= csrf_field() ?>
                <h3 class="font-display font-bold"><?= htmlspecialchars(t('escrow.return_received_title')) ?></h3>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('escrow.return_received_hint')) ?></p>
                <button type="submit" class="<?= $btn ?> bg-brand-600 hover:bg-brand-500 text-white"><?= htmlspecialchars(t('escrow.return_received_btn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($status === 'completed'): ?>
            <div class="rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 text-sm font-semibold text-emerald-800 dark:text-emerald-300">
                <?= htmlspecialchars(t('escrow.done_seller')) ?>
            </div>

            <?php if (!empty($isBuyer) || !empty($isSeller)): ?>
                <?php
                $rateWhom = !empty($isBuyer) ? t('reviews.rate_seller') : t('reviews.rate_buyer');
                ?>
                <?php if ($myReview): ?>
                    <div class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] px-4 py-4 space-y-2">
                        <h3 class="font-display font-bold text-sm"><?= htmlspecialchars(t('reviews.your_review')) ?></h3>
                        <div class="flex items-center gap-1">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="<?= $i <= (int) $myReview['rating'] ? 'text-amber-500' : 'text-gray-300 dark:text-gray-600' ?>">
                                    <?= IconHelper::star('w-4 h-4', $i <= (int) $myReview['rating']) ?>
                                </span>
                            <?php endfor; ?>
                            <span class="ml-2 text-xs font-semibold text-ink-700 dark:text-gray-300"><?= (int) $myReview['rating'] ?>/5</span>
                        </div>
                        <?php if (!empty($myReview['body'])): ?>
                            <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"><?= nl2br(htmlspecialchars($myReview['body'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <form method="post" action="<?= ProductHelper::url('/orders/' . (int) $order['id'] . '/review') ?>" class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] px-4 py-4 space-y-3">
                        <?= csrf_field() ?>
                        <div>
                            <h3 class="font-display font-bold"><?= htmlspecialchars($rateWhom) ?></h3>
                            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('reviews.form_hint')) ?></p>
                        </div>
                        <div class="flex flex-row-reverse justify-end gap-1" data-review-stars>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="rating" id="order-rating-<?= $i ?>" value="<?= $i ?>" class="sr-only" <?= $i === 1 ? 'required' : '' ?>>
                                <label for="order-rating-<?= $i ?>"
                                       class="cursor-pointer p-1.5 rounded-xl border border-black/10 dark:border-white/10 text-gray-300 transition">
                                    <?= IconHelper::star('w-5 h-5', true) ?>
                                </label>
                            <?php endfor; ?>
                        </div>
                        <style>
                            [data-review-stars] input:checked ~ label,
                            [data-review-stars] input:checked + label,
                            [data-review-stars] label:hover,
                            [data-review-stars] label:hover ~ label {
                                color: #f59e0b;
                                border-color: rgba(251, 191, 36, 0.55);
                                background: rgba(255, 251, 235, 0.95);
                            }
                            .dark [data-review-stars] input:checked ~ label,
                            .dark [data-review-stars] input:checked + label,
                            .dark [data-review-stars] label:hover,
                            .dark [data-review-stars] label:hover ~ label {
                                background: rgba(245, 158, 11, 0.12);
                            }
                        </style>
                        <textarea name="body" rows="3" maxlength="2000" placeholder="<?= htmlspecialchars(t('reviews.body_placeholder')) ?>"
                                  class="ui-input w-full p-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm"></textarea>
                        <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('reviews.submit')) ?></button>
                    </form>
                <?php endif; ?>

                <?php if ($counterpartReview): ?>
                    <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-4 py-3 text-sm text-gray-500">
                        <?= htmlspecialchars(t('reviews.counterpart_left', ['rating' => (string) (int) $counterpartReview['rating']])) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($status === 'partial_refunded'): ?>
            <div class="rounded-2xl bg-sky-50 dark:bg-sky-900/20 border border-sky-100 dark:border-sky-800/40 px-4 py-3 text-sm font-semibold text-sky-800 dark:text-sky-300">
                <?= htmlspecialchars(t('escrow.done_partial')) ?>
            </div>
        <?php endif; ?>
        <?php if ($status === 'refunded'): ?>
            <div class="rounded-2xl bg-sky-50 dark:bg-sky-900/20 border border-sky-100 dark:border-sky-800/40 px-4 py-3 text-sm font-semibold text-sky-800 dark:text-sky-300">
                <?= htmlspecialchars(t('escrow.done_refund')) ?>
            </div>
        <?php endif; ?>
        <?php if ($status === 'cancelled'): ?>
            <div class="rounded-2xl bg-gray-50 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 px-4 py-3 text-sm font-semibold text-ink-700 dark:text-gray-300">
                <?= htmlspecialchars(t('escrow.done_cancelled')) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($isBuyer) || !empty($isSeller)): ?>
            <button type="button"
                    data-chat-open
                    data-order-id="<?= (int) $order['id'] ?>"
                    class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white">
                <?= htmlspecialchars(t('chat.write_party')) ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($returnEvents): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
            <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('escrow.case_timeline')) ?></h3>
            <ol class="space-y-2">
                <?php foreach ($returnEvents as $event): ?>
                    <?php
                    $eventKey = 'escrow.event_' . (string) ($event['event_type'] ?? '');
                    $eventLabel = t($eventKey);
                    if ($eventLabel === $eventKey) {
                        $eventLabel = (string) ($event['event_type'] ?? '');
                    }
                    ?>
                    <li class="text-sm text-ink-800 dark:text-gray-200">
                        <span class="text-[11px] text-gray-400"><?= htmlspecialchars((string) ($event['created_at'] ?? '')) ?></span>
                        · <?= htmlspecialchars($eventLabel) ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>

    <a href="<?= ProductHelper::url('/orders') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 font-medium transition"><?= htmlspecialchars(t('escrow.back_deals')) ?></a>
</section>
