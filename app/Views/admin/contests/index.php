<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$items = $items ?? [];
$counts = $counts ?? [];
$settings = $settings ?? [];
$e = static fn ($s): string => htmlspecialchars((string) $s);
$statusTone = static fn (string $s): string => match ($s) {
    'active', 'completed' => 'ok',
    'pending_moderation', 'suspended' => 'warn',
    'rejected', 'disputed' => 'danger',
    'scheduled', 'closed', 'results_published' => 'info',
    default => 'neutral',
};
$badge = static fn (string $s): string => '<span class="adm-badge adm-badge--' . $statusTone($s) . '"><span class="adm-dot"></span>'
    . htmlspecialchars(t('contests.status.' . $s)) . '</span>';
$activeFilter = (string) ($statusFilter ?? '');
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('contests.admin.title')) ?></h1>
            <p class="adm-sub"><?= $e(t('contests.admin.hint')) ?></p>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
    <section class="adm-card">
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('contests.admin.reports_queue')) ?> <span class="adm-count adm-count--danger"><?= count($reports) ?></span></h2>
        </div>
        <div class="adm-list">
            <?php foreach ($reports as $r): ?>
                <div class="adm-row" style="flex-direction:column;align-items:stretch;gap:8px">
                    <div>
                        <a class="adm-link" href="<?= ProductHelper::url('/admin/contests/' . (int) $r['contest_id']) ?>"><?= $e($r['contest_title']) ?></a>
                        <span class="muted" style="font-size:12px">
                            · <?= $e(t('contests.report_reason.' . $r['reason'])) ?> · <?= $e($r['reporter_name'] ?? '') ?> · <span class="subtle"><?= $e(ContestView::date($r['created_at'])) ?></span>
                        </span>
                    </div>
                    <?php if (!empty($r['details'])): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= $e($r['details']) ?></div><?php endif; ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/reports/' . (int) $r['id'] . '/resolve') ?>" class="adm-toolbar">
                        <?= csrf_field() ?>
                        <input name="note" required minlength="3" maxlength="500" placeholder="<?= $e(t('contests.admin.resolution')) ?>" class="adm-input grow">
                        <button name="status" value="resolved" class="adm-btn"><?= $e(t('contests.admin.report_resolve')) ?></button>
                        <button name="status" value="rejected" class="adm-btn adm-btn--ghost"><?= $e(t('contests.admin.report_reject')) ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="adm-toolbar">
        <nav class="adm-seg" aria-label="<?= $e(t('contests.status_label')) ?>">
            <a href="<?= ProductHelper::url('/admin/contests') ?>" class="<?= $activeFilter === '' ? 'is-active' : '' ?>"><?= $e(t('contests.entry_filter.all')) ?></a>
            <?php foreach (ContestPolicy::STATUSES as $s): ?>
                <a href="<?= ProductHelper::url('/admin/contests?status=' . $s) ?>" class="<?= $activeFilter === $s ? 'is-active' : '' ?>"><?= $e(t('contests.status.' . $s)) ?> <span class="subtle"><?= (int) ($counts[$s] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <section class="adm-card">
        <?php if ($items === []): ?>
            <div class="adm-empty"><?= $e(t('contests.empty')) ?></div>
        <?php else: ?>
        <div class="adm-table-wrap">
            <table class="adm-table">
                <thead>
                    <tr>
                        <th style="width:1%">#</th>
                        <th><?= $e(t('contests.f.title')) ?></th>
                        <th><?= $e(t('contests.organizer')) ?></th>
                        <th><?= $e(t('contests.status_label')) ?></th>
                        <th class="num"><?= $e(t('contests.participants')) ?></th>
                        <th class="num"><?= $e(t('contests.reports')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $c): ?>
                    <tr>
                        <td class="subtle"><?= (int) $c['id'] ?></td>
                        <td style="min-width:240px">
                            <a class="strong" href="<?= ProductHelper::url('/admin/contests/' . (int) $c['id']) ?>"><?= $e($c['title']) ?></a>
                            <div class="muted" style="font-size:12px;margin-top:2px"><?= $e(t('contests.mechanic.' . $c['mechanic'])) ?> · <?= $e(ContestView::date($c['starts_at'])) ?> — <?= $e(ContestView::date($c['ends_at'])) ?></div>
                        </td>
                        <td><?= $e($c['organizer_name']) ?></td>
                        <td class="nowrap"><?= $badge((string) $c['status']) ?></td>
                        <td class="num"><?= (int) $c['entries_count'] ?></td>
                        <td class="num">
                            <?php if ((int) $c['open_reports'] > 0): ?>
                                <span class="adm-count adm-count--danger"><?= (int) $c['open_reports'] ?></span>
                            <?php else: ?>
                                <span class="subtle">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($isAdmin)): ?>
    <form method="post" action="<?= ProductHelper::url('/admin/contests/settings') ?>" class="adm-card">
        <?= csrf_field() ?>
        <div class="adm-card-head">
            <h2 class="adm-card-title"><?= $e(t('contests.admin.settings')) ?></h2>
        </div>
        <div class="adm-card-body adm-stack">
            <label class="adm-check" style="font-weight:500;color:var(--adm-text)">
                <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
                <?= $e(t('contests.admin.module_enabled')) ?>
            </label>

            <fieldset class="adm-stack" style="gap:10px;margin:0;padding:12px 14px;border:1px solid var(--adm-border);border-radius:var(--adm-radius)">
                <legend class="adm-label" style="padding:0 4px"><?= $e(t('contests.admin.mechanics')) ?></legend>
                <?php foreach (ContestPolicy::MECHANICS as $m): ?>
                    <label class="adm-check" style="align-items:flex-start">
                        <input type="checkbox" name="method_<?= $m ?>" value="1" style="margin-top:2px" <?= !empty($settings['mechanics'][$m]) ? 'checked' : '' ?>>
                        <span><span style="font-weight:500;color:var(--adm-text)"><?= $e(t('contests.mechanic.' . $m)) ?></span> — <?= $e(t('contests.mechanic_hint.' . $m)) ?></span>
                    </label>
                <?php endforeach; ?>
                <div class="adm-alert adm-alert--warn"><?= $e(t('contests.admin.mechanics_legal')) ?></div>
                <label class="adm-check" style="align-items:flex-start">
                    <input type="checkbox" name="legal_ack" value="1" style="margin-top:2px">
                    <span><?= $e(t('contests.admin.legal_ack')) ?></span>
                </label>
                <p class="adm-help" style="margin:0"><?= $e(t('contests.admin.not_implemented')) ?></p>
            </fieldset>

            <div class="adm-form-grid">
                <label class="adm-field">
                    <span class="adm-label"><?= $e(t('contests.admin.organizer_mode')) ?></span>
                    <select name="organizer_mode" class="adm-select">
                        <?php foreach (ContestPolicy::ORGANIZER_MODES as $mode): ?>
                            <option value="<?= $mode ?>" <?= ($settings['organizer_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= $e(t('contests.admin.organizer_mode_' . $mode)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="adm-check" style="align-self:end;min-height:34px">
                    <input type="checkbox" name="require_verified_email" value="1" <?= !empty($settings['require_verified_email']) ? 'checked' : '' ?>>
                    <?= $e(t('contests.admin.require_email')) ?>
                </label>
            </div>
        </div>
        <div class="adm-card-foot adm-form-actions">
            <button class="adm-btn adm-btn--primary"><?= $e(t('contests.admin.save')) ?></button>
        </div>
    </form>
    <?php endif; ?>
</div>
