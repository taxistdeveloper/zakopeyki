<?php
use App\Helpers\ProductHelper;
$certificate = $certificate ?? [];
$product = $product ?? [];
$publicUrl = $publicUrl ?? '';
$public = !empty($public);
?>
<section class="max-w-2xl mx-auto pb-16">
    <?php if (!$public): ?>
        <a href="<?= ProductHelper::url('/digital') ?>" class="text-sm text-gray-400 hover:text-violet-600">← <?= htmlspecialchars(t('digital.library_title')) ?></a>
    <?php endif; ?>
    <div class="mt-4 rounded-3xl border border-violet-200 bg-white px-8 py-10 text-center space-y-4">
        <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-violet-600"><?= htmlspecialchars(t('digital.cert_eyebrow')) ?></p>
        <h1 class="font-display text-2xl font-bold"><?= htmlspecialchars(t('digital.cert_title')) ?></h1>
        <p class="text-lg font-semibold"><?= htmlspecialchars((string) ($certificate['holder_name'] ?? '')) ?></p>
        <p class="text-sm text-gray-600"><?= htmlspecialchars(t('digital.cert_body', [
            'title' => (string) ($certificate['product_title'] ?? $product['title'] ?? ''),
        ])) ?></p>
        <p class="text-xs text-gray-400"><?= htmlspecialchars(t('digital.cert_issued')) ?>
            <?= !empty($certificate['issued_at']) ? htmlspecialchars(date('d.m.Y', strtotime((string) $certificate['issued_at']))) : '' ?></p>
        <p class="font-mono text-sm tracking-widest"><?= htmlspecialchars((string) ($certificate['public_code'] ?? '')) ?></p>
        <?php if ($publicUrl !== ''): ?>
            <p class="text-[11px] text-gray-400 break-all"><?= htmlspecialchars($publicUrl) ?></p>
        <?php endif; ?>
        <p class="text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3"><?= htmlspecialchars(t('digital.cert_disclaimer')) ?></p>
    </div>
</section>
