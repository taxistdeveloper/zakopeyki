<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Boost\BoostPolicy;

$product = $product ?? [];
$ui = $ui ?? [];
$code = (string) ($ui['code'] ?? 'hidden');
$productId = (int) ($product['id'] ?? 0);
if ($code === '' || $code === 'hidden' || $productId <= 0) {
    return;
}

$amount = Wallet::formatMoney(BoostPolicy::PRICE_KZT);
$title = (string) ($product['title'] ?? '');
$messageKey = BoostPolicy::uiMessageKey($code);
$message = $messageKey ? t($messageKey) : '';
$notice = (string) ($ui['notice'] ?? '');
$canPurchase = !empty($ui['can_purchase']);
$continue = !empty($ui['continue_payment']);
$descId = 'boost-desc-' . $productId;
$tone = match ($code) {
    'applied', 'applied_off' => 'text-emerald-700 dark:text-emerald-300',
    'ready' => 'text-gray-500 dark:text-gray-400',
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible' => 'text-red-700 dark:text-red-300',
    default => 'text-amber-800 dark:text-amber-200',
};
$buttonKey = 'boost.ui_buy';
$confirmKey = 'boost.ui_confirm';
if ($code === 'applied') {
    $buttonKey = 'boost.ui_again';
    $confirmKey = 'boost.ui_confirm_again';
} elseif ($code === 'failed') {
    $buttonKey = 'boost.ui_retry';
    $confirmKey = 'boost.ui_confirm_retry';
}
$confirm = $continue
    ? t('boost.ui_confirm_continue', ['amount' => $amount])
    : t($confirmKey, ['title' => $title, 'amount' => $amount]);
$action = ProductHelper::url('/profile/lots/' . $productId . '/boost');
$idempotencyKey = $canPurchase ? ('ui' . bin2hex(random_bytes(8))) : '';
$placement = (string) ($placement ?? 'row');
$buttonLabel = $continue ? t('boost.ui_continue') : t($buttonKey, ['amount' => $amount]);
?>
<?php if ($placement === 'overlay'): ?>
<?php
$lit = in_array($code, ['applied', 'applied_off', 'pending_wait', 'pending_pay', 'processing', 'processing_off'], true);
$flameTone = match ($code) {
    'failed', 'failed_off', 'gateway', 'disabled_pending', 'ineligible' => 'text-red-500',
    'pending_wait', 'pending_pay', 'processing', 'processing_off' => 'text-amber-500',
    default => 'text-orange-500 hover:text-orange-600',
};
$flameLabel = $continue
    ? $buttonLabel
    : t('boost.ui_overlay', ['amount' => $amount]);
$flameTitle = ($canPurchase || $continue)
    ? $flameLabel
    : ($message !== '' ? $message : t('boost.ui_title'));
$flameBtn = 'w-8 h-8 rounded-xl bg-white/90 dark:bg-ink-900/80 border border-black/[0.06] dark:border-white/10 shadow-sm flex items-center justify-center transition hover:scale-105 ' . $flameTone;
$flameSvg = '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="' . ($lit ? 'currentColor' : 'none') . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>';
?>
<div class="relative"
     data-boost-card="<?= $productId ?>"
     data-boost-code="<?= htmlspecialchars($code) ?>"
     data-boost-offer>
    <?php if ($canPurchase || $continue): ?>
        <form method="post"
              action="<?= htmlspecialchars($action) ?>"
              autocomplete="off"
              data-boost-wait="<?= htmlspecialchars(t('boost.ui_wait')) ?>"
              onsubmit="return profileBoostSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
            <?= csrf_field() ?>
            <?php if ($idempotencyKey !== ''): ?>
                <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
            <?php endif; ?>
            <button type="submit"
                    class="<?= $flameBtn ?>"
                    title="<?= htmlspecialchars($flameTitle) ?>"
                    aria-label="<?= htmlspecialchars(t('boost.ui_title') . '. ' . $flameLabel . '. ' . t('boost.ui_desc')) ?>">
                <?= $flameSvg ?>
            </button>
        </form>
    <?php else: ?>
        <span class="<?= $flameBtn ?> cursor-default hover:scale-100"
              role="status"
              title="<?= htmlspecialchars($flameTitle) ?>"
              aria-label="<?= htmlspecialchars($flameTitle) ?>">
            <?= $flameSvg ?>
        </span>
    <?php endif; ?>
</div>
<?php return; endif; ?>

<div class="w-full basis-full border-t border-black/[0.06] dark:border-white/10 pt-2.5 mt-1"
     data-boost-card="<?= $productId ?>"
     data-boost-code="<?= htmlspecialchars($code) ?>"
     <?= BoostPolicy::uiWatches($code) ? 'data-boost-watch="1"' : '' ?>>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <div class="text-[11px] font-bold text-ink-900 dark:text-white">
                <?= htmlspecialchars(t('boost.ui_title')) ?>
                <span class="font-display"> · <?= htmlspecialchars($amount) ?></span>
            </div>
            <p id="<?= htmlspecialchars($descId) ?>" class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5 leading-relaxed">
                <?= htmlspecialchars(t('boost.ui_desc')) ?>
            </p>
            <?php if ($message !== ''): ?>
                <p class="text-[11px] mt-1 leading-relaxed <?= $tone ?>" role="status" aria-live="polite" data-boost-message>
                    <?= htmlspecialchars($message) ?>
                </p>
            <?php endif; ?>
            <?php if ($notice === 'gateway'): ?>
                <p class="text-[11px] mt-1 leading-relaxed text-red-700 dark:text-red-300" role="status">
                    <?= htmlspecialchars(t('boost.err_gateway')) ?>
                </p>
            <?php endif; ?>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-boost-refresh hidden>
                <?= htmlspecialchars(t('boost.ui_refresh')) ?>
            </p>
            <p class="text-[11px] mt-1 text-gray-500 dark:text-gray-400" data-boost-offline hidden>
                <?= htmlspecialchars(t('boost.ui_offline')) ?>
            </p>
        </div>
        <?php if ($canPurchase || $continue): ?>
            <form method="post"
                  action="<?= htmlspecialchars($action) ?>"
                  class="flex-shrink-0"
                  autocomplete="off"
                  data-boost-wait="<?= htmlspecialchars(t('boost.ui_wait')) ?>"
                  onsubmit="return profileBoostSubmit(this, <?= json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);">
                <?= csrf_field() ?>
                <?php if ($idempotencyKey !== ''): ?>
                    <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars($idempotencyKey) ?>">
                <?php endif; ?>
                <button type="submit"
                        class="min-h-11 w-full sm:w-auto px-3 py-2 rounded-xl text-[11px] font-bold transition focus:outline-none focus:ring-2 focus:ring-brand-500/40 <?= $canPurchase && $code === 'ready'
                            ? 'bg-brand-600 text-white hover:bg-brand-700'
                            : 'border border-brand-500/50 text-brand-700 dark:text-brand-300 hover:bg-brand-50 dark:hover:bg-white/5' ?>"
                        aria-describedby="<?= htmlspecialchars($descId) ?>">
                    <?= htmlspecialchars($continue ? t('boost.ui_continue') : t($buttonKey, ['amount' => $amount])) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
