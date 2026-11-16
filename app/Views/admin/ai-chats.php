<?php
use App\Helpers\ProductHelper;

$e = static fn ($s): string => htmlspecialchars((string) $s);
$conversations = $conversations ?? [];
$filterStatus = $filterStatus ?? null;

// Ключ null в массиве превращается в '' — «Все» сравниваем как пустую строку.
$filters = [
    'human_escalated' => t('admin.ai_status_escalated'),
    'ai_active' => t('admin.ai_status_ai'),
    'closed' => t('admin.ai_status_closed'),
    '' => t('admin.tickets_all'),
];
$activeFilter = (string) ($filterStatus ?? '');
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.ai_chats')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.ai_chats_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <a href="<?= ProductHelper::url('/admin/ai-export-dataset') ?>" class="adm-btn"><?= $e(t('admin.ai_export')) ?></a>
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
                $href = ProductHelper::url('/admin/ai-chats' . ($key !== '' ? '?status=' . urlencode($key) : '?status=all'));
                $active = $activeFilter === $key;
            ?>
                <a href="<?= $href ?>" class="<?= $active ? 'is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                    <?= $e($label) ?>
                    <?php if ($key === 'human_escalated' && !empty($aiEscalated)): ?>
                        <span class="adm-count adm-count--warn"><?= (int) $aiEscalated ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <section class="adm-card">
        <?php if (empty($conversations)): ?>
            <div class="adm-empty"><?= $e(t('admin.ai_chats_empty')) ?></div>
        <?php else: ?>
            <div class="adm-list">
                <?php foreach ($conversations as $c):
                    $st = (string) ($c['status'] ?? '');
                    $tone = match ($st) {
                        'human_escalated' => 'warn',
                        'closed' => 'neutral',
                        default => 'info',
                    };
                    $stLabel = match ($st) {
                        'human_escalated' => t('admin.ai_status_escalated'),
                        'closed' => t('admin.ai_status_closed'),
                        default => t('admin.ai_status_ai'),
                    };
                ?>
                    <a class="adm-row" href="<?= ProductHelper::url('/admin/ai-chats/' . (int) $c['id']) ?>">
                        <div class="adm-row-main">
                            <div class="adm-row-title truncate">
                                <span class="subtle">#<?= (int) $c['id'] ?></span>
                                <?= $e($c['user_name'] ?? t('admin.ai_guest')) ?>
                                <?php if (!empty($c['user_email'])): ?>
                                    <span class="muted" style="font-weight:400"> · <?= $e($c['user_email']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="adm-row-sub truncate"><?= $e($c['last_preview'] ?? '—') ?></div>
                        </div>
                        <div class="adm-row-side">
                            <span class="adm-badge adm-badge--<?= $tone ?>"><span class="adm-dot"></span><?= $e($stLabel) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
