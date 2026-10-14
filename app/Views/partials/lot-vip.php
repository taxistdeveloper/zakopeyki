<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Vip\VipPolicy;

$product = $product ?? [];
$ui = $ui ?? [];
$code = (string) ($ui['code'] ?? 'hidden');
$productId = (int) ($product['id'] ?? 0);
if ($code === '' || $code === 'hidden' || $productId <= 0) {
    return;
}

$amount = Wallet::formatMoney(VipPolicy::PRICE_KZT);
$title = (string) ($product['title'] ?? '');
$until = VipPolicy::formatUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null);
$left = isset($ui['lifts_remaining']) && $ui['lifts_remaining'] !== null ? (string) $ui['lifts_remaining'] : '';
$messageKey = VipPolicy::uiMessageKey($code);
$message = $messageKey ? t($messageKey, ['until' => $until, 'amount' => $amount, 'left' => $left]) : '';
$notice = (string) ($ui['notice'] ?? '');
$canPurchase = !empty($ui['can_purchase']);
$continue = !empty($ui['continue_payment']);
$descId = 'vip-desc-' . $productId;
$tone = match ($code) {
    'active', 'active_off', 'moved' => 'text-amber-800 dark:text-amber-200',
    'ready', 'expired' => 'text-gray-500 dark:text-gray-400',
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible', 'expired_off' => 'text-red-700 dark:text-red-300',
    default => 'text-amber-800 dark:text-amber-200',
};
$buttonKey = 'vip.ui_buy';
$confirmKey = 'vip.ui_confirm';
if ($code === 'expired') {
    $buttonKey = 'vip.ui_again';
    $confirmKey = 'vip.ui_confirm_again';
} elseif ($code === 'failed') {
    $buttonKey = 'vip.ui_retry';
    $confirmKey = 'vip.ui_confirm_retry';
}
$confirm = $continue
    ? t('vip.ui_confirm_continue', ['amount' => $amount])
    : t($confirmKey, ['title' => $title, 'amount' => $amount]);
$action = ProductHelper::url('/profile/lots/' . $productId . '/vip');
$idempotencyKey = $canPurchase ? ('ui' . bin2hex(random_bytes(8))) : '';
$placement = (string) ($placement ?? 'row');
$buttonLabel = $continue ? t('vip.ui_continue') : t($buttonKey, ['amount' => $amount]);
$vipSvg = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l2.2 4.6L19 8.2l-3.5 3.4.8 4.9L12 14.8 7.7 16.5l.8-4.9L5 8.2l4.8-.6L12 3z"/></svg>';
?>
<?php if ($placement === 'overlay'): ?>
<?php
$lit = in_array($code, ['active', 'active_off', 'moved', 'pending_wait', 'pending_pay', 'processing', 'processing_off'], true);
$markTone = match ($code) {
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible', 'expired_off' => 'text-red-500',
    'pending_wait', 'pending_pay', 'processing', 'processing_off', 'moved' => 'text-amber-500',
    'active', 'active_off' => 'text-amber-600',
    default => 'text-amber-600 hover:text-amber-700',
};
unset($lit);
$markLabel = $continue
    ? $buttonLabel
    : t('vip.ui_overlay', ['amount' => $amount]);
$markTitle = ($canPurchase || $continue)
    ? $markLabel
    : ($message !== '' ? $message : t('vip.ui_title'));
$markBtn = 'w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 ' . $markTone;
?>
<div class="relative"
     data-vip-card="<?= $productId ?>"
     data-vip-code="<?= htmlspecialchars($code) ?>">
    <?php if ($canPurchase || $continue): ?>
        <form method="post"
              action="<?= htmlspecialchars($action) ?>"
              autocomplete="off"
              data-vip-wait="<?= htmlspecialchars(t('vip.ui_wait')) ?>"
              onsubmit="return profileVipSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
            <?= csrf_field() ?>
            <?php if ($idempotencyKey !== ''): ?>
                <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
            <?php endif; ?>
            <button type="submit"
                    class="<?= $markBtn ?>"
                    title="<?= htmlspecialchars($markTitle) ?>"
                    aria-label="<?= htmlspecialchars(t('vip.ui_title') . '. ' . $markLabel . '. ' . t('vip.ui_desc')) ?>">
                <?= $vipSvg ?>
            </button>
        </form>
    <?php else: ?>
        <span class="<?= $markBtn ?> cursor-default hover:scale-100"
              role="status"
              title="<?= htmlspecialchars($markTitle) ?>"
              aria-label="<?= htmlspecialchars($markTitle) ?>">
            <?= $vipSvg ?>
        </span>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<div class="w-full basis-full border-t border-black/[0.06] dark:border-white/10 pt-2.5 mt-1"
     data-vip-card="<?= $productId ?>"
     data-vip-code="<?= htmlspecialchars($code) ?>"
     <?= VipPolicy::uiWatches($code) ? 'data-vip-watch="1"' : '' ?>>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <div class="text-[11px] font-bold text-ink-900 dark:text-white">
                <?= htmlspecialchars(t('vip.ui_title')) ?>
                <span class="font-display"> · <?= htmlspecialchars($amount) ?></span>
                <span class="font-normal text-gray-500"> · <?= htmlspecialchars(t('vip.ui_term')) ?></span>
            </div>
            <p id="<?= htmlspecialchars($descId) ?>" class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
                <?= htmlspecialchars(t('vip.ui_desc')) ?>
            </p>
            <?php if ($message !== ''): ?>
                <p class="text-[11px] mt-1 leading-relaxed <?= $tone ?>" role="status" aria-live="polite" data-vip-message>
                    <?= htmlspecialchars($message) ?>
                </p>
            <?php endif; ?>
            <?php if ($notice === 'gateway'): ?>
                <p class="text-[11px] mt-1 leading-relaxed text-red-700 dark:text-red-300" role="status">
                    <?= htmlspecialchars(t('vip.err_gateway')) ?>
                </p>
            <?php endif; ?>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-vip-refresh hidden>
                <?= htmlspecialchars(t('vip.ui_refresh')) ?>
            </p>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-vip-offline hidden>
                <?= htmlspecialchars(t('vip.ui_offline')) ?>
            </p>
        </div>
        <?php if ($canPurchase || $continue): ?>
            <form method="post"
                  action="<?= htmlspecialchars($action) ?>"
                  class="flex-shrink-0"
                  autocomplete="off"
                  data-vip-wait="<?= htmlspecialchars(t('vip.ui_wait')) ?>"
                  onsubmit="return profileVipSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
                <?= csrf_field() ?>
                <?php if ($idempotencyKey !== ''): ?>
                    <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
                <?php endif; ?>
                <button type="submit"
                        class="min-h-11 w-full sm:w-auto px-3 py-2 rounded-xl text-[11px] font-bold transition focus:outline-none focus:ring-2 focus:ring-amber-500/40 <?= $canPurchase && $code === 'ready'
                            ? 'bg-amber-600 text-white hover:bg-amber-500'
                            : 'border border-amber-500/60 text-amber-800 dark:text-amber-200 hover:bg-amber-50 dark:hover:bg-white/5' ?>"
                        aria-describedby="<?= htmlspecialchars($descId) ?>">
                    <?= htmlspecialchars($buttonLabel) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
