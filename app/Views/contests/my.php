<?php
use App\Helpers\ContestView;
use App\Helpers\ProductHelper;

$organized = $organized ?? [];
$participations = $participations ?? [];
$wins = $wins ?? [];
$card = 'rounded-[22px] border border-black/[0.06] dark:border-white/10 bg-white/90 dark:bg-white/[0.04] shadow-soft p-4 sm:p-5';
?>
<section class="max-w-4xl mx-auto space-y-5 fade-up pb-10">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/contests') ?>" class="text-sm text-gray-400 hover:text-brand-600">← <?= htmlspecialchars(t('contests.title')) ?></a>
            <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars(t('contests.my_title')) ?></h1>
        </div>
        <?php if ($denyReason === null): ?>
            <a href="<?= ProductHelper::url('/contests/new') ?>" class="h-10 px-4 inline-flex items-center rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-bold"><?= htmlspecialchars(t('contests.create_cta')) ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 text-emerald-800 border border-emerald-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('contests.my_organized')) ?></h2>
        <?php if ($denyReason !== null): ?>
            <p class="text-sm text-gray-500 mb-3"><?= htmlspecialchars(t('contests.deny.' . $denyReason)) ?></p>
        <?php endif; ?>
        <?php if ($organized === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.my_none_organized')) ?></p>
        <?php else: ?>
            <ul class="divide-y divide-black/[0.05] dark:divide-white/10">
                <?php foreach ($organized as $c): ?>
                    <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold truncate"><?= htmlspecialchars((string) $c['title']) ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars(ContestView::date($c['starts_at'])) ?> — <?= htmlspecialchars(ContestView::date($c['ends_at'])) ?> · <?= htmlspecialchars(t('contests.participants_n', ['n' => (int) $c['entries_count']])) ?></p>
                        </div>
                        <div class="flex items-center gap-2">
                            <?= ContestView::statusBadge((string) $c['status']) ?>
                            <a href="<?= ProductHelper::url('/contests/' . (int) $c['id'] . '/manage') ?>" class="text-xs font-bold text-brand-600 hover:underline"><?= htmlspecialchars(t('contests.manage_title')) ?></a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('contests.my_participations')) ?></h2>
        <?php if ($participations === []): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('contests.my_none_participations')) ?></p>
        <?php else: ?>
            <ul class="divide-y divide-black/[0.05] dark:divide-white/10">
                <?php foreach ($participations as $p): ?>
                    <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                        <a class="font-semibold hover:text-brand-600" href="<?= ProductHelper::url('/contests/' . rawurlencode((string) $p['slug'])) ?>"><?= htmlspecialchars((string) $p['title']) ?></a>
                        <span class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.entry_status.' . $p['status'])) ?> · <?= ContestView::statusBadge((string) $p['contest_status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($wins !== []): ?>
    <div class="<?= $card ?>">
        <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('contests.my_wins')) ?></h2>
        <ul class="divide-y divide-black/[0.05] dark:divide-white/10">
            <?php foreach ($wins as $w): ?>
                <li class="py-3 flex flex-wrap items-center justify-between gap-2">
                    <a class="font-semibold hover:text-brand-600" href="<?= ProductHelper::url('/contests/' . rawurlencode((string) $w['slug']) . '#results') ?>">🎁 <?= htmlspecialchars((string) $w['prize_title']) ?> — <?= htmlspecialchars((string) $w['title']) ?></a>
                    <span class="text-xs text-gray-500"><?= htmlspecialchars(t('contests.fulfilment.' . $w['fulfilment_status'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</section>
