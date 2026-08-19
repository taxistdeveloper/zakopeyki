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

$filters = [
    'blocked' => t('admin.aml_filter_blocked'),
    'clear' => t('admin.aml_filter_clear'),
    'pending' => t('admin.aml_filter_pending'),
    'all' => t('admin.aml_filter_all'),
];
?>
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.aml')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.aml_hint')) ?></p>
        </div>
        <a href="<?= ProductHelper::url('/admin/logs?action=aml') ?>" class="text-xs font-bold text-brand-600 hover:underline">
            <?= htmlspecialchars(t('admin.aml_open_logs')) ?> →
        </a>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-red-200/70 dark:border-red-900/40 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-red-500"><?= htmlspecialchars(t('admin.aml_stat_blocked')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-red-700 dark:text-red-300"><?= (int) $amlUserStats['blocked'] ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-emerald-200/70 dark:border-emerald-900/40 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-emerald-600"><?= htmlspecialchars(t('admin.aml_stat_clear')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-emerald-700 dark:text-emerald-300"><?= (int) $amlUserStats['clear'] ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.aml_stat_iin')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= (int) $amlUserStats['with_iin'] ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.aml_stat_list')) ?></div>
            <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= (int) $amlListStats['list_count'] ?></div>
            <div class="text-[10px] text-gray-400 mt-1">
                <?= htmlspecialchars($amlListStats['list_updated'] ? t('admin.aml_list_updated', ['when' => substr((string) $amlListStats['list_updated'], 0, 16)]) : t('admin.aml_list_empty')) ?>
            </div>
        </div>
    </div>

    <form method="get" action="<?= ProductHelper::url('/admin/aml') ?>" class="flex flex-wrap gap-2">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <input type="search" name="q" value="<?= htmlspecialchars($searchQuery) ?>"
               placeholder="<?= htmlspecialchars(t('admin.aml_search')) ?>"
               class="ui-input flex-1 min-w-[12rem] h-10 px-3 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
        <button type="submit" class="h-10 px-4 rounded-xl bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold uppercase tracking-wider">
            <?= htmlspecialchars(t('admin.users_find')) ?>
        </button>
    </form>

    <div class="flex flex-wrap gap-2">
        <?php foreach ($filters as $key => $label):
            $on = $filterStatus === $key;
        ?>
            <a href="<?= ProductHelper::url('/admin/aml?status=' . urlencode($key) . ($searchQuery !== '' ? '&q=' . urlencode($searchQuery) : '')) ?>"
               class="h-8 px-3 inline-flex items-center rounded-xl text-[11px] font-bold uppercase tracking-wide border transition <?= $on
                   ? 'bg-brand-600 text-white border-brand-600'
                   : 'border-black/[0.08] dark:border-white/10 text-gray-500 hover:border-brand-400/50' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($users)): ?>
        <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('admin.aml_empty')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft divide-y divide-black/[0.04] dark:divide-white/5">
            <?php foreach ($users as $u):
                $uid = (int) $u['id'];
                $displayName = trim((string) ($u['name'] ?? '')) ?: ((string) ($u['login'] ?? 'User'));
                $st = (string) ($u['aml_status'] ?? '');
            ?>
                <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                    <div class="min-w-0 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 flex items-center justify-center text-xs font-bold flex-shrink-0">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($displayName, 0, 1))) ?>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-ink-900 dark:text-white truncate">#<?= $uid ?> · <?= htmlspecialchars($displayName) ?></p>
                            <p class="text-[11px] text-gray-400 mt-0.5 truncate">
                                <?= htmlspecialchars((string) ($u['email'] ?? '')) ?>
                                · <?= htmlspecialchars(t('admin.aml_iin')) ?> <?= htmlspecialchars(AMLService::maskIin($u['iin'] ?? null)) ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if ($st === 'AML_BLOCKED'): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">AML_BLOCKED</span>
                        <?php elseif ($st === 'clear'): ?>
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"><?= htmlspecialchars(t('admin.aml_badge_clear')) ?></span>
                        <?php else: ?>
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-gray-100 text-gray-500 dark:bg-white/10"><?= htmlspecialchars(t('admin.aml_badge_pending')) ?></span>
                        <?php endif; ?>
                        <span class="text-[10px] text-gray-400"><?= htmlspecialchars(substr((string) ($u['aml_checked_at'] ?? ''), 0, 16) ?: '—') ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft">
        <div class="px-4 py-3.5 border-b border-black/[0.06] dark:border-white/10">
            <h2 class="font-display font-bold text-ink-900 dark:text-white text-sm"><?= htmlspecialchars(t('admin.aml_events')) ?></h2>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.aml_events_hint')) ?></p>
        </div>
        <?php if (empty($amlEvents)): ?>
            <div class="px-4 py-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('admin.aml_events_empty')) ?></div>
        <?php else: ?>
            <div class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($amlEvents as $ev):
                    $eid = (int) ($ev['user_id'] ?? $ev['entity_id'] ?? 0);
                    $lvl = (string) ($ev['level'] ?? 'info');
                    $lvlClass = match ($lvl) {
                        'error' => 'text-red-600',
                        'warning' => 'text-amber-600',
                        default => 'text-emerald-700',
                    };
                ?>
                    <div class="px-4 py-3 flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold <?= $lvlClass ?>">
                                <?= htmlspecialchars(ActivityLogger::actionLabel((string) ($ev['action'] ?? ''))) ?>
                            </p>
                            <p class="text-[11px] text-gray-500 mt-0.5"><?= htmlspecialchars((string) ($ev['message'] ?? '')) ?></p>
                            <?php if ($eid > 0): ?>
                                <a href="<?= ProductHelper::url('/admin/users/' . $eid) ?>" class="text-[11px] font-semibold text-brand-600 hover:underline">
                                    <?= htmlspecialchars((string) ($ev['user_name'] ?? ('#' . $eid))) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <span class="text-[10px] text-gray-400 flex-shrink-0"><?= htmlspecialchars(substr((string) ($ev['created_at'] ?? ''), 0, 16)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
