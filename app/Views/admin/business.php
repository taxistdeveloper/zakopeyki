<?php

use App\Helpers\ProductHelper;
use App\Models\BusinessUpgradeRequest;

$requests = $requests ?? [];
$filterStatus = $filterStatus ?? 'pending';
$pendingCount = (int) ($pendingCount ?? 0);

$filters = [
    'pending' => t('admin.business_filter_pending'),
    'approved' => t('admin.business_filter_approved'),
    'rejected' => t('admin.business_filter_rejected'),
    'all' => t('admin.business_filter_all'),
];
?>
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-600"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.business')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.business_hint')) ?></p>
        </div>
        <form method="post" action="<?= ProductHelper::url('/admin/business/reset-limits') ?>" onsubmit="return confirm(<?= json_encode(t('admin.business_reset_confirm')) ?>)">
            <button type="submit" class="text-xs font-bold px-4 py-2.5 rounded-xl border border-black/10 dark:border-white/10 hover:border-brand-400 transition">
                <?= htmlspecialchars(t('admin.business_reset_limits')) ?>
            </button>
        </form>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300 border border-red-100 dark:border-red-900/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2">
        <?php foreach ($filters as $key => $label): ?>
            <a href="<?= ProductHelper::url('/admin/business?status=' . urlencode($key)) ?>"
               class="text-xs font-bold px-3 py-2 rounded-xl border transition <?= $filterStatus === $key ? 'bg-ink-900 text-white border-ink-900' : 'border-black/10 dark:border-white/10 text-ink-700 dark:text-gray-300' ?>">
                <?= htmlspecialchars($label) ?><?= $key === 'pending' ? ' (' . $pendingCount . ')' : '' ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (!$requests): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-5 py-10 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('admin.business_empty')) ?>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($requests as $req):
                $docs = BusinessUpgradeRequest::decodeDocs($req['doc_files'] ?? null);
                $status = (string) ($req['status'] ?? 'pending');
                ?>
                <article class="rounded-2xl bg-white/90 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 p-4 sm:p-5 shadow-soft space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-bold text-ink-900 dark:text-white">
                                <?= htmlspecialchars((string) ($req['business_name'] ?? '')) ?>
                                <span class="text-xs font-semibold text-gray-400 uppercase ml-1"><?= htmlspecialchars(strtoupper((string) ($req['entity_type'] ?? ''))) ?></span>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?= htmlspecialchars((string) ($req['user_name'] ?? '')) ?> · <?= htmlspecialchars((string) ($req['user_email'] ?? '')) ?>
                                · БИН/ИИН <?= htmlspecialchars((string) ($req['bin'] ?? '')) ?>
                            </div>
                            <?php if (!empty($req['address'])): ?>
                                <div class="text-xs text-gray-400 mt-1"><?= htmlspecialchars((string) $req['address']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="text-[10px] font-bold uppercase tracking-wide px-2.5 py-1 rounded-lg
                            <?= $status === 'pending' ? 'bg-amber-100 text-amber-800' : ($status === 'approved' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700') ?>">
                            <?= htmlspecialchars(t('admin.business_status_' . $status)) ?>
                        </span>
                    </div>

                    <?php if ($docs): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($docs as $doc): ?>
                                <a class="text-xs font-semibold text-brand-600 hover:underline" href="<?= ProductHelper::url('public/uploads/business/' . rawurlencode($doc)) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($doc) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($status === 'pending'): ?>
                        <div class="flex flex-col sm:flex-row gap-2 pt-2 border-t border-black/[0.05] dark:border-white/10">
                            <form method="post" action="<?= ProductHelper::url('/admin/business/' . (int) $req['id'] . '/approve') ?>" class="flex-1 flex gap-2">
                                <input type="text" name="note" placeholder="<?= htmlspecialchars(t('admin.business_note')) ?>" class="flex-1 h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-transparent text-sm">
                                <button type="submit" class="h-10 px-4 rounded-xl bg-emerald-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.business_approve')) ?></button>
                            </form>
                            <form method="post" action="<?= ProductHelper::url('/admin/business/' . (int) $req['id'] . '/reject') ?>" class="flex-1 flex gap-2">
                                <input type="text" name="reason" required placeholder="<?= htmlspecialchars(t('admin.business_reject_reason')) ?>" class="flex-1 h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-transparent text-sm">
                                <button type="submit" class="h-10 px-4 rounded-xl bg-red-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.business_reject')) ?></button>
                            </form>
                        </div>
                    <?php elseif ($status === 'approved' && ($req['user_account_type'] ?? '') === 'business'): ?>
                        <?php if (!empty($req['admin_note'])): ?>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars((string) $req['admin_note']) ?></p>
                        <?php endif; ?>
                        <form method="post" action="<?= ProductHelper::url('/admin/business/' . (int) $req['id'] . '/revoke') ?>" class="flex flex-col sm:flex-row gap-2 pt-2 border-t border-black/[0.05] dark:border-white/10" onsubmit="return confirm(<?= json_encode(t('admin.business_revoke_confirm')) ?>)">
                            <input type="text" name="reason" required placeholder="<?= htmlspecialchars(t('admin.business_revoke_reason')) ?>" class="flex-1 h-10 px-3 rounded-xl border border-black/10 dark:border-white/10 bg-transparent text-sm">
                            <button type="submit" class="h-10 px-4 rounded-xl bg-red-600 text-white text-xs font-bold"><?= htmlspecialchars(t('admin.business_revoke')) ?></button>
                        </form>
                    <?php elseif (!empty($req['admin_note'])): ?>
                        <p class="text-xs text-gray-500"><?= htmlspecialchars((string) $req['admin_note']) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
