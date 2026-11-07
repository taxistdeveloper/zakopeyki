<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$items = $items ?? [];
$counts = $counts ?? [];
$settings = $settings ?? [];
$card = 'rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-4 sm:p-5';
?>
<section class="max-w-6xl mx-auto space-y-5 fade-up pb-10">
    <div>
        <a href="<?= ProductHelper::url('/admin') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('admin.title')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars(t('contests.admin.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('contests.admin.hint')) ?></p>
    </div>
    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($isAdmin)): ?>
    <form method="post" action="<?= ProductHelper::url('/admin/contests/settings') ?>" class="<?= $card ?> space-y-4">
        <?= csrf_field() ?>
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.admin.settings')) ?></h2>
        <label class="flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" name="enabled" value="1" <?= !empty($settings['enabled']) ? 'checked' : '' ?>>
            <?= htmlspecialchars(t('contests.admin.module_enabled')) ?>
        </label>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800/40 p-4 space-y-2">
            <p class="text-sm font-bold text-amber-900 dark:text-amber-200"><?= htmlspecialchars(t('contests.admin.mechanics')) ?></p>
            <?php foreach (ContestPolicy::MECHANICS as $m): ?>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="method_<?= $m ?>" value="1" class="mt-1" <?= !empty($settings['mechanics'][$m]) ? 'checked' : '' ?>>
                    <span><b><?= htmlspecialchars(t('contests.mechanic.' . $m)) ?></b> — <?= htmlspecialchars(t('contests.mechanic_hint.' . $m)) ?></span>
                </label>
            <?php endforeach; ?>
            <p class="text-xs text-amber-800 dark:text-amber-300"><?= htmlspecialchars(t('contests.admin.mechanics_legal')) ?></p>
            <label class="flex items-start gap-2 text-xs font-semibold text-amber-900 dark:text-amber-200">
                <input type="checkbox" name="legal_ack" value="1" class="mt-0.5">
                <span><?= htmlspecialchars(t('contests.admin.legal_ack')) ?></span>
            </label>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.admin.not_implemented')) ?></p>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="block text-sm">
                <span class="block font-semibold mb-1"><?= htmlspecialchars(t('contests.admin.organizer_mode')) ?></span>
                <select name="organizer_mode" class="w-full h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5">
                    <?php foreach (ContestPolicy::ORGANIZER_MODES as $mode): ?>
                        <option value="<?= $mode ?>" <?= ($settings['organizer_mode'] ?? '') === $mode ? 'selected' : '' ?>><?= htmlspecialchars(t('contests.admin.organizer_mode_' . $mode)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm pt-6">
                <input type="checkbox" name="require_verified_email" value="1" <?= !empty($settings['require_verified_email']) ? 'checked' : '' ?>>
                <?= htmlspecialchars(t('contests.admin.require_email')) ?>
            </label>
        </div>
        <button class="h-10 px-5 rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('contests.admin.save')) ?></button>
    </form>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('contests.admin.reports_queue')) ?> (<?= count($reports) ?>)</h2>
        <ul class="divide-y divide-black/[0.05] dark:divide-white/10 text-sm">
            <?php foreach ($reports as $r): ?>
                <li class="py-3 space-y-2">
                    <p><a class="font-semibold text-brand-600 hover:underline" href="<?= ProductHelper::url('/admin/contests/' . (int) $r['contest_id']) ?>"><?= htmlspecialchars((string) $r['contest_title']) ?></a>
                        · <?= htmlspecialchars(t('contests.report_reason.' . $r['reason'])) ?> · <?= htmlspecialchars((string) ($r['reporter_name'] ?? '')) ?> · <span class="text-gray-400"><?= htmlspecialchars(ContestView::date($r['created_at'])) ?></span></p>
                    <?php if (!empty($r['details'])): ?><p class="text-xs text-gray-600 whitespace-pre-line"><?= htmlspecialchars((string) $r['details']) ?></p><?php endif; ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/reports/' . (int) $r['id'] . '/resolve') ?>" class="flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input name="note" required minlength="3" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.admin.resolution')) ?>" class="flex-1 min-w-[200px] h-9 px-3 rounded-xl border border-black/10 bg-white dark:bg-white/5 text-xs">
                        <button name="status" value="resolved" class="h-9 px-3 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('contests.admin.report_resolve')) ?></button>
                        <button name="status" value="rejected" class="h-9 px-3 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('contests.admin.report_reject')) ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="<?= $card ?>">
        <div class="flex flex-wrap gap-1 text-[11px] mb-3">
            <a href="<?= ProductHelper::url('/admin/contests') ?>" class="px-2 py-1 rounded-lg <?= ($statusFilter ?? '') === '' ? 'bg-brand-500 text-white' : 'bg-black/[0.04] dark:bg-white/10' ?>"><?= htmlspecialchars(t('contests.entry_filter.all')) ?></a>
            <?php foreach (ContestPolicy::STATUSES as $s): ?>
                <a href="<?= ProductHelper::url('/admin/contests?status=' . $s) ?>" class="px-2 py-1 rounded-lg <?= ($statusFilter ?? '') === $s ? 'bg-brand-500 text-white' : 'bg-black/[0.04] dark:bg-white/10' ?>"><?= htmlspecialchars(t('contests.status.' . $s)) ?> (<?= (int) ($counts[$s] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </div>
        <?php if ($items === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.empty')) ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-[11px] text-gray-400"><th class="py-2">#</th><th><?= htmlspecialchars(t('contests.f.title')) ?></th><th><?= htmlspecialchars(t('contests.organizer')) ?></th><th><?= htmlspecialchars(t('contests.status_label')) ?></th><th><?= htmlspecialchars(t('contests.participants')) ?></th><th><?= htmlspecialchars(t('contests.reports')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $c): ?>
                <tr class="border-t border-black/[0.05] dark:border-white/10">
                    <td class="py-2 text-gray-400"><?= (int) $c['id'] ?></td>
                    <td class="py-2"><a class="font-semibold hover:text-brand-600" href="<?= ProductHelper::url('/admin/contests/' . (int) $c['id']) ?>"><?= htmlspecialchars((string) $c['title']) ?></a><br><span class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.mechanic.' . $c['mechanic'])) ?> · <?= htmlspecialchars(ContestView::date($c['starts_at'])) ?> — <?= htmlspecialchars(ContestView::date($c['ends_at'])) ?></span></td>
                    <td class="py-2 text-xs"><?= htmlspecialchars((string) $c['organizer_name']) ?></td>
                    <td class="py-2"><?= ContestView::statusBadge((string) $c['status']) ?></td>
                    <td class="py-2 tabular-nums"><?= (int) $c['entries_count'] ?></td>
                    <td class="py-2 tabular-nums <?= (int) $c['open_reports'] > 0 ? 'text-red-600 font-bold' : 'text-gray-400' ?>"><?= (int) $c['open_reports'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</section>
