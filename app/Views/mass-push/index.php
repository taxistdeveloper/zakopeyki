<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\MassPush\MassPushPolicy;

$campaigns = $campaigns ?? [];
$enabled = !empty($enabled);
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$price = number_format(MassPushPolicy::PRICE_KZT, 0, '', ' ');
?>
<section class="max-w-3xl mx-auto space-y-6 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('mass_push.eyebrow')) ?></p>
        <h1 class="font-display text-xl sm:text-2xl font-bold tracking-tight"><?= htmlspecialchars(t('mass_push.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars(t('mass_push.lead')) ?></p>
    </div>

    <div class="rounded-[28px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 sm:p-5 space-y-2 text-sm">
        <p class="font-display font-bold"><?= htmlspecialchars(t('mass_push.price', ['amount' => $price])) ?></p>
        <p class="text-gray-500"><?= htmlspecialchars(t('mass_push.audience_rule')) ?></p>
        <p class="text-gray-500"><?= htmlspecialchars(t('mass_push.delivery_rule')) ?></p>
        <p class="text-gray-500"><?= htmlspecialchars(t('mass_push.moderation_rule')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/25 text-red-700 dark:text-red-300 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$enabled): ?>
        <div class="rounded-2xl border border-dashed border-black/10 px-5 py-8 text-sm text-gray-500"><?= htmlspecialchars(t('mass_push.disabled')) ?></div>
    <?php else: ?>
        <form method="post" action="<?= ProductHelper::url('/mass-push') ?>" id="mp-form" data-audience="<?= htmlspecialchars(ProductHelper::url('/mass-push/audience')) ?>" class="rounded-[28px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 sm:p-5 space-y-4 shadow-soft">
            <?= csrf_field() ?>
            <h2 class="font-display font-bold"><?= htmlspecialchars(t('mass_push.create')) ?></h2>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('mass_push.field_title')) ?>
                <input type="text" name="title" id="mp-title" required minlength="2" maxlength="80" class="<?= $input ?> mt-1.5">
            </label>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('mass_push.field_body')) ?>
                <textarea name="body" id="mp-body" required minlength="2" maxlength="320" rows="4" class="<?= $input ?> mt-1.5 h-auto py-2"></textarea>
            </label>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('mass_push.field_url')) ?>
                <input type="url" name="target_url" maxlength="255" placeholder="https://" class="<?= $input ?> mt-1.5">
            </label>
            <div class="rounded-2xl bg-black/[0.03] dark:bg-white/[0.04] px-4 py-3 text-sm">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 mb-1"><?= htmlspecialchars(t('mass_push.preview')) ?></p>
                <p id="mp-preview" class="whitespace-pre-wrap"><?= htmlspecialchars(t('mass_push.preview_empty')) ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" id="mp-count" class="h-11 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('mass_push.count')) ?></button>
                <p id="mp-audience" class="text-sm text-gray-500"><?= htmlspecialchars(t('mass_push.count_hint')) ?></p>
            </div>
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('mass_push.moderation_hint')) ?></p>
            <button type="submit" id="mp-submit" class="h-11 px-5 rounded-xl bg-accent-500 hover:bg-accent-400 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('mass_push.submit')) ?></button>
        </form>
    <?php endif; ?>

    <div class="space-y-3">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('mass_push.mine')) ?></h2>
        <?php if ($campaigns === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('mass_push.empty')) ?></p>
        <?php endif; ?>
        <?php foreach ($campaigns as $row): ?>
            <?php
                $status = (string) ($row['status'] ?? '');
                $accepted = (int) ($row['accepted_count'] ?? 0);
                $failed = (int) ($row['failed_count'] ?? 0);
                $audienceCount = (int) ($row['audience_count'] ?? 0);
                $pending = max(0, $audienceCount - $accepted - $failed);
            ?>
            <article class="rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 space-y-2">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="font-display font-bold"><?= htmlspecialchars((string) $row['title']) ?></h3>
                        <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars((string) $row['body']) ?></p>
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-brand-700"><?= htmlspecialchars(t('mass_push.status_' . $status)) ?></span>
                </div>
                <p class="text-xs text-gray-500">
                    <?= htmlspecialchars(Wallet::formatMoney((int) $row['amount'])) ?>
                    <?php if (!empty($row['started_at'])): ?>
                        · <?= htmlspecialchars((string) $row['started_at']) ?>
                    <?php endif; ?>
                </p>
                <p class="text-xs text-gray-500"><?= htmlspecialchars(t('mass_push.stats', [
                    'audience' => (string) $audienceCount,
                    'accepted' => (string) $accepted,
                    'failed' => (string) $failed,
                    'pending' => (string) $pending,
                ])) ?></p>
                <?php if ($status === MassPushPolicy::STATUS_REJECTED && !empty($row['reject_reason'])): ?>
                    <p class="text-xs text-amber-700"><?= htmlspecialchars((string) $row['reject_reason']) ?></p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-2 pt-1">
                    <?php if ($enabled && $status === MassPushPolicy::STATUS_APPROVED): ?>
                        <form method="post" action="<?= ProductHelper::url('/mass-push/' . (int) $row['id'] . '/pay') ?>" class="js-once">
                            <?= csrf_field() ?>
                            <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars(bin2hex(random_bytes(16))) ?>">
                            <button type="submit" class="h-10 px-4 rounded-xl bg-accent-500 text-white text-xs font-bold"><?= htmlspecialchars(t('mass_push.pay', ['amount' => $price])) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($enabled && $status === MassPushPolicy::STATUS_PENDING && !empty($row['redirect_url'])): ?>
                        <form method="post" action="<?= ProductHelper::url('/mass-push/' . (int) $row['id'] . '/pay') ?>" class="js-once">
                            <?= csrf_field() ?>
                            <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('mass_push.continue_pay')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($enabled && $status === MassPushPolicy::STATUS_PAID): ?>
                        <form method="post" action="<?= ProductHelper::url('/mass-push/' . (int) $row['id'] . '/launch') ?>" class="js-once">
                            <?= csrf_field() ?>
                            <button type="submit" class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('mass_push.launch')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($status === MassPushPolicy::STATUS_PARTIAL): ?>
                        <form method="post" action="<?= ProductHelper::url('/mass-push/' . (int) $row['id'] . '/retry') ?>" class="js-once">
                            <?= csrf_field() ?>
                            <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('mass_push.retry')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (MassPushPolicy::mayEdit($status) && $enabled): ?>
                        <form method="post" action="<?= ProductHelper::url('/mass-push/' . (int) $row['id'] . '/update') ?>" class="w-full grid gap-2 sm:grid-cols-2">
                            <?= csrf_field() ?>
                            <input type="text" name="title" required minlength="2" maxlength="80" value="<?= htmlspecialchars((string) $row['title']) ?>" class="<?= $input ?>">
                            <input type="url" name="target_url" maxlength="255" value="<?= htmlspecialchars((string) ($row['target_url'] ?? '')) ?>" placeholder="https://" class="<?= $input ?>">
                            <textarea name="body" required minlength="2" maxlength="320" rows="3" class="<?= $input ?> sm:col-span-2 h-auto py-2"><?= htmlspecialchars((string) $row['body']) ?></textarea>
                            <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold sm:col-span-2"><?= htmlspecialchars(t('mass_push.resubmit')) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (MassPushPolicy::mayCancel($status)): ?>
                        <form method="post" action="<?= ProductHelper::url('/mass-push/' . (int) $row['id'] . '/cancel') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="h-10 px-4 rounded-xl text-xs font-bold text-gray-500"><?= htmlspecialchars(t('mass_push.cancel')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <form method="post" action="<?= ProductHelper::url('/mass-push/unsubscribe') ?>" class="rounded-2xl border border-black/[0.06] p-4 space-y-2">
        <?= csrf_field() ?>
        <p class="text-sm"><?= htmlspecialchars(!empty($unsubscribed) ? t('mass_push.unsubscribed_state') : t('mass_push.unsubscribe_hint')) ?></p>
        <?php if (empty($unsubscribed)): ?>
            <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('mass_push.unsubscribe')) ?></button>
        <?php endif; ?>
    </form>
</section>
<script>
(function () {
    var title = document.getElementById('mp-title');
    var body = document.getElementById('mp-body');
    var preview = document.getElementById('mp-preview');
    var form = document.getElementById('mp-form');
    function paint() {
        if (!preview || !title || !body) return;
        var text = (title.value || '').trim();
        var rest = (body.value || '').trim();
        preview.textContent = text === '' && rest === '' ? <?= json_encode(t('mass_push.preview_empty'), JSON_UNESCAPED_UNICODE) ?> : (text + (rest ? ': ' + rest : ''));
    }
    if (title) title.addEventListener('input', paint);
    if (body) body.addEventListener('input', paint);
    var countBtn = document.getElementById('mp-count');
    if (countBtn && form) {
        countBtn.addEventListener('click', function () {
            var box = document.getElementById('mp-audience');
            box.textContent = <?= json_encode(t('mass_push.count_wait'), JSON_UNESCAPED_UNICODE) ?>;
            fetch(form.getAttribute('data-audience'), { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        box.textContent = (data && data.error) ? data.error : <?= json_encode(t('mass_push.err_generic'), JSON_UNESCAPED_UNICODE) ?>;
                        return;
                    }
                    box.textContent = <?= json_encode(t('mass_push.count_result'), JSON_UNESCAPED_UNICODE) ?>.replace(':n', String(data.count));
                })
                .catch(function () {
                    box.textContent = <?= json_encode(t('mass_push.count_failed'), JSON_UNESCAPED_UNICODE) ?>;
                });
        });
    }
    document.querySelectorAll('form.js-once').forEach(function (node) {
        node.addEventListener('submit', function () {
            var button = node.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
        });
    });
})();
</script>
