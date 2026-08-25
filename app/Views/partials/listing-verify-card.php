<?php

use App\Core\Auth;
use App\Helpers\IconHelper;
use App\Helpers\ProductHelper;
use App\Services\AMLService;

$verifyUser = $verifyUser ?? Auth::user() ?? [];
$verifyStatus = AMLService::userListingStatus($verifyUser);
$verifyType = (string) ($verifyType ?? ($_SESSION['listing_verify_type'] ?? ''));
$verifyError = $verifyError ?? ($_SESSION['verify_listing_error'] ?? null);
$isBiz = AMLService::isBusinessUser($verifyUser);
$savedIin = preg_replace('/\D/', '', (string) ($verifyUser['iin'] ?? ''));
$savedBin = preg_replace('/\D/', '', (string) ($verifyUser['bin'] ?? ''));
$hasSavedIin = strlen((string) $savedIin) === 12;
$hasSavedBin = strlen((string) $savedBin) === 12;
$hasSavedId = $isBiz ? $hasSavedBin : $hasSavedIin;
$savedMask = $isBiz
    ? substr((string) $savedBin, 0, 6) . '******'
    : substr((string) $savedIin, 0, 6) . '******';
$input = 'ui-input w-full h-12 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm tracking-[0.18em]';
?>
<div class="space-y-5">
    <div class="flex items-start gap-3">
        <span class="w-11 h-11 rounded-2xl bg-brand-500 text-white inline-flex items-center justify-center shrink-0 shadow-soft">
            <?= IconHelper::svg('shield', 'w-5 h-5') ?>
        </span>
        <div class="min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('verify.eyebrow')) ?></p>
            <h2 id="listing-verify-heading" class="font-display text-xl font-bold text-ink-900 dark:text-white mt-0.5"><?= htmlspecialchars(t('verify.title')) ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($isBiz ? t('verify.lead_business') : t('verify.lead')) ?></p>
        </div>
    </div>

    <ol class="space-y-2">
        <?php
        $steps = [
            ['n' => '1', 't' => $isBiz ? t('verify.step1_bin') : t('verify.step1')],
            ['n' => '2', 't' => t('verify.step2')],
            ['n' => '3', 't' => t('verify.step3')],
        ];
        foreach ($steps as $step):
        ?>
            <li class="flex items-start gap-2.5 text-sm text-ink-800 dark:text-gray-200">
                <span class="w-6 h-6 rounded-lg bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300 text-[11px] font-bold inline-flex items-center justify-center shrink-0 mt-0.5"><?= htmlspecialchars($step['n']) ?></span>
                <span><?= htmlspecialchars($step['t']) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($verifyError): ?>
        <div class="text-sm font-semibold text-red-800 dark:text-red-200 bg-red-50 dark:bg-red-950/30 border border-red-100 dark:border-red-900/40 rounded-xl px-3 py-2"><?= htmlspecialchars((string) $verifyError) ?></div>
    <?php endif; ?>

    <?php if ($verifyStatus === 'blocked'): ?>
        <div class="text-sm font-semibold text-red-800 dark:text-red-200 bg-red-50 dark:bg-red-950/30 border border-red-100 dark:border-red-900/40 rounded-xl px-3 py-2">
            <?= htmlspecialchars(t('flash.aml_blocked')) ?>
        </div>
    <?php else: ?>
        <form method="post" action="<?= ProductHelper::url('/profile/verify-listing') ?>" id="listing-verify-form" class="space-y-4">
            <?= csrf_field() ?>
            <?php if ($verifyType !== '' && isset(ProductHelper::marketplaceTypes()[$verifyType])): ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars($verifyType) ?>">
            <?php endif; ?>
            <?php if ($verifyStatus === 'ok'): ?>
                <div class="text-sm font-semibold text-emerald-800 dark:text-emerald-200 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40 rounded-xl px-3 py-2">
                    <?= htmlspecialchars(t('verify.already_ok')) ?>
                </div>
            <?php elseif ($hasSavedId): ?>
                <div>
                    <label class="block text-xs font-bold mb-1"><?= htmlspecialchars($isBiz ? t('profile.bin') : t('profile.iin')) ?></label>
                    <input type="text" value="<?= htmlspecialchars($savedMask) ?>" readonly class="<?= $input ?> opacity-80">
                    <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars($isBiz ? t('profile.bin_saved_hint') : t('profile.iin_saved_hint')) ?></p>
                </div>
            <?php else: ?>
                <div>
                    <label class="block text-xs font-bold mb-1" for="listing-verify-iin"><?= htmlspecialchars($isBiz ? t('profile.bin') : t('profile.iin')) ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="<?= $isBiz ? 'bin' : 'iin' ?>" id="listing-verify-iin" data-kind="<?= $isBiz ? 'bin' : 'iin' ?>" inputmode="numeric" maxlength="12" autocomplete="off" required pattern="\d{12}" class="<?= $input ?>" placeholder="000000000000">
                    <p id="listing-verify-iin-error" class="hidden text-[11px] text-red-600 mt-1"><?= htmlspecialchars($isBiz ? t('flash.aml_bin_invalid') : t('flash.aml_iin_invalid')) ?></p>
                    <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars($isBiz ? t('profile.bin_hint') : t('profile.iin_hint')) ?></p>
                </div>
            <?php endif; ?>
            <button type="submit" class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-soft">
                <?= htmlspecialchars($verifyStatus === 'ok' ? t('verify.continue') : t('verify.submit')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
