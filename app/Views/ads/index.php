<?php

use App\Helpers\ProductHelper;
use App\Models\Wallet;
use App\Services\Ads\AdPolicy;

$campaigns = $campaigns ?? [];
$flags = $flags ?? [];
$input = 'ui-input w-full h-11 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$any = false;
foreach (AdPolicy::SETTING_KEYS as $format => $key) {
    if (!empty($flags[$format])) {
        $any = true;
    }
}
?>
<section class="max-w-3xl mx-auto space-y-6 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600 mb-1"><?= htmlspecialchars(t('ads.eyebrow')) ?></p>
        <h1 class="font-display text-xl sm:text-2xl font-bold tracking-tight"><?= htmlspecialchars(t('ads.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2"><?= htmlspecialchars(t('ads.lead')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/25 text-red-700 dark:text-red-300 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$any): ?>
        <div class="rounded-2xl border border-dashed border-black/10 px-5 py-8 text-sm text-gray-500"><?= htmlspecialchars(t('ads.all_off')) ?></div>
    <?php else: ?>
        <form method="post" action="<?= ProductHelper::url('/ads') ?>" enctype="multipart/form-data" class="rounded-[28px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-4 sm:p-5 space-y-4 shadow-soft">
            <?= csrf_field() ?>
            <h2 class="font-display font-bold"><?= htmlspecialchars(t('ads.create')) ?></h2>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('ads.field_format')) ?>
                <select name="format" id="ad-format" class="<?= $input ?> mt-1.5">
                    <?php foreach (array_keys(AdPolicy::SETTING_KEYS) as $format): ?>
                        <?php if (empty($flags[$format])) continue; ?>
                        <option value="<?= htmlspecialchars($format) ?>"><?= htmlspecialchars(t('ads.format_' . $format)) ?> — <?= htmlspecialchars(t('ads.rate_' . $format)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block text-xs font-bold" id="ad-category-wrap">
                <?= htmlspecialchars(t('ads.field_category')) ?>
                <select name="category" id="ad-category" class="<?= $input ?> mt-1.5">
                    <option value=""><?= htmlspecialchars(t('ads.category_all')) ?></option>
                    <?php foreach (\App\Helpers\ProductHelper::PRODUCT_CATEGORY_TREE as $parent => $children): ?>
                        <option value="<?= htmlspecialchars($parent) ?>"><?= htmlspecialchars(ProductHelper::categoryLabel($parent)) ?></option>
                        <?php foreach ($children as $child): ?>
                            <?php $full = ProductHelper::formatCategory($parent, $child); ?>
                            <option value="<?= htmlspecialchars($full) ?>">— <?= htmlspecialchars(ProductHelper::categoryLabel($child)) ?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('ads.field_title')) ?>
                <input type="text" name="title" required minlength="2" maxlength="120" class="<?= $input ?> mt-1.5">
            </label>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('ads.field_url')) ?>
                <input type="url" name="target_url" required maxlength="500" placeholder="https://" class="<?= $input ?> mt-1.5">
            </label>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('ads.field_units')) ?>
                <input type="number" name="units" id="ad-units" required min="1" max="<?= AdPolicy::MAX_THOUSANDS ?>" value="1" class="<?= $input ?> mt-1.5">
            </label>
            <p class="text-sm font-semibold" id="ad-quote"><?= htmlspecialchars(t('ads.quote_hint')) ?></p>
            <label class="block text-xs font-bold"><?= htmlspecialchars(t('ads.field_image')) ?>
                <input type="file" name="image" id="ad-image" accept="image/jpeg,image/png,image/webp" required class="mt-1.5 block w-full text-sm">
            </label>
            <img id="ad-preview" alt="" class="hidden max-h-48 rounded-2xl border border-black/10">
            <p class="text-xs text-gray-500"><?= htmlspecialchars(t('ads.moderation_hint')) ?></p>
            <button type="submit" class="h-11 px-5 rounded-xl bg-accent-500 hover:bg-accent-400 text-white text-xs font-bold uppercase tracking-wider"><?= htmlspecialchars(t('ads.submit')) ?></button>
        </form>
        <script>
        (function () {
            var rates = <?= js_encode([
                'hero' => AdPolicy::CPM_KZT[AdPolicy::FORMAT_HERO],
                'category' => AdPolicy::CPM_KZT[AdPolicy::FORMAT_CATEGORY],
                'sidebar' => AdPolicy::CPM_KZT[AdPolicy::FORMAT_SIDEBAR],
                'exclusive' => AdPolicy::EXCLUSIVE_WEEK_KZT,
            ]) ?>;
            var format = document.getElementById('ad-format');
            var units = document.getElementById('ad-units');
            var quote = document.getElementById('ad-quote');
            var wrap = document.getElementById('ad-category-wrap');
            var category = document.getElementById('ad-category');
            var image = document.getElementById('ad-image');
            var preview = document.getElementById('ad-preview');
            function paint() {
                if (!format || !units || !quote) return;
                var name = format.value;
                var n = parseInt(units.value, 10);
                if (!n || n < 1) n = 1;
                var max = name === 'exclusive' ? <?= AdPolicy::MAX_WEEKS ?> : <?= AdPolicy::MAX_THOUSANDS ?>;
                units.max = String(max);
                if (n > max) n = max;
                var amount = n * (rates[name] || 0);
                quote.textContent = name === 'exclusive'
                    ? (n + ' × ' + rates.exclusive + ' ₸')
                    : (n + ' × ' + (rates[name] || 0) + ' ₸ / 1 000');
                quote.setAttribute('data-amount', String(amount));
                if (wrap) wrap.classList.toggle('hidden', name === 'hero');
                if (category) category.required = name === 'category' || name === 'exclusive';
            }
            if (format) format.addEventListener('change', paint);
            if (units) units.addEventListener('input', paint);
            paint();
            if (image && preview) {
                image.addEventListener('change', function () {
                    var file = image.files && image.files[0];
                    if (!file) return;
                    preview.src = URL.createObjectURL(file);
                    preview.classList.remove('hidden');
                });
            }
        })();
        </script>
    <?php endif; ?>

    <div class="space-y-3">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('ads.mine')) ?></h2>
        <?php if ($campaigns === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('ads.empty')) ?></p>
        <?php endif; ?>
        <?php foreach ($campaigns as $row): ?>
            <?php
            $status = (string) ($row['status'] ?? '');
            $format = (string) ($row['format'] ?? '');
            $amount = (int) ($row['amount'] ?? 0);
            ?>
            <article class="rounded-2xl border border-black/[0.06] dark:border-white/10 bg-white/80 dark:bg-white/[0.03] p-4 space-y-2">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('ads.format_' . $format)) ?></p>
                        <h3 class="font-semibold"><?= htmlspecialchars((string) $row['title']) ?></h3>
                    </div>
                    <span class="text-xs font-bold px-2 py-1 rounded-lg bg-black/5 dark:bg-white/10"><?= htmlspecialchars(t('ads.status_' . $status)) ?></span>
                </div>
                <p class="text-sm"><?= htmlspecialchars(Wallet::formatMoney($amount)) ?></p>
                <p class="text-xs text-gray-500">
                    <?= htmlspecialchars((int) ($row['impressions_paid'] ?? 0) > 0
                        ? t('ads.stats', [
                            'used' => (string) (int) ($row['impressions_used'] ?? 0),
                            'paid' => (string) (int) ($row['impressions_paid'] ?? 0),
                            'clicks' => (string) (int) ($row['clicks'] ?? 0),
                        ])
                        : t('ads.stats_time', [
                            'used' => (string) (int) ($row['impressions_used'] ?? 0),
                            'clicks' => (string) (int) ($row['clicks'] ?? 0),
                        ])) ?>
                    <?php if (!empty($row['ends_at'])): ?>
                        · <?= htmlspecialchars(t('ads.until', ['until' => AdPolicy::formatUntil((string) $row['ends_at'])])) ?>
                    <?php endif; ?>
                </p>
                <?php if ($status === AdPolicy::STATUS_REJECTED && !empty($row['reject_reason'])): ?>
                    <p class="text-sm text-red-600"><?= htmlspecialchars((string) $row['reject_reason']) ?></p>
                    <form method="post" action="<?= ProductHelper::url('/ads/' . (int) $row['id'] . '/resubmit') ?>" enctype="multipart/form-data" class="space-y-2">
                        <?= csrf_field() ?>
                        <input type="text" name="title" value="<?= htmlspecialchars((string) $row['title']) ?>" required minlength="2" maxlength="120" class="<?= $input ?>">
                        <input type="url" name="target_url" value="<?= htmlspecialchars((string) $row['target_url']) ?>" required class="<?= $input ?>">
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="text-sm">
                        <button type="submit" class="h-10 px-4 rounded-xl bg-ink-900 text-white text-xs font-bold"><?= htmlspecialchars(t('ads.resubmit')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($status === AdPolicy::STATUS_APPROVED && !empty($flags[$format])): ?>
                    <form method="post" action="<?= ProductHelper::url('/ads/' . (int) $row['id'] . '/pay') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="h-10 px-4 rounded-xl bg-accent-500 text-white text-xs font-bold"><?= htmlspecialchars(t('ads.pay', ['amount' => Wallet::formatMoney($amount)])) ?></button>
                    </form>
                <?php elseif ($status === AdPolicy::STATUS_PENDING && !empty($row['redirect_url']) && !empty($flags[$format])): ?>
                    <form method="post" action="<?= ProductHelper::url('/ads/' . (int) $row['id'] . '/pay') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('ads.continue_pay')) ?></button>
                    </form>
                <?php elseif ($status === AdPolicy::STATUS_ACTIVE): ?>
                    <form method="post" action="<?= ProductHelper::url('/ads/' . (int) $row['id'] . '/pause') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('ads.pause')) ?></button>
                    </form>
                <?php elseif ($status === AdPolicy::STATUS_PAUSED && !empty($flags[$format])): ?>
                    <form method="post" action="<?= ProductHelper::url('/ads/' . (int) $row['id'] . '/resume') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="h-10 px-4 rounded-xl border border-black/10 text-xs font-bold"><?= htmlspecialchars(t('ads.resume')) ?></button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
