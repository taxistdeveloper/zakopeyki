<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Highlight\HighlightPolicy;

$product = $product ?? [];
$ui = $ui ?? [];
$code = (string) ($ui['code'] ?? 'hidden');
$productId = (int) ($product['id'] ?? 0);
if ($code === '' || $code === 'hidden' || $productId <= 0) {
    return;
}

$amount = Wallet::formatMoney(HighlightPolicy::PRICE_KZT);
$title = (string) ($product['title'] ?? '');
$until = HighlightPolicy::formatUntil(isset($ui['ends_at']) ? (string) $ui['ends_at'] : null);
$messageKey = HighlightPolicy::uiMessageKey($code);
$message = $messageKey ? t($messageKey, ['until' => $until, 'amount' => $amount]) : '';
$notice = (string) ($ui['notice'] ?? '');
$canPurchase = !empty($ui['can_purchase']);
$continue = !empty($ui['continue_payment']);
$descId = 'highlight-desc-' . $productId;
$tone = match ($code) {
    'active', 'active_off' => 'text-amber-800 dark:text-amber-200',
    'ready', 'expired' => 'text-gray-500 dark:text-gray-400',
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible', 'expired_off' => 'text-red-700 dark:text-red-300',
    default => 'text-amber-800 dark:text-amber-200',
};
$buttonKey = 'highlight.ui_buy';
$confirmKey = 'highlight.ui_confirm';
if ($code === 'expired') {
    $buttonKey = 'highlight.ui_again';
    $confirmKey = 'highlight.ui_confirm_again';
} elseif ($code === 'failed') {
    $buttonKey = 'highlight.ui_retry';
    $confirmKey = 'highlight.ui_confirm_retry';
}
$confirm = $continue
    ? t('highlight.ui_confirm_continue', ['amount' => $amount])
    : t($confirmKey, ['title' => $title, 'amount' => $amount]);
$action = ProductHelper::url('/profile/lots/' . $productId . '/highlight');
$idempotencyKey = $canPurchase ? ('ui' . bin2hex(random_bytes(8))) : '';
$placement = (string) ($placement ?? 'row');
$buttonLabel = $continue ? t('highlight.ui_continue') : t($buttonKey, ['amount' => $amount]);
?>
<?php if ($placement === 'overlay'): ?>
<?php
$lit = in_array($code, ['active', 'active_off', 'pending_wait', 'pending_pay', 'processing', 'processing_off'], true);
$markTone = match ($code) {
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible', 'expired_off' => 'text-red-500',
    'pending_wait', 'pending_pay', 'processing', 'processing_off' => 'text-amber-500',
    'active', 'active_off' => 'text-amber-500',
    default => 'text-amber-600 hover:text-amber-700',
};
$markLabel = $continue
    ? $buttonLabel
    : t('highlight.ui_overlay', ['amount' => $amount]);
$markTitle = ($canPurchase || $continue)
    ? $markLabel
    : ($message !== '' ? $message : t('highlight.ui_title'));
$markBtn = 'w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 ' . $markTone;
$markSvg = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="' . ($lit ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>';
?>
<div class="relative"
     data-highlight-card="<?= $productId ?>"
     data-highlight-code="<?= htmlspecialchars($code) ?>">
    <?php if ($canPurchase || $continue): ?>
        <form method="post"
              action="<?= htmlspecialchars($action) ?>"
              autocomplete="off"
              data-highlight-wait="<?= htmlspecialchars(t('highlight.ui_wait')) ?>"
              onsubmit="return profileHighlightSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
            <?= csrf_field() ?>
            <?php if ($idempotencyKey !== ''): ?>
                <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
            <?php endif; ?>
            <button type="submit"
                    class="<?= $markBtn ?>"
                    title="<?= htmlspecialchars($markTitle) ?>"
                    aria-label="<?= htmlspecialchars(t('highlight.ui_title') . '. ' . $markLabel . '. ' . t('highlight.ui_desc')) ?>">
                <?= $markSvg ?>
            </button>
        </form>
    <?php else: ?>
        <span class="<?= $markBtn ?> cursor-default hover:scale-100"
              role="status"
              title="<?= htmlspecialchars($markTitle) ?>"
              aria-label="<?= htmlspecialchars($markTitle) ?>">
            <?= $markSvg ?>
        </span>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<div class="w-full basis-full border-t border-black/[0.06] dark:border-white/10 pt-2.5 mt-1"
     data-highlight-card="<?= $productId ?>"
     data-highlight-code="<?= htmlspecialchars($code) ?>"
     <?= HighlightPolicy::uiWatches($code) ? 'data-highlight-watch="1"' : '' ?>>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <div class="text-[11px] font-bold text-ink-900 dark:text-white">
                <?= htmlspecialchars(t('highlight.ui_title')) ?>
                <span class="font-display"> · <?= htmlspecialchars($amount) ?></span>
                <span class="font-normal text-gray-500"> · <?= htmlspecialchars(t('highlight.ui_term')) ?></span>
            </div>
            <p id="<?= htmlspecialchars($descId) ?>" class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
                <?= htmlspecialchars(t('highlight.ui_desc')) ?>
            </p>
            <?php if ($message !== ''): ?>
                <p class="text-[11px] mt-1 leading-relaxed <?= $tone ?>" role="status" aria-live="polite" data-highlight-message>
                    <?= htmlspecialchars($message) ?>
                </p>
            <?php endif; ?>
            <?php if ($notice === 'gateway'): ?>
                <p class="text-[11px] mt-1 leading-relaxed text-red-700 dark:text-red-300" role="status">
                    <?= htmlspecialchars(t('highlight.err_gateway')) ?>
                </p>
            <?php endif; ?>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-highlight-refresh hidden>
                <?= htmlspecialchars(t('highlight.ui_refresh')) ?>
            </p>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-highlight-offline hidden>
                <?= htmlspecialchars(t('highlight.ui_offline')) ?>
            </p>
        </div>
        <?php if ($canPurchase || $continue): ?>
            <form method="post"
                  action="<?= htmlspecialchars($action) ?>"
                  class="flex-shrink-0"
                  autocomplete="off"
                  data-highlight-wait="<?= htmlspecialchars(t('highlight.ui_wait')) ?>"
                  onsubmit="return profileHighlightSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
                <?= csrf_field() ?>
                <?php if ($idempotencyKey !== ''): ?>
                    <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
                <?php endif; ?>
                <button type="submit"
                        class="min-h-11 w-full sm:w-auto px-3 py-2 rounded-xl text-[11px] font-bold transition focus:outline-none focus:ring-2 focus:ring-amber-500/40 <?= $canPurchase && $code === 'ready'
                            ? 'bg-amber-500 text-ink-900 hover:bg-amber-400'
                            : 'border border-amber-500/60 text-amber-800 dark:text-amber-200 hover:bg-amber-50 dark:hover:bg-white/5' ?>"
                        aria-describedby="<?= htmlspecialchars($descId) ?>">
                    <?= htmlspecialchars($continue ? t('highlight.ui_continue') : t($buttonKey, ['amount' => $amount])) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
