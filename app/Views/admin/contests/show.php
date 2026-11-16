<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$c = $contest;
$id = (int) $c['id'];
$status = (string) $c['status'];
$roles = \App\Services\Contest\ContestService::actorRoles($c);
$can = static fn (string $to): bool => ContestPolicy::canTransition($status, $to, $roles);
$isOwn = in_array('organizer', $roles, true);
$e = static fn ($s): string => htmlspecialchars((string) $s);
$statusTone = match ($status) {
    'active', 'completed' => 'ok',
    'pending_moderation', 'suspended' => 'warn',
    'rejected', 'disputed' => 'danger',
    'scheduled', 'closed', 'results_published' => 'info',
    default => 'neutral',
};
$fulfilTone = static fn (string $s): string => match ($s) {
    'confirmed' => 'ok',
    'handed_over' => 'info',
    'disputed', 'expired' => 'danger',
    default => 'neutral',
};
$in = 'adm-input grow';
?>
<div class="adm">
    <header class="adm-head">
        <div style="min-width:0">
            <nav class="adm-crumbs" aria-label="breadcrumbs">
                <a href="<?= ProductHelper::url('/admin/contests') ?>"><?= $e(t('contests.admin.title')) ?></a>
                <span class="sep">/</span>
                <span>#<?= $id ?></span>
            </nav>
            <h1 class="adm-title"><?= $e($c['title']) ?></h1>
            <p class="adm-sub">
                <?= $e(t('contests.mechanic.' . $c['mechanic'])) ?>
                · <?= $e(ContestView::date($c['starts_at'])) ?> — <?= $e(ContestView::date($c['ends_at'])) ?>
            </p>
        </div>
        <div class="adm-head-actions">
            <span class="adm-badge adm-badge--<?= $statusTone ?>"><span class="adm-dot"></span><?= $e(t('contests.status.' . $status)) ?></span>
            <a class="adm-btn" href="<?= ProductHelper::url('/contests/' . $id . '/manage') ?>"><?= $e(t('contests.manage_title')) ?></a>
            <a class="adm-btn" href="<?= ProductHelper::url('/contests/' . rawurlencode((string) $c['slug'])) ?>"><?= $e(t('contests.view_page')) ?></a>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <div class="adm-grid-2" style="align-items:start">
        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title"><?= $e(t('contests.organizer')) ?></h2>
                <?php if (!empty($organizer['blocked_at'])): ?>
                    <span class="adm-badge adm-badge--danger"><span class="adm-dot"></span><?= $e(t('admin.user_block_title')) ?></span>
                <?php endif; ?>
            </div>
            <div class="adm-card-body adm-stack" style="gap:6px">
                <div>
                    <a class="adm-link" href="<?= ProductHelper::url('/admin/users/' . (int) $c['organizer_id']) ?>"><?= $e($organizer['name'] ?? '') ?></a>
                    <span class="muted"> · <?= $e($organizer['email'] ?? '') ?></span>
                </div>
                <div class="muted" style="font-size:12px"><?= $e(t('contests.admin.org_status', [
                    'type' => (string) ($organizer['account_type'] ?? ''),
                    'business' => (string) ($organizer['business_status'] ?? ''),
                    'aml' => (string) ($organizer['aml_status'] ?? '—'),
                    'email' => !empty($organizer['email_verified_at']) ? '✓' : '✗',
                ])) ?></div>
            </div>
        </section>

        <!-- Модерация -->
        <section class="adm-card">
            <div class="adm-card-head">
                <h2 class="adm-card-title"><?= $e(t('contests.admin.moderation')) ?></h2>
            </div>
            <div class="adm-card-body adm-stack" style="gap:10px">
                <?php if ($status === ContestPolicy::S_PENDING): ?>
                    <?php if ($isOwn && !\App\Core\Auth::isAdmin()): ?>
                        <div class="adm-alert adm-alert--warn"><?= $e(t('contests.errors.self_moderation')) ?></div>
                    <?php else: ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/approve') ?>" class="adm-toolbar">
                        <?= csrf_field() ?>
                        <input name="note" maxlength="500" placeholder="<?= $e(t('contests.admin.note_optional')) ?>" class="<?= $in ?>">
                        <button class="adm-btn adm-btn--primary"><?= $e(t('contests.admin.approve')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/reject') ?>" class="adm-toolbar">
                        <?= csrf_field() ?>
                        <input name="note" required minlength="5" maxlength="500" placeholder="<?= $e(t('contests.admin.reject_reason')) ?>" class="<?= $in ?>">
                        <button class="adm-btn adm-btn--danger"><?= $e(t('contests.admin.reject')) ?></button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($can(ContestPolicy::S_SUSPENDED)): ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/suspend') ?>" class="adm-toolbar">
                        <?= csrf_field() ?>
                        <input name="note" required minlength="5" maxlength="500" placeholder="<?= $e(t('contests.admin.suspend_reason')) ?>" class="<?= $in ?>">
                        <button class="adm-btn"><?= $e(t('contests.admin.suspend')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($status === ContestPolicy::S_SUSPENDED): ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/resume') ?>" class="adm-toolbar">
                        <?= csrf_field() ?>
                        <button class="adm-btn adm-btn--primary"><?= $e(t('contests.admin.resume')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if (\App\Core\Auth::isAdmin() && $can(ContestPolicy::S_CANCELLED)): ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/cancel') ?>" class="adm-toolbar" onsubmit="return confirm(<?= htmlspecialchars(js_encode(t('contests.cancel_confirm'))) ?>)">
                        <?= csrf_field() ?>
                        <input name="note" maxlength="500" placeholder="<?= $e(t('contests.cancel_reason')) ?>" class="<?= $in ?>">
                        <button class="adm-btn adm-btn--danger"><?= $e(t('contests.cancel')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if (!empty($c['moderation_note'])): ?><p class="adm-help" style="margin:0"><?= $e(t('contests.moderator_note')) ?>: <?= $e($c['moderation_note']) ?></p><?php endif; ?>
            </div>
        </section>
    </div>

    <?php if ($rules): ?>
    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('contests.rules')) ?> <span class="subtle" style="font-weight:400">v<?= (int) $rules['version'] ?></span></h2>
            <span class="muted" style="font-size:12px"><?= $e(t('contests.entry_counts', ['p' => (int) $counts['pending'], 'c' => (int) $counts['confirmed'], 'd' => (int) $counts['disqualified'], 'w' => (int) $counts['withdrawn']])) ?></span>
        </div>
        <dl class="adm-kv">
            <div>
                <dt><?= $e(t('contests.prizes')) ?></dt>
                <dd><?= $e(implode('; ', array_map(static fn ($p) => $p['place'] . '. ' . $p['title'], (array) $rules['prizes']))) ?></dd>
            </div>
            <div>
                <dt><?= $e(t('contests.conditions')) ?></dt>
                <dd><?= $e(implode(', ', array_map(static fn ($x) => t('contests.cond.' . $x['key'], ['days' => (int) ($x['days'] ?? 0)]), (array) $rules['conditions'])) ?: '—') ?></dd>
            </div>
            <?php if (!empty($rules['criteria_text'])): ?>
            <div>
                <dt><?= $e(t('contests.criteria')) ?></dt>
                <dd style="white-space:pre-line"><?= $e($rules['criteria_text']) ?></dd>
            </div>
            <?php endif; ?>
        </dl>
        <div class="adm-card-body adm-stack" style="gap:12px;border-top:1px solid var(--adm-border)">
            <?php if (trim((string) $c['description']) !== ''): ?><div class="muted" style="white-space:pre-line"><?= $e($c['description']) ?></div><?php endif; ?>
            <div style="white-space:pre-line;padding:12px;border-radius:var(--adm-radius);background:var(--adm-surface-2);border:1px solid var(--adm-border);font-size:13px"><?= $e($rules['rules_text']) ?></div>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($winners)): ?>
    <section class="adm-card">
        <div class="adm-card-head">
            <div style="min-width:0">
                <h2 class="adm-card-title"><?= $e(t('contests.winners_fulfilment')) ?></h2>
                <?php if (!empty($execution)): ?><p class="adm-card-sub mono" style="overflow-wrap:anywhere"><?= $e($execution['algorithm']) ?> · <?= $e($execution['eligible_hash']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="adm-list">
            <?php foreach ($winners as $w): ?>
                <div class="adm-row" style="flex-direction:column;align-items:stretch;gap:8px">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px">
                        <div>
                            <span class="subtle"><?= (int) $w['place'] ?>.</span>
                            <span style="font-weight:500"><?= $e($w['user_name'] ?? '') ?></span>
                            <span class="muted"> — <?= $e($w['prize_title']) ?></span>
                        </div>
                        <span class="adm-badge adm-badge--<?= $fulfilTone((string) $w['fulfilment_status']) ?>"><span class="adm-dot"></span><?= $e(t('contests.fulfilment.' . $w['fulfilment_status'])) ?></span>
                    </div>
                    <?php if (!empty($w['fulfilment_note'])): ?><div class="muted" style="font-size:12px"><?= $e($w['fulfilment_note']) ?></div><?php endif; ?>
                    <?php if ($w['fulfilment_status'] === 'disputed'): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/winners/' . (int) $w['id'] . '/resolve') ?>" class="adm-toolbar">
                            <?= csrf_field() ?>
                            <input name="note" required minlength="5" maxlength="500" placeholder="<?= $e(t('contests.admin.resolution')) ?>" class="<?= $in ?>">
                            <button name="outcome" value="confirmed" class="adm-btn"><?= $e(t('contests.admin.dispute_delivered')) ?></button>
                            <button name="outcome" value="expired" class="adm-btn adm-btn--danger"><?= $e(t('contests.admin.dispute_not_delivered')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('contests.reports')) ?> <span class="adm-count"><?= count($reports) ?></span></h2>
        </div>
        <div class="adm-list">
            <?php foreach ($reports as $r): ?>
                <div class="adm-row" style="flex-direction:column;align-items:stretch;gap:8px">
                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px">
                        <div>
                            <span style="font-weight:500"><?= $e(t('contests.report_reason.' . $r['reason'])) ?></span>
                            <span class="muted"> · <?= $e($r['reporter_name'] ?? '') ?></span>
                        </div>
                        <span class="adm-badge adm-badge--<?= $r['status'] === 'open' ? 'warn' : 'neutral' ?>"><span class="adm-dot"></span><?= $e(t('contests.report_status.' . $r['status'])) ?></span>
                    </div>
                    <?php if (!empty($r['details'])): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= $e($r['details']) ?></div><?php endif; ?>
                    <?php if ($r['status'] === 'open'): ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/contests/reports/' . (int) $r['id'] . '/resolve') ?>" class="adm-toolbar">
                            <?= csrf_field() ?>
                            <input type="hidden" name="contest_id" value="<?= $id ?>">
                            <input name="note" required minlength="3" maxlength="500" placeholder="<?= $e(t('contests.admin.resolution')) ?>" class="<?= $in ?>">
                            <button name="status" value="resolved" class="adm-btn"><?= $e(t('contests.admin.report_resolve')) ?></button>
                            <button name="status" value="rejected" class="adm-btn adm-btn--ghost"><?= $e(t('contests.admin.report_reject')) ?></button>
                        </form>
                    <?php elseif (!empty($r['resolution_note'])): ?>
                        <div class="adm-help"><?= $e($r['resolution_note']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
