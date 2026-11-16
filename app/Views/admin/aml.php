<?php
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;
use App\Services\AMLService;

$users = $users ?? [];
$filterStatus = $filterStatus ?? 'blocked';
$searchQuery = $searchQuery ?? '';
$amlUserStats = $amlUserStats ?? ['blocked' => 0, 'clear' => 0, 'with_iin' => 0];
$amlListStats = $amlListStats ?? ['list_count' => 0, 'list_updated' => null];
$amlEvents = $amlEvents ?? [];
$e = static fn ($s): string => htmlspecialchars((string) $s);

$filters = [
    'blocked' => t('admin.aml_filter_blocked'),
    'clear' => t('admin.aml_filter_clear'),
    'pending' => t('admin.aml_filter_pending'),
    'all' => t('admin.aml_filter_all'),
];
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.aml')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.aml_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <a href="<?= ProductHelper::url('/admin/logs?action=aml') ?>" class="adm-btn"><?= $e(t('admin.aml_open_logs')) ?></a>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <?php if (!empty($flash)): ?>
        <div class="adm-alert adm-alert--ok" role="status"><?= $e($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="adm-alert adm-alert--error" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <div class="adm-stats" style="--cols:4">
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.aml_stat_blocked')) ?></div>
            <div class="adm-stat-value"><?= (int) $amlUserStats['blocked'] ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.aml_stat_clear')) ?></div>
            <div class="adm-stat-value"><?= (int) $amlUserStats['clear'] ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.aml_stat_iin')) ?></div>
            <div class="adm-stat-value"><?= (int) $amlUserStats['with_iin'] ?></div>
        </div>
        <div class="adm-stat">
            <div class="adm-stat-label"><?= $e(t('admin.aml_stat_list')) ?></div>
            <div class="adm-stat-value"><?= (int) $amlListStats['list_count'] ?></div>
            <div class="adm-stat-meta">
                <?= $e($amlListStats['list_updated'] ? t('admin.aml_list_updated', ['when' => substr((string) $amlListStats['list_updated'], 0, 16)]) : t('admin.aml_list_empty')) ?>
            </div>
        </div>
    </div>

    <div class="adm-toolbar">
        <div class="adm-seg">
            <?php foreach ($filters as $key => $label):
                $on = $filterStatus === $key;
            ?>
                <a href="<?= ProductHelper::url('/admin/aml?status=' . urlencode($key) . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '')) ?>"
                   class="<?= $on ? 'is-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="<?= ProductHelper::url('/admin/aml') ?>" class="adm-toolbar grow" style="justify-content:flex-end">
            <input type="hidden" name="status" value="<?= $e($filterStatus) ?>">
            <input type="search" name="q" value="<?= $e($searchQuery) ?>"
                   placeholder="<?= $e(t('admin.aml_search')) ?>"
                   class="adm-input" style="min-width:220px;max-width:320px;flex:1">
            <button type="submit" class="adm-btn"><?= $e(t('admin.users_find')) ?></button>
        </form>
    </div>

    <section class="adm-card">
        <?php if (empty($users)): ?>
            <div class="adm-empty"><?= $e(t('admin.aml_empty')) ?></div>
        <?php else: ?>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= $e(t('admin.user_name')) ?></th>
                            <th><?= $e(t('admin.aml_iin')) ?></th>
                            <th><?= $e(t('admin.aml_bin')) ?></th>
                            <th><?= $e(t('admin.aml_status')) ?></th>
                            <th class="num"><?= $e(t('admin.aml_checked_at')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $uid = (int) $u['id'];
                            $displayName = trim((string) ($u['name'] ?? '')) ?: ((string) ($u['login'] ?? 'User'));
                            $st = (string) ($u['aml_status'] ?? '');
                        ?>
                            <tr>
                                <td class="subtle nowrap">#<?= $uid ?></td>
                                <td style="min-width:220px">
                                    <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="strong"><?= $e($displayName) ?></a>
                                    <div class="muted truncate" style="font-size:12px;max-width:320px"><?= $e((string) ($u['email'] ?? '')) ?></div>
                                </td>
                                <td class="mono nowrap"><?= $e(AMLService::maskIin($u['iin'] ?? null)) ?></td>
                                <td class="mono nowrap"><?= $e(AMLService::maskIin($u['bin'] ?? null)) ?></td>
                                <td class="nowrap">
                                    <?php if ($st === 'AML_BLOCKED'): ?>
                                        <span class="adm-badge adm-badge--danger"><span class="adm-dot"></span>AML_BLOCKED</span>
                                    <?php elseif ($st === 'clear'): ?>
                                        <span class="adm-badge adm-badge--ok"><span class="adm-dot"></span><?= $e(t('admin.aml_badge_clear')) ?></span>
                                    <?php else: ?>
                                        <span class="adm-badge adm-badge--neutral"><span class="adm-dot"></span><?= $e(t('admin.aml_badge_pending')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="num muted nowrap"><?= $e(substr((string) ($u['aml_checked_at'] ?? ''), 0, 16) ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="adm-card">
        <div class="adm-card-head">
            <div>
                <h2 class="adm-card-title"><?= $e(t('admin.aml_events')) ?></h2>
                <p class="adm-card-sub"><?= $e(t('admin.aml_events_hint')) ?></p>
            </div>
        </div>
        <?php if (empty($amlEvents)): ?>
            <div class="adm-empty"><?= $e(t('admin.aml_events_empty')) ?></div>
        <?php else: ?>
            <div class="adm-list">
                <?php foreach ($amlEvents as $ev):
                    $eid = (int) ($ev['user_id'] ?? $ev['entity_id'] ?? 0);
                    $lvl = (string) ($ev['level'] ?? 'info');
                    $lvlClass = match ($lvl) {
                        'error' => 'danger',
                        'warning' => 'warn',
                        default => 'neutral',
                    };
                ?>
                    <div class="adm-row">
                        <div class="adm-row-main">
                            <div class="adm-row-title">
                                <?php if ($lvlClass !== 'neutral'): ?>
                                    <span class="adm-badge adm-badge--<?= $lvlClass ?>"><span class="adm-dot"></span><?= $e(t('admin.logs_level_' . $lvl)) ?></span>
                                <?php endif; ?>
                                <?= $e(ActivityLogger::actionLabel((string) ($ev['action'] ?? ''))) ?>
                            </div>
                            <div class="adm-row-sub">
                                <?= $e((string) ($ev['message'] ?? '')) ?>
                                <?php if ($eid > 0): ?>
                                    · <a href="<?= ProductHelper::url('/admin/users/' . $eid) ?>" class="adm-link"><?= $e((string) ($ev['user_name'] ?? ('#' . $eid))) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="adm-row-side nowrap"><?= $e(substr((string) ($ev['created_at'] ?? ''), 0, 16)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
