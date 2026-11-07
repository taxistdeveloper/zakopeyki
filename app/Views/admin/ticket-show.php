<?php
use App\Helpers\ProductHelper;

$ticket = $ticket ?? [];
$messages = $messages ?? [];
$status = (string) ($ticket['status'] ?? 'open');
$closed = $status === 'closed';
$e = static fn ($s): string => htmlspecialchars((string) $s);

$statusLabel = match ($status) {
    'answered' => t('support.status_answered'),
    'closed' => t('support.status_closed'),
    default => t('support.status_open'),
};
$statusTone = match ($status) {
    'answered' => 'info',
    'closed' => 'neutral',
    default => 'warn',
};
?>
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumbs">
                <a href="<?= ProductHelper::url('/admin/tickets') ?>"><?= $e(t('admin.tickets')) ?></a>
                <span class="sep">/</span>
                <span class="mono"><?= $e($ticket['ticket_number']) ?></span>
            </nav>
            <h1 class="adm-title"><?= $e($ticket['subject']) ?></h1>
            <p class="adm-sub">
                <?= $e($ticket['user_name'] ?? '') ?>
                · <?= $e($ticket['user_email'] ?? '') ?>
                · <?= $e(t('support.cat_' . ($ticket['category'] ?? 'general'))) ?>
            </p>
        </div>
        <div class="adm-head-actions">
            <span class="adm-badge adm-badge--<?= $statusTone ?>"><span class="adm-dot"></span><?= $e($statusLabel) ?></span>
            <?php if (!empty($ticket['product_id'])): ?>
                <a class="adm-btn" href="<?= ProductHelper::url('/product/' . (int) $ticket['product_id']) ?>">
                    <?= $e(t('product.report_open_listing')) ?> #<?= (int) $ticket['product_id'] ?>
                </a>
            <?php endif; ?>
            <?php if ($closed): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id'] . '/reopen') ?>" class="adm-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="adm-btn"><?= $e(t('admin.ticket_reopen')) ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id'] . '/close') ?>" class="adm-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="adm-btn"><?= $e(t('admin.ticket_close')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <section class="adm-card" style="max-width:860px;width:100%">
        <div class="adm-thread js-thread" style="max-height:min(62vh,600px);overflow-y:auto">
            <?php foreach ($messages as $m):
                $type = (string) ($m['sender_type'] ?? 'user');
                $mine = $type === 'admin';
                $system = $type === 'system';
                $time = $e(substr((string) $m['created_at'], 11, 5));
                $who = $system ? '' : ($mine ? t('support.from_support') : (string) ($ticket['user_name'] ?? t('support.from_user')));
            ?>
                <?php if ($system): ?>
                    <div class="adm-msg muted" style="align-self:center;text-align:center;font-size:12px;background:transparent"><?= $e($m['body']) ?><div class="adm-msg-meta" style="margin:4px 0 0"><?= $time ?></div></div>
                <?php else: ?>
                    <div class="adm-msg<?= $mine ? ' is-own' : '' ?>"><div class="adm-msg-meta"><span style="font-weight:600;color:var(--adm-text-2)"><?= $e($who) ?></span> · <?= $time ?></div><?= $e($m['body']) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if (!$closed): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id'] . '/reply') ?>" class="adm-card-foot adm-stack" style="gap:8px">
                <?= csrf_field() ?>
                <textarea name="body" rows="3" required maxlength="4000" class="adm-textarea"
                          placeholder="<?= $e(t('admin.ticket_reply_placeholder')) ?>"></textarea>
                <div class="adm-form-actions">
                    <button type="submit" class="adm-btn adm-btn--primary"><?= $e(t('support.send')) ?></button>
                </div>
            </form>
        <?php else: ?>
            <div class="adm-card-foot" style="text-align:center"><?= $e(t('support.closed_hint')) ?></div>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    var t = document.querySelector('.js-thread');
    if (t) t.scrollTop = t.scrollHeight;
})();
</script>
