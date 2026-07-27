<?php
use App\Helpers\ProductHelper;

$tickets = $tickets ?? [];

$statusLabel = static function (string $status): string {
    return match ($status) {
        'answered' => t('support.status_answered'),
        'closed' => t('support.status_closed'),
        default => t('support.status_open'),
    };
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'answered' => 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
        'closed' => 'bg-gray-100 text-gray-500 dark:bg-white/10',
        default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    };
};
?>
<section class="max-w-2xl mx-auto space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('support.eyebrow')) ?></p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('support.title')) ?></h1>
            <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('support.subtitle')) ?></p>
        </div>
        <a href="<?= ProductHelper::url('/support/new') ?>"
           class="inline-flex items-center h-11 px-5 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider transition">
            <?= htmlspecialchars(t('support.new')) ?>
        </a>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if (empty($tickets)): ?>
        <div class="text-center py-16 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm space-y-3">
            <p><?= htmlspecialchars(t('support.empty_list')) ?></p>
            <a href="<?= ProductHelper::url('/support/new') ?>" class="inline-block text-brand-600 font-semibold hover:underline"><?= htmlspecialchars(t('support.create_link')) ?></a>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft divide-y divide-black/[0.04] dark:divide-white/5">
            <?php foreach ($tickets as $ticket):
                $unread = (int) ($ticket['unread_count'] ?? 0);
                $status = (string) ($ticket['status'] ?? 'open');
            ?>
                <a href="<?= ProductHelper::url('/support/' . (int) $ticket['id']) ?>"
                   class="flex gap-3 p-4 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-sm text-ink-900 dark:text-white truncate">
                                <?= htmlspecialchars((string) $ticket['ticket_number']) ?>
                                <span class="text-gray-400 font-medium">·</span>
                                <?= htmlspecialchars((string) $ticket['subject']) ?>
                            </p>
                            <?php if (!empty($ticket['last_message_at'])): ?>
                                <span class="text-[10px] text-gray-400 flex-shrink-0"><?= htmlspecialchars(substr((string) $ticket['last_message_at'], 0, 16)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                            <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $statusClass($status) ?>">
                                <?= htmlspecialchars($statusLabel($status)) ?>
                            </span>
                            <span class="text-[11px] text-gray-400"><?= htmlspecialchars(t('support.cat_' . ($ticket['category'] ?? 'general'))) ?></span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-1.5">
                            <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($ticket['last_preview'] ?? '') ?></p>
                            <?php if ($unread > 0): ?>
                                <span class="flex-shrink-0 min-w-[1.25rem] h-5 px-1.5 rounded-full bg-accent-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $unread > 99 ? '99+' : $unread ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
