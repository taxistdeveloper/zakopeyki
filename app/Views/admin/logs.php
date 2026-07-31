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

$levelClass = static function (string $level): string {
    return match ($level) {
        'error' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        default => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
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
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.logs')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.logs_hint')) ?></p>
        </div>
        <div class="text-xs font-semibold text-gray-400"><?= number_format($total, 0, '', ' ') ?> <?= htmlspecialchars(t('admin.logs_total')) ?></div>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <a href="<?= htmlspecialchars($buildUrl(['level' => null, 'page' => 1])) ?>"
           class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft hover:border-brand-400/50 transition <?= $filterLevel === null ? 'ring-1 ring-brand-400/40' : '' ?>">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.logs_all')) ?></div>
            <div class="font-display text-2xl font-bold mt-1"><?= (int) array_sum($levelCounts) ?></div>
        </a>
        <a href="<?= htmlspecialchars($buildUrl(['level' => 'info', 'page' => 1])) ?>"
           class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft hover:border-sky-400/50 transition <?= $filterLevel === 'info' ? 'ring-1 ring-sky-400/40' : '' ?>">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-sky-600"><?= htmlspecialchars(t('admin.logs_level_info')) ?></div>
            <div class="font-display text-2xl font-bold mt-1"><?= (int) ($levelCounts['info'] ?? 0) ?></div>
        </a>
        <a href="<?= htmlspecialchars($buildUrl(['level' => 'error', 'page' => 1])) ?>"
           class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft hover:border-red-400/50 transition <?= $filterLevel === 'error' ? 'ring-1 ring-red-400/40' : '' ?>">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-red-600"><?= htmlspecialchars(t('admin.logs_level_error')) ?></div>
            <div class="font-display text-2xl font-bold mt-1"><?= (int) ($levelCounts['error'] ?? 0) ?></div>
        </a>
    </div>

    <form method="get" action="<?= ProductHelper::url('/admin/logs') ?>" class="flex flex-wrap gap-2">
        <?php if ($filterLevel): ?>
            <input type="hidden" name="level" value="<?= htmlspecialchars($filterLevel) ?>">
        <?php endif; ?>
        <?php if ($filterUserId): ?>
            <input type="hidden" name="user_id" value="<?= (int) $filterUserId ?>">
        <?php endif; ?>
        <select name="action" class="ui-input h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
            <option value=""><?= htmlspecialchars(t('admin.logs_all_actions')) ?></option>
            <?php foreach ($actionPrefixes as $prefix): ?>
                <option value="<?= htmlspecialchars($prefix) ?>" <?= $filterAction === $prefix ? 'selected' : '' ?>>
                    <?= htmlspecialchars(t('admin.log_cat_' . $prefix, [], $prefix)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
               placeholder="<?= htmlspecialchars(t('admin.logs_search')) ?>"
               class="ui-input flex-1 min-w-[200px] h-10 px-4 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
        <button type="submit" class="h-10 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold uppercase tracking-wider transition">
            <?= htmlspecialchars(t('admin.users_find')) ?>
        </button>
    </form>

    <?php if ($filterUserId): ?>
        <div class="text-xs text-gray-500">
            <?= htmlspecialchars(t('admin.logs_user_filter')) ?> #<?= (int) $filterUserId ?>
            · <a href="<?= htmlspecialchars($buildUrl(['user_id' => null, 'page' => 1])) ?>" class="text-brand-600 hover:underline"><?= htmlspecialchars(t('admin.logs_clear_user')) ?></a>
        </div>
    <?php endif; ?>

    <div class="overflow-x-auto bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft">
        <?php if (empty($logs)): ?>
            <div class="px-4 py-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('admin.logs_empty')) ?></div>
        <?php else: ?>
            <table class="w-full text-left text-xs">
                <thead class="bg-ink-50/80 dark:bg-white/[0.03] border-b border-black/[0.06] dark:border-white/10">
                    <tr>
                        <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.logs_when')) ?></th>
                        <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.logs_level')) ?></th>
                        <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.logs_who')) ?></th>
                        <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.logs_action')) ?></th>
                        <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.logs_message')) ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/[0.04] dark:divide-white/5">
                    <?php foreach ($logs as $row):
                        $lvl = (string) ($row['level'] ?? 'info');
                        $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
                        $ctx = null;
                        if (!empty($row['context_json'])) {
                            $decoded = json_decode((string) $row['context_json'], true);
                            $ctx = is_array($decoded) ? $decoded : null;
                        }
                    ?>
                    <tr class="align-top hover:bg-black/[0.015] dark:hover:bg-white/[0.02]">
                        <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                            <?= htmlspecialchars(date('d.m.Y H:i:s', strtotime((string) $row['created_at']))) ?>
                            <?php if (!empty($row['ip'])): ?>
                                <div class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars((string) $row['ip']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $levelClass($lvl) ?>">
                                <?= htmlspecialchars($levelLabel($lvl)) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($uid > 0): ?>
                                <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="font-semibold text-ink-800 dark:text-gray-200 hover:text-brand-600">
                                    <?= htmlspecialchars((string) ($row['user_name'] ?: ('#' . $uid))) ?>
                                </a>
                                <div class="text-[10px] text-gray-400 mt-0.5">#<?= $uid ?></div>
                            <?php else: ?>
                                <span class="text-gray-400"><?= htmlspecialchars(t('admin.logs_guest')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(ActivityLogger::actionLabel((string) $row['action'])) ?></div>
                            <div class="text-[10px] text-gray-400 mt-0.5 font-mono"><?= htmlspecialchars((string) $row['action']) ?></div>
                            <?php if (!empty($row['entity_type'])): ?>
                                <div class="text-[10px] text-gray-400 mt-0.5">
                                    <?= htmlspecialchars((string) $row['entity_type']) ?><?= !empty($row['entity_id']) ? ' #' . (int) $row['entity_id'] : '' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 max-w-md">
                            <div class="text-ink-800 dark:text-gray-200"><?= htmlspecialchars((string) $row['message']) ?></div>
                            <?php if ($ctx): ?>
                                <details class="mt-1">
                                    <summary class="cursor-pointer text-[10px] font-semibold text-brand-600"><?= htmlspecialchars(t('admin.logs_details')) ?></summary>
                                    <pre class="mt-1 text-[10px] text-gray-500 whitespace-pre-wrap break-all bg-black/[0.03] dark:bg-white/[0.04] rounded-lg p-2 max-h-40 overflow-auto"><?= htmlspecialchars(json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="flex flex-wrap items-center justify-center gap-2">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars($buildUrl(['page' => $page - 1])) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50"><?= htmlspecialchars(t('admin.logs_prev')) ?></a>
            <?php endif; ?>
            <span class="text-xs text-gray-500"><?= $page ?> / <?= $pages ?></span>
            <?php if ($page < $pages): ?>
                <a href="<?= htmlspecialchars($buildUrl(['page' => $page + 1])) ?>" class="h-9 px-3 inline-flex items-center rounded-xl text-xs font-semibold bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 hover:border-brand-400/50"><?= htmlspecialchars(t('admin.logs_next')) ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
