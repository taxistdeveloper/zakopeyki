<?php
use App\Helpers\ProductHelper;
use App\Models\DeliveryOrder;

$d = $delivery;
$status = $d['status'] ?? '';
$sender = $d['sender'] ?? null;
$recipient = $d['recipient'] ?? null;
$shipment = $d['shipment'] ?? null;
$quotes = $d['quotes'] ?? [];
$selectedQuote = $d['selected_quote'] ?? null;
$tracking = $d['tracking'] ?? [];

$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$btn = 'inline-flex items-center justify-center w-full font-display font-bold py-3 rounded-2xl text-xs uppercase tracking-wider transition';
$canEditData = in_array($status, [
    DeliveryOrder::STATUS_DATA_COLLECTION,
    DeliveryOrder::STATUS_DATA_COMPLETE,
    DeliveryOrder::STATUS_QUOTE_RECEIVED,
    DeliveryOrder::STATUS_READY_FOR_PAYMENT,
], true);
$packRecommendation = $packRecommendation ?? null;
$canSelectQuote = $status === DeliveryOrder::STATUS_QUOTE_RECEIVED;
$canPay = $status === DeliveryOrder::STATUS_READY_FOR_PAYMENT && $selectedQuote;
$payAmount = $selectedQuote ? number_format((int) $selectedQuote['total_amount'], 0, '', ' ') . ' ₸' : '';
?>
<section class="max-w-2xl mx-auto space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('delivery.eyebrow')) ?></p>
            <h1 class="font-display text-2xl font-bold text-ink-900 dark:text-white mt-1">
                <?= htmlspecialchars(t('delivery.page_title', ['number' => $d['order_number']])) ?>
            </h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('delivery.subtitle')) ?></p>
        </div>
        <span class="inline-flex px-3 py-1.5 rounded-xl text-xs font-bold uppercase tracking-wider bg-brand-50 dark:bg-brand-500/15 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-500/25">
            <?= htmlspecialchars(DeliveryOrder::statusLabel($status)) ?>
        </span>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="rounded-2xl border border-blue-200/80 dark:border-blue-800/40 bg-blue-50/80 dark:bg-blue-950/20 px-4 py-3 text-sm text-blue-900 dark:text-blue-200">
        <?= htmlspecialchars(t('delivery.legal_notice')) ?>
    </div>

    <?php if ($p2pOrder): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 shadow-soft">
            <p class="text-xs text-gray-400"><?= htmlspecialchars(t('delivery.linked_deal')) ?></p>
            <a href="<?= ProductHelper::url('/orders/' . (int) $p2pOrder['id']) ?>" class="font-semibold text-brand-600 hover:underline">
                <?= htmlspecialchars($p2pOrder['product_title'] ?? '') ?> — #<?= (int) $p2pOrder['id'] ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if (!empty($isSeller) && $canEditData): ?>
        <form method="post" action="<?= ProductHelper::url('/delivery/' . (int) $d['id'] . '/sender') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-4 shadow-soft">
            <?= csrf_field() ?>
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('delivery.sender_title')) ?></h2>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('delivery.sender_hint')) ?></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="name" required value="<?= htmlspecialchars($sender['name'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.name')) ?>" class="<?= $input ?>">
                <input type="tel" name="phone" required value="<?= htmlspecialchars($sender['phone'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.phone')) ?>" class="<?= $input ?>">
                <input type="text" name="city" required value="<?= htmlspecialchars($sender['city'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.city')) ?>" class="<?= $input ?>">
                <input type="text" name="region" value="<?= htmlspecialchars($sender['region'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.region')) ?>" class="<?= $input ?>">
                <input type="text" name="street" value="<?= htmlspecialchars($sender['street'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.street')) ?>" class="<?= $input ?> sm:col-span-2">
                <input type="text" name="building" value="<?= htmlspecialchars($sender['building'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.building')) ?>" class="<?= $input ?>">
                <input type="text" name="apartment" value="<?= htmlspecialchars($sender['apartment'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.apartment')) ?>" class="<?= $input ?>">
            </div>
            <div class="border-t border-black/[0.06] dark:border-white/10 pt-4 space-y-3">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider"><?= htmlspecialchars(t('delivery.shipment_title')) ?></p>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('delivery.item_dims_hint')) ?></p>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="dimensions_unknown" value="1" <?= !empty($shipment['dimensions_unknown']) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <?= htmlspecialchars(t('delivery.dimensions_unknown')) ?>
                </label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <input type="number" step="0.001" min="0" name="item_weight" value="<?= htmlspecialchars((string) ($shipment['item_weight'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.item_weight_kg')) ?>" class="<?= $input ?>">
                    <input type="number" step="0.1" min="0" name="item_length" value="<?= htmlspecialchars((string) ($shipment['item_length'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.item_length_cm')) ?>" class="<?= $input ?>">
                    <input type="number" step="0.1" min="0" name="item_width" value="<?= htmlspecialchars((string) ($shipment['item_width'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.item_width_cm')) ?>" class="<?= $input ?>">
                    <input type="number" step="0.1" min="0" name="item_height" value="<?= htmlspecialchars((string) ($shipment['item_height'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.item_height_cm')) ?>" class="<?= $input ?>">
                </div>
                <input type="number" step="0.001" min="0" name="packaging_weight" value="<?= htmlspecialchars((string) ($shipment['packaging_weight'] ?? '0.15')) ?>" placeholder="<?= htmlspecialchars(t('delivery.packaging_weight_kg')) ?>" class="<?= $input ?>">
                <?php if ($packRecommendation && !empty($packRecommendation['recommended'])): ?>
                    <div class="rounded-2xl bg-brand-50/80 dark:bg-brand-950/20 border border-brand-200/60 dark:border-brand-500/25 px-4 py-3 text-sm">
                        <p class="font-semibold text-brand-800 dark:text-brand-200">
                            <?= htmlspecialchars(t('delivery.recommend_pack', ['name' => $packRecommendation['recommended']['name']])) ?>
                        </p>
                        <p class="text-xs text-brand-700/80 dark:text-brand-300/80 mt-1">
                            <?= htmlspecialchars($packRecommendation['recommended']['fit_reason'] ?? '') ?>
                        </p>
                    </div>
                <?php elseif ($packRecommendation && !empty($packRecommendation['none_fit'])): ?>
                    <div class="rounded-2xl bg-amber-50/80 dark:bg-amber-950/20 border border-amber-200/60 px-4 py-3 text-sm text-amber-900 dark:text-amber-200">
                        <?= htmlspecialchars(t('delivery.no_pack_fits')) ?>
                    </div>
                <?php endif; ?>
                <select name="packaging_id" class="<?= $input ?>">
                    <option value=""><?= htmlspecialchars(t('delivery.packaging_none')) ?></option>
                    <?php foreach ($packagings as $pack): ?>
                        <option value="<?= (int) $pack['id'] ?>" <?= (int) ($shipment['packaging_id'] ?? 0) === (int) $pack['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pack['name']) ?> (<?= htmlspecialchars($pack['code']) ?> — <?= (float) $pack['max_weight_kg'] ?> кг)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" min="1" name="package_count" value="<?= (int) ($shipment['package_count'] ?? 1) ?>" placeholder="<?= htmlspecialchars(t('delivery.package_count')) ?>" class="<?= $input ?>">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_fragile" value="1" <?= !empty($shipment['is_fragile']) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <?= htmlspecialchars(t('delivery.fragile')) ?>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="is_irregular" value="1" <?= !empty($shipment['is_irregular']) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <?= htmlspecialchars(t('delivery.irregular')) ?>
                </label>
            </div>
            <button type="submit" class="<?= $btn ?> bg-ink-900 hover:bg-ink-800 text-white"><?= htmlspecialchars(t('delivery.save_sender')) ?></button>
        </form>
    <?php elseif ($sender): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 shadow-soft text-sm space-y-1">
            <h3 class="font-display font-bold text-ink-900 dark:text-white mb-2"><?= htmlspecialchars(t('delivery.sender_title')) ?></h3>
            <p><?= htmlspecialchars($sender['name']) ?> · <?= htmlspecialchars($sender['phone']) ?></p>
            <p class="text-gray-500"><?= htmlspecialchars(trim(($sender['city'] ?? '') . ', ' . ($sender['street'] ?? '') . ' ' . ($sender['building'] ?? ''), ', ')) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($isBuyer) && $canEditData): ?>
        <form method="post" action="<?= ProductHelper::url('/delivery/' . (int) $d['id'] . '/recipient') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-4 shadow-soft">
            <?= csrf_field() ?>
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('delivery.recipient_title')) ?></h2>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('delivery.recipient_hint')) ?></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="name" required value="<?= htmlspecialchars($recipient['name'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.name')) ?>" class="<?= $input ?>">
                <input type="tel" name="phone" required value="<?= htmlspecialchars($recipient['phone'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.phone')) ?>" class="<?= $input ?>">
                <select name="delivery_mode" class="<?= $input ?> sm:col-span-2">
                    <option value="courier" <?= ($recipient['delivery_mode'] ?? 'courier') === 'courier' ? 'selected' : '' ?>><?= htmlspecialchars(t('delivery.mode_courier')) ?></option>
                    <option value="pvz" <?= ($recipient['delivery_mode'] ?? '') === 'pvz' ? 'selected' : '' ?>><?= htmlspecialchars(t('delivery.mode_pvz')) ?></option>
                </select>
                <input type="text" name="city" required value="<?= htmlspecialchars($recipient['city'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.city')) ?>" class="<?= $input ?>">
                <input type="text" name="street" value="<?= htmlspecialchars($recipient['street'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.street')) ?>" class="<?= $input ?>">
                <input type="text" name="building" value="<?= htmlspecialchars($recipient['building'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.building')) ?>" class="<?= $input ?>">
                <input type="text" name="apartment" value="<?= htmlspecialchars($recipient['apartment'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.apartment')) ?>" class="<?= $input ?>">
                <input type="text" name="pvz_code" value="<?= htmlspecialchars($recipient['pvz_code'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.pvz_code')) ?>" class="<?= $input ?>">
                <input type="text" name="pvz_name" value="<?= htmlspecialchars($recipient['pvz_name'] ?? '') ?>" placeholder="<?= htmlspecialchars(t('delivery.pvz_name')) ?>" class="<?= $input ?>">
            </div>
            <button type="submit" class="<?= $btn ?> bg-brand-600 hover:bg-brand-500 text-white"><?= htmlspecialchars(t('delivery.save_recipient')) ?></button>
        </form>
    <?php elseif ($recipient): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 shadow-soft text-sm space-y-1">
            <h3 class="font-display font-bold text-ink-900 dark:text-white mb-2"><?= htmlspecialchars(t('delivery.recipient_title')) ?></h3>
            <p><?= htmlspecialchars($recipient['name']) ?> · <?= htmlspecialchars($recipient['phone']) ?></p>
            <p class="text-gray-500">
                <?= htmlspecialchars(t('delivery.mode_' . ($recipient['delivery_mode'] ?? 'courier'))) ?>
                · <?= htmlspecialchars($recipient['city'] ?? '') ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($quotes !== [] && !empty($isBuyer) && ($canSelectQuote || $selectedQuote)): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-4 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('delivery.quotes_title')) ?></h2>
            <?php if ($canSelectQuote): ?>
                <form method="post" action="<?= ProductHelper::url('/delivery/' . (int) $d['id'] . '/quote') ?>" class="space-y-3">
                    <?= csrf_field() ?>
                    <?php foreach ($quotes as $quote):
                        $qTotal = number_format((int) $quote['total_amount'], 0, '', ' ') . ' ₸';
                        $eta = '';
                        if (!empty($quote['eta_days_min']) || !empty($quote['eta_days_max'])) {
                            $eta = (int) ($quote['eta_days_min'] ?? $quote['eta_days_max']) . '–' . (int) ($quote['eta_days_max'] ?? $quote['eta_days_min']) . ' ' . t('delivery.days');
                        }
                    ?>
                        <label class="flex items-start gap-3 p-4 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer hover:border-brand-300 dark:hover:border-brand-500/40 transition">
                            <input type="radio" name="quote_id" value="<?= (int) $quote['id'] ?>" required class="mt-1" <?= !empty($quote['is_selected']) ? 'checked' : '' ?>>
                            <span class="flex-1 min-w-0">
                                <span class="font-semibold text-ink-900 dark:text-white block"><?= htmlspecialchars($quote['service_name']) ?></span>
                                <span class="text-xs text-gray-500"><?= htmlspecialchars($eta) ?></span>
                            </span>
                            <span class="font-display font-extrabold text-brand-600"><?= htmlspecialchars($qTotal) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <button type="submit" class="<?= $btn ?> bg-brand-600 hover:bg-brand-500 text-white"><?= htmlspecialchars(t('delivery.confirm_quote')) ?></button>
                </form>
            <?php elseif ($selectedQuote): ?>
                <p class="text-sm">
                    <?= htmlspecialchars($selectedQuote['service_name']) ?> —
                    <span class="font-display font-extrabold text-brand-600"><?= htmlspecialchars(number_format((int) $selectedQuote['total_amount'], 0, '', ' ') . ' ₸') ?></span>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($isBuyer) && $canPay): ?>
        <form method="post" action="<?= ProductHelper::url('/delivery/' . (int) $d['id'] . '/pay') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
            <?= csrf_field() ?>
            <input type="hidden" name="payment_method" value="card">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('delivery.payment_title')) ?></h2>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('delivery.payment_hint')) ?></p>
            <button type="submit" class="<?= $btn ?> bg-emerald-600 hover:bg-emerald-500 text-white">
                <?= htmlspecialchars(t('delivery.pay_btn', ['amount' => $payAmount])) ?>
            </button>
        </form>
    <?php endif; ?>

    <?php if ($tracking !== []): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-5 space-y-3 shadow-soft">
            <h2 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('delivery.tracking_title')) ?></h2>
            <?php if (!empty($d['logistics_order_id'])): ?>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('delivery.logistics_order')) ?>: <span class="font-mono"><?= htmlspecialchars($d['logistics_order_id']) ?></span></p>
            <?php endif; ?>
            <div class="space-y-2">
                <?php foreach ($tracking as $event): ?>
                    <div class="text-sm border-l-2 border-brand-300 pl-3">
                        <p class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($event['carrier_message'] ?? $event['carrier_status'] ?? '') ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($event['event_at'] ?? '') ?><?php if (!empty($event['tracking_number'])): ?> · <?= htmlspecialchars($event['tracking_number']) ?><?php endif; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
