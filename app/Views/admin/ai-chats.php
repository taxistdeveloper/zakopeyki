<?php use App\Helpers\ProductHelper; ?>
<section class="space-y-6 fade-up">
    <div>
        <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
        <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.ai_chats')) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.ai_chats_hint')) ?></p>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php
    $filters = [
        'human_escalated' => t('admin.ai_status_escalated'),
        'ai_active' => t('admin.ai_status_ai'),
        'closed' => t('admin.ai_status_closed'),
        null => t('admin.tickets_all'),
    ];
    ?>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($filters as $key => $label): ?>
            <?php
            $href = ProductHelper::url('/admin/ai-chats' . ($key !== null ? '?status=' . urlencode((string) $key) : '?status=all'));
            $active = ($filterStatus === $key) || ($key === null && $filterStatus === null);
            ?>
            <a href="<?= $href ?>"
               class="text-xs px-3 py-1.5 rounded-full border transition <?= $active ? 'bg-brand-500 text-white border-brand-500' : 'border-black/10 dark:border-white/10 text-ink-700 dark:text-gray-300 hover:border-brand-400' ?>">
                <?= htmlspecialchars($label) ?>
                <?php if ($key === 'human_escalated' && !empty($aiEscalated)): ?>
                    <span class="ml-1 opacity-90">(<?= (int) $aiEscalated ?>)</span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
        <a href="<?= ProductHelper::url('/admin/ai-export-dataset') ?>"
           class="text-xs px-3 py-1.5 rounded-full border border-violet-300 text-violet-700 dark:text-violet-300 hover:bg-violet-50 dark:hover:bg-violet-950/30 transition ml-auto">
            <?= htmlspecialchars(t('admin.ai_export')) ?>
        </a>
    </div>

    <?php if (empty($conversations)): ?>
        <div class="rounded-2xl border border-dashed border-black/10 dark:border-white/10 px-4 py-10 text-center text-sm text-gray-400">
            <?= htmlspecialchars(t('admin.ai_chats_empty')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft overflow-hidden divide-y divide-black/[0.04] dark:divide-white/5">
            <?php foreach ($conversations as $c): ?>
                <a href="<?= ProductHelper::url('/admin/ai-chats/' . (int) $c['id']) ?>"
                   class="flex flex-wrap items-center justify-between gap-2 px-4 py-3.5 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-ink-900 dark:text-white">
                            #<?= (int) $c['id'] ?>
                            ·
                            <?= htmlspecialchars($c['user_name'] ?? t('admin.ai_guest')) ?>
                            <?php if (!empty($c['user_email'])): ?>
                                <span class="font-normal text-gray-400"> · <?= htmlspecialchars($c['user_email']) ?></span>
                            <?php endif; ?>
                        </p>
                        <p class="text-[11px] text-gray-400 mt-0.5 truncate max-w-xl"><?= htmlspecialchars((string) ($c['last_preview'] ?? '—')) ?></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php
                        $st = (string) ($c['status'] ?? '');
                        $badge = match ($st) {
                            'human_escalated' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                            'closed' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                            default => 'bg-brand-100 text-brand-800 dark:bg-brand-900/40 dark:text-brand-200',
                        };
                        $stLabel = match ($st) {
                            'human_escalated' => t('admin.ai_status_escalated'),
                            'closed' => t('admin.ai_status_closed'),
                            default => t('admin.ai_status_ai'),
                        };
                        ?>
                        <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-1 rounded-lg <?= $badge ?>"><?= htmlspecialchars($stLabel) ?></span>
                        <span class="text-xs font-bold text-brand-600">→</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
