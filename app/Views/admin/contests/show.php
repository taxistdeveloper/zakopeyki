<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$c = $contest;
$id = (int) $c['id'];
$status = (string) $c['status'];
$card = 'rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-4 sm:p-5';
$in = 'flex-1 min-w-[220px] h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$roles = \App\Services\Contest\ContestService::actorRoles($c);
$can = static fn (string $to): bool => ContestPolicy::canTransition($status, $to, $roles);
$isOwn = in_array('organizer', $roles, true);
?>
<section class="max-w-4xl mx-auto space-y-5 fade-up pb-10">
    <div>
        <a href="<?= ProductHelper::url('/admin/contests') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('contests.admin.title')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars((string) $c['title']) ?></h1>
        <div class="flex flex-wrap items-center gap-2 mt-2 text-xs text-gray-500">
            <?= ContestView::statusBadge($status) ?>
            <span><?= htmlspecialchars(t('contests.mechanic.' . $c['mechanic'])) ?></span>
            <span>· <?= htmlspecialchars(ContestView::date($c['starts_at'])) ?> — <?= htmlspecialchars(ContestView::date($c['ends_at'])) ?></span>
            <a class="font-bold text-brand-600" href="<?= ProductHelper::url('/contests/' . $id . '/manage') ?>"><?= htmlspecialchars(t('contests.manage_title')) ?> →</a>
            <a class="font-bold text-brand-600" href="<?= ProductHelper::url('/contests/' . rawurlencode((string) $c['slug'])) ?>"><?= htmlspecialchars(t('contests.view_page')) ?> →</a>
        </div>
    </div>
    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="<?= $card ?> text-sm space-y-1">
        <h2 class="font-display font-bold mb-2"><?= htmlspecialchars(t('contests.organizer')) ?></h2>
        <p><a class="text-brand-600 font-semibold" href="<?= ProductHelper::url('/admin/users/' . (int) $c['organizer_id']) ?>"><?= htmlspecialchars((string) ($organizer['name'] ?? '')) ?></a> · <?= htmlspecialchars((string) ($organizer['email'] ?? '')) ?></p>
        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.admin.org_status', [
            'type' => (string) ($organizer['account_type'] ?? ''),
            'business' => (string) ($organizer['business_status'] ?? ''),
            'aml' => (string) ($organizer['aml_status'] ?? '—'),
            'email' => !empty($organizer['email_verified_at']) ? '✓' : '✗',
        ])) ?></p>
        <?php if (!empty($organizer['blocked_at'])): ?><p class="text-xs font-bold text-red-600"><?= htmlspecialchars(t('admin.user_block_title')) ?></p><?php endif; ?>
    </div>

    <!-- Модерация -->
    <div class="<?= $card ?> space-y-3">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.admin.moderation')) ?></h2>
        <?php if ($status === ContestPolicy::S_PENDING): ?>
            <?php if ($isOwn && !\App\Core\Auth::isAdmin()): ?>
                <p class="text-sm text-amber-700"><?= htmlspecialchars(t('contests.errors.self_moderation')) ?></p>
            <?php else: ?>
            <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/approve') ?>" class="flex flex-wrap gap-2">
                <?= csrf_field() ?>
                <input name="note" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.admin.note_optional')) ?>" class="<?= $in ?>">
                <button class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.admin.approve')) ?></button>
            </form>
            <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/reject') ?>" class="flex flex-wrap gap-2">
                <?= csrf_field() ?>
                <input name="note" required minlength="5" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.admin.reject_reason')) ?>" class="<?= $in ?>">
                <button class="h-10 px-4 rounded-xl border border-red-300 text-red-700 text-sm font-bold"><?= htmlspecialchars(t('contests.admin.reject')) ?></button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($can(ContestPolicy::S_SUSPENDED)): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/suspend') ?>" class="flex flex-wrap gap-2">
                <?= csrf_field() ?>
                <input name="note" required minlength="5" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.admin.suspend_reason')) ?>" class="<?= $in ?>">
                <button class="h-10 px-4 rounded-xl bg-orange-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.admin.suspend')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($status === ContestPolicy::S_SUSPENDED): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/resume') ?>">
                <?= csrf_field() ?>
                <button class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.admin.resume')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (\App\Core\Auth::isAdmin() && $can(ContestPolicy::S_CANCELLED)): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/cancel') ?>" class="flex flex-wrap gap-2" onsubmit="return confirm(<?= htmlspecialchars(js_encode(t('contests.cancel_confirm'))) ?>)">
                <?= csrf_field() ?>
                <input name="note" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.cancel_reason')) ?>" class="<?= $in ?>">
                <button class="h-10 px-4 rounded-xl bg-red-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.cancel')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (!empty($c['moderation_note'])): ?><p class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.moderator_note')) ?>: <?= htmlspecialchars((string) $c['moderation_note']) ?></p><?php endif; ?>
    </div>

    <?php if ($rules): ?>
    <div class="<?= $card ?> space-y-3 text-sm">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.rules')) ?> (v<?= (int) $rules['version'] ?>)</h2>
        <p><b><?= htmlspecialchars(t('contests.prizes')) ?>:</b> <?= htmlspecialchars(implode('; ', array_map(static fn ($p) => $p['place'] . '. ' . $p['title'], (array) $rules['prizes']))) ?></p>
        <p><b><?= htmlspecialchars(t('contests.conditions')) ?>:</b> <?= htmlspecialchars(implode(', ', array_map(static fn ($x) => t('contests.cond.' . $x['key'], ['days' => (int) ($x['days'] ?? 0)]), (array) $rules['conditions'])) ?: '—') ?></p>
        <?php if (trim((string) $c['description']) !== ''): ?><div class="whitespace-pre-line text-gray-600"><?= htmlspecialchars((string) $c['description']) ?></div><?php endif; ?>
        <div class="whitespace-pre-line rounded-xl bg-black/[0.03] dark:bg-white/[0.05] p-3"><?= htmlspecialchars((string) $rules['rules_text']) ?></div>
        <?php if (!empty($rules['criteria_text'])): ?><div class="whitespace-pre-line"><b><?= htmlspecialchars(t('contests.criteria')) ?>:</b> <?= htmlspecialchars((string) $rules['criteria_text']) ?></div><?php endif; ?>
        <p class="text-xs text-gray-400"><?= htmlspecialchars(t('contests.entry_counts', ['p' => (int) $counts['pending'], 'c' => (int) $counts['confirmed'], 'd' => (int) $counts['disqualified'], 'w' => (int) $counts['withdrawn']])) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($winners)): ?>
    <div class="<?= $card ?> space-y-2 text-sm">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.winners_fulfilment')) ?></h2>
        <?php if (!empty($execution)): ?><p class="text-[11px] text-gray-400 break-all"><?= htmlspecialchars((string) $execution['algorithm']) ?> · <?= htmlspecialchars((string) $execution['eligible_hash']) ?></p><?php endif; ?>
        <?php foreach ($winners as $w): ?>
            <div class="border-t border-black/[0.05] dark:border-white/10 pt-2">
                <p><b><?= (int) $w['place'] ?>.</b> <?= htmlspecialchars((string) ($w['user_name'] ?? '')) ?> — <?= htmlspecialchars((string) $w['prize_title']) ?> · <?= htmlspecialchars(t('contests.fulfilment.' . $w['fulfilment_status'])) ?></p>
                <?php if (!empty($w['fulfilment_note'])): ?><p class="text-xs text-gray-500"><?= htmlspecialchars((string) $w['fulfilment_note']) ?></p><?php endif; ?>
                <?php if ($w['fulfilment_status'] === 'disputed'): ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/' . $id . '/winners/' . (int) $w['id'] . '/resolve') ?>" class="flex flex-wrap gap-2 mt-2">
                        <?= csrf_field() ?>
                        <input name="note" required minlength="5" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.admin.resolution')) ?>" class="<?= $in ?>">
                        <button name="outcome" value="confirmed" class="h-10 px-3 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('contests.admin.dispute_delivered')) ?></button>
                        <button name="outcome" value="expired" class="h-10 px-3 rounded-xl border border-red-300 text-red-700 text-xs font-bold"><?= htmlspecialchars(t('contests.admin.dispute_not_delivered')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
    <div class="<?= $card ?> space-y-2 text-sm">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.reports')) ?></h2>
        <?php foreach ($reports as $r): ?>
            <div class="border-t border-black/[0.05] dark:border-white/10 pt-2 space-y-1">
                <p><?= htmlspecialchars(t('contests.report_reason.' . $r['reason'])) ?> · <?= htmlspecialchars((string) ($r['reporter_name'] ?? '')) ?> · <?= htmlspecialchars(t('contests.report_status.' . $r['status'])) ?></p>
                <?php if (!empty($r['details'])): ?><p class="text-xs text-gray-600 whitespace-pre-line"><?= htmlspecialchars((string) $r['details']) ?></p><?php endif; ?>
                <?php if ($r['status'] === 'open'): ?>
                    <form method="post" action="<?= ProductHelper::url('/admin/contests/reports/' . (int) $r['id'] . '/resolve') ?>" class="flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="contest_id" value="<?= $id ?>">
                        <input name="note" required minlength="3" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.admin.resolution')) ?>" class="<?= $in ?>">
                        <button name="status" value="resolved" class="h-10 px-3 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('contests.admin.report_resolve')) ?></button>
                        <button name="status" value="rejected" class="h-10 px-3 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('contests.admin.report_reject')) ?></button>
                    </form>
                <?php elseif (!empty($r['resolution_note'])): ?>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars((string) $r['resolution_note']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
