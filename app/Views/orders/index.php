<?php
use App\Helpers\ProductHelper;
use App\Services\EscrowService;

$uid = \App\Core\Auth::id();
?>
<section class="max-w-3xl mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('escrow.safe_eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('escrow.deals_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('escrow.deals_subtitle')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($orders)): ?>
        <div class="text-center py-16 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('escrow.deals_empty')) ?>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($orders as $o):
                $role = (int) $o['buyer_id'] === (int) $uid ? t('escrow.role_buyer') : t('escrow.role_seller');
                $amt = number_format((int) $o['amount'], 0, '', ' ') . ' ₸';
            ?>
                <a href="<?= ProductHelper::url('/orders/' . (int) $o['id']) ?>" class="block bg-white/90 dark:bg-white/[0.04] rounded-2xl border border-black/[0.06] dark:border-white/10 p-4 sm:p-5 hover:border-brand-400/50 hover:shadow-soft transition">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">#<?= (int) $o['id'] ?> · <?= htmlspecialchars($role) ?></p>
                            <h2 class="font-semibold text-ink-900 dark:text-white mt-0.5 line-clamp-1"><?= htmlspecialchars($o['product_title']) ?></h2>
                            <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($o['buyer_name']) ?> ↔ <?= htmlspecialchars($o['seller_name']) ?></p>
                        </div>
                        <div class="text-right">
                            <p class="font-display font-bold text-brand-600"><?= htmlspecialchars($amt) ?></p>
                            <p class="text-[11px] font-semibold text-ink-700 dark:text-gray-300 mt-1"><?= htmlspecialchars(EscrowService::statusLabel($o['status'] ?? '')) ?></p>
                        </div>
                    </div>
                    <?php if (($o['escrow_hold'] ?? '') === 'holding'): ?>
                        <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-300 font-medium"><?= htmlspecialchars(t('escrow.holding_short')) ?></p>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
