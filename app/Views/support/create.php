<?php
use App\Helpers\ProductHelper;

$categories = $categories ?? [];
$category = $category ?? 'general';
$old = $old ?? [];
$selected = (string) ($old['category'] ?? $category);
?>
<section class="max-w-xl mx-auto space-y-5 fade-up pb-8">
    <div>
        <a href="<?= ProductHelper::url('/support') ?>" class="inline-flex items-center text-sm text-gray-400 hover:text-brand-600 transition mb-3">← <?= htmlspecialchars(t('support.back')) ?></a>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('support.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('support.create_title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('support.create_hint')) ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= ProductHelper::url('/support') ?>" class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 shadow-soft p-5 sm:p-6 space-y-4">
        <?= csrf_field() ?>

        <div>
            <label for="support-category" class="block text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1.5"><?= htmlspecialchars(t('support.category')) ?></label>
            <select id="support-category" name="category" class="ui-input w-full px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $selected === $cat ? 'selected' : '' ?>><?= htmlspecialchars(t('support.cat_' . $cat)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="support-subject" class="block text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1.5"><?= htmlspecialchars(t('support.subject')) ?></label>
            <input id="support-subject" type="text" name="subject" required maxlength="200"
                   value="<?= htmlspecialchars((string) ($old['subject'] ?? '')) ?>"
                   placeholder="<?= htmlspecialchars(t('support.subject_placeholder')) ?>"
                   class="ui-input w-full px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm">
        </div>

        <div>
            <label for="support-body" class="block text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-1.5"><?= htmlspecialchars(t('support.message')) ?></label>
            <textarea id="support-body" name="body" required maxlength="4000" rows="7"
                      placeholder="<?= htmlspecialchars(t('support.message_placeholder')) ?>"
                      class="ui-input w-full px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm resize-y min-h-[140px]"><?= htmlspecialchars((string) ($old['body'] ?? '')) ?></textarea>
        </div>

        <button type="submit" class="w-full h-12 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider transition">
            <?= htmlspecialchars(t('support.submit')) ?>
        </button>
    </form>
</section>
