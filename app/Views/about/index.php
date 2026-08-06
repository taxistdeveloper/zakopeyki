<?php
use App\Helpers\ProductHelper;

/** @var list<array{slug: string, title: string, file: string, url: string}> $documents */
$documents = $documents ?? [];
?>
<section class="max-w-2xl mx-auto space-y-6 fade-up pb-10">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('about.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('about.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-2 leading-relaxed"><?= htmlspecialchars(t('about.lead')) ?></p>
    </div>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.what_title')) ?></h2>
        <p class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t('about.what_text')) ?></p>
    </div>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.how_title')) ?></h2>
        <ul class="space-y-3 text-sm text-ink-700/80 dark:text-gray-300">
            <li class="flex gap-3"><span class="text-brand-500 font-bold">1.</span> <span><?= htmlspecialchars(t('about.how_1')) ?></span></li>
            <li class="flex gap-3"><span class="text-brand-500 font-bold">2.</span> <span><?= htmlspecialchars(t('about.how_2')) ?></span></li>
            <li class="flex gap-3"><span class="text-brand-500 font-bold">3.</span> <span><?= htmlspecialchars(t('about.how_3')) ?></span></li>
        </ul>
    </div>

    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.city_title')) ?></h2>
        <p class="text-sm text-ink-700/80 dark:text-gray-300 leading-relaxed"><?= htmlspecialchars(t('about.city_text')) ?></p>
    </div>

    <?php if ($documents !== []): ?>
    <div class="rounded-[24px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-5 sm:p-6 space-y-4">
        <div>
            <h2 class="font-display text-lg font-bold text-ink-900 dark:text-white"><?= htmlspecialchars(t('about.docs_title')) ?></h2>
            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed"><?= htmlspecialchars(t('about.docs_lead')) ?></p>
        </div>
        <ul class="divide-y divide-black/[0.06] dark:divide-white/10">
            <?php foreach ($documents as $doc): ?>
            <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 dark:text-brand-300" aria-hidden="true">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-ink-900 dark:text-white leading-snug"><?= htmlspecialchars($doc['title']) ?></p>
                    <button
                        type="button"
                        class="js-open-doc mt-2 inline-flex items-center h-8 px-3 rounded-lg border border-black/[0.08] dark:border-white/15 text-[12px] font-semibold text-ink-800 dark:text-gray-200 hover:border-brand-400/50 hover:text-brand-700 dark:hover:text-brand-300 transition"
                        data-doc-url="<?= htmlspecialchars($doc['url']) ?>"
                        data-doc-title="<?= htmlspecialchars($doc['title']) ?>"
                    >
                        <?= htmlspecialchars(t('about.docs_details')) ?>
                    </button>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-3 pt-1">
        <a href="<?= ProductHelper::url('/catalog/new') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-display font-bold text-xs uppercase tracking-wide transition">
            <?= htmlspecialchars(t('about.cta_catalog')) ?>
        </a>
        <a href="<?= ProductHelper::url('/register') ?>" class="inline-flex items-center justify-center h-11 px-5 rounded-xl border border-black/[0.1] dark:border-white/15 text-ink-800 dark:text-gray-200 font-semibold text-sm hover:border-brand-400/50 transition">
            <?= htmlspecialchars(t('about.cta_register')) ?>
        </a>
    </div>
</section>

<?php if ($documents !== []): ?>
<div id="doc-modal" class="fixed inset-0 z-[100] hidden" aria-hidden="true">
    <div class="absolute inset-0 bg-ink-900/50 backdrop-blur-sm" data-doc-close></div>
    <div class="relative z-10 flex min-h-full items-end sm:items-center justify-center p-0 sm:p-4">
        <div role="dialog" aria-modal="true" aria-labelledby="doc-modal-title"
             class="w-full sm:max-w-3xl max-h-[92vh] sm:max-h-[88vh] flex flex-col rounded-t-[28px] sm:rounded-[28px] bg-white dark:bg-ink-900 shadow-2xl border border-white/70 dark:border-white/10 overflow-hidden">
            <div class="flex items-start justify-between gap-3 px-5 pt-5 pb-3 border-b border-black/5 dark:border-white/10 shrink-0">
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('about.docs_title')) ?></p>
                    <h2 id="doc-modal-title" class="font-display text-base sm:text-lg font-bold text-ink-900 dark:text-white mt-0.5 leading-snug truncate"></h2>
                </div>
                <button type="button" data-doc-close class="h-9 w-9 rounded-xl border border-black/10 dark:border-white/15 text-gray-500 hover:text-ink-900 dark:hover:text-white hover:bg-gray-50 dark:hover:bg-white/5 transition shrink-0" aria-label="<?= htmlspecialchars(t('about.docs_close')) ?>">&times;</button>
            </div>

            <div class="flex-1 min-h-0 bg-gray-50 dark:bg-black/30">
                <iframe id="doc-iframe" title="<?= htmlspecialchars(t('about.docs_title')) ?>" class="block w-full h-[62vh] sm:h-[68vh] border-0 bg-white" src="about:blank"></iframe>
            </div>

            <div class="shrink-0 border-t border-black/5 dark:border-white/10 px-5 py-3 bg-white dark:bg-ink-900 flex flex-wrap gap-2">
                <a id="doc-open-link" href="#" target="_blank" rel="noopener" class="inline-flex items-center justify-center h-10 px-4 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-display font-bold text-[11px] uppercase tracking-wide transition">
                    <?= htmlspecialchars(t('about.docs_open')) ?>
                </a>
                <button type="button" data-doc-close class="inline-flex items-center justify-center h-10 px-4 rounded-xl border border-black/[0.1] dark:border-white/15 text-ink-800 dark:text-gray-200 font-semibold text-sm hover:border-brand-400/50 transition">
                    <?= htmlspecialchars(t('about.docs_close')) ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('doc-modal');
    var iframe = document.getElementById('doc-iframe');
    var titleEl = document.getElementById('doc-modal-title');
    var openLink = document.getElementById('doc-open-link');
    if (!modal || !iframe || !titleEl || !openLink) return;

    function openDoc(url, title) {
        titleEl.textContent = title || '';
        openLink.href = url;
        iframe.src = url;
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeDoc() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        iframe.src = 'about:blank';
        openLink.removeAttribute('href');
    }

    document.querySelectorAll('.js-open-doc').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openDoc(btn.getAttribute('data-doc-url') || '', btn.getAttribute('data-doc-title') || '');
        });
    });
    document.querySelectorAll('[data-doc-close]').forEach(function (el) {
        el.addEventListener('click', closeDoc);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeDoc();
    });
})();
</script>
<?php endif; ?>
