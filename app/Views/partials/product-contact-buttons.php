<?php
/** @var string|null $callHref */
/** @var string|null $whatsappHref */
/** @var string $outlineBtn */
/** @var string $waOutlineBtn */
/** @var string $phoneIconSvg */
/** @var string $waIconSvg */

if (empty($callHref) && empty($whatsappHref)) {
    return;
}
?>
<div class="grid grid-cols-2 gap-2.5">
    <?php if (!empty($callHref)): ?>
        <a href="<?= htmlspecialchars($callHref) ?>" class="<?= $outlineBtn ?>">
            <?= $phoneIconSvg ?>
            <span class="truncate"><?= htmlspecialchars(t('product.call')) ?></span>
        </a>
    <?php endif; ?>
    <?php if (!empty($whatsappHref)): ?>
        <a href="<?= htmlspecialchars($whatsappHref) ?>"
           target="_blank"
           rel="noopener noreferrer"
           class="<?= $waOutlineBtn ?>">
            <?= $waIconSvg ?>
            <span class="truncate"><?= htmlspecialchars(t('product.whatsapp')) ?></span>
        </a>
    <?php endif; ?>
</div>
