<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$contest = $contest ?? [];
$rules = $rules ?? null;
$entry = $entry ?? null;
$user = $user ?? null;
$status = (string) $contest['status'];
$slug = rawurlencode((string) $contest['slug']);
$base = '/contests/' . $slug;
$cover = ContestView::coverUrl($contest);
$isOrganizer = $user && (int) $user['id'] === (int) $contest['organizer_id'];
$card = 'rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-4 sm:p-5';
$btn = 'h-11 px-5 rounded-xl text-sm font-bold transition';
$conditionState = $entry ? ($entryConditions ?? []) : ($conditionPreview ?? []);
$myWin = null;
foreach (($winners ?? []) as $w) {
    if ($user && (int) ($w['user_id'] ?? 0) === (int) $user['id']) {
        $myWin = $w;
    }
}
$shareText = (string) $contest['title'];
?>
<section class="max-w-4xl mx-auto space-y-5 fade-up pb-10">
    <a href="<?= ProductHelper::url('/contests') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('contests.title')) ?></a>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (!empty($canManage)): ?>
        <div class="rounded-2xl border border-sky-200 bg-sky-50 dark:bg-sky-900/20 dark:border-sky-800/40 px-4 py-3 text-sm flex flex-wrap items-center justify-between gap-2">
            <span><?= htmlspecialchars(t('contests.you_manage')) ?> <?= ContestView::statusBadge($status) ?></span>
            <a href="<?= ProductHelper::url('/contests/' . (int) $contest['id'] . '/manage') ?>" class="font-bold text-sky-700 dark:text-sky-300 hover:underline"><?= htmlspecialchars(t('contests.manage_title')) ?> →</a>
        </div>
    <?php endif; ?>

    <article class="rounded-[28px] overflow-hidden border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft">
        <?php if ($cover): ?>
            <img src="<?= htmlspecialchars($cover) ?>" alt="" class="w-full max-h-[360px] object-cover">
        <?php endif; ?>
        <div class="p-5 sm:p-7 space-y-3">
            <div class="flex flex-wrap items-center gap-1.5">
                <?= ContestView::statusBadge($status) ?>
                <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-black/[0.04] dark:bg-white/10 text-gray-500"><?= htmlspecialchars(t('contests.mechanic.' . $contest['mechanic'])) ?></span>
                <?php if (!empty($contest['category'])): ?>
                    <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-semibold bg-black/[0.04] dark:bg-white/10 text-gray-500"><?= htmlspecialchars(ProductHelper::categoryLabel((string) $contest['category'])) ?></span>
                <?php endif; ?>
                <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-semibold bg-black/[0.04] dark:bg-white/10 text-gray-500"><?= htmlspecialchars(t('contests.region.' . ($contest['region'] ?: 'kz'))) ?></span>
            </div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white"><?= htmlspecialchars((string) $contest['title']) ?></h1>
            <?php if (trim((string) $contest['summary']) !== ''): ?>
                <p class="text-gray-600 dark:text-gray-300"><?= htmlspecialchars((string) $contest['summary']) ?></p>
            <?php endif; ?>
            <p class="text-sm">
                <?= htmlspecialchars(t('contests.organizer')) ?>:
                <a class="font-semibold text-brand-600 hover:underline" href="<?= ProductHelper::url('/users/' . (int) $contest['organizer_id']) ?>"><?= htmlspecialchars(ContestView::organizerName($contest)) ?></a>
                <?php if (($contest['organizer_business_status'] ?? '') === 'verified'): ?>
                    <span class="text-emerald-600 text-xs font-bold">✓ <?= htmlspecialchars(t('contests.verified_business')) ?></span>
                <?php endif; ?>
            </p>
            <div class="grid sm:grid-cols-3 gap-3 pt-2 text-sm">
                <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.05] p-3">
                    <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.period')) ?></p>
                    <p class="font-semibold"><?= htmlspecialchars(ContestView::date($contest['starts_at'])) ?> — <?= htmlspecialchars(ContestView::date($contest['ends_at'])) ?></p>
                    <?php if ($status === 'active' && ($left = ContestView::timeLeft($contest['ends_at'])) !== ''): ?>
                        <p class="text-[11px] text-brand-600 font-semibold mt-0.5"><?= htmlspecialchars($left) ?></p>
                    <?php endif; ?>
                </div>
                <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.05] p-3">
                    <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.participants')) ?></p>
                    <p class="font-semibold tabular-nums"><?= (int) $contest['entries_count'] ?></p>
                </div>
                <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.05] p-3">
                    <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.winners_count')) ?></p>
                    <p class="font-semibold tabular-nums"><?= (int) $contest['winners_count'] ?></p>
                </div>
            </div>
        </div>
    </article>

    <?php if ($rules): ?>
    <div class="<?= $card ?> space-y-2">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.prizes')) ?></h2>
        <ol class="space-y-1 text-sm">
            <?php foreach ((array) $rules['prizes'] as $p): ?>
                <li><span class="font-bold tabular-nums"><?= (int) $p['place'] ?>.</span> <?= htmlspecialchars((string) $p['title']) ?></li>
            <?php endforeach; ?>
        </ol>
        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.prize_by_organizer')) ?></p>
    </div>
    <?php endif; ?>

    <?php if (trim((string) $contest['description']) !== ''): ?>
    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-2"><?= htmlspecialchars(t('contests.description')) ?></h2>
        <div class="text-sm leading-relaxed whitespace-pre-line"><?= htmlspecialchars((string) $contest['description']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Условия и участие -->
    <div class="<?= $card ?> space-y-3" id="participate">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.conditions')) ?></h2>
        <ul class="space-y-2 text-sm">
            <?php foreach (($effectiveConditions ?? []) as $c): ?>
                <?php $st = $conditionState[$c['key']]['status'] ?? null; ?>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 w-5 h-5 shrink-0 rounded-full flex items-center justify-center text-[11px] font-bold <?= $st === 'met' ? 'bg-emerald-500 text-white' : ($st === 'not_met' ? 'bg-red-500 text-white' : 'bg-black/10 dark:bg-white/10 text-gray-500') ?>"><?= $st === 'met' ? '✓' : ($st === 'not_met' ? '!' : '•') ?></span>
                    <span>
                        <?= htmlspecialchars(t('contests.cond.' . $c['key'], ['days' => (int) ($c['days'] ?? 0)])) ?>
                        <?php if ($c['key'] === ContestPolicy::COND_FOLLOW_ORGANIZER && $st !== 'met' && !$isOrganizer): ?>
                            · <a class="text-brand-600 font-semibold hover:underline" href="<?= ProductHelper::url('/users/' . (int) $contest['organizer_id']) ?>"><?= htmlspecialchars(t('contests.go_follow')) ?></a>
                        <?php endif; ?>
                        <?php if ($c['key'] === ContestPolicy::COND_EMAIL_VERIFIED && $st === 'not_met'): ?>
                            · <a class="text-brand-600 font-semibold hover:underline" href="<?= ProductHelper::url('/profile#email-verify') ?>"><?= htmlspecialchars(t('contests.go_verify')) ?></a>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
            <?php if (($effectiveConditions ?? []) === []): ?>
                <li class="text-gray-500"><?= htmlspecialchars(t('contests.no_conditions')) ?></li>
            <?php endif; ?>
        </ul>
        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.conditions_server_note')) ?></p>

        <?php if ($entry): ?>
            <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.05] p-3 text-sm">
                <p class="font-semibold"><?= htmlspecialchars(t('contests.your_status')) ?>: <?= htmlspecialchars(t('contests.entry_status.' . $entry['status'])) ?></p>
                <?php if (!empty($entry['status_reason']) && $entry['status'] === 'disqualified'): ?>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(t('contests.reason')) ?>: <?= htmlspecialchars(t('contests.cond_fail.' . preg_replace('/^(conditions|manual|review):/', '', (string) $entry['status_reason']), [], (string) $entry['status_reason'])) ?></p>
                <?php endif; ?>
                <?php if (!empty($entry['submission_text'])): ?>
                    <p class="text-xs text-gray-500 mt-2"><?= htmlspecialchars(t('contests.your_submission')) ?>:</p>
                    <p class="text-sm whitespace-pre-line mt-1"><?= htmlspecialchars((string) $entry['submission_text']) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($status === 'active' && $entry['status'] === 'pending'): ?>
                <form method="post" action="<?= ProductHelper::url($base . '/withdraw') ?>" onsubmit="return confirm(<?= htmlspecialchars(js_encode(t('contests.withdraw_confirm'))) ?>)">
                    <?= csrf_field() ?>
                    <button class="text-xs font-semibold text-gray-400 hover:text-red-600"><?= htmlspecialchars(t('contests.withdraw')) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($status === 'active' && empty($mechanicEnabled)): ?>
            <p class="text-sm text-amber-700"><?= htmlspecialchars(t('contests.join_paused')) ?></p>
        <?php elseif ($status === 'active' && !$user): ?>
            <a href="<?= ProductHelper::url('/login') ?>" class="<?= $btn ?> inline-flex items-center bg-brand-500 hover:bg-brand-600 text-white"><?= htmlspecialchars(t('contests.login_to_join')) ?></a>
        <?php elseif ($status === 'active' && !$isOrganizer): ?>
            <form method="post" action="<?= ProductHelper::url($base . '/join') ?>" class="space-y-3">
                <?= csrf_field() ?>
                <?php if ($contest['mechanic'] === ContestPolicy::MECHANIC_JURY): ?>
                    <label class="block text-sm font-semibold" for="submission"><?= htmlspecialchars(t('contests.submission_label')) ?></label>
                    <textarea id="submission" name="submission" required maxlength="<?= ContestPolicy::MAX_SUBMISSION_CHARS ?>" rows="5" class="w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 p-3 text-sm"></textarea>
                <?php endif; ?>
                <label class="flex items-start gap-2 text-xs text-gray-500">
                    <input type="checkbox" required class="mt-0.5">
                    <span><?= htmlspecialchars(t('contests.join_consent')) ?></span>
                </label>
                <button class="<?= $btn ?> bg-brand-500 hover:bg-brand-600 text-white"><?= htmlspecialchars(t('contests.join')) ?></button>
            </form>
        <?php elseif ($status === 'scheduled'): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.starts', ['date' => ContestView::date($contest['starts_at'])])) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($personalUrl)): ?>
    <!-- Персональная ссылка участника -->
    <div class="<?= $card ?> space-y-3">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.personal_link')) ?></h2>
        <p class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.personal_link_hint')) ?></p>
        <div class="flex gap-2">
            <input readonly value="<?= htmlspecialchars($personalUrl) ?>" class="flex-1 h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-black/[0.02] dark:bg-white/5 text-xs" id="contest-personal-url">
            <button type="button" class="h-10 px-3 rounded-xl bg-ink-900 text-white text-xs font-bold dark:bg-white dark:text-ink-900" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('contest-personal-url').value); this.textContent=<?= htmlspecialchars(js_encode(t('contests.copied'))) ?>;"><?= htmlspecialchars(t('contests.copy')) ?></button>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <img src="<?= ProductHelper::url('/contests/' . (int) $contest['id'] . '/qr.svg?personal=1') ?>" alt="QR" width="140" height="140" class="rounded-xl border border-black/10 bg-white">
            <?php if (!empty($myRefTotals)): ?>
                <div class="text-sm space-y-1">
                    <p><?= htmlspecialchars(t('contests.ref_visits')) ?>: <b class="tabular-nums"><?= (int) $myRefTotals['visits'] ?></b></p>
                    <p><?= htmlspecialchars(t('contests.ref_registrations')) ?>: <b class="tabular-nums"><?= (int) $myRefTotals['registrations'] ?></b></p>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($myReferrals)): ?>
            <table class="w-full text-xs">
                <thead><tr class="text-left text-gray-400"><th class="py-1"><?= htmlspecialchars(t('contests.ref_date')) ?></th><th><?= htmlspecialchars(t('contests.ref_reward_status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($myReferrals as $r): ?>
                    <tr class="border-t border-black/[0.05] dark:border-white/10">
                        <td class="py-1"><?= htmlspecialchars(ContestView::date($r['registered_at'])) ?></td>
                        <td><?= htmlspecialchars($r['rewarded'] ? t('contests.ref_rewarded', ['amount' => \App\Models\Bonus::format($r['amount'])]) : t('contests.ref_not_rewarded')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.ref_separation_note')) ?></p>
    </div>
    <?php endif; ?>

    <!-- Результаты -->
    <?php if (!empty($execution)): ?>
    <div class="<?= $card ?> space-y-3" id="results">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.results')) ?></h2>
        <ol class="space-y-2 text-sm">
            <?php foreach ($winners as $w): ?>
                <li class="flex flex-wrap items-baseline gap-2">
                    <span class="font-bold tabular-nums"><?= (int) $w['place'] ?>.</span>
                    <span class="font-semibold"><?= htmlspecialchars(ContestView::publicWinnerName($w['user_name'] ?? null)) ?></span>
                    <span class="text-gray-500">— <?= htmlspecialchars((string) $w['prize_title']) ?></span>
                    <span class="text-[11px] text-gray-400">(<?= htmlspecialchars(t('contests.fulfilment.' . $w['fulfilment_status'])) ?>)</span>
                    <?php if (!empty($w['jury_note'])): ?>
                        <span class="block w-full text-xs text-gray-500"><?= htmlspecialchars((string) $w['jury_note']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.results_at', ['date' => ContestView::date($execution['executed_at']), 'n' => (int) $execution['eligible_count']])) ?></p>

        <?php if ($myWin): ?>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800/40 p-4 space-y-2 text-sm">
                <p class="font-bold">🎉 <?= htmlspecialchars(t('contests.you_won', ['prize' => (string) $myWin['prize_title']])) ?></p>
                <p class="text-xs"><?= htmlspecialchars(t('contests.fulfilment_status')) ?>: <?= htmlspecialchars(t('contests.fulfilment.' . $myWin['fulfilment_status'])) ?> · <?= htmlspecialchars(t('contests.fulfilment_due', ['date' => ContestView::date($myWin['fulfilment_due_at'])])) ?></p>
                <?php if (in_array($myWin['fulfilment_status'], ['pending', 'handed_over'], true)): ?>
                    <div class="flex flex-wrap gap-2">
                        <form method="post" action="<?= ProductHelper::url($base . '/winners/' . (int) $myWin['id'] . '/confirm') ?>">
                            <?= csrf_field() ?>
                            <button class="h-9 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold"><?= htmlspecialchars(t('contests.confirm_received')) ?></button>
                        </form>
                    </div>
                <?php endif; ?>
                <?php if (in_array($myWin['fulfilment_status'], ['pending', 'handed_over', 'expired'], true)): ?>
                    <form method="post" action="<?= ProductHelper::url($base . '/winners/' . (int) $myWin['id'] . '/dispute') ?>" class="flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input name="note" required minlength="5" maxlength="500" placeholder="<?= htmlspecialchars(t('contests.dispute_placeholder')) ?>" class="flex-1 min-w-[200px] h-9 px-3 rounded-xl border border-black/10 bg-white dark:bg-white/5 text-xs">
                        <button class="h-9 px-4 rounded-xl border border-red-300 text-red-700 text-xs font-bold"><?= htmlspecialchars(t('contests.dispute')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Правила и прозрачность -->
    <?php if ($rules): ?>
    <details class="<?= $card ?>">
        <summary class="font-display font-bold cursor-pointer"><?= htmlspecialchars(t('contests.rules')) ?></summary>
        <div class="mt-3 space-y-3 text-sm">
            <div class="whitespace-pre-line leading-relaxed"><?= htmlspecialchars((string) $rules['rules_text']) ?></div>
            <?php if (!empty($rules['criteria_text'])): ?>
                <h3 class="font-bold"><?= htmlspecialchars(t('contests.criteria')) ?></h3>
                <div class="whitespace-pre-line leading-relaxed"><?= htmlspecialchars((string) $rules['criteria_text']) ?></div>
            <?php endif; ?>
            <p class="text-[11px] text-gray-400 break-all"><?= htmlspecialchars(t('contests.rules_version', ['v' => (int) $rules['version'], 'hash' => (string) $rules['content_hash']])) ?></p>
            <?php if ($contest['mechanic'] === ContestPolicy::MECHANIC_RANDOM && !empty($contest['random_commit'])): ?>
                <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.05] p-3 space-y-1 text-xs break-all">
                    <p class="font-bold"><?= htmlspecialchars(t('contests.fairness_title')) ?></p>
                    <p><?= htmlspecialchars(t('contests.fairness_commit')) ?>: <code><?= htmlspecialchars((string) $contest['random_commit']) ?></code></p>
                    <?php if (!empty($execution['seed'])): ?>
                        <p><?= htmlspecialchars(t('contests.fairness_seed')) ?>: <code><?= htmlspecialchars((string) $execution['seed']) ?></code></p>
                        <p><?= htmlspecialchars(t('contests.fairness_list')) ?>: <code><?= htmlspecialchars((string) $execution['eligible_hash']) ?></code> (<?= (int) $execution['eligible_count'] ?>)</p>
                        <p><?= htmlspecialchars(t('contests.fairness_algo', ['algo' => (string) $execution['algorithm']])) ?></p>
                    <?php else: ?>
                        <p class="text-gray-500"><?= htmlspecialchars(t('contests.fairness_hidden')) ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Поделиться -->
    <div class="<?= $card ?> space-y-3">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('contests.share')) ?></h2>
        <div class="flex flex-wrap items-center gap-4">
            <?php if (ContestPolicy::isPublicStatus($status)): ?>
                <img src="<?= ProductHelper::url('/contests/' . (int) $contest['id'] . '/qr.svg') ?>" alt="QR" width="120" height="120" class="rounded-xl border border-black/10 bg-white">
            <?php endif; ?>
            <div class="flex flex-wrap gap-2">
                <a target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($shareText . "\n" . ($personalUrl ?? $publicUrl)) ?>" class="h-9 px-4 inline-flex items-center rounded-xl bg-emerald-500 text-white text-xs font-bold">WhatsApp</a>
                <a target="_blank" rel="noopener" href="https://t.me/share/url?url=<?= rawurlencode((string) ($personalUrl ?? $publicUrl)) ?>&amp;text=<?= rawurlencode($shareText) ?>" class="h-9 px-4 inline-flex items-center rounded-xl bg-sky-500 text-white text-xs font-bold">Telegram</a>
            </div>
        </div>
        <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.share_note')) ?></p>
    </div>

    <?php if ($user && !$isOrganizer && ContestPolicy::isPublicStatus($status)): ?>
    <details class="<?= $card ?>">
        <summary class="text-sm font-semibold text-gray-500 cursor-pointer"><?= htmlspecialchars(t('contests.report')) ?></summary>
        <?php if (!empty($hasOpenReport)): ?>
            <p class="text-sm text-gray-500 mt-3"><?= htmlspecialchars(t('contests.report_already')) ?></p>
        <?php else: ?>
        <form method="post" action="<?= ProductHelper::url($base . '/report') ?>" class="mt-3 space-y-2">
            <?= csrf_field() ?>
            <select name="reason" required class="h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm">
                <?php foreach (ContestPolicy::REPORT_REASONS as $r): ?>
                    <option value="<?= $r ?>"><?= htmlspecialchars(t('contests.report_reason.' . $r)) ?></option>
                <?php endforeach; ?>
            </select>
            <textarea name="details" maxlength="1000" rows="3" placeholder="<?= htmlspecialchars(t('contests.report_details')) ?>" class="w-full rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 p-3 text-sm"></textarea>
            <button class="h-10 px-4 rounded-xl border border-red-300 text-red-700 text-xs font-bold"><?= htmlspecialchars(t('contests.report_send')) ?></button>
        </form>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <p class="text-[11px] text-gray-400 leading-relaxed"><?= htmlspecialchars(t('contests.platform_disclaimer')) ?></p>
</section>
