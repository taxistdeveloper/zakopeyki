<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;
use App\Services\Contest\ContestPolicy;

$items = $items ?? [];
$filters = $filters ?? [];
$total = (int) ($total ?? 0);
$page = (int) ($page ?? 1);
$perPage = (int) ($perPage ?? 12);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$sel = 'h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$qs = static function (array $over) use ($filters): string {
    $q = array_filter(array_merge($filters, $over), static fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
};
?>
<section class="max-w-6xl mx-auto space-y-5 fade-up pb-10">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('contests.eyebrow')) ?></p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('contests.title')) ?></h1>
            <p class="text-sm text-gray-500 mt-1.5 max-w-2xl"><?= htmlspecialchars(t('contests.subtitle')) ?></p>
        </div>
        <div class="flex gap-2">
            <?php if (\App\Core\Auth::check()): ?>
                <a href="<?= ProductHelper::url('/contests/my') ?>" class="h-10 px-4 inline-flex items-center rounded-xl border border-black/10 dark:border-white/10 text-sm font-semibold hover:bg-black/[0.03]"><?= htmlspecialchars(t('contests.my_title')) ?></a>
            <?php endif; ?>
            <?php if (!empty($canCreate)): ?>
                <a href="<?= ProductHelper::url('/contests/new') ?>" class="h-10 px-4 inline-flex items-center rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.create_cta')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($moduleEnabled)): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-300 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars(t('contests.admin_preview_notice')) ?></div>
    <?php endif; ?>

    <form method="get" action="<?= ProductHelper::url('/contests') ?>" class="flex flex-wrap gap-2 rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] p-3">
        <input type="search" name="q" value="<?= htmlspecialchars((string) ($filters['q'] ?? '')) ?>" placeholder="<?= htmlspecialchars(t('contests.filter_q')) ?>" class="<?= $sel ?> flex-1 min-w-[180px]">
        <select name="status" class="<?= $sel ?>" aria-label="<?= htmlspecialchars(t('contests.filter_status')) ?>">
            <option value=""><?= htmlspecialchars(t('contests.filter_status_all')) ?></option>
            <?php foreach (['active', 'upcoming', 'finished'] as $s): ?>
                <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= htmlspecialchars(t('contests.filter_status_' . $s)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="mechanic" class="<?= $sel ?>" aria-label="<?= htmlspecialchars(t('contests.filter_mechanic')) ?>">
            <option value=""><?= htmlspecialchars(t('contests.filter_mechanic_all')) ?></option>
            <?php foreach (ContestPolicy::MECHANICS as $m): ?>
                <option value="<?= $m ?>" <?= ($filters['mechanic'] ?? '') === $m ? 'selected' : '' ?>><?= htmlspecialchars(t('contests.mechanic.' . $m)) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="region" class="<?= $sel ?>" aria-label="<?= htmlspecialchars(t('contests.filter_region')) ?>">
            <option value=""><?= htmlspecialchars(t('contests.filter_region_all')) ?></option>
            <?php foreach (ContestPolicy::REGIONS as $r): ?>
                <option value="<?= $r ?>" <?= ($filters['region'] ?? '') === $r ? 'selected' : '' ?>><?= htmlspecialchars(t('contests.region.' . $r)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($categories)): ?>
        <select name="category" class="<?= $sel ?>" aria-label="<?= htmlspecialchars(t('contests.filter_category')) ?>">
            <option value=""><?= htmlspecialchars(t('contests.filter_category_all')) ?></option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= ($filters['category'] ?? '') === $c ? 'selected' : '' ?>><?= htmlspecialchars(ProductHelper::categoryLabel($c)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if (!empty($organizers)): ?>
        <select name="organizer" class="<?= $sel ?>" aria-label="<?= htmlspecialchars(t('contests.filter_organizer')) ?>">
            <option value="0"><?= htmlspecialchars(t('contests.filter_organizer_all')) ?></option>
            <?php foreach ($organizers as $o): ?>
                <option value="<?= (int) $o['id'] ?>" <?= (int) ($filters['organizer'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $o['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="h-10 px-4 rounded-xl bg-ink-900 dark:bg-white dark:text-ink-900 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.filter_apply')) ?></button>
    </form>

    <?php if ($items === []): ?>
        <div class="rounded-[22px] border border-dashed border-black/10 dark:border-white/10 p-10 text-center text-sm text-gray-500"><?= htmlspecialchars(t('contests.empty')) ?></div>
    <?php else: ?>
        <p class="text-xs text-gray-400"><?= htmlspecialchars(t('contests.found', ['n' => $total])) ?></p>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($items as $c): ?>
                <?php $cover = ContestView::coverUrl($c); ?>
                <a href="<?= ProductHelper::url('/contests/' . rawurlencode((string) $c['slug'])) ?>" class="group rounded-[22px] overflow-hidden border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft hover:shadow-lift transition flex flex-col">
                    <div class="aspect-[16/9] bg-gradient-to-br from-brand-500/20 via-violet-500/15 to-amber-400/20 flex items-center justify-center overflow-hidden">
                        <?php if ($cover): ?>
                            <img src="<?= htmlspecialchars($cover) ?>" alt="" loading="lazy" class="w-full h-full object-cover group-hover:scale-[1.02] transition">
                        <?php else: ?>
                            <?= \App\Helpers\IconHelper::svg('gift', 'w-10 h-10 text-brand-500/70') ?>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 flex-1 flex flex-col gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <?= ContestView::statusBadge((string) $c['status']) ?>
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide bg-black/[0.04] dark:bg-white/10 text-gray-500"><?= htmlspecialchars(t('contests.mechanic.' . $c['mechanic'])) ?></span>
                        </div>
                        <h2 class="font-display font-bold text-ink-900 dark:text-white leading-snug"><?= htmlspecialchars((string) $c['title']) ?></h2>
                        <p class="text-sm text-brand-600 font-semibold">🎁 <?= htmlspecialchars((string) $c['prize_summary']) ?></p>
                        <p class="text-xs text-gray-500 mt-auto"><?= htmlspecialchars(ContestView::organizerName($c)) ?> · <?= htmlspecialchars(t('contests.region.' . ($c['region'] ?: 'kz'))) ?></p>
                        <p class="text-[11px] text-gray-400">
                            <?php if ($c['status'] === 'active'): ?>
                                <?= htmlspecialchars(t('contests.until', ['date' => ContestView::date($c['ends_at'])])) ?> · <?= htmlspecialchars(t('contests.participants_n', ['n' => (int) $c['entries_count']])) ?>
                            <?php elseif ($c['status'] === 'scheduled'): ?>
                                <?= htmlspecialchars(t('contests.starts', ['date' => ContestView::date($c['starts_at'])])) ?>
                            <?php else: ?>
                                <?= htmlspecialchars(t('contests.ended', ['date' => ContestView::date($c['ends_at'])])) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="flex justify-center gap-1 pt-2" aria-label="pagination">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <a href="<?= ProductHelper::url('/contests') . htmlspecialchars($qs(['page' => $p])) ?>" class="min-w-9 h-9 px-3 inline-flex items-center justify-center rounded-xl text-sm font-semibold <?= $p === $page ? 'bg-brand-500 text-white' : 'border border-black/10 dark:border-white/10' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <p class="text-[11px] text-gray-400 leading-relaxed"><?= htmlspecialchars(t('contests.platform_disclaimer')) ?></p>
</section>
