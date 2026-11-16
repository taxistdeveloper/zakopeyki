<?php
function t($k, $r = []) {
  $map = [
    'product.delivery_kz' => 'По Казахстану',
    'listing_shipping.buyer_price_after_address' => 'Стоимость рассчитывается после указания адреса доставки.',
    'listing_shipping.fulfillment_pickup' => 'Самовывоз',
    'product.delivery_city_hint' => 'Самовывоз и курьер в :city',
    'product.delivery' => 'Доставка',
    'product.delivery_more' => 'Подробнее о доставке',
    'product.show_less' => 'Свернуть',
    'product.delivery_escrow' => 'Оплата через эскроу — деньги у площадки до получения',
    'listing_shipping.buyer_delivery_title' => 'Доставка по Казахстану',
    'listing_shipping.buyer_from_city' => 'Отправка из :city',
    'listing_shipping.buyer_packaging' => 'Упаковка: :name',
    'listing_shipping.buyer_weight' => ':weight кг',
    'product.delivery_city' => 'По городу',
  ];
  $s = $map[$k] ?? $k;
  foreach ($r as $kk=>$vv) { $s = str_replace(':'.$kk, $vv, $s); }
  return $s;
}
class IconHelper { public static function svg($n,$c=''){ return '<svg class="'.$c.'"></svg>'; } }
$listingShipping = ['has_pickup'=>true,'has_delivery'=>false,'from_city'=>'Караганда'];
$item = ['location'=>'Караганда'];
?>
        <?php
        $deliveryTitle = t('product.delivery_kz');
        $deliveryHint = t('listing_shipping.buyer_price_after_address');
        $deliveryDetails = [];
        if (!empty($listingShipping)) {
            if (!empty($listingShipping['has_delivery'])) {
                $deliveryTitle = t('listing_shipping.buyer_delivery_title');
                $deliveryHint = $listingShipping['price_note'] ?? t('listing_shipping.buyer_price_after_address');
                if (!empty($listingShipping['from_city'])) {
                    $deliveryDetails[] = t('listing_shipping.buyer_from_city', ['city' => $listingShipping['from_city']]);
                }
                if (!empty($listingShipping['packaging'])) {
                    $deliveryDetails[] = t('listing_shipping.buyer_packaging', ['name' => $listingShipping['packaging']]);
                }
                if (!empty($listingShipping['package_dims']) || !empty($listingShipping['gross_weight'])) {
                    $dimsLine = '';
                    if (!empty($listingShipping['package_dims'])) {
                        $dimsLine = (string) $listingShipping['package_dims'];
                    }
                    if (!empty($listingShipping['gross_weight'])) {
                        $weight = t('listing_shipping.buyer_weight', [
                            'weight' => rtrim(rtrim(number_format((float) $listingShipping['gross_weight'], 2, '.', ''), '0'), '.'),
                        ]);
                        $dimsLine = $dimsLine !== '' ? ($dimsLine . ' · ' . $weight) : $weight;
                    }
                    if ($dimsLine !== '') {
                        $deliveryDetails[] = $dimsLine;
                    }
                }
            } elseif (!empty($listingShipping['has_pickup'])) {
                $pickupCity = $item['location'] ?? ($listingShipping['from_city'] ?? '');
                $deliveryTitle = t('listing_shipping.fulfillment_pickup');
                $deliveryHint = $pickupCity !== ''
                    ? t('product.delivery_city_hint', ['city' => $pickupCity])
                    : t('listing_shipping.fulfillment_pickup');
            }
            if (!empty($listingShipping['has_pickup']) && !empty($listingShipping['has_delivery']) && !empty($item['location'])) {
                $deliveryDetails[] = t('product.delivery_city_hint', ['city' => $item['location']]);
            }
        } elseif (!empty($item['location'])) {
            $deliveryTitle = t('product.delivery_city');
            $deliveryHint = t('product.delivery_city_hint', ['city' => $item['location']]);
            $deliveryDetails[] = t('product.delivery_kz') . ' — ' . t('listing_shipping.buyer_price_after_address');
        }
        $deliveryDetails[] = t('product.delivery_escrow');
        ?>
        <div class="rounded-[18px] border border-black/[0.06] dark:border-white/10 bg-white dark:bg-white/[0.04] px-3.5 sm:px-4 py-3">
            <button type="button"
                    data-toggle="pdp-delivery-more"
                    data-more="<?= htmlspecialchars(t('product.delivery_more')) ?>"
                    data-less="<?= htmlspecialchars(t('product.show_less')) ?>"
                    class="w-full flex items-center gap-3 text-left group">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-ink-900/[0.04] dark:bg-white/[0.06] text-ink-700 dark:text-gray-200 shrink-0" aria-hidden="true">
                    <?= IconHelper::svg('package', 'w-3.5 h-3.5') ?>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-baseline gap-1.5 flex-wrap text-[13px] leading-snug">
                        <span class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t('product.delivery')) ?></span>
                        <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">·</span>
                        <span class="text-ink-700 dark:text-gray-200"><?= htmlspecialchars($deliveryTitle) ?></span>
                    </span>
                    <span class="block mt-0.5 text-[12px] text-gray-500 dark:text-gray-400 truncate"><?= htmlspecialchars($deliveryHint) ?></span>
                </span>
                <span class="inline-flex items-center gap-1 shrink-0 text-[12px] font-semibold text-accent-600 group-hover:text-accent-500 transition">
                    <span data-toggle-label class="hidden sm:inline"><?= htmlspecialchars(t('product.delivery_more')) ?></span>
                    <svg class="w-3.5 h-3.5 transition-transform duration-200" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </span>
            </button>
            <div id="pdp-delivery-more" class="hidden mt-3 pt-3 border-t border-dashed border-black/[0.06] dark:border-white/10 space-y-2 text-[12px] text-gray-500 dark:text-gray-400 leading-relaxed">
                <?php foreach ($deliveryDetails as $detail): ?>
                    <p><?= htmlspecialchars($detail) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
