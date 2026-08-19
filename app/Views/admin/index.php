<?php
use App\Helpers\ProductHelper;

$canTickets = !empty($canTickets);
$canAi = !empty($canAi);
$canProducts = !empty($canProducts);
$canDisputes = !empty($canDisputes);
$isAdmin = !empty($isAdmin);
$counts = $counts ?? [];
$items = $items ?? [];
$hasNav = $canTickets || $canAi || $isAdmin;
$recentErrors = (int) ($recentErrors ?? 0);
$stubMode = !empty($stubMode);
?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500 mb-1"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.heading')) ?></h2>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/25 text-red-800 dark:text-red-300 border border-red-100 dark:border-red-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($isAdmin):
        $userStats = $userStats ?? [];
        $visitStats = $visitStats ?? [];
        $recentVisitors = $recentVisitors ?? [];
        $statTotal = (int) ($userStats['total'] ?? $userCount ?? 0);
        $statToday = (int) ($userStats['today'] ?? 0);
        $statWeek = (int) ($userStats['week'] ?? 0);
        $visitorsToday = (int) ($visitStats['visitors_today'] ?? 0);
        $visitorsWeek = (int) ($visitStats['visitors_week'] ?? 0);
        $visitorsTotal = (int) ($visitStats['visitors_total'] ?? 0);
        $hitsToday = (int) ($visitStats['hits_today'] ?? 0);
    ?>
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400 mb-2"><?= htmlspecialchars(t('admin.stats_visits_heading')) ?></p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_visitors_today')) ?></div>
                <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $visitorsToday ?></div>
                <div class="text-[10px] text-gray-400 mt-1"><?= $hitsToday ?> <?= htmlspecialchars(t('admin.stats_hits_label')) ?></div>
            </div>
            <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_visitors_week')) ?></div>
                <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $visitorsWeek ?></div>
            </div>
            <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_visitors_total')) ?></div>
                <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $visitorsTotal ?></div>
            </div>
            <a href="<?= ProductHelper::url('/admin/users') ?>" class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft hover:border-brand-400/50 transition block">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.stats_users_total')) ?></div>
                <div class="font-display text-2xl font-bold mt-1 text-ink-900 dark:text-white"><?= $statTotal ?></div>
                <div class="text-[10px] text-gray-400 mt-1">+<?= $statToday ?> <?= htmlspecialchars(t('admin.stats_today_short')) ?> · +<?= $statWeek ?> <?= htmlspecialchars(t('admin.stats_week_short')) ?></div>
            </a>
        </div>
    </div>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft overflow-hidden">
        <div class="px-4 py-3.5 border-b border-black/[0.06] dark:border-white/10">
            <h3 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.stats_recent_visitors')) ?></h3>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.stats_recent_visitors_hint')) ?></p>
        </div>
        <?php if (empty($recentVisitors)): ?>
            <div class="px-4 py-10 text-center text-sm text-gray-400"><?= htmlspecialchars(t('admin.stats_visitors_empty')) ?></div>
        <?php else: ?>
            <div class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($recentVisitors as $v):
                    $uid = (int) ($v['user_id'] ?? 0);
                    $name = trim((string) ($v['user_name'] ?? ''));
                    $label = $name !== '' ? $name : t('admin.stats_guest');
                    $path = (string) ($v['path'] ?? '/');
                    $ip = (string) ($v['ip'] ?? '');
                    $when = (string) ($v['last_seen_at'] ?? '');
                    $hits = (int) ($v['hits'] ?? 1);
                ?>
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <div class="min-w-0 flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl <?= $uid > 0 ? 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300' : 'bg-gray-100 text-gray-500 dark:bg-white/10' ?> flex items-center justify-center text-[11px] font-bold flex-shrink-0">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($label, 0, 1))) ?>
                            </div>
                            <div class="min-w-0">
                                <?php if ($uid > 0): ?>
                                    <a href="<?= ProductHelper::url('/admin/users/' . $uid) ?>" class="text-xs font-semibold text-ink-900 dark:text-white hover:text-brand-600 truncate block">
                                        <?= htmlspecialchars($label) ?>
                                    </a>
                                <?php else: ?>
                                    <p class="text-xs font-semibold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($label) ?></p>
                                <?php endif; ?>
                                <p class="text-[11px] text-gray-400 mt-0.5 truncate">
                                    <?= htmlspecialchars($path) ?>
                                    <?php if ($ip !== ''): ?> · <?= htmlspecialchars($ip) ?><?php endif; ?>
                                    · <?= $hits ?> <?= htmlspecialchars(t('admin.stats_hits_label')) ?>
                                </p>
                            </div>
                        </div>
                        <span class="text-[10px] text-gray-400 flex-shrink-0"><?= htmlspecialchars(substr($when, 0, 16)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="rounded-[22px] border shadow-soft overflow-hidden <?= $stubMode
        ? 'bg-amber-50/90 dark:bg-amber-950/30 border-amber-200/80 dark:border-amber-800/40'
        : 'bg-emerald-50/90 dark:bg-emerald-950/30 border-emerald-200/80 dark:border-emerald-800/40' ?>">
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4">
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] <?= $stubMode ? 'text-amber-600' : 'text-emerald-600' ?>">
                    <?= htmlspecialchars(t('admin.site_status')) ?>
                </p>
                <h3 class="font-display font-bold text-ink-900 dark:text-white mt-0.5">
                    <?= htmlspecialchars($stubMode ? t('admin.site_closed') : t('admin.site_open')) ?>
                </h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.site_status_hint')) ?></p>
            </div>
            <form method="post" action="<?= ProductHelper::url('/admin/site-status') ?>" class="shrink-0">
                <?= csrf_field() ?>
                <input type="hidden" name="open" value="<?= $stubMode ? '1' : '0' ?>">
                <button type="submit"
                    class="h-10 px-4 rounded-xl text-xs font-bold text-white transition <?= $stubMode
                        ? 'bg-emerald-600 hover:bg-emerald-700'
                        : 'bg-amber-600 hover:bg-amber-700' ?>"
                    onclick="return confirm(<?= json_encode($stubMode ? t('admin.site_open_confirm') : t('admin.site_close_confirm')) ?>)">
                    <?= htmlspecialchars($stubMode ? t('admin.site_open_action') : t('admin.site_close_action')) ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasNav): ?>
    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-brand-200/70 dark:border-brand-900/40 shadow-soft overflow-hidden">
        <?php if ($canTickets): ?>
        <a href="<?= ProductHelper::url('/admin/tickets') ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-brand-800 dark:text-brand-300"><?= htmlspecialchars(t('admin.tickets')) ?></h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.tickets_hint')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!empty($ticketUnread)): ?>
                    <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-accent-500 text-white text-[10px] font-bold flex items-center justify-center"><?= (int) $ticketUnread > 99 ? '99+' : (int) $ticketUnread ?></span>
                <?php endif; ?>
                <span class="text-xs font-bold text-brand-600"><?= (int) ($openTickets ?? 0) ?> →</span>
            </div>
        </a>
        <?php endif; ?>
        <?php if ($canAi): ?>
        <a href="<?= ProductHelper::url('/admin/ai-chats') ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 <?= $canTickets ? 'border-t border-brand-100 dark:border-brand-900/30' : '' ?> hover:bg-violet-50/40 dark:hover:bg-white/[0.03] transition">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-violet-800 dark:text-violet-300"><?= htmlspecialchars(t('admin.ai_chats')) ?></h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.ai_chats_hint')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!empty($aiEscalated)): ?>
                    <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-amber-500 text-white text-[10px] font-bold flex items-center justify-center"><?= (int) $aiEscalated > 99 ? '99+' : (int) $aiEscalated ?></span>
                <?php endif; ?>
                <span class="text-xs font-bold text-violet-600"><?= (int) ($aiEscalated ?? 0) ?> →</span>
            </div>
        </a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <a href="<?= ProductHelper::url('/admin/users') ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 <?= ($canTickets || $canAi) ? 'border-t border-brand-100 dark:border-brand-900/30' : '' ?> hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-ink-800 dark:text-gray-200"><?= htmlspecialchars(t('admin.users')) ?></h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.users_hint')) ?></p>
            </div>
            <span class="text-xs font-bold text-brand-600"><?= (int) ($userCount ?? 0) ?> →</span>
        </a>
        <a href="<?= ProductHelper::url('/admin/aml') ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 border-t border-brand-100 dark:border-brand-900/30 hover:bg-red-50/40 dark:hover:bg-white/[0.03] transition">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-red-800 dark:text-red-300"><?= htmlspecialchars(t('admin.aml')) ?></h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.aml_hint')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!empty($amlBlockedCount)): ?>
                    <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"><?= (int) $amlBlockedCount > 99 ? '99+' : (int) $amlBlockedCount ?></span>
                <?php endif; ?>
                <span class="text-xs font-bold text-red-600"><?= htmlspecialchars(t('admin.aml_open')) ?> →</span>
            </div>
        </a>
        <a href="<?= ProductHelper::url('/admin/gig-categories') ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 border-t border-brand-100 dark:border-brand-900/30 hover:bg-teal-50/40 dark:hover:bg-white/[0.03] transition">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-teal-800 dark:text-teal-300"><?= htmlspecialchars(t('admin.gig_categories')) ?></h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.gig_categories_hint')) ?></p>
            </div>
            <span class="text-xs font-bold text-teal-600"><?= htmlspecialchars(t('admin.gig_categories_open')) ?> →</span>
        </a>
        <a href="<?= ProductHelper::url('/admin/logs') ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 border-t border-brand-100 dark:border-brand-900/30 hover:bg-red-50/40 dark:hover:bg-white/[0.03] transition">
            <div class="min-w-0">
                <h3 class="font-display font-bold text-red-800 dark:text-red-300"><?= htmlspecialchars(t('admin.logs')) ?></h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.logs_hint')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php if ($recentErrors > 0): ?>
                    <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $recentErrors > 99 ? '99+' : $recentErrors ?></span>
                <?php endif; ?>
                <span class="text-xs font-bold text-red-600"><?= htmlspecialchars(t('admin.logs_open')) ?> →</span>
            </div>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($canDisputes && !empty($disputes)): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-violet-200/70 dark:border-violet-900/40 shadow-soft overflow-hidden">
            <div class="px-4 py-3.5 border-b border-violet-100 dark:border-violet-900/30 bg-violet-50/50 dark:bg-violet-950/20">
                <h3 class="font-display font-bold text-violet-800 dark:text-violet-300"><?= htmlspecialchars(t('admin.disputes')) ?> (<?= count($disputes) ?>)</h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.disputes_hint')) ?></p>
            </div>
            <div class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($disputes as $d): ?>
                    <a href="<?= ProductHelper::url('/orders/' . (int) $d['id']) ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 hover:bg-violet-50/40 dark:hover:bg-white/[0.03] transition">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-ink-900 dark:text-white">#<?= (int) $d['id'] ?> · <?= htmlspecialchars($d['product_title']) ?></p>
                            <p class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars($d['buyer_name']) ?> ↔ <?= htmlspecialchars($d['seller_name']) ?></p>
                        </div>
                        <span class="text-xs font-bold text-violet-600"><?= htmlspecialchars(t('admin.resolve')) ?> →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canProducts): ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.active_lots')) ?></div>
            <div class="font-display text-2xl font-bold mt-1"><?= array_sum($counts) ?></div>
        </div>
        <?php $i = 0; foreach ($counts as $type => $cnt): if ($i++ >= 3) break; ?>
            <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 truncate"><?= ProductHelper::label($type) ?></div>
                <div class="font-display text-2xl font-bold mt-1"><?= (int) $cnt ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($canProducts): ?>
    <div class="overflow-x-auto bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft">
        <table class="w-full text-left text-xs">
            <thead class="bg-ink-50/80 dark:bg-white/[0.03] border-b border-black/[0.06] dark:border-white/10">
                <tr>
                    <th class="px-4 py-3.5 font-semibold text-gray-500">ID</th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.name')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.type')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.status')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.price')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.actions')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($items as $item): ?>
                    <tr class="hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                        <td class="px-4 py-3.5 text-gray-400"><?= (int) $item['id'] ?></td>
                        <td class="px-4 py-3.5 font-semibold max-w-[220px] truncate text-ink-800 dark:text-gray-200"><?= htmlspecialchars($item['title']) ?></td>
                        <td class="px-4 py-3.5"><?= ProductHelper::label($item['type']) ?></td>
                        <td class="px-4 py-3.5">
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $item['status'] === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-white/10' ?>"><?= $item['status'] ?></span>
                        </td>
                        <td class="px-4 py-3.5 font-display font-bold"><?= htmlspecialchars(ProductHelper::formatPrice($item)) ?></td>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <a href="<?= ProductHelper::url('/product/' . $item['id']) ?>" class="text-brand-600 hover:underline font-semibold"><?= htmlspecialchars(t('admin.open')) ?></a>
                                <form method="post" action="<?= ProductHelper::url('/admin/toggle/' . $item['id']) ?>" class="inline">
                                    <button class="text-amber-600 hover:underline font-semibold"><?= htmlspecialchars(t('admin.archive')) ?></button>
                                </form>
                                <form method="post" action="<?= ProductHelper::url('/admin/delete/' . $item['id']) ?>" class="inline" onsubmit="return confirm(<?= json_encode(t('admin.confirm_delete')) ?>)">
                                    <button class="text-red-600 hover:underline font-semibold"><?= htmlspecialchars(t('admin.delete')) ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif (!$hasNav && empty($disputes)): ?>
        <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('admin.manager_no_access')) ?>
        </div>
    <?php endif; ?>
</section>
