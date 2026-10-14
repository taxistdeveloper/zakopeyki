<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\TopFixed\TopFixedPolicy;

$product = $product ?? [];
$ui = $ui ?? [];
$code = (string) ($ui['code'] ?? 'hidden');
$productId = (int) ($product['id'] ?? 0);
if ($code === '' || $code === 'hidden' || $productId <= 0) {
    return;
}

$amount = Wallet::formatMoney(TopFixedPolicy::PRICE_KZT);
$title = (string) ($product['title'] ?? '');
$until = TopFixedPolicy::formatUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null);
$category = (string) ($ui['category'] ?? ($product['category'] ?? ''));
$messageKey = TopFixedPolicy::uiMessageKey($code);
$message = $messageKey ? t($messageKey, ['until' => $until, 'amount' => $amount, 'category' => $category]) : '';
$notice = (string) ($ui['notice'] ?? '');
$canPurchase = !empty($ui['can_purchase']);
$continue = !empty($ui['continue_payment']);
$descId = 'top-fixed-desc-' . $productId;
$tone = match ($code) {
    'active', 'active_off', 'moved' => 'text-sky-800 dark:text-sky-200',
    'ready', 'expired' => 'text-gray-500 dark:text-gray-400',
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible', 'expired_off' => 'text-red-700 dark:text-red-300',
    default => 'text-sky-800 dark:text-sky-200',
};
$buttonKey = 'top_fixed.ui_buy';
$confirmKey = 'top_fixed.ui_confirm';
if ($code === 'expired') {
    $buttonKey = 'top_fixed.ui_again';
    $confirmKey = 'top_fixed.ui_confirm_again';
} elseif ($code === 'failed') {
    $buttonKey = 'top_fixed.ui_retry';
    $confirmKey = 'top_fixed.ui_confirm_retry';
}
$confirm = $continue
    ? t('top_fixed.ui_confirm_continue', ['amount' => $amount])
    : t($confirmKey, ['title' => $title, 'amount' => $amount, 'category' => $category]);
$action = ProductHelper::url('/profile/lots/' . $productId . '/top-fixed');
$idempotencyKey = $canPurchase ? ('ui' . bin2hex(random_bytes(8))) : '';
$placement = (string) ($placement ?? 'row');
$buttonLabel = $continue ? t('top_fixed.ui_continue') : t($buttonKey, ['amount' => $amount]);
$pinSvg = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 17v5M8 3h8l1 6H7l1-6zM7 9h10l-1.2 5.2a2 2 0 01-1.9 1.5H10.1a2 2 0 01-1.9-1.5L7 9z"/></svg>';
?>
<?php if ($placement === 'overlay'): ?>
<?php
$lit = in_array($code, ['active', 'active_off', 'moved', 'pending_wait', 'pending_pay', 'processing', 'processing_off'], true);
$markTone = match ($code) {
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible', 'expired_off' => 'text-red-500',
    'pending_wait', 'pending_pay', 'processing', 'processing_off', 'moved' => 'text-sky-500',
    'active', 'active_off' => 'text-sky-600',
    default => 'text-sky-600 hover:text-sky-700',
};
$markLabel = $continue
    ? $buttonLabel
    : t('top_fixed.ui_overlay', ['amount' => $amount]);
$markTitle = ($canPurchase || $continue)
    ? $markLabel
    : ($message !== '' ? $message : t('top_fixed.ui_title'));
$markBtn = 'w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 ' . $markTone;
?>
<div class="relative"
     data-top-fixed-card="<?= $productId ?>"
     data-top-fixed-code="<?= htmlspecialchars($code) ?>">
    <?php if ($canPurchase || $continue): ?>
        <form method="post"
              action="<?= htmlspecialchars($action) ?>"
              autocomplete="off"
              data-top-fixed-wait="<?= htmlspecialchars(t('top_fixed.ui_wait')) ?>"
              onsubmit="return profileTopFixedSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
            <?= csrf_field() ?>
            <?php if ($idempotencyKey !== ''): ?>
                <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
            <?php endif; ?>
            <button type="submit"
                    class="<?= $markBtn ?>"
                    title="<?= htmlspecialchars($markTitle) ?>"
                    aria-label="<?= htmlspecialchars(t('top_fixed.ui_title') . '. ' . $markLabel . '. ' . t('top_fixed.ui_desc')) ?>">
                <?= $pinSvg ?>
            </button>
        </form>
    <?php else: ?>
        <span class="<?= $markBtn ?> cursor-default hover:scale-100"
              role="status"
              title="<?= htmlspecialchars($markTitle) ?>"
              aria-label="<?= htmlspecialchars($markTitle) ?>">
            <?= $pinSvg ?>
        </span>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<div class="w-full basis-full border-t border-black/[0.06] dark:border-white/10 pt-2.5 mt-1"
     data-top-fixed-card="<?= $productId ?>"
     data-top-fixed-code="<?= htmlspecialchars($code) ?>"
     <?= TopFixedPolicy::uiWatches($code) ? 'data-top-fixed-watch="1"' : '' ?>>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <div class="text-[11px] font-bold text-ink-900 dark:text-white">
                <?= htmlspecialchars(t('top_fixed.ui_title')) ?>
                <span class="font-display"> · <?= htmlspecialchars($amount) ?></span>
                <span class="font-normal text-gray-500"> · <?= htmlspecialchars(t('top_fixed.ui_term')) ?></span>
            </div>
            <p id="<?= htmlspecialchars($descId) ?>" class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
                <?= htmlspecialchars(t('top_fixed.ui_desc')) ?>
            </p>
            <?php if ($message !== ''): ?>
                <p class="text-[11px] mt-1 leading-relaxed <?= $tone ?>" role="status" aria-live="polite" data-top-fixed-message>
                    <?= htmlspecialchars($message) ?>
                </p>
            <?php endif; ?>
            <?php if ($notice === 'gateway'): ?>
                <p class="text-[11px] mt-1 leading-relaxed text-red-700 dark:text-red-300" role="status">
                    <?= htmlspecialchars(t('top_fixed.err_gateway')) ?>
                </p>
            <?php endif; ?>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-top-fixed-refresh hidden>
                <?= htmlspecialchars(t('top_fixed.ui_refresh')) ?>
            </p>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-top-fixed-offline hidden>
                <?= htmlspecialchars(t('top_fixed.ui_offline')) ?>
            </p>
        </div>
        <?php if ($canPurchase || $continue): ?>
            <form method="post"
                  action="<?= htmlspecialchars($action) ?>"
                  class="flex-shrink-0"
                  autocomplete="off"
                  data-top-fixed-wait="<?= htmlspecialchars(t('top_fixed.ui_wait')) ?>"
                  onsubmit="return profileTopFixedSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
                <?= csrf_field() ?>
                <?php if ($idempotencyKey !== ''): ?>
                    <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
                <?php endif; ?>
                <button type="submit"
                        class="min-h-11 w-full sm:w-auto px-3 py-2 rounded-xl text-[11px] font-bold transition focus:outline-none focus:ring-2 focus:ring-sky-500/40 <?= $canPurchase && $code === 'ready'
                            ? 'bg-sky-600 text-white hover:bg-sky-500'
                            : 'border border-sky-500/60 text-sky-800 dark:text-sky-200 hover:bg-sky-50 dark:hover:bg-white/5' ?>"
                        aria-describedby="<?= htmlspecialchars($descId) ?>">
                    <?= htmlspecialchars($continue ? t('top_fixed.ui_continue') : t($buttonKey, ['amount' => $amount])) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
