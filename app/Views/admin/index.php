<?php use App\Helpers\ProductHelper; ?>
<section class="space-y-6 fade-up">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500 mb-1"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
        <h2 class="font-display text-xl sm:text-2xl font-bold tracking-tight text-ink-900 dark:text-white"><?= htmlspecialchars(t('admin.heading')) ?></h2>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if (!empty($disputes)): ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-violet-200/70 dark:border-violet-900/40 shadow-soft overflow-hidden">
            <div class="px-4 py-3.5 border-b border-violet-100 dark:border-violet-900/30 bg-violet-50/50 dark:bg-violet-950/20">
                <h3 class="font-display font-bold text-violet-800 dark:text-violet-300"><?= htmlspecialchars(t('admin.disputes')) ?> (<?= count($disputes) ?>)</h3>
                <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('admin.disputes_hint')) ?></p>
            </div>
            <div class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($disputes as $d): ?>
                    <a href="<?= ProductHelper::url('/orders/' . (int) $d['id']) ?>" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 hover:bg-violet-50/40 dark:hover:bg-white/[0.03] transition">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-ink-900 dark:text-white">#<?= (int) $d['id'] ?> · <?= htmlspecialchars($d['product_title']) ?></p>
                            <p class="text-[11px] text-gray-400 mt-0.5"><?= htmlspecialchars($d['buyer_name']) ?> ↔ <?= htmlspecialchars($d['seller_name']) ?></p>
                        </div>
                        <span class="text-xs font-bold text-violet-600"><?= htmlspecialchars(t('admin.resolve')) ?> →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.users')) ?></div>
            <div class="font-display text-2xl font-bold mt-1"><?= (int) $userCount ?></div>
        </div>
        <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
            <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400"><?= htmlspecialchars(t('admin.active_lots')) ?></div>
            <div class="font-display text-2xl font-bold mt-1"><?= array_sum($counts) ?></div>
        </div>
        <?php $i = 0; foreach ($counts as $type => $cnt): if ($i++ >= 2) break; ?>
            <div class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 shadow-soft">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 truncate"><?= ProductHelper::label($type) ?></div>
                <div class="font-display text-2xl font-bold mt-1"><?= (int) $cnt ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="overflow-x-auto bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft">
        <table class="w-full text-left text-xs">
            <thead class="bg-ink-50/80 dark:bg-white/[0.03] border-b border-black/[0.06] dark:border-white/10">
                <tr>
                    <th class="px-4 py-3.5 font-semibold text-gray-500">ID</th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.name')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.type')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.status')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.price')) ?></th>
                    <th class="px-4 py-3.5 font-semibold text-gray-500"><?= htmlspecialchars(t('admin.actions')) ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-black/[0.04] dark:divide-white/5">
                <?php foreach ($items as $item): ?>
                    <tr class="hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                        <td class="px-4 py-3.5 text-gray-400"><?= (int) $item['id'] ?></td>
                        <td class="px-4 py-3.5 font-semibold max-w-[220px] truncate text-ink-800 dark:text-gray-200"><?= htmlspecialchars($item['title']) ?></td>
                        <td class="px-4 py-3.5"><?= ProductHelper::label($item['type']) ?></td>
                        <td class="px-4 py-3.5">
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $item['status'] === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-gray-100 text-gray-500 dark:bg-white/10' ?>"><?= $item['status'] ?></span>
                        </td>
                        <td class="px-4 py-3.5 font-display font-bold"><?= htmlspecialchars(ProductHelper::formatPrice($item)) ?></td>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <a href="<?= ProductHelper::url('/product/' . $item['id']) ?>" class="text-brand-600 hover:underline font-semibold"><?= htmlspecialchars(t('admin.open')) ?></a>
                                <form method="post" action="<?= ProductHelper::url('/admin/toggle/' . $item['id']) ?>" class="inline">
                                    <button class="text-amber-600 hover:underline font-semibold"><?= htmlspecialchars(t('admin.archive')) ?></button>
                                </form>
                                <form method="post" action="<?= ProductHelper::url('/admin/delete/' . $item['id']) ?>" class="inline" onsubmit="return confirm(<?= json_encode(t('admin.confirm_delete')) ?>)">
                                    <button class="text-red-600 hover:underline font-semibold"><?= htmlspecialchars(t('admin.delete')) ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
