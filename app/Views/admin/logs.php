<?php
use App\Helpers\ActivityLogger;
use App\Helpers\ProductHelper;

$logs = $logs ?? [];
$levelCounts = $levelCounts ?? ['info' => 0, 'warning' => 0, 'error' => 0];
$actionPrefixes = $actionPrefixes ?? [];
$filterLevel = $filterLevel ?? null;
$filterAction = $filterAction ?? '';
$searchQuery = $searchQuery ?? '';
$filterUserId = $filterUserId ?? null;
$page = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$total = (int) ($total ?? 0);
$e = static fn ($s): string => htmlspecialchars((string) $s);

$levelClass = static function (string $level): string {
    return match ($level) {
        'error' => 'adm-badge--danger',
        'warning' => 'adm-badge--warn',
        default => 'adm-badge--neutral',
    };
};

$levelLabel = static function (string $level): string {
    return match ($level) {
        'error' => t('admin.logs_level_error'),
        'warning' => t('admin.logs_level_warning'),
        default => t('admin.logs_level_info'),
    };
};

$buildUrl = static function (array $overrides = []) use ($filterLevel, $filterAction, $searchQuery, $filterUserId): string {
    $params = [];
    $level = array_key_exists('level', $overrides) ? $overrides['level'] : $filterLevel;
    $action = array_key_exists('action', $overrides) ? $overrides['action'] : $filterAction;
    $q = array_key_exists('q', $overrides) ? $overrides['q'] : $searchQuery;
    $userId = array_key_exists('user_id', $overrides) ? $overrides['user_id'] : $filterUserId;
    $pageNum = $overrides['page'] ?? null;

    if ($level) {
        $params['level'] = $level;
    }
    if ($action !== '' && $action !== null) {
        $params['action'] = $action;
    }
    if ($q !== '' && $q !== null) {
        $params['q'] = $q;
    }
    if ($userId) {
        $params['user_id'] = $userId;
    }
    if ($pageNum && (int) $pageNum > 1) {
        $params['page'] = (int) $pageNum;
    }

    return ProductHelper::url('/admin/logs' . ($params ? '?' . http_build_query($params) : ''));
};
?>
<div class="adm">
    <header class="adm-head">
        <div>
            <h1 class="adm-title"><?= $e(t('admin.logs')) ?></h1>
            <p class="adm-sub"><?= $e(t('admin.logs_hint')) ?></p>
        </div>
        <div class="adm-head-actions">
            <span class="muted"><?= number_format($total, 0, '', ' ') ?> <?= $e(t('admin.logs_total')) ?></span>
        </div>
    </header>

    <?php \App\Core\View::partial('admin/_nav'); ?>

    <div class="adm-toolbar">
        <div class="adm-seg">
            <a href="<?= $e($buildUrl(['level' => null, 'page' => 1])) ?>" class="<?= $filterLevel === null ? 'is-active' : '' ?>">
                <?= $e(t('admin.logs_all')) ?> <span class="adm-count"><?= (int) array_sum($levelCounts) ?></span>
            </a>
            <a href="<?= $e($buildUrl(['level' => 'info', 'page' => 1])) ?>" class="<?= $filterLevel === 'info' ? 'is-active' : '' ?>">
                <?= $e(t('admin.logs_level_info')) ?> <span class="adm-count"><?= (int) ($levelCounts['info'] ?? 0) ?></span>
            </a>
            <a href="<?= $e($buildUrl(['level' => 'error', 'page' => 1])) ?>" class="<?= $filterLevel === 'error' ? 'is-active' : '' ?>">
                <?= $e(t('admin.logs_level_error')) ?> <span class="adm-count<?= (int) ($levelCounts['error'] ?? 0) > 0 ? ' adm-count--danger' : '' ?>"><?= (int) ($levelCounts['error'] ?? 0) ?></span>
            </a>
        </div>
        <form method="get" action="<?= ProductHelper::url('/admin/logs') ?>" class="adm-toolbar grow" style="justify-content:flex-end">
            <?php if ($filterLevel): ?>
                <input type="hidden" name="level" value="<?= $e($filterLevel) ?>">
            <?php endif; ?>
            <?php if ($filterUserId): ?>
                <input type="hidden" name="user_id" value="<?= (int) $filterUserId ?>">
            <?php endif; ?>
            <select name="action" class="adm-select">
                <option value=""><?= $e(t('admin.logs_all_actions')) ?></option>
                <?php foreach ($actionPrefixes as $prefix): ?>
                    <option value="<?= $e($prefix) ?>" <?= $filterAction === $prefix ? 'selected' : '' ?>>
                        <?= $e(t('admin.log_cat_' . $prefix, [], $prefix)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="q" value="<?= $e($searchQuery) ?>"
                   placeholder="<?= $e(t('admin.logs_search')) ?>"
                   class="adm-input" style="min-width:220px;max-width:320px;flex:1">
            <button type="submit" class="adm-btn"><?= $e(t('admin.users_find')) ?></button>
        </form>
    </div>

    <?php if ($filterUserId): ?>
        <div class="adm-alert adm-alert--info">
            <?= $e(t('admin.logs_user_filter')) ?> #<?= (int) $filterUserId ?>
            · <a href="<?= $e($buildUrl(['user_id' => null, 'page' => 1])) ?>" class="adm-link"><?= $e(t('admin.logs_clear_user')) ?></a>
        </div>
    <?php endif; ?>

    <section class="adm-card">
        <?php if (empty($logs)): ?>
            <div class="adm-empty"><?= $e(t('admin.logs_empty')) ?></div>
        <?php else: ?>
            <div class="adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th><?= $e(t('admin.logs_when')) ?></th>
                            <th><?= $e(t('admin.logs_level')) ?></th>
                            <th><?= $e(t('admin.logs_who')) ?></th>
                            <th><?= $e(t('admin.logs_action')) ?></th>
                            <th><?= $e(t('admin.logs_message')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $row):
                            $lvl = (string) ($row['level'] ?? 'info');
                            $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
                            $ctx = null;
                            if (!empty($row['context_json'])) {
                                $decoded = json_decode((string) $row['context_json'], true);
                                $ctx = is_array($decoded) ? $decoded : null;
                            }
                        ?>
                        <tr>
                            <td class="nowrap" style="vertical-align:top">
                                <div><?= $e(date('d.m.Y H:i:s', strtotime((string) $row['created_at']))) ?></div>
                                <?php if (!empty($row['ip'])): ?>
                                    <div class="subtle mono" style="font-size:12px"><?= $e((string) $row['ip']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap" style="vertical-align:top">
                                <span class="adm-badge <?= $levelClass($lvl) ?>"><span class="adm-dot"></span><?= $e($levelLabel($lvl)) ?></span>
                            </td>
                            <td class="nowrap" style="vertical-align:top">
                                <?php if ($uid > 0): ?>
                                    <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="strong">
                                        <?= $e((string) ($row['user_name'] ?: ('#' . $uid))) ?>
                                    </a>
                                    <div class="subtle" style="font-size:12px">#<?= $uid ?></div>
                                <?php else: ?>
                                    <span class="subtle"><?= $e(t('admin.logs_guest')) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:180px;vertical-align:top">
                                <div class="strong"><?= $e(ActivityLogger::actionLabel((string) $row['action'])) ?></div>
                                <div class="subtle mono" style="font-size:12px"><?= $e((string) $row['action']) ?></div>
                                <?php if (!empty($row['entity_type'])): ?>
                                    <div class="subtle" style="font-size:12px">
                                        <?= $e((string) $row['entity_type']) ?><?= !empty($row['entity_id']) ? ' #' . (int) $row['entity_id'] : '' ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:240px;max-width:480px;white-space:normal;vertical-align:top">
                                <div><?= $e((string) $row['message']) ?></div>
                                <?php if ($ctx): ?>
                                    <details style="margin-top:4px">
                                        <summary class="adm-link" style="font-size:12px;cursor:pointer"><?= $e(t('admin.logs_details')) ?></summary>
                                        <pre class="mono muted" style="margin:6px 0 0;font-size:12px;white-space:pre-wrap;word-break:break-all;background:var(--adm-surface-2);border:1px solid var(--adm-border);border-radius:6px;padding:8px;max-height:160px;overflow:auto"><?= $e(json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <div class="adm-pager">
                <div>
                    <?php if ($page > 1): ?>
                        <a href="<?= $e($buildUrl(['page' => $page - 1])) ?>" class="adm-btn adm-btn--sm"><?= $e(t('admin.logs_prev')) ?></a>
                    <?php endif; ?>
                </div>
                <span><?= $page ?> / <?= $pages ?></span>
                <div>
                    <?php if ($page < $pages): ?>
                        <a href="<?= $e($buildUrl(['page' => $page + 1])) ?>" class="adm-btn adm-btn--sm"><?= $e(t('admin.logs_next')) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
