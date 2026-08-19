<?php
use App\Core\View;
use App\Helpers\ProductHelper;

$verifyError = $error ?? null;
?>
<section class="max-w-md mx-auto fade-up pb-8">
    <a href="<?= ProductHelper::url('/') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-4">← <?= htmlspecialchars(t('verify.back')) ?></a>
    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[28px] border border-black/[0.06] dark:border-white/10 shadow-soft p-5 sm:p-6">
        <?php View::partial('partials/listing-verify-card', [
            'verifyUser' => $user ?? [],
            'verifyType' => $verifyType ?? '',
            'verifyError' => $verifyError,
        ]); ?>
    </div>
</section>
