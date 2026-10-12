<?php

$class = (string) ($class ?? '');
?>
<span class="highlight-sticker <?= htmlspecialchars($class) ?>" data-highlight-sticker><?= htmlspecialchars(t('highlight.sticker')) ?></span>
