<?php

$class = (string) ($class ?? '');
?>
<span class="vip-sticker <?= htmlspecialchars($class) ?>" data-vip-sticker><?= htmlspecialchars(t('vip.sticker')) ?></span>
