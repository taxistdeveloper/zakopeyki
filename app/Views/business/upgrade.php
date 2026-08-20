<?php

use App\Helpers\ProductHelper;

$user = $user ?? [];
$limit = $limit ?? [];
$pending = $pending ?? null;
$latest = $latest ?? null;
$isBusiness = !empty($limit['is_business']);
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
?>
<section class="max-w-3xl mx-auto space-y-5 pb-8">
    <div>
        <a href="<?= ProductHelper::url('/profile?tab=business') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('profile.tab_business')) ?></a>
        <h1 class="font-display text-2xl font-bold text-ink-900 dark:text-white mt-2"><?= htmlspecialchars(t('business.upgrade_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('business.upgrade_lead')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($isBusiness): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-5">
            <p class="text-sm font-bold text-emerald-900"><?= htmlspecialchars(t('business.already_business')) ?></p>
            <a href="<?= ProductHelper::url('/business/package') ?>" class="inline-flex mt-3 text-sm font-bold text-brand-600 hover:underline">
                <?= htmlspecialchars(t('business.go_package')) ?> →
            </a>
        </div>
    <?php elseif ($pending): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-5">
            <p class="text-sm font-bold text-amber-900"><?= htmlspecialchars(t('business.pending_title')) ?></p>
            <p class="text-sm text-amber-800/80 mt-1"><?= htmlspecialchars(t('business.pending_body')) ?></p>
        </div>
    <?php else: ?>
        <?php if (($latest['status'] ?? '') === 'rejected'): ?>
            <div class="rounded-2xl border border-red-200 bg-red-50/70 p-4 text-sm text-red-800">
                <?= htmlspecialchars(t('business.rejected_body', ['reason' => (string) ($user['business_rejected_reason'] ?? $latest['admin_note'] ?? '')])) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= ProductHelper::url('/business/upgrade') ?>" enctype="multipart/form-data" class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.03] p-5 sm:p-6 space-y-4 shadow-soft">
            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('business.entity_type')) ?></label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="flex items-center gap-2 rounded-xl border border-black/10 dark:border-white/10 px-3 py-3 text-sm cursor-pointer">
                        <input type="radio" name="entity_type" value="ip" required <?= (($user['business_entity_type'] ?? '') === 'too') ? '' : 'checked' ?>>
                        <?= htmlspecialchars(t('business.entity_ip')) ?>
                    </label>
                    <label class="flex items-center gap-2 rounded-xl border border-black/10 dark:border-white/10 px-3 py-3 text-sm cursor-pointer">
                        <input type="radio" name="entity_type" value="too" <?= (($user['business_entity_type'] ?? '') === 'too') ? 'checked' : '' ?>>
                        <?= htmlspecialchars(t('business.entity_too')) ?>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('business.business_name')) ?></label>
                <input type="text" name="business_name" required value="<?= htmlspecialchars((string) ($user['business_name'] ?? '')) ?>" class="<?= $input ?>">
            </div>

            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('business.bin')) ?></label>
                <input type="text" name="bin" required maxlength="12" pattern="\d{12}" value="<?= htmlspecialchars((string) ($user['bin'] ?? '')) ?>" class="<?= $input ?>">
                <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('business.bin_hint')) ?></p>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('business.phone')) ?></label>
                    <input type="text" name="phone" value="<?= htmlspecialchars((string) ($user['phone'] ?? '')) ?>" class="<?= $input ?>">
                </div>
                <div>
                    <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('business.address')) ?></label>
                    <input type="text" name="address" class="<?= $input ?>">
                </div>
            </div>

            <div>
                <label class="block text-[13px] font-semibold mb-1.5"><?= htmlspecialchars(t('business.docs')) ?></label>
                <input type="file" name="docs[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" required class="block w-full text-sm">
                <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('business.docs_hint')) ?></p>
            </div>

            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('business.upgrade_legal')) ?></p>

            <button type="submit" class="w-full sm:w-auto bg-ink-900 hover:bg-ink-800 text-white font-semibold text-sm px-6 py-3 rounded-2xl transition">
                <?= htmlspecialchars(t('business.submit_upgrade')) ?>
            </button>
        </form>
    <?php endif; ?>
</section>
