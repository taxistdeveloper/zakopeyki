<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$contest = $contest ?? null;
$in = $input ?? [];
$errors = $errors ?? [];
$mechanics = $mechanics ?? [];
$action = $contest ? '/contests/' . (int) $contest['id'] . '/update' : '/contests';
$field = 'w-full h-11 px-3.5 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$area = 'w-full px-3.5 py-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$label = 'block text-[13px] font-semibold mb-1.5';
$err = static fn (string $k): string => isset($errors[$k])
    ? '<p class="text-xs text-red-600 mt-1">' . htmlspecialchars((string) $errors[$k]) . '</p>' : '';
$v = static fn (string $k, $d = ''): string => htmlspecialchars((string) ($in[$k] ?? $d));
$prizes = array_values((array) ($in['prizes'] ?? ['']));
if ($prizes === []) {
    $prizes = [''];
}
$stepOf = [
    'title' => 1, 'summary' => 1, 'description' => 1, 'cover' => 1,
    'mechanic' => 2, 'winners_count' => 2, 'prizes' => 2, 'prize_summary' => 2, 'criteria_text' => 2,
    'starts_at' => 3, 'ends_at' => 3,
    'rules_text' => 4, 'organizer_terms' => 4,
];
$firstErrStep = 1;
foreach ($stepOf as $k => $s) {
    if (isset($errors[$k])) {
        $firstErrStep = $s;
        break;
    }
}
$curMechanic = (string) ($in['mechanic'] ?? ($mechanics[0] ?? 'jury'));
?>
<section class="max-w-3xl mx-auto space-y-5 fade-up pb-10">
    <div>
        <a href="<?= ProductHelper::url($contest ? '/contests/' . (int) $contest['id'] . '/manage' : '/contests/my') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('contests.back')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars($contest ? t('contests.edit_title') : t('contests.create_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('contests.wizard_hint')) ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars(t('contests.err.fix_fields')) ?></div>
    <?php endif; ?>
    <?php if ($contest && !empty($contest['moderation_note']) && $contest['status'] === 'rejected'): ?>
        <div class="bg-amber-50 text-amber-800 border border-amber-200 px-4 py-3 rounded-2xl text-sm"><b><?= htmlspecialchars(t('contests.moderator_note')) ?>:</b> <?= htmlspecialchars((string) $contest['moderation_note']) ?></div>
    <?php endif; ?>

    <ol class="grid grid-cols-4 gap-2 text-[11px] font-semibold" id="wizard-steps">
        <?php foreach ([1, 2, 3, 4] as $s): ?>
            <li><button type="button" data-step-btn="<?= $s ?>" class="w-full h-9 rounded-xl border border-black/10 dark:border-white/10"><?= $s ?>. <?= htmlspecialchars(t('contests.step_' . $s)) ?></button></li>
        <?php endforeach; ?>
    </ol>

    <form method="post" action="<?= ProductHelper::url($action) ?>" enctype="multipart/form-data" class="space-y-5" id="contest-wizard" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars((string) ($idempotencyKey ?? '')) ?>">

        <!-- Шаг 1 -->
        <fieldset data-step="1" class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-5 space-y-4">
            <div>
                <label class="<?= $label ?>" for="f-title"><?= htmlspecialchars(t('contests.f.title')) ?> *</label>
                <input id="f-title" name="title" maxlength="160" required class="<?= $field ?>" value="<?= $v('title') ?>">
                <?= $err('title') ?>
            </div>
            <div>
                <label class="<?= $label ?>" for="f-summary"><?= htmlspecialchars(t('contests.f.summary')) ?></label>
                <input id="f-summary" name="summary" maxlength="300" class="<?= $field ?>" value="<?= $v('summary') ?>">
            </div>
            <div>
                <label class="<?= $label ?>" for="f-description"><?= htmlspecialchars(t('contests.f.description')) ?></label>
                <textarea id="f-description" name="description" rows="6" maxlength="10000" class="<?= $area ?>"><?= $v('description') ?></textarea>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $label ?>" for="f-category"><?= htmlspecialchars(t('contests.f.category')) ?></label>
                    <select id="f-category" name="category" class="<?= $field ?>">
                        <option value=""><?= htmlspecialchars(t('contests.f.category_none')) ?></option>
                        <?php foreach (($categories ?? []) as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= ($in['category'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars(ProductHelper::categoryLabel($c)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="<?= $label ?>" for="f-region"><?= htmlspecialchars(t('contests.f.region')) ?></label>
                    <select id="f-region" name="region" class="<?= $field ?>">
                        <?php foreach (ContestPolicy::REGIONS as $r): ?>
                            <option value="<?= $r ?>" <?= ($in['region'] ?? 'kz') === $r ? 'selected' : '' ?>><?= htmlspecialchars(t('contests.region.' . $r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="<?= $label ?>" for="f-cover"><?= htmlspecialchars(t('contests.f.cover')) ?></label>
                <?php if ($contest && ($cu = ContestView::coverUrl($contest))): ?>
                    <img src="<?= htmlspecialchars($cu) ?>" alt="" class="h-24 rounded-xl mb-2 object-cover">
                <?php endif; ?>
                <input id="f-cover" type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="text-sm">
                <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('contests.f.cover_hint')) ?></p>
                <?= $err('cover') ?>
            </div>
        </fieldset>

        <!-- Шаг 2 -->
        <fieldset data-step="2" class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-5 space-y-4">
            <div>
                <p class="<?= $label ?>"><?= htmlspecialchars(t('contests.f.mechanic')) ?> *</p>
                <?php if ($mechanics === []): ?>
                    <p class="text-sm text-amber-700"><?= htmlspecialchars(t('contests.deny.no_mechanics')) ?></p>
                <?php endif; ?>
                <div class="grid sm:grid-cols-2 gap-3">
                    <?php foreach ($mechanics as $m): ?>
                        <label class="flex gap-3 rounded-2xl border border-black/10 dark:border-white/10 p-3 cursor-pointer has-[:checked]:border-brand-500">
                            <input type="radio" name="mechanic" value="<?= $m ?>" <?= $curMechanic === $m ? 'checked' : '' ?> data-mechanic>
                            <span>
                                <span class="block text-sm font-bold"><?= htmlspecialchars(t('contests.mechanic.' . $m)) ?></span>
                                <span class="block text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('contests.mechanic_hint.' . $m)) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?= $err('mechanic') ?>
                <p class="text-[11px] text-gray-400 mt-2"><?= htmlspecialchars(t('contests.f.mechanic_legal')) ?></p>
            </div>
            <div>
                <label class="<?= $label ?>" for="f-prize-summary"><?= htmlspecialchars(t('contests.f.prize_summary')) ?> *</label>
                <input id="f-prize-summary" name="prize_summary" maxlength="255" class="<?= $field ?>" value="<?= $v('prize_summary') ?>">
                <?= $err('prize_summary') ?>
            </div>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $label ?>" for="f-winners"><?= htmlspecialchars(t('contests.f.winners_count')) ?> *</label>
                    <input id="f-winners" type="number" name="winners_count" min="1" max="<?= ContestPolicy::MAX_WINNERS ?>" class="<?= $field ?>" value="<?= $v('winners_count', 1) ?>">
                    <?= $err('winners_count') ?>
                </div>
            </div>
            <div>
                <p class="<?= $label ?>"><?= htmlspecialchars(t('contests.f.prizes')) ?> *</p>
                <div id="prize-list" class="space-y-2">
                    <?php foreach ($prizes as $i => $p): ?>
                        <input name="prizes[]" maxlength="255" class="<?= $field ?>" placeholder="<?= htmlspecialchars(t('contests.f.prize_place', ['n' => $i + 1])) ?>" value="<?= htmlspecialchars((string) $p) ?>">
                    <?php endforeach; ?>
                </div>
                <button type="button" id="prize-add" class="mt-2 text-xs font-bold text-brand-600">+ <?= htmlspecialchars(t('contests.f.prize_add')) ?></button>
                <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('contests.f.prizes_hint')) ?></p>
                <?= $err('prizes') ?>
            </div>
            <div data-jury-only>
                <label class="<?= $label ?>" for="f-criteria"><?= htmlspecialchars(t('contests.f.criteria')) ?> *</label>
                <textarea id="f-criteria" name="criteria_text" rows="4" maxlength="5000" class="<?= $area ?>"><?= $v('criteria_text') ?></textarea>
                <?= $err('criteria_text') ?>
            </div>
        </fieldset>

        <!-- Шаг 3 -->
        <fieldset data-step="3" class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-5 space-y-4">
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $label ?>" for="f-starts"><?= htmlspecialchars(t('contests.f.starts_at')) ?> *</label>
                    <input id="f-starts" type="datetime-local" name="starts_at" class="<?= $field ?>" value="<?= htmlspecialchars(ContestView::inputDate((string) ($in['starts_at'] ?? ''))) ?>">
                    <?= $err('starts_at') ?>
                </div>
                <div>
                    <label class="<?= $label ?>" for="f-ends"><?= htmlspecialchars(t('contests.f.ends_at')) ?> *</label>
                    <input id="f-ends" type="datetime-local" name="ends_at" class="<?= $field ?>" value="<?= htmlspecialchars(ContestView::inputDate((string) ($in['ends_at'] ?? ''))) ?>">
                    <?= $err('ends_at') ?>
                </div>
            </div>
            <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.f.dates_hint', ['days' => ContestPolicy::MAX_DURATION_DAYS])) ?></p>
            <div class="space-y-3">
                <p class="<?= $label ?>"><?= htmlspecialchars(t('contests.f.conditions')) ?></p>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="cond_follow_organizer" value="1" <?= !empty($in['cond_follow_organizer']) ? 'checked' : '' ?>>
                    <?= htmlspecialchars(t('contests.f.cond_follow')) ?>
                </label>
                <label class="flex flex-wrap items-center gap-2 text-sm">
                    <?= htmlspecialchars(t('contests.f.cond_age')) ?>
                    <input type="number" name="cond_account_age_days" min="0" max="<?= ContestPolicy::MAX_ACCOUNT_AGE_DAYS ?>" value="<?= $v('cond_account_age_days', 0) ?>" class="w-24 h-9 px-2 rounded-lg border border-black/10 dark:border-white/10 bg-white dark:bg-white/5">
                    <span class="text-xs text-gray-400"><?= htmlspecialchars(t('contests.f.cond_age_hint')) ?></span>
                </label>
                <p class="text-[11px] text-gray-400"><?= htmlspecialchars(t('contests.f.conditions_hint')) ?></p>
            </div>
            <div>
                <label class="<?= $label ?>" for="f-fulfil"><?= htmlspecialchars(t('contests.f.fulfilment_days')) ?></label>
                <input id="f-fulfil" type="number" name="fulfilment_days" min="1" max="<?= ContestPolicy::MAX_FULFILMENT_DAYS ?>" class="<?= $field ?> max-w-[140px]" value="<?= $v('fulfilment_days', ContestPolicy::DEFAULT_FULFILMENT_DAYS) ?>">
            </div>
        </fieldset>

        <!-- Шаг 4 -->
        <fieldset data-step="4" class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-5 space-y-4">
            <div>
                <label class="<?= $label ?>" for="f-rules"><?= htmlspecialchars(t('contests.f.rules')) ?> *</label>
                <textarea id="f-rules" name="rules_text" rows="10" maxlength="20000" class="<?= $area ?>" placeholder="<?= htmlspecialchars(t('contests.f.rules_placeholder')) ?>"><?= $v('rules_text') ?></textarea>
                <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars(t('contests.f.rules_hint')) ?></p>
                <?= $err('rules_text') ?>
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="organizer_terms" value="1" class="mt-1" <?= !empty($in['organizer_terms']) ? 'checked' : '' ?>>
                <span><?= htmlspecialchars(t('contests.f.organizer_terms')) ?></span>
            </label>
            <?= $err('organizer_terms') ?>
            <div class="flex flex-wrap gap-2 pt-2">
                <button type="submit" name="save_draft" value="1" class="h-11 px-5 rounded-xl border border-black/10 dark:border-white/10 text-sm font-bold"><?= htmlspecialchars(t('contests.f.save_draft')) ?></button>
                <button type="submit" name="submit_for_moderation" value="1" class="h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.f.submit')) ?></button>
            </div>
        </fieldset>

        <div class="flex justify-between" id="wizard-nav">
            <button type="button" id="wizard-prev" class="h-10 px-4 rounded-xl border border-black/10 dark:border-white/10 text-sm font-semibold"><?= htmlspecialchars(t('contests.prev')) ?></button>
            <button type="button" id="wizard-next" class="h-10 px-4 rounded-xl bg-ink-900 text-white dark:bg-white dark:text-ink-900 text-sm font-bold"><?= htmlspecialchars(t('contests.next')) ?></button>
        </div>
    </form>
</section>
<script>
(function () {
    var form = document.getElementById('contest-wizard');
    if (!form) return;
    var steps = form.querySelectorAll('[data-step]');
    var btns = document.querySelectorAll('[data-step-btn]');
    var prev = document.getElementById('wizard-prev');
    var next = document.getElementById('wizard-next');
    var current = <?= (int) $firstErrStep ?>;
    function show(n) {
        current = Math.max(1, Math.min(4, n));
        steps.forEach(function (s) { s.classList.toggle('hidden', +s.getAttribute('data-step') !== current); });
        btns.forEach(function (b) {
            var on = +b.getAttribute('data-step-btn') === current;
            b.classList.toggle('bg-brand-500', on); b.classList.toggle('text-white', on); b.classList.toggle('border-brand-500', on);
        });
        prev.classList.toggle('invisible', current === 1);
        next.classList.toggle('invisible', current === 4);
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { show(+b.getAttribute('data-step-btn')); }); });
    prev.addEventListener('click', function () { show(current - 1); });
    next.addEventListener('click', function () { show(current + 1); });

    function syncMechanic() {
        var sel = form.querySelector('[data-mechanic]:checked');
        var jury = !sel || sel.value === 'jury';
        form.querySelectorAll('[data-jury-only]').forEach(function (el) { el.classList.toggle('hidden', !jury); });
    }
    form.querySelectorAll('[data-mechanic]').forEach(function (r) { r.addEventListener('change', syncMechanic); });
    syncMechanic();

    var list = document.getElementById('prize-list');
    document.getElementById('prize-add').addEventListener('click', function () {
        var inputs = list.querySelectorAll('input');
        if (inputs.length >= <?= ContestPolicy::MAX_WINNERS ?>) return;
        var el = inputs[0].cloneNode(); el.value = '';
        el.placeholder = (<?= js_encode(t('contests.f.prize_place', ['n' => '#'])) ?>).replace('#', inputs.length + 1);
        list.appendChild(el);
    });
    show(current);
})();
</script>
