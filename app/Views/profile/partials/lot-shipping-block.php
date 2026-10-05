<?php
use App\Helpers\ProductHelper;
use App\Models\ProductListingShipping;
use App\Services\Listing\ListingShippingService;

$ls = $listingShipping ?? [];
$packagings = $listingPackagings ?? [];
$userShip = \App\Models\User::defaultShipFrom($user ?? null);
$fulfillment = $ls['fulfillment_mode'] ?? ProductListingShipping::FULFILLMENT_DELIVERY;
$paramMode = $ls['param_mode'] ?? ProductListingShipping::MODE_EXACT;
$originType = $ls['origin_type'] ?? 'door';
$hasDefaultAddress = trim((string) ($userShip['ship_city'] ?? '')) !== '';
$useDefault = $hasDefaultAddress && (int) ($ls['use_default_ship_from'] ?? 1) === 1;
$shipCity = $ls['ship_city'] ?? ($useDefault ? ($userShip['ship_city'] ?? '') : ($editing['location'] ?? ''));
$defaultAddressLine = implode(', ', array_filter([
    trim((string) ($userShip['ship_city'] ?? '')),
    trim((string) ($userShip['ship_street'] ?? '')),
    trim((string) ($userShip['ship_building'] ?? '')),
    trim((string) ($userShip['ship_apartment'] ?? '')),
], static fn ($part) => $part !== ''));
$shippingErrors = $_SESSION['listing_shipping_errors'] ?? null;
unset($_SESSION['listing_shipping_errors']);
?>
<div id="lot-shipping-wrap" class="hidden space-y-4 rounded-2xl border border-black/[0.08] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] p-5">
    <div>
        <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('listing_shipping.title')) ?></h3>
        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('listing_shipping.subtitle')) ?></p>
    </div>

    <?php if (is_array($shippingErrors) && !empty($shippingErrors['missing_fields'])): ?>
        <div class="rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800/50 px-3 py-2 text-xs text-red-800 dark:text-red-200">
            <?= htmlspecialchars(t('listing_shipping.missing_fields_hint')) ?>:
            <strong><?= htmlspecialchars(implode(', ', $shippingErrors['missing_fields'])) ?></strong>
        </div>
    <?php endif; ?>

    <div class="rounded-xl bg-blue-50/80 dark:bg-blue-950/20 border border-blue-200/60 dark:border-blue-800/40 px-3 py-2 text-xs text-blue-900 dark:text-blue-200">
        <?= htmlspecialchars(t('listing_shipping.no_quote_at_publish')) ?>
    </div>

    <div>
        <p class="text-xs font-bold mb-2"><?= htmlspecialchars(t('listing_shipping.fulfillment_title')) ?></p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <?php foreach ([
                ProductListingShipping::FULFILLMENT_DELIVERY => t('listing_shipping.fulfillment_delivery'),
                ProductListingShipping::FULFILLMENT_PICKUP => t('listing_shipping.fulfillment_pickup'),
                ProductListingShipping::FULFILLMENT_BOTH => t('listing_shipping.fulfillment_both'),
            ] as $val => $label): ?>
                <label class="flex items-center gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer text-xs font-semibold">
                    <input type="radio" name="fulfillment_mode" value="<?= htmlspecialchars($val) ?>" <?= $fulfillment === $val ? 'checked' : '' ?> class="lot-fulfillment-radio">
                    <?= htmlspecialchars($label) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="text-[11px] text-gray-400 mt-2"><?= htmlspecialchars(t('listing_shipping.cdek_when_delivery')) ?></p>
    </div>

    <div id="lot-ship-from-block" class="space-y-3">
        <p class="text-xs font-bold"><?= htmlspecialchars(t('listing_shipping.ship_from_title')) ?></p>
        <p class="text-[11px] text-gray-500"><?= htmlspecialchars(t('listing_shipping.point_a_hint')) ?></p>
        <label id="lot-use-default-wrap" class="flex items-start gap-2 text-xs <?= $hasDefaultAddress ? '' : 'hidden' ?>">
            <input type="checkbox" name="use_default_ship_from" value="1" id="lot-use-default-ship" <?= $useDefault ? 'checked' : '' ?> class="rounded mt-0.5">
            <span>
                <?= htmlspecialchars(t('listing_shipping.use_default_address')) ?>
                <?php if ($defaultAddressLine !== ''): ?>
                    <span class="block text-[11px] font-normal text-gray-400 mt-0.5"><?= htmlspecialchars(t('listing_shipping.saved_address')) ?>: <?= htmlspecialchars($defaultAddressLine) ?></span>
                <?php endif; ?>
                <span class="block text-[11px] font-normal text-amber-700/80 dark:text-amber-300/80 mt-0.5"><?= htmlspecialchars(t('listing_shipping.snapshot_note')) ?></span>
            </span>
        </label>
        <label id="lot-save-default-wrap" class="flex items-start gap-2 text-xs <?= $useDefault ? 'hidden' : '' ?>">
            <input type="checkbox" name="save_default_ship_from" value="1" id="lot-save-default-ship" class="rounded mt-0.5">
            <span>
                <?= htmlspecialchars(t('listing_shipping.save_as_default')) ?>
                <?php if (!$hasDefaultAddress): ?>
                    <span class="block text-[11px] font-normal text-gray-400 mt-0.5"><?= htmlspecialchars(t('listing_shipping.default_address_hint')) ?></span>
                <?php endif; ?>
            </span>
        </label>
        <div id="lot-ship-from-fields" class="grid grid-cols-1 sm:grid-cols-2 gap-3 <?= $useDefault ? 'hidden' : '' ?>">
            <input type="text" name="ship_contact_name" value="<?= htmlspecialchars($ls['ship_contact_name'] ?? ($user['name'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.contact_name')) ?>" class="<?= $input ?>">
            <input type="tel" name="ship_phone" value="<?= htmlspecialchars($ls['ship_phone'] ?? ($user['phone'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.phone')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_country" value="<?= htmlspecialchars($ls['ship_country'] ?? ($userShip['ship_country'] ?? 'KZ')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.country')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_city" id="lot-ship-city" value="<?= htmlspecialchars($shipCity) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.city')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_region" value="<?= htmlspecialchars($ls['ship_region'] ?? ($userShip['ship_region'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.region')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_postal_code" value="<?= htmlspecialchars($ls['ship_postal_code'] ?? ($userShip['ship_postal_code'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.postal_code')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_street" value="<?= htmlspecialchars($ls['ship_street'] ?? ($userShip['ship_street'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.street')) ?>" class="<?= $input ?> sm:col-span-2">
            <input type="text" name="ship_building" value="<?= htmlspecialchars($ls['ship_building'] ?? ($userShip['ship_building'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.building')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_apartment" value="<?= htmlspecialchars($ls['ship_apartment'] ?? ($userShip['ship_apartment'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.apartment')) ?>" class="<?= $input ?>">
        </div>

        <div id="lot-cdek-origin-block" class="space-y-3 rounded-xl border border-black/[0.06] dark:border-white/10 p-3">
            <p class="text-xs font-bold"><?= htmlspecialchars(t('listing_shipping.cdek_origin_title')) ?></p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <label class="flex items-center gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer text-xs font-semibold">
                    <input type="radio" name="origin_type" value="door" class="lot-origin-type" <?= $originType !== 'pvz' ? 'checked' : '' ?>>
                    <?= htmlspecialchars(t('listing_shipping.origin_door')) ?>
                </label>
                <label class="flex items-center gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer text-xs font-semibold">
                    <input type="radio" name="origin_type" value="pvz" class="lot-origin-type" <?= $originType === 'pvz' ? 'checked' : '' ?>>
                    <?= htmlspecialchars(t('listing_shipping.origin_pvz')) ?>
                </label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <input type="number" name="cdek_city_code" id="lot-cdek-city-code" value="<?= htmlspecialchars((string) ($ls['cdek_city_code'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.cdek_city_code')) ?>" class="<?= $input ?>" readonly>
                    <p class="text-[11px] text-gray-400 mt-1" id="lot-cdek-city-status"><?= htmlspecialchars(t('listing_shipping.cdek_city_auto')) ?></p>
                </div>
                <div id="lot-shipment-point-wrap" class="<?= $originType === 'pvz' ? '' : 'hidden' ?>" data-cdek-pvz-picker>
                    <input type="text" name="shipment_point" id="lot-shipment-point" list="lot-cdek-pvz-datalist" value="<?= htmlspecialchars((string) ($ls['shipment_point'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.shipment_point')) ?>" class="<?= $input ?>" autocomplete="off">
                    <datalist id="lot-cdek-pvz-datalist"></datalist>
                    <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('listing_shipping.shipment_point_hint')) ?></p>
                </div>
            </div>
        </div>
    </div>

    <div id="lot-shipment-block" class="space-y-3 border-t border-black/[0.06] dark:border-white/10 pt-4">
        <p class="text-xs font-bold"><?= htmlspecialchars(t('listing_shipping.how_ship_title')) ?></p>
        <div class="space-y-2">
            <?php foreach ([
                ProductListingShipping::MODE_EXACT => t('listing_shipping.mode_exact'),
                ProductListingShipping::MODE_STANDARD => t('listing_shipping.mode_standard'),
                ProductListingShipping::MODE_UNKNOWN => t('listing_shipping.mode_unknown'),
            ] as $val => $label): ?>
                <label class="flex items-start gap-2 rounded-xl border border-black/[0.08] dark:border-white/10 px-3 py-2.5 cursor-pointer">
                    <input type="radio" name="param_mode" value="<?= htmlspecialchars($val) ?>" <?= $paramMode === $val ? 'checked' : '' ?> class="lot-param-mode mt-0.5">
                    <span class="text-xs font-semibold"><?= htmlspecialchars($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div id="lot-mode-exact" class="grid grid-cols-2 sm:grid-cols-4 gap-3 <?= $paramMode !== ProductListingShipping::MODE_EXACT ? 'hidden' : '' ?>">
            <input type="number" step="0.001" min="0" name="item_weight" value="<?= htmlspecialchars((string) ($ls['item_weight'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.item_weight')) ?>" class="<?= $input ?>">
            <input type="number" step="0.1" min="0" name="item_length" value="<?= htmlspecialchars((string) ($ls['item_length'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.length')) ?>" class="<?= $input ?>">
            <input type="number" step="0.1" min="0" name="item_width" value="<?= htmlspecialchars((string) ($ls['item_width'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.width')) ?>" class="<?= $input ?>">
            <input type="number" step="0.1" min="0" name="item_height" value="<?= htmlspecialchars((string) ($ls['item_height'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.height')) ?>" class="<?= $input ?>">
        </div>

        <div id="lot-mode-standard" class="<?= $paramMode !== ProductListingShipping::MODE_STANDARD ? 'hidden' : '' ?>">
            <select name="packaging_id" class="<?= $input ?>">
                <option value=""><?= htmlspecialchars(t('listing_shipping.choose_packaging')) ?></option>
                <?php foreach ($packagings as $pack): ?>
                    <option value="<?= (int) $pack['id'] ?>" <?= (int) ($ls['packaging_id'] ?? 0) === (int) $pack['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pack['name']) ?> — <?= (int) $pack['length_cm'] ?>×<?= (int) $pack['width_cm'] ?>×<?= (int) $pack['height_cm'] ?> см, <?= (float) $pack['max_weight_kg'] ?> кг
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="lot-mode-unknown" class="space-y-2 <?= $paramMode !== ProductListingShipping::MODE_UNKNOWN ? 'hidden' : '' ?>">
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('listing_shipping.unknown_hint')) ?></p>
            <select name="product_type_hint" class="<?= $input ?>">
                <?php foreach (array_keys(ListingShippingService::TYPE_HINTS) as $hintKey): ?>
                    <option value="<?= htmlspecialchars($hintKey) ?>" <?= ($ls['product_type_hint'] ?? '') === $hintKey ? 'selected' : '' ?>>
                        <?= htmlspecialchars(t('listing_shipping.type_' . $hintKey)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input type="number" step="0.001" min="0" name="packaging_weight" id="lot-packaging-weight" value="<?= htmlspecialchars((string) ($ls['packaging_weight'] ?? '0.15')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.packaging_weight')) ?>" class="<?= $input ?>">
            <label class="flex items-center gap-2 text-xs self-center">
                <input type="checkbox" name="auto_packaging_weight" value="1" id="lot-auto-pack-weight" class="rounded">
                <?= htmlspecialchars(t('listing_shipping.auto_packaging_weight')) ?>
            </label>
            <input type="number" min="1" max="20" name="package_count" value="<?= htmlspecialchars((string) ($ls['package_count'] ?? '1')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.package_count')) ?>" class="<?= $input ?>">
            <input type="number" min="0" name="declared_value" value="<?= htmlspecialchars((string) ($ls['declared_value'] ?? ($editing['price'] ?? ''))) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.declared_value')) ?>" class="<?= $input ?>">
            <input type="text" name="shipment_description" value="<?= htmlspecialchars((string) ($ls['shipment_description'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.shipment_description')) ?>" class="<?= $input ?> sm:col-span-2">
            <input type="hidden" name="declared_currency" value="<?= htmlspecialchars((string) ($ls['declared_currency'] ?? 'KZT')) ?>">
        </div>

        <div class="flex flex-wrap gap-3 text-xs">
            <label class="flex items-center gap-2"><input type="checkbox" name="is_fragile" value="1" <?= !empty($ls['is_fragile']) ? 'checked' : '' ?> class="rounded"> <?= htmlspecialchars(t('listing_shipping.fragile')) ?></label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_irregular" value="1" id="lot-is-irregular" <?= !empty($ls['is_irregular']) ? 'checked' : '' ?> class="rounded"> <?= htmlspecialchars(t('listing_shipping.irregular')) ?></label>
        </div>
        <select name="irregular_reason" id="lot-irregular-reason" class="<?= $input ?> <?= empty($ls['is_irregular']) ? 'hidden' : '' ?>">
            <?php foreach (['cylindrical', 'non_rectangular', 'no_box', 'oversize', 'fragile_special', 'other'] as $reason): ?>
                <option value="<?= $reason ?>" <?= ($ls['irregular_reason'] ?? '') === $reason ? 'selected' : '' ?>><?= htmlspecialchars(t('listing_shipping.irregular_' . $reason)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<script>
(function () {
    const wrap = document.getElementById('lot-shipping-wrap');
    const typeSelect = document.getElementById('lot-type');
    const physical = <?= json_encode(array_merge(\App\Services\Listing\ListingShippingService::PHYSICAL_TYPES, \App\Services\Listing\ListingShippingService::OPTIONAL_TYPES)) ?>;
    const citiesUrl = <?= json_encode(ProductHelper::url('/delivery/cdek/cities'), JSON_UNESCAPED_SLASHES) ?>;
    const pointsUrl = <?= json_encode(ProductHelper::url('/delivery/cdek/points'), JSON_UNESCAPED_SLASHES) ?>;

    function isPickupOnly() {
        return !!document.querySelector('input.lot-fulfillment-radio[value="pickup"]:checked');
    }

    function syncVisibility() {
        if (!wrap || !typeSelect) return;
        const t = typeSelect.value;
        const show = physical.includes(t);
        wrap.classList.toggle('hidden', !show);
        const shipBlock = document.getElementById('lot-shipment-block');
        const cdekOrigin = document.getElementById('lot-cdek-origin-block');
        const pickupOnly = isPickupOnly();
        if (shipBlock) shipBlock.classList.toggle('hidden', pickupOnly);
        if (cdekOrigin) cdekOrigin.classList.toggle('hidden', pickupOnly);
        document.getElementById('lot-ship-from-block')?.classList.toggle('opacity-60', pickupOnly);
    }

    function syncOriginType() {
        const pvz = document.querySelector('input.lot-origin-type[value="pvz"]:checked');
        document.getElementById('lot-shipment-point-wrap')?.classList.toggle('hidden', !pvz);
    }

    let cityTimer = null;
    function resolveCity() {
        const city = (document.getElementById('lot-ship-city')?.value || '').trim();
        const codeInput = document.getElementById('lot-cdek-city-code');
        const status = document.getElementById('lot-cdek-city-status');
        if (!codeInput || city.length < 2) return;
        fetch(citiesUrl + '?city=' + encodeURIComponent(city) + '&country_code=KZ', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && data.code) {
                    codeInput.value = data.code;
                    if (status) status.textContent = (data.city || city) + ' → ' + data.code;
                } else if (status) {
                    status.textContent = <?= json_encode(t('listing_shipping.cdek_city_not_found'), JSON_UNESCAPED_UNICODE) ?>;
                }
            })
            .catch(function () {});
    }

    function scheduleCity() {
        clearTimeout(cityTimer);
        cityTimer = setTimeout(resolveCity, 400);
    }

    let pvzTimer = null;
    function loadPvz() {
        const list = document.getElementById('lot-cdek-pvz-datalist');
        const codeInput = document.getElementById('lot-shipment-point');
        const city = (document.getElementById('lot-ship-city')?.value || '').trim();
        const cityCode = (document.getElementById('lot-cdek-city-code')?.value || '').trim();
        if (!list || !codeInput) return;
        const q = (codeInput.value || '').trim();
        let url = pointsUrl + '?limit=40&type=PVZ&city=' + encodeURIComponent(city) + '&q=' + encodeURIComponent(q);
        if (cityCode) url += '&city_code=' + encodeURIComponent(cityCode);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.points) return;
                list.innerHTML = '';
                data.points.forEach(function (p) {
                    const opt = document.createElement('option');
                    opt.value = p.code;
                    opt.label = (p.address || p.name || p.code) + (p.city ? (' — ' + p.city) : '');
                    list.appendChild(opt);
                });
            })
            .catch(function () {});
    }

    document.querySelectorAll('.lot-fulfillment-radio').forEach(el => el.addEventListener('change', syncVisibility));
    document.querySelectorAll('.lot-origin-type').forEach(el => el.addEventListener('change', function () {
        syncOriginType();
        if (document.querySelector('input.lot-origin-type[value="pvz"]:checked')) loadPvz();
    }));

    document.querySelectorAll('.lot-param-mode').forEach(radio => {
        radio.addEventListener('change', () => {
            const v = document.querySelector('.lot-param-mode:checked')?.value;
            document.getElementById('lot-mode-exact')?.classList.toggle('hidden', v !== 'exact');
            document.getElementById('lot-mode-standard')?.classList.toggle('hidden', v !== 'standard_packaging');
            document.getElementById('lot-mode-unknown')?.classList.toggle('hidden', v !== 'unknown');
        });
    });

    document.getElementById('lot-use-default-ship')?.addEventListener('change', function () {
        document.getElementById('lot-ship-from-fields')?.classList.toggle('hidden', this.checked);
        document.getElementById('lot-save-default-wrap')?.classList.toggle('hidden', this.checked);
        if (this.checked) {
            const saveCb = document.getElementById('lot-save-default-ship');
            if (saveCb) saveCb.checked = false;
            scheduleCity();
        }
    });

    document.getElementById('lot-is-irregular')?.addEventListener('change', function () {
        document.getElementById('lot-irregular-reason')?.classList.toggle('hidden', !this.checked);
    });

    document.getElementById('lot-auto-pack-weight')?.addEventListener('change', function () {
        const inp = document.getElementById('lot-packaging-weight');
        if (inp) inp.disabled = this.checked;
    });

    document.getElementById('lot-ship-city')?.addEventListener('input', function () {
        scheduleCity();
        clearTimeout(pvzTimer);
        pvzTimer = setTimeout(loadPvz, 400);
    });
    document.getElementById('lot-shipment-point')?.addEventListener('input', function () {
        clearTimeout(pvzTimer);
        pvzTimer = setTimeout(loadPvz, 300);
    });

    typeSelect?.addEventListener('change', syncVisibility);
    syncVisibility();
    syncOriginType();
    if ((document.getElementById('lot-ship-city')?.value || '').trim()) scheduleCity();
})();
</script>
