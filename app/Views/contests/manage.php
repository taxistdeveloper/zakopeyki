<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$c = $contest;
$id = (int) $c['id'];
$status = (string) $c['status'];
$roles = $roles ?? [];
$isOrganizer = in_array('organizer', $roles, true);
$isAdmin = in_array('admin', $roles, true);
$card = 'rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-4 sm:p-5';
$small = 'h-8 px-3 rounded-lg text-[11px] font-bold';
$confirmed = array_values(array_filter($entries ?? [], static fn (array $e): bool => $e['status'] === 'confirmed'));
$canReview = in_array($status, [ContestPolicy::S_ACTIVE, ContestPolicy::S_CLOSED, ContestPolicy::S_SUSPENDED], true);
$f = $funnel ?? [];
?>
<section class="max-w-5xl mx-auto space-y-5 fade-up pb-10">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="<?= ProductHelper::url('/contests/my') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('contests.my_title')) ?></a>
            <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars((string) $c['title']) ?></h1>
            <div class="flex flex-wrap items-center gap-2 mt-2">
                <?= ContestView::statusBadge($status) ?>
                <span class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.mechanic.' . $c['mechanic'])) ?> · <?= htmlspecialchars(ContestView::date($c['starts_at'])) ?> — <?= htmlspecialchars(ContestView::date($c['ends_at'])) ?></span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= ProductHelper::url('/contests/' . rawurlencode((string) $c['slug'])) ?>" class="h-10 px-4 inline-flex items-center rounded-xl border border-black/10 dark:border-white/10 text-sm font-semibold"><?= htmlspecialchars(t('contests.view_page')) ?></a>
            <?php if (($isOrganizer || $isAdmin) && ContestPolicy::isEditable($status)): ?>
                <a href="<?= ProductHelper::url('/contests/' . $id . '/edit') ?>" class="h-10 px-4 inline-flex items-center rounded-xl border border-black/10 dark:border-white/10 text-sm font-semibold"><?= htmlspecialchars(t('contests.edit')) ?></a>
            <?php endif; ?>
            <?php if (in_array('moderator', $roles, true)): ?>
                <a href="<?= ProductHelper::url('/admin/contests/' . $id) ?>" class="h-10 px-4 inline-flex items-center rounded-xl border border-black/10 dark:border-white/10 text-sm font-semibold"><?= htmlspecialchars(t('contests.admin.open')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($c['moderation_note']) && in_array($status, ['rejected', 'scheduled', 'active'], true)): ?>
        <div class="bg-amber-50 text-amber-800 border border-amber-200 px-4 py-3 rounded-2xl text-sm"><b><?= htmlspecialchars(t('contests.moderator_note')) ?>:</b> <?= htmlspecialchars((string) $c['moderation_note']) ?></div>
    <?php endif; ?>
    <?php if (!empty($c['suspended_reason']) && in_array($status, ['suspended', 'cancelled'], true)): ?>
        <div class="bg-orange-50 text-orange-800 border border-orange-200 px-4 py-3 rounded-2xl text-sm"><b><?= htmlspecialchars(t('contests.reason')) ?>:</b> <?= htmlspecialchars((string) $c['suspended_reason']) ?></div>
    <?php endif; ?>
    <?php if (empty($mechanicEnabled) && !in_array($status, ['completed', 'cancelled'], true)): ?>
        <div class="bg-amber-50 text-amber-800 border border-amber-200 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars(t('contests.mechanic_off_notice')) ?></div>
    <?php endif; ?>

    <!-- Действия -->
    <?php if (($isOrganizer || $isAdmin) && in_array($status, ContestPolicy::EDITABLE_STATUSES, true)): ?>
        <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/submit') ?>" class="<?= $card ?> space-y-3">
            <?= csrf_field() ?>
            <p class="text-sm"><?= htmlspecialchars(t('contests.submit_hint')) ?></p>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="organizer_terms" value="1" required class="mt-1"> <span><?= htmlspecialchars(t('contests.f.organizer_terms')) ?></span></label>
            <button class="h-10 px-4 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.f.submit')) ?></button>
        </form>
    <?php endif; ?>

    <!-- Аналитика -->
    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('contests.analytics')) ?></h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <?php foreach ([
                'unique_visitors' => $f['unique_visitors'] ?? 0,
                'page_views' => $f['page_views'] ?? 0,
                'ref_visits' => $f['ref_visits'] ?? 0,
                'ref_registrations' => $f['ref_registrations'] ?? 0,
                'entries_total' => $f['entries_total'] ?? 0,
                'confirmed' => $f['entries']['confirmed'] ?? 0,
                'referred_participants' => $f['referred_participants'] ?? 0,
                'new_followers' => $f['new_followers'] ?? 0,
            ] as $k => $val): ?>
                <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.05] p-3">
                    <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.metric.' . $k)) ?></p>
                    <p class="font-display text-xl font-bold tabular-nums"><?= (int) $val ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="text-[11px] text-gray-400 mt-3"><?= htmlspecialchars(t('contests.analytics_note')) ?></p>
        <?php if (!empty($f['top_referrers'])): ?>
            <h3 class="text-sm font-bold mt-4 mb-2"><?= htmlspecialchars(t('contests.top_referrers')) ?></h3>
            <ul class="text-sm space-y-1">
                <?php foreach ($f['top_referrers'] as $r): ?>
                    <li><?= htmlspecialchars(ContestView::publicWinnerName($r['name'] ?? null)) ?> — <?= (int) $r['visits'] ?> / <?= (int) $r['registrations'] ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Распространение -->
    <?php if (ContestPolicy::isPublicStatus($status)): ?>
    <div class="<?= $card ?> flex flex-wrap items-center gap-4">
        <img src="<?= ProductHelper::url('/contests/' . $id . '/qr.svg') ?>" alt="QR" width="120" height="120" class="rounded-xl border border-black/10 bg-white">
        <div class="space-y-2 min-w-0 flex-1">
            <p class="text-sm font-bold"><?= htmlspecialchars(t('contests.share')) ?></p>
            <input readonly value="<?= htmlspecialchars((string) $publicUrl) ?>" class="w-full h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-black/[0.02] text-xs">
            <a href="<?= ProductHelper::url('/contests/' . $id . '/qr.svg') ?>" download="contest-<?= $id ?>-qr.svg" class="text-xs font-bold text-brand-600"><?= htmlspecialchars(t('contests.qr_download')) ?></a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Участники -->
    <div class="<?= $card ?>" id="entries">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.entries')) ?></h2>
            <div class="flex flex-wrap gap-1 text-[11px]">
                <?php foreach (['' => 'all', 'pending' => 'pending', 'confirmed' => 'confirmed', 'disqualified' => 'disqualified', 'withdrawn' => 'withdrawn'] as $k => $lbl): ?>
                    <a href="<?= ProductHelper::url('/contests/' . $id . '/manage' . ($k !== '' ? '?entries=' . $k : '')) ?>#entries" class="px-2 py-1 rounded-lg <?= ($entryFilter ?? '') === $k ? 'bg-brand-500 text-white' : 'bg-black/[0.04] dark:bg-white/10' ?>">
                        <?= htmlspecialchars(t('contests.entry_filter.' . $lbl)) ?><?= $k !== '' ? ' (' . (int) ($counts[$k] ?? 0) . ')' : '' ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (($entries ?? []) === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.no_entries')) ?></p>
        <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-[11px] text-gray-400"><th class="py-2">#</th><th><?= htmlspecialchars(t('contests.participant')) ?></th><th><?= htmlspecialchars(t('contests.status_label')) ?></th><th><?= htmlspecialchars(t('contests.flags')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($entries as $e): ?>
                    <tr class="border-t border-black/[0.05] dark:border-white/10 align-top">
                        <td class="py-2 tabular-nums text-gray-400"><?= (int) $e['id'] ?></td>
                        <td class="py-2">
                            <p class="font-semibold"><?= htmlspecialchars($e['user_name'] !== null ? (string) $e['user_name'] : t('contests.anonymous')) ?></p>
                            <p class="text-[11px] text-gray-400"><?= htmlspecialchars(ContestView::date($e['created_at'])) ?></p>
                            <?php if (!empty($e['submission_text'])): ?>
                                <details class="mt-1"><summary class="text-[11px] text-brand-600 cursor-pointer"><?= htmlspecialchars(t('contests.submission')) ?></summary><p class="text-xs whitespace-pre-line mt-1"><?= htmlspecialchars((string) $e['submission_text']) ?></p></details>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 text-xs"><?= htmlspecialchars(t('contests.entry_status.' . $e['status'])) ?><?php if (!empty($e['status_reason'])): ?><br><span class="text-gray-400"><?= htmlspecialchars((string) $e['status_reason']) ?></span><?php endif; ?></td>
                        <td class="py-2 text-[11px] text-amber-700"><?php foreach (array_filter(explode(',', (string) $e['fraud_flags'])) as $fl): ?><span class="block"><?= htmlspecialchars(t('contests.flag.' . $fl, [], $fl)) ?></span><?php endforeach; ?></td>
                        <td class="py-2">
                            <?php if ($canReview): ?>
                                <div class="flex flex-col gap-1 items-end">
                                    <?php if ($status === ContestPolicy::S_CLOSED && $e['status'] === 'pending'): ?>
                                        <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/entries/' . (int) $e['id'] . '/review') ?>">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
                                            <button class="<?= $small ?> bg-emerald-600 text-white"><?= htmlspecialchars(t('contests.entry_confirm')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($e['status'], ['pending', 'confirmed'], true)): ?>
                                        <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/entries/' . (int) $e['id'] . '/review') ?>" class="flex gap-1">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="disqualify">
                                            <input name="reason" required minlength="5" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.reason')) ?>" class="w-36 h-8 px-2 rounded-lg border border-black/10 text-[11px] bg-white dark:bg-white/5">
                                            <button class="<?= $small ?> border border-red-300 text-red-700"><?= htmlspecialchars(t('contests.entry_disqualify')) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Подведение итогов -->
    <?php if ($status === ContestPolicy::S_CLOSED && ($isOrganizer || $isAdmin)): ?>
    <div class="<?= $card ?> space-y-3" id="results">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.results_execute')) ?></h2>
        <?php if (($counts['pending'] ?? 0) > 0): ?>
            <p class="text-sm text-amber-700"><?= htmlspecialchars(t('contests.results_pending_review', ['n' => (int) $counts['pending']])) ?></p>
        <?php elseif ($c['mechanic'] === ContestPolicy::MECHANIC_RANDOM): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.results_random_hint', ['n' => (int) ($counts['confirmed'] ?? 0)])) ?></p>
            <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/results') ?>" onsubmit="return confirm(<?= htmlspecialchars(js_encode(t('contests.results_confirm'))) ?>)">
                <?= csrf_field() ?>
                <button class="h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-bold" <?= empty($mechanicEnabled) ? 'disabled' : '' ?>><?= htmlspecialchars(t('contests.results_random_btn')) ?></button>
            </form>
        <?php else: ?>
            <?php $places = min((int) $c['winners_count'], count($confirmed)); ?>
            <?php if ($places === 0): ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.errors.no_eligible')) ?></p>
            <?php else: ?>
            <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/results') ?>" class="space-y-3" onsubmit="return confirm(<?= htmlspecialchars(js_encode(t('contests.results_confirm'))) ?>)">
                <?= csrf_field() ?>
                <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.results_jury_hint')) ?></p>
                <?php for ($p = 1; $p <= $places; $p++): ?>
                    <div class="grid sm:grid-cols-[80px_1fr_1fr] gap-2 items-center">
                        <span class="text-sm font-bold"><?= htmlspecialchars(t('contests.place_n', ['n' => $p])) ?></span>
                        <select name="place[<?= $p ?>]" required class="h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm">
                            <option value=""><?= htmlspecialchars(t('contests.choose_entry')) ?></option>
                            <?php foreach ($confirmed as $e): ?>
                                <option value="<?= (int) $e['id'] ?>">#<?= (int) $e['id'] ?> · <?= htmlspecialchars((string) ($e['user_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="note[<?= $p ?>]" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.jury_note')) ?>" class="h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm">
                    </div>
                <?php endfor; ?>
                <button class="h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-bold" <?= empty($mechanicEnabled) ? 'disabled' : '' ?>><?= htmlspecialchars(t('contests.results_publish_btn')) ?></button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.results_once_note')) ?></p>
    </div>
    <?php endif; ?>

    <!-- Победители и вручение -->
    <?php if (!empty($winners)): ?>
    <div class="<?= $card ?>" id="winners">
        <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('contests.winners_fulfilment')) ?></h2>
        <ul class="divide-y divide-black/[0.05] dark:divide-white/10">
            <?php foreach ($winners as $w): ?>
                <li class="py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <div>
                        <p><b><?= (int) $w['place'] ?>.</b> <?= htmlspecialchars((string) ($w['user_name'] ?? t('contests.anonymous'))) ?> — <?= htmlspecialchars((string) $w['prize_title']) ?></p>
                        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.fulfilment.' . $w['fulfilment_status'])) ?> · <?= htmlspecialchars(t('contests.fulfilment_due', ['date' => ContestView::date($w['fulfilment_due_at'])])) ?><?= !empty($w['fulfilment_note']) ? ' · ' . htmlspecialchars((string) $w['fulfilment_note']) : '' ?></p>
                    </div>
                    <?php if (($isOrganizer || $isAdmin) && $w['fulfilment_status'] === 'pending'): ?>
                        <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/winners/' . (int) $w['id'] . '/handed') ?>" class="flex gap-1">
                            <?= csrf_field() ?>
                            <input name="note" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.handed_note')) ?>" class="w-44 h-8 px-2 rounded-lg border border-black/10 text-[11px] bg-white dark:bg-white/5">
                            <button class="<?= $small ?> bg-emerald-600 text-white"><?= htmlspecialchars(t('contests.mark_handed')) ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Отмена -->
    <?php
    $cancelRoles = array_values(array_intersect($roles, ['organizer', 'admin']));
    $canCancel = $cancelRoles !== [] && ContestPolicy::canTransition($status, ContestPolicy::S_CANCELLED, $cancelRoles);
    ?>
    <?php if ($canCancel): ?>
    <details class="<?= $card ?>">
        <summary class="text-sm font-semibold text-red-600 cursor-pointer"><?= htmlspecialchars(t('contests.cancel')) ?></summary>
        <form method="post" action="<?= ProductHelper::url('/contests/' . $id . '/cancel') ?>" class="mt-3 flex flex-wrap gap-2" onsubmit="return confirm(<?= htmlspecialchars(js_encode(t('contests.cancel_confirm'))) ?>)">
            <?= csrf_field() ?>
            <input name="reason" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.cancel_reason')) ?>" class="flex-1 min-w-[220px] h-10 px-3 rounded-xl border border-black/10 bg-white dark:bg-white/5 text-sm">
            <button class="h-10 px-4 rounded-xl bg-red-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.cancel')) ?></button>
        </form>
    </details>
    <?php endif; ?>

    <?php if (!empty($reports)): ?>
    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-2"><?= htmlspecialchars(t('contests.reports')) ?></h2>
        <ul class="text-sm space-y-1">
            <?php foreach ($reports as $r): ?>
                <li><?= htmlspecialchars(t('contests.report_reason.' . $r['reason'])) ?> · <?= htmlspecialchars(t('contests.report_status.' . $r['status'])) ?> · <span class="text-gray-400"><?= htmlspecialchars(ContestView::date($r['created_at'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <details class="<?= $card ?>">
        <summary class="text-sm font-semibold cursor-pointer"><?= htmlspecialchars(t('contests.versions')) ?></summary>
        <ul class="mt-2 text-xs space-y-1 break-all">
            <?php foreach (($versions ?? []) as $ver): ?>
                <li>v<?= (int) $ver['version'] ?> · <?= htmlspecialchars(ContestView::date($ver['created_at'])) ?> · <code><?= htmlspecialchars((string) $ver['content_hash']) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </details>
</section>
