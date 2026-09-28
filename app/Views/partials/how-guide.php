<?php

use App\Helpers\HowGuides;

$guide = (string) ($guide ?? '');
$part = (string) ($part ?? 'lead');
$spec = HowGuides::get($guide);
if ($spec === null) {
    return;
}
$id = preg_replace('/[^a-z0-9_-]/', '', $guide) ?: 'guide';
$modalId = 'how-modal-' . $id;
$openId = 'how-open-' . $id;

$renderSteps = static function (string $prefix, int $count): void {
    echo '<ol class="space-y-2 text-sm text-ink-800 dark:text-gray-200 list-decimal pl-5">';
    for ($i = 1; $i <= $count; $i++) {
        echo '<li class="pl-1 leading-snug">' . htmlspecialchars(t($prefix . $i)) . '</li>';
    }
    echo '</ol>';
};
?>
<?php if ($part === 'lead'): ?>
<div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2.5">
    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-2xl"><?= htmlspecialchars(t($spec['lead'])) ?></p>
    <button type="button" id="<?= htmlspecialchars($openId) ?>"
            class="inline-flex items-center gap-1.5 h-9 px-3.5 rounded-xl border border-black/[0.1] dark:border-white/15 bg-white dark:bg-white/5 text-ink-800 dark:text-gray-200 text-xs font-semibold hover:border-brand-400/50 hover:text-brand-700 dark:hover:text-brand-300 transition shrink-0"
            aria-haspopup="dialog" aria-controls="<?= htmlspecialchars($modalId) ?>" aria-expanded="false">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.2 2.4c-.7.3-1.2.8-1.2 1.6V14"/><path d="M12 17h.01"/></svg>
        <?= htmlspecialchars(t('catalog.how_use')) ?>
    </button>
</div>
<?php if (!empty($spec['kinds'])): ?>
    <div class="mt-3 max-w-2xl">
        <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars(t($spec['kindsTitle'])) ?></p>
        <ol class="mt-1.5 space-y-1 text-sm text-gray-500 dark:text-gray-400 list-decimal pl-5">
            <?php foreach ($spec['kinds'] as $kindKey): ?>
                <li class="pl-1 leading-snug"><?= htmlspecialchars(t($kindKey)) ?></li>
            <?php endforeach; ?>
        </ol>
    </div>
<?php endif; ?>
<?php endif; ?>
<?php if ($part === 'modal'): ?>
<div id="<?= htmlspecialchars($modalId) ?>" class="fixed inset-0 z-[110] hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-ink-900/55 backdrop-blur-sm" data-how-close></div>
    <div class="relative z-10 flex min-h-full items-end sm:items-center justify-center p-0 sm:p-4" data-how-close>
        <div role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($modalId) ?>-title" id="<?= htmlspecialchars($modalId) ?>-panel"
             class="w-full sm:max-w-xl max-h-[92vh] flex flex-col rounded-t-[28px] sm:rounded-[28px] bg-white dark:bg-ink-800 shadow-lift border border-white/60 dark:border-white/10 overflow-hidden">
            <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-black/[0.06] dark:border-white/10 shrink-0">
                <h2 id="<?= htmlspecialchars($modalId) ?>-title" class="font-display text-base sm:text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('catalog.how_use')) ?></h2>
                <button type="button" id="<?= htmlspecialchars($modalId) ?>-dismiss" data-how-close class="h-9 w-9 rounded-xl border border-black/10 dark:border-white/15 text-gray-500 hover:text-ink-900 dark:hover:text-white hover:bg-gray-50 dark:hover:bg-white/5 transition shrink-0" aria-label="<?= htmlspecialchars(t('catalog.how_close')) ?>">&times;</button>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto px-5 py-4 space-y-5">
                <?php foreach ($spec['blocks'] as $block): ?>
                    <section class="space-y-2.5">
                        <?php if (!empty($block['title'])): ?>
                            <h3 class="font-display font-bold text-sm text-ink-900 dark:text-white"><?= htmlspecialchars(t($block['title'])) ?></h3>
                        <?php endif; ?>
                        <?php if (!empty($block['text'])): ?>
                            <p class="text-sm text-ink-800 dark:text-gray-200 leading-snug"><?= htmlspecialchars(t($block['text'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($block['points'])): ?>
                            <ol class="space-y-3 text-sm text-ink-800 dark:text-gray-200 list-decimal pl-5">
                                <?php foreach ($block['points'] as $point): ?>
                                    <li class="pl-1 leading-snug">
                                        <p class="font-semibold text-ink-900 dark:text-white"><?= htmlspecialchars(t($point['title'])) ?></p>
                                        <p class="mt-1"><?= htmlspecialchars(t($point['text'])) ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                        <?php if (!empty($block['steps'])): ?>
                            <?php if (!empty($block['stepsTitle'])): ?>
                                <h4 class="font-display font-semibold text-[13px] text-ink-900 dark:text-white"><?= htmlspecialchars(t($block['stepsTitle'])) ?></h4>
                            <?php endif; ?>
                            <?php $renderSteps($block['steps'], (int) $block['stepsCount']); ?>
                        <?php endif; ?>
                        <?php if (!empty($block['aside'])): ?>
                            <div>
                                <?php if (!empty($block['asideTitle'])): ?>
                                    <h4 class="font-display font-semibold text-[13px] text-ink-900 dark:text-white"><?= htmlspecialchars(t($block['asideTitle'])) ?></h4>
                                <?php endif; ?>
                                <p class="mt-1.5 text-sm text-ink-800 dark:text-gray-200 leading-snug"><?= htmlspecialchars(t($block['aside'])) ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($block['callout'])): ?>
                            <p class="text-sm leading-snug text-ink-800 dark:text-gray-200 bg-amber-50 dark:bg-amber-500/10 border border-amber-200/70 dark:border-amber-400/20 rounded-2xl px-4 py-3"><?= htmlspecialchars(t($block['callout'])) ?></p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
            <div class="shrink-0 border-t border-black/[0.06] dark:border-white/10 px-5 py-4">
                <button type="button" data-how-close class="w-full bg-accent-500 hover:bg-accent-400 text-white font-display font-bold py-3.5 rounded-2xl text-xs uppercase tracking-wider transition"><?= htmlspecialchars(t('catalog.how_ok')) ?></button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById(<?= json_encode($modalId) ?>);
    var openBtn = document.getElementById(<?= json_encode($openId) ?>);
    if (!modal || !openBtn) return;

    function openHow() {
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        openBtn.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        var closeBtn = document.getElementById(<?= json_encode($modalId . '-dismiss') ?>);
        if (closeBtn) closeBtn.focus();
    }

    function closeHow() {
        if (modal.classList.contains('hidden')) return;
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        openBtn.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        openBtn.focus();
    }

    var panel = document.getElementById(<?= json_encode($modalId . '-panel') ?>);
    if (panel) panel.addEventListener('click', function (e) { e.stopPropagation(); });
    openBtn.addEventListener('click', openHow);
    modal.querySelectorAll('[data-how-close]').forEach(function (el) {
        el.addEventListener('click', closeHow);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeHow();
    });
})();
</script>
<?php endif; ?>
