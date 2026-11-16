<?php
/**
 * Вкладки разделов админки для узких экранов (на десктопе навигация — в сайдбаре).
 *   <?php \App\Core\View::partial('admin/_nav'); ?>
 */
use App\Helpers\AdminNav;
use App\Helpers\ProductHelper;
?>
<nav class="adm-tabs" aria-label="<?= htmlspecialchars(t('admin.title')) ?>">
    <?php foreach (AdminNav::groups() as $admGroup): foreach ($admGroup['items'] as $admItem): ?>
        <a href="<?= ProductHelper::url($admItem['path']) ?>" class="adm-tab<?= $admItem['active'] ? ' is-active' : '' ?>"<?= $admItem['active'] ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars($admItem['label']) ?>
            <?php if ($admItem['count'] > 0 && $admItem['tone'] !== ''): ?><span class="adm-count adm-count--<?= $admItem['tone'] ?>"><?= $admItem['count'] > 99 ? '99+' : $admItem['count'] ?></span><?php endif; ?>
        </a>
    <?php endforeach; endforeach; ?>
</nav>
<script>
(function () {
    if (window.__admUiBound) return;
    window.__admUiBound = true;
    var hint = <?= json_encode(t('admin.search_hotkey'), JSON_UNESCAPED_UNICODE) ?>;
    function searchInput() { return document.querySelector('.adm input[type="search"]'); }
    document.addEventListener('DOMContentLoaded', function () {
        var s = searchInput();
        if (s && !s.title) s.title = hint;
    });
    // «/» — фокус на поиск страницы
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
        var t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        var input = searchInput();
        if (!input) return;
        e.preventDefault();
        input.focus();
        input.select();
    });
    // Клик по строке таблицы с data-href открывает запись
    document.addEventListener('click', function (e) {
        var row = e.target.closest('.adm tr[data-href]:not(.js-row-link)');
        if (!row || e.target.closest('a, button, input, select, textarea, label, summary')) return;
        if (window.getSelection && String(window.getSelection())) return;
        var href = row.getAttribute('data-href');
        if (e.metaKey || e.ctrlKey) window.open(href, '_blank'); else window.location.href = href;
    });
})();
</script>
