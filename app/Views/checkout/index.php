<?php
use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\EscrowService;
use App\Services\FreedomPay\Client as FreedomPayClient;

$items = $items ?? (isset($item) ? [$item] : []);
$total = (int) ($total ?? 0);
if ($total <= 0) {
    foreach ($items as $row) {
        $qty = max(1, (int) ($row['buy_qty'] ?? $row['cart_qty'] ?? 1));
        $total += ProductHelper::lineAmount($row, $qty);
    }
}
$dealMode = ($dealMode ?? 'escrow') === 'direct' ? 'direct' : 'escrow';
$fromCart = !empty($fromCart);
$isDirectDeal = $dealMode === 'direct' && !$fromCart;
$isEscrowDeal = !$isDirectDeal;
$arbitrationFee = $isEscrowDeal ? EscrowService::arbitrationFeeForItems($items) : 0;
$payTotal = $total + $arbitrationFee;
$priceLabel = number_format($payTotal, 0, '', ' ') . ' ₸';
$subtotalLabel = number_format($total, 0, '', ' ') . ' ₸';
$feeLabel = number_format($arbitrationFee, 0, '', ' ') . ' ₸';
$checkoutPayUrl = $checkoutPayUrl ?? ProductHelper::url('/checkout/' . (int) ($items[0]['id'] ?? 0) . '/pay');
$cancelUrl = $cancelUrl ?? ProductHelper::url('/cart');
$digitalOnly = $items !== [];
foreach ($items as $row) {
    if (!ProductHelper::isDigitalListing($row)) {
        $digitalOnly = false;
        break;
    }
}
$walletBalance = (int) ($walletBalance ?? 0);
$need = $payTotal;
$canWallet = $walletBalance >= $need;
$fpConfigured = (new FreedomPayClient())->isConfigured();
$simPayments = (bool) ($GLOBALS['appConfig']['allow_simulated_payments'] ?? false) && !$fpConfigured;
$canCard = $fpConfigured || $simPayments;
?>
<section class="max-w-lg mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('checkout.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('checkout.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t($isDirectDeal ? 'checkout.subtitle_direct' : 'checkout.subtitle')) ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft backdrop-blur">
        <div class="border-b border-black/[0.05] dark:border-white/10 divide-y divide-black/[0.05] dark:divide-white/10">
            <?php foreach ($items as $row):
                $imageUrl = ProductHelper::imageUrl($row);
            ?>
                <div class="flex gap-4 p-5">
                    <div class="w-20 h-20 rounded-2xl overflow-hidden bg-gradient-to-br from-ink-100 via-brand-50 to-accent-50 dark:from-white/10 dark:via-brand-900/20 dark:to-transparent flex-shrink-0 flex items-center justify-center">
                        <?php if ($imageUrl): ?>
                            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                            <?= ProductHelper::icon($row['type'], 'w-10 h-10 text-brand-500/70') ?>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="font-semibold text-ink-900 dark:text-white text-sm leading-snug line-clamp-2"><?= htmlspecialchars($row['title']) ?></h2>
                        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($row['seller_name'] ?? '') ?> · <?= htmlspecialchars($row['location'] ?? '') ?></p>
                        <?php
                        $rowQty = max(1, (int) ($row['buy_qty'] ?? $row['cart_qty'] ?? 1));
                        $rowLine = ProductHelper::lineAmount($row, $rowQty);
                        ?>
                        <?php if ($rowQty > 1): ?>
                            <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('checkout.qty_line', ['n' => $rowQty, 'price' => number_format((int) ($row['price'] ?? 0), 0, '', ' ')])) ?></p>
                        <?php endif; ?>
                        <p class="font-display text-xl font-extrabold text-brand-600 mt-2"><?= number_format($rowLine, 0, '', ' ') ?> ₸</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" action="<?= $checkoutPayUrl ?>" class="p-5 sm:p-6 space-y-5">
            <?= csrf_field() ?>
            <?php if (!$fromCart): ?>
                <input type="hidden" name="quantity" value="<?= (int) ($item['buy_qty'] ?? 1) ?>">
            <?php endif; ?>
            <?php if ($isDirectDeal): ?>
                <input type="hidden" name="deal_mode" value="direct">
            <?php endif; ?>
            <div class="rounded-2xl <?= $isDirectDeal ? 'bg-slate-50/90 dark:bg-slate-950/20 border-slate-200/70 dark:border-slate-800/40 text-slate-900 dark:text-slate-200' : 'bg-amber-50/90 dark:bg-amber-950/20 border-amber-200/70 dark:border-amber-800/40 text-amber-900 dark:text-amber-200' ?> border px-4 py-3 text-xs leading-relaxed">
                <?= htmlspecialchars(t($isDirectDeal ? 'checkout.direct_notice' : ($digitalOnly ? 'checkout.digital_notice' : 'checkout.escrow_notice'))) ?>
                <?php if (count($items) > 1): ?>
                    <span class="block mt-1.5"><?= htmlspecialchars(t('checkout.cart_escrow_hint')) ?></span>
                <?php endif; ?>
            </div>

            <div class="flex items-center justify-between gap-3 rounded-2xl border border-black/[0.06] dark:border-white/10 bg-ink-50/60 dark:bg-white/[0.03] px-4 py-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('wallet.available')) ?></p>
                    <p class="font-display font-bold text-ink-900 dark:text-white mt-0.5"><?= htmlspecialchars(Wallet::formatMoney($walletBalance)) ?></p>
                </div>
                <a href="<?= ProductHelper::url('/wallet') ?>" class="text-xs font-semibold text-brand-600 hover:underline"><?= htmlspecialchars(t('wallet.top_up')) ?></a>
            </div>

            <?php if ($digitalOnly): ?>
                <input type="hidden" name="delivery_method" value="digital">
            <?php else:
                $deliveryMethods = $deliveryMethods ?? ['kazpost', 'cdek', 'courier', 'other'];
                $cdekAvailable = !empty($cdekAvailable) || in_array('cdek', $deliveryMethods, true);
                $pointBOld = $pointBOld ?? [];
                $pointBErrors = $pointBErrors ?? null;
                $oldMode = (string) ($pointBOld['delivery_mode'] ?? 'pvz');
                $user = \App\Core\Auth::user();
            ?>
            <div class="space-y-2">
                <h3 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('checkout.delivery')) ?></h3>
                <?php foreach ($deliveryMethods as $i => $dm): ?>
                    <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                        <input type="radio" name="delivery_method" value="<?= htmlspecialchars($dm) ?>" <?= $i === 0 ? 'checked' : '' ?> class="accent-brand-600 checkout-delivery-radio" data-delivery="<?= htmlspecialchars($dm) ?>">
                        <span class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('escrow.delivery_' . $dm)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php if ($cdekAvailable): ?>
            <div id="checkout-cdek-point-b" class="hidden space-y-3 rounded-2xl border border-brand-200/70 dark:border-brand-800/40 bg-brand-50/40 dark:bg-brand-950/20 p-4">
                <div>
                    <h3 class="text-sm font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('checkout.cdek_block_title')) ?></h3>
                    <p class="text-[11px] text-gray-500 mt-1"><?= htmlspecialchars(t('checkout.cdek_block_hint')) ?></p>
                    <p class="text-[11px] text-amber-800 dark:text-amber-200 mt-1"><?= htmlspecialchars(t('checkout.cdek_required_hint')) ?></p>
                    <p class="text-[11px] text-gray-500 mt-1"><?= htmlspecialchars(t('checkout.cdek_quote_after_pay')) ?></p>
                </div>
                <?php if (is_array($pointBErrors) && !empty($pointBErrors['missing_fields'])): ?>
                    <div class="rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800/50 px-3 py-2 text-xs text-red-800 dark:text-red-200">
                        <?= htmlspecialchars(t('listing_shipping.missing_fields_hint')) ?>:
                        <strong><?= htmlspecialchars(implode(', ', $pointBErrors['missing_fields'])) ?></strong>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer text-xs font-semibold">
                        <input type="radio" name="delivery_mode" value="pvz" class="checkout-cdek-mode" <?= $oldMode !== 'courier' ? 'checked' : '' ?>>
                        <?= htmlspecialchars(t('checkout.cdek_mode_pvz')) ?>
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer text-xs font-semibold">
                        <input type="radio" name="delivery_mode" value="courier" class="checkout-cdek-mode" <?= $oldMode === 'courier' ? 'checked' : '' ?>>
                        <?= htmlspecialchars(t('checkout.cdek_mode_door')) ?>
                    </label>
                </div>

                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('checkout.cdek_recipient')) ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <input type="text" name="name" value="<?= htmlspecialchars((string) ($pointBOld['name'] ?? $pointBOld['recipient_name'] ?? ($user['name'] ?? ''))) ?>" placeholder="<?= htmlspecialchars(t('delivery.name')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?>" id="checkout-recipient-name">
                    <input type="tel" name="phone" value="<?= htmlspecialchars((string) ($pointBOld['phone'] ?? $pointBOld['recipient_phone'] ?? ($user['phone'] ?? ''))) ?>" placeholder="<?= htmlspecialchars(t('delivery.phone')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?>">
                    <input type="text" name="city" id="checkout-recipient-city" value="<?= htmlspecialchars((string) ($pointBOld['city'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.city')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?>">
                    <input type="number" name="cdek_city_code" id="checkout-cdek-city-code" value="<?= htmlspecialchars((string) ($pointBOld['cdek_city_code'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.cdek_city_code')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?>" readonly>
                    <input type="text" name="postal_code" value="<?= htmlspecialchars((string) ($pointBOld['postal_code'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.postal_code')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?> checkout-door-field">
                    <input type="text" name="region" value="<?= htmlspecialchars((string) ($pointBOld['region'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.region')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?> checkout-door-field">
                    <input type="text" name="street" value="<?= htmlspecialchars((string) ($pointBOld['street'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.street')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?> sm:col-span-2 checkout-door-field">
                    <input type="text" name="building" value="<?= htmlspecialchars((string) ($pointBOld['building'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.building')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?> checkout-door-field">
                    <input type="text" name="apartment" value="<?= htmlspecialchars((string) ($pointBOld['apartment'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.apartment')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?> checkout-door-field">
                </div>
                <div id="checkout-pvz-wrap" class="space-y-2" data-cdek-pvz-picker>
                    <input type="text" name="pvz_code" id="checkout-pvz-code" list="checkout-cdek-pvz-datalist" value="<?= htmlspecialchars((string) ($pointBOld['pvz_code'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.pvz_code')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?>" autocomplete="off">
                    <input type="text" name="pvz_name" id="checkout-pvz-name" value="<?= htmlspecialchars((string) ($pointBOld['pvz_name'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('delivery.pvz_name')) ?>" class="<?= $input ?? 'w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 px-3 py-2.5 text-sm' ?>" readonly>
                    <datalist id="checkout-cdek-pvz-datalist"></datalist>
                    <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('delivery.pvz_directory_hint')) ?></p>
                </div>
                <p class="text-[11px] text-gray-400" id="checkout-cdek-city-status"></p>
                <input type="hidden" name="country" value="KZ">
            </div>
            <script>
            (function () {
                var block = document.getElementById('checkout-cdek-point-b');
                if (!block) return;
                var citiesUrl = <?= json_encode(\App\Helpers\ProductHelper::url('/delivery/cdek/cities'), JSON_UNESCAPED_SLASHES) ?>;
                var pointsUrl = <?= json_encode(\App\Helpers\ProductHelper::url('/delivery/cdek/points'), JSON_UNESCAPED_SLASHES) ?>;
                var cityTimer = null, pvzTimer = null;

                function isCdekSelected() {
                    var el = document.querySelector('.checkout-delivery-radio:checked');
                    return el && el.value === 'cdek';
                }
                function isPvz() {
                    return !!document.querySelector('.checkout-cdek-mode[value="pvz"]:checked');
                }
                function syncDelivery() {
                    block.classList.toggle('hidden', !isCdekSelected());
                    syncMode();
                }
                function syncMode() {
                    var pvz = isPvz();
                    document.getElementById('checkout-pvz-wrap')?.classList.toggle('hidden', !pvz);
                    document.querySelectorAll('.checkout-door-field').forEach(function (el) {
                        el.classList.toggle('hidden', pvz);
                        if (pvz) el.removeAttribute('required');
                    });
                }
                function resolveCity() {
                    var city = (document.getElementById('checkout-recipient-city')?.value || '').trim();
                    var codeInput = document.getElementById('checkout-cdek-city-code');
                    var status = document.getElementById('checkout-cdek-city-status');
                    if (!codeInput || city.length < 2) return;
                    fetch(citiesUrl + '?city=' + encodeURIComponent(city) + '&country_code=KZ', {
                        credentials: 'same-origin', headers: { 'Accept': 'application/json' }
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data && data.ok && data.code) {
                            codeInput.value = data.code;
                            if (status) status.textContent = (data.city || city) + ' → ' + data.code;
                            loadPvz();
                        } else if (status) {
                            status.textContent = <?= json_encode(t('listing_shipping.cdek_city_not_found'), JSON_UNESCAPED_UNICODE) ?>;
                        }
                    }).catch(function () {});
                }
                function loadPvz() {
                    var list = document.getElementById('checkout-cdek-pvz-datalist');
                    var codeInput = document.getElementById('checkout-pvz-code');
                    var nameInput = document.getElementById('checkout-pvz-name');
                    if (!list || !codeInput || !isPvz()) return;
                    var city = (document.getElementById('checkout-recipient-city')?.value || '').trim();
                    var cityCode = (document.getElementById('checkout-cdek-city-code')?.value || '').trim();
                    var q = (codeInput.value || '').trim();
                    var url = pointsUrl + '?limit=40&type=PVZ&city=' + encodeURIComponent(city) + '&q=' + encodeURIComponent(q);
                    if (cityCode) url += '&city_code=' + encodeURIComponent(cityCode);
                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data || !data.points) return;
                            list.innerHTML = '';
                            data.points.forEach(function (p) {
                                var opt = document.createElement('option');
                                opt.value = p.code;
                                opt.label = (p.address || p.name || p.code) + (p.city ? (' — ' + p.city) : '');
                                list.appendChild(opt);
                            });
                            var match = data.points.find(function (p) { return p.code === codeInput.value; });
                            if (match && nameInput) nameInput.value = match.name || match.address || match.code;
                        }).catch(function () {});
                }
                document.querySelectorAll('.checkout-delivery-radio').forEach(function (el) {
                    el.addEventListener('change', syncDelivery);
                });
                document.querySelectorAll('.checkout-cdek-mode').forEach(function (el) {
                    el.addEventListener('change', syncMode);
                });
                document.getElementById('checkout-recipient-city')?.addEventListener('input', function () {
                    clearTimeout(cityTimer);
                    cityTimer = setTimeout(resolveCity, 400);
                });
                document.getElementById('checkout-pvz-code')?.addEventListener('input', function () {
                    clearTimeout(pvzTimer);
                    pvzTimer = setTimeout(loadPvz, 300);
                });
                syncDelivery();
                if ((document.getElementById('checkout-recipient-city')?.value || '').trim()) resolveCity();
            })();
            </script>
            <?php endif; ?>
            <?php endif; ?>

            <div class="space-y-2">
                <h3 class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400"><?= htmlspecialchars(t('checkout.method')) ?></h3>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition <?= !$canWallet ? 'opacity-60' : '' ?>">
                    <input type="radio" name="payment_method" value="wallet" <?= $canWallet && !$fpConfigured ? 'checked' : ($canWallet ? '' : 'disabled') ?> class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200 flex-1">
                        <?= htmlspecialchars(t('checkout.method_wallet')) ?>
                        <?php if (!$canWallet): ?>
                            <span class="block text-[11px] font-medium text-red-500 mt-0.5"><?= htmlspecialchars(t('wallet.need_more', ['need' => Wallet::formatMoney(max(0, $need - $walletBalance))])) ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php if ($fpConfigured): ?>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                    <input type="radio" name="payment_method" value="card" <?= !$canWallet || $fpConfigured ? 'checked' : '' ?> class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200">
                        <?= htmlspecialchars(t('checkout.method_freedompay')) ?>
                        <span class="block text-[11px] font-medium text-gray-400 mt-0.5"><?= htmlspecialchars(t('checkout.method_freedompay_hint')) ?></span>
                    </span>
                </label>
                <?php elseif ($simPayments): ?>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                    <input type="radio" name="payment_method" value="card" <?= !$canWallet ? 'checked' : '' ?> class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('checkout.method_card')) ?></span>
                </label>
                <label class="flex items-center gap-3 p-3.5 rounded-2xl border border-black/[0.08] dark:border-white/10 cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:has-[:checked]:bg-brand-500/10 transition">
                    <input type="radio" name="payment_method" value="kaspi" class="accent-brand-600">
                    <span class="text-sm font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('checkout.method_kaspi')) ?></span>
                </label>
                <?php elseif (!$canWallet): ?>
                    <p class="text-xs text-red-500 px-1"><?= htmlspecialchars(t('wallet.payments_disabled')) ?></p>
                <?php endif; ?>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-3 pt-1">
                <?php
                $payLogos = [
                    ['file' => 'halyk.png', 'alt' => 'Halyk', 'class' => 'h-9 w-auto max-w-[10rem] object-contain'],
                    ['file' => 'visa.png', 'alt' => 'Visa', 'class' => 'h-10 w-auto max-w-[7rem] object-contain rounded-md'],
                    ['file' => 'mastercard.png', 'alt' => 'Mastercard', 'class' => 'h-10 w-auto max-w-[8rem] object-contain rounded-md'],
                ];
                foreach ($payLogos as $logo):
                ?>
                <img src="<?= ProductHelper::url('/public/assets/img/payments/' . $logo['file']) ?>" alt="<?= htmlspecialchars($logo['alt']) ?>" class="<?= $logo['class'] ?>">
                <?php endforeach; ?>
            </div>

            <div class="space-y-2 pt-2 border-t border-black/[0.05] dark:border-white/10">
                <?php if ($isEscrowDeal && $arbitrationFee > 0): ?>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-500"><?= htmlspecialchars(t('checkout.subtotal')) ?></span>
                        <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($subtotalLabel) ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-500"><?= htmlspecialchars(t('checkout.arbitration_fee', ['percent' => EscrowService::ARBITRATION_FEE_PERCENT])) ?></span>
                        <span class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars($feeLabel) ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between items-center <?= $isEscrowDeal && $arbitrationFee > 0 ? 'pt-1' : '' ?>">
                    <span class="text-sm text-gray-500"><?= htmlspecialchars(t($isDirectDeal ? 'checkout.to_pay_direct' : 'checkout.to_pay')) ?></span>
                    <span class="font-display text-2xl font-extrabold text-ink-900 dark:text-white"><?= htmlspecialchars($priceLabel) ?></span>
                </div>
            </div>

            <button type="submit" <?= (!$canWallet && !$canCard) ? 'disabled' : '' ?> class="w-full bg-accent-500 hover:bg-accent-400 disabled:opacity-50 disabled:cursor-not-allowed text-white font-display font-bold py-3.5 rounded-2xl text-sm uppercase tracking-wider transition shadow-soft">
                <?= htmlspecialchars(t($isDirectDeal ? 'checkout.pay_direct_btn' : 'checkout.pay_escrow_btn')) ?>
            </button>
            <a href="<?= $cancelUrl ?>" class="block w-full text-center text-sm text-gray-400 hover:text-brand-600 font-medium transition">
                <?= htmlspecialchars(t('checkout.cancel')) ?>
            </a>
        </form>
    </div>
</section>
