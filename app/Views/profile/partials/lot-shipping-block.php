<?php
use App\Models\ProductListingShipping;
use App\Services\Listing\ListingShippingService;

$ls = $listingShipping ?? [];
$packagings = $listingPackagings ?? [];
$userShip = \App\Models\User::defaultShipFrom($user ?? null);
$fulfillment = $ls['fulfillment_mode'] ?? ProductListingShipping::FULFILLMENT_DELIVERY;
$paramMode = $ls['param_mode'] ?? ProductListingShipping::MODE_EXACT;
$useDefault = ($ls['use_default_ship_from'] ?? 1) && ($userShip['ship_city'] ?? '') !== '';
$shipCity = $ls['ship_city'] ?? ($useDefault ? ($userShip['ship_city'] ?? '') : ($editing['location'] ?? ''));
?>
<div id="lot-shipping-wrap" class="hidden space-y-4 rounded-2xl border border-black/[0.08] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] p-5">
    <div>
        <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('listing_shipping.title')) ?></h3>
        <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('listing_shipping.subtitle')) ?></p>
    </div>

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
    </div>

    <div id="lot-ship-from-block" class="space-y-3">
        <p class="text-xs font-bold"><?= htmlspecialchars(t('listing_shipping.ship_from_title')) ?></p>
        <label class="flex items-center gap-2 text-xs">
            <input type="checkbox" name="use_default_ship_from" value="1" id="lot-use-default-ship" <?= $useDefault ? 'checked' : '' ?> class="rounded">
            <?= htmlspecialchars(t('listing_shipping.use_default_address')) ?>
        </label>
        <label class="flex items-center gap-2 text-xs">
            <input type="checkbox" name="save_default_ship_from" value="1" class="rounded">
            <?= htmlspecialchars(t('listing_shipping.save_as_default')) ?>
        </label>
        <div id="lot-ship-from-fields" class="grid grid-cols-1 sm:grid-cols-2 gap-3 <?= $useDefault ? 'hidden' : '' ?>">
            <input type="text" name="ship_contact_name" value="<?= htmlspecialchars($ls['ship_contact_name'] ?? ($user['name'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.contact_name')) ?>" class="<?= $input ?>">
            <input type="tel" name="ship_phone" value="<?= htmlspecialchars($ls['ship_phone'] ?? ($user['phone'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.phone')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_city" value="<?= htmlspecialchars($shipCity) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.city')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_region" value="<?= htmlspecialchars($ls['ship_region'] ?? ($userShip['ship_region'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.region')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_street" value="<?= htmlspecialchars($ls['ship_street'] ?? ($userShip['ship_street'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.street')) ?>" class="<?= $input ?> sm:col-span-2">
            <input type="text" name="ship_building" value="<?= htmlspecialchars($ls['ship_building'] ?? ($userShip['ship_building'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.building')) ?>" class="<?= $input ?>">
            <input type="text" name="ship_apartment" value="<?= htmlspecialchars($ls['ship_apartment'] ?? ($userShip['ship_apartment'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('listing_shipping.apartment')) ?>" class="<?= $input ?>">
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

    function syncVisibility() {
        if (!wrap || !typeSelect) return;
        const t = typeSelect.value;
        const show = physical.includes(t);
        wrap.classList.toggle('hidden', !show);
        const delivery = document.querySelector('input.lot-fulfillment-radio[value="delivery"]');
        const shipBlock = document.getElementById('lot-shipment-block');
        const pickupOnly = document.querySelector('input.lot-fulfillment-radio[value="pickup"]:checked');
        if (shipBlock) shipBlock.classList.toggle('hidden', !!pickupOnly);
    }

    document.querySelectorAll('.lot-fulfillment-radio').forEach(el => el.addEventListener('change', syncVisibility));

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
    });

    document.getElementById('lot-is-irregular')?.addEventListener('change', function () {
        document.getElementById('lot-irregular-reason')?.classList.toggle('hidden', !this.checked);
    });

    document.getElementById('lot-auto-pack-weight')?.addEventListener('change', function () {
        const inp = document.getElementById('lot-packaging-weight');
        if (inp) inp.disabled = this.checked;
    });

    typeSelect?.addEventListener('change', syncVisibility);
    syncVisibility();
})();
</script>
