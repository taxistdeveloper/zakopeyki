<?php

use App\Helpers\ProductHelper;

$product = $product ?? [];
$productId = (int) ($product['id'] ?? 0);
if ($productId <= 0) {
    return;
}

$href = ProductHelper::url('/profile/lots/' . $productId . '/promote');
$label = t('profile.promote_overlay');
$desc = t('profile.promote_entry_aria');
$btn = 'w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 text-orange-500 hover:text-orange-600';
?>
<a href="<?= htmlspecialchars($href) ?>"
   class="<?= $btn ?>"
   title="<?= htmlspecialchars($label) ?>"
   aria-label="<?= htmlspecialchars($desc) ?>"
   data-lot-promote-entry="<?= $productId ?>">
    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
    </svg>
</a>
