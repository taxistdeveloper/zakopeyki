<?php
use App\Helpers\ProductHelper;

$tickets = $tickets ?? [];
$filterStatus = $filterStatus ?? null;
$e = static fn ($s): string => htmlspecialchars((string) $s);

$statusLabel = static function (string $status): string {
    return match ($status) {
        'answered' => t('support.status_answered'),
        'closed' => t('support.status_closed'),
        default => t('support.status_open'),
    };
};

$statusTone = static function (string $status): string {
    return match ($status) {
        'answered' => 'info',
        'closed' => 'neutral',
        default => 'warn',
    };
};

// Ключ null в массиве превращается в '' — сравниваем как строки.
$filters = [
    '' => t('admin.tickets_all'),
    'open' => t('support.status_open'),
    'answered' => t('support.status_answered'),
    'closed' => t('support.status_closed'),
];
$activeFilter = (string) ($filterStatus ?? '');
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.tickets')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.tickets_hint')) ?></p>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>

    <div class="adm-toolbar">
        <nav class="adm-seg" aria-label="<?= $e(t('admin.status')) ?>">
            <?php foreach ($filters as $key => $label):
                $key = (string) $key;
                $active = $activeFilter === $key;
                $url = ProductHelper::url('/admin/tickets' . ($key !== '' ? '?status=' . urlencode($key) : ''));
            ?>
                <a href="<?= $url ?>" class="<?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <section class="adm-card">
        <?php if (empty($tickets)): ?>
            <div class="adm-empty"><?= $e(t('admin.tickets_empty')) ?></div>
        <?php else: ?>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th style="width:1%">#</th>
                            <th><?= $e(t('support.subject')) ?></th>
                            <th><?= $e(t('admin.status')) ?></th>
                            <th class="num"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket):
                            $unread = (int) ($ticket['unread_count'] ?? 0);
                            $status = (string) ($ticket['status'] ?? 'open');
                            $href = ProductHelper::url('/admin/tickets/' . (int) $ticket['id']);
                        ?>
                            <tr class="js-row-link" data-href="<?= $href ?>">
                                <td class="subtle nowrap mono"><?= $e($ticket['ticket_number']) ?></td>
                                <td style="min-width:260px;max-width:640px">
                                    <a class="strong truncate" style="display:block" href="<?= $href ?>"><?= $e($ticket['subject']) ?></a>
                                    <div class="muted truncate" style="font-size:12px;margin-top:2px">
                                        <?= $e($ticket['user_name'] ?? '') ?> · <?= $e(t('support.cat_' . ($ticket['category'] ?? 'general'))) ?>
                                    </div>
                                    <?php if ((string) ($ticket['last_preview'] ?? '') !== ''): ?>
                                        <div class="subtle truncate" style="font-size:12px;margin-top:2px"><?= $e($ticket['last_preview']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="nowrap">
                                    <span class="adm-badge adm-badge--<?= $statusTone($status) ?>"><span class="adm-dot"></span><?= $e($statusLabel($status)) ?></span>
                                </td>
                                <td class="num">
                                    <?php if ($unread > 0): ?>
                                        <span class="adm-count adm-count--danger"><?= $unread > 99 ? '99+' : $unread ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    document.querySelectorAll('.js-row-link').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            if (window.getSelection && String(window.getSelection())) return;
            const href = row.getAttribute('data-href');
            if (!href) return;
            if (e.metaKey || e.ctrlKey) window.open(href, '_blank');
            else window.location.href = href;
        });
    });
})();
</script>
