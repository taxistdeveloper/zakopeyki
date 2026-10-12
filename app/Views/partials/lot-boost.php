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
?>
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
