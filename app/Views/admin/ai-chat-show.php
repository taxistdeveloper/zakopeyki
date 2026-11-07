<?php
use App\Helpers\ProductHelper;

$e = static fn ($s): string => htmlspecialchars((string) $s);
$messages = $messages ?? [];
$convStatus = (string) ($conversation['status'] ?? '');
$isClosed = $convStatus === 'closed';
$statusTone = match ($convStatus) {
    'human_escalated' => 'warn',
    'closed' => 'neutral',
    default => 'info',
};
$statusLabel = match ($convStatus) {
    'human_escalated' => t('admin.ai_status_escalated'),
    'ai_active' => t('admin.ai_status_ai'),
    'closed' => t('admin.ai_status_closed'),
    default => $convStatus,
};
?>
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumbs">
                <a href="<?= ProductHelper::url('/admin/ai-chats') ?>"><?= $e(t('admin.ai_chats')) ?></a>
                <span class="sep">/</span>
                <span>#<?= (int) $conversation['id'] ?></span>
            </nav>
            <h1 class="adm-title"><?= $e(t('admin.ai_chat')) ?> #<?= (int) $conversation['id'] ?></h1>
            <p class="adm-sub">
                <?= $e($userName ?? t('admin.ai_guest')) ?>
                <?php if (!empty($userEmail)): ?>
                    · <?= $e($userEmail) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="adm-head-actions">
            <span class="adm-badge adm-badge--<?= $statusTone ?>"><span class="adm-dot"></span><?= $e($statusLabel) ?></span>
            <?php if (!$isClosed): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/ai-chats/' . (int) $conversation['id'] . '/close') ?>" class="adm-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="adm-btn"><?= $e(t('admin.ai_close_learn')) ?></button>
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
        <div class="adm-thread js-thread" style="max-height:55vh;overflow-y:auto">
            <?php foreach ($messages as $m):
                $type = (string) $m['sender_type'];
                $label = match ($type) {
                    'user' => t('admin.ai_sender_user'),
                    'agent' => t('admin.ai_sender_agent'),
                    'system' => t('admin.ai_sender_system'),
                    default => 'AI',
                };
                $meta = '<div class="adm-msg-meta"><span style="font-weight:600;color:var(--adm-text-2)">' . $e($label) . '</span> · ' . $e($m['created_at']) . '</div>';
            ?>
                <?php if ($type === 'system'): ?>
                    <div class="adm-msg" style="align-self:center;background:var(--adm-warn-bg);border-color:var(--adm-warn-bd)"><?= $meta ?><?= $e($m['message']) ?></div>
                <?php else: ?>
                    <div class="adm-msg<?= $type === 'user' ? '' : ' is-own' ?>"><?= $meta ?><?= $e($m['message']) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if (!$isClosed): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/ai-chats/' . (int) $conversation['id'] . '/reply') ?>" class="adm-card-foot adm-stack" style="gap:8px">
                <?= csrf_field() ?>
                <textarea name="body" rows="3" required maxlength="4000" class="adm-textarea"
                          placeholder="<?= $e(t('admin.ai_reply_placeholder')) ?>"></textarea>
                <div class="adm-form-actions">
                    <button type="submit" name="close" value="1" class="adm-btn"><?= $e(t('admin.ai_send_close')) ?></button>
                    <button type="submit" class="adm-btn adm-btn--primary"><?= $e(t('admin.ai_send')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    var t = document.querySelector('.js-thread');
    if (t) t.scrollTop = t.scrollHeight;
})();
</script>
