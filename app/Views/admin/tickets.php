<?php
use App\Helpers\ProductHelper;

$tickets = $tickets ?? [];
$filterStatus = $filterStatus ?? null;

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

$filters = [
    null => t('admin.tickets_all'),
    'open' => t('support.status_open'),
    'answered' => t('support.status_answered'),
    'closed' => t('support.status_closed'),
];
?>
<section class="space-y-5 fade-up pb-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.title')) ?></a>
            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-red-500"><?= htmlspecialchars(t('admin.eyebrow')) ?></p>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('admin.tickets')) ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('admin.tickets_hint')) ?></p>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2">
        <?php foreach ($filters as $key => $label):
            $active = $filterStatus === $key;
            $url = ProductHelper::url('/admin/tickets' . ($key ? '?status=' . urlencode($key) : ''));
        ?>
            <a href="<?= $url ?>"
               class="inline-flex h-8 px-3 items-center rounded-xl text-[11px] font-semibold transition <?= $active ? 'bg-brand-600 text-white' : 'bg-white/80 dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/10 text-ink-700 dark:text-gray-300 hover:border-brand-400/50' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($tickets)): ?>
        <div class="text-center py-14 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('admin.tickets_empty')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft divide-y divide-black/[0.04] dark:divide-white/5">
            <?php foreach ($tickets as $ticket):
                $unread = (int) ($ticket['unread_count'] ?? 0);
                $status = (string) ($ticket['status'] ?? 'open');
            ?>
                <a href="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id']) ?>"
                   class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold text-ink-900 dark:text-white truncate">
                            <?= htmlspecialchars((string) $ticket['ticket_number']) ?>
                            <span class="text-gray-400 font-medium">·</span>
                            <?= htmlspecialchars((string) $ticket['subject']) ?>
                        </p>
                        <p class="text-[11px] text-gray-400 mt-0.5 truncate">
                            <?= htmlspecialchars((string) ($ticket['user_name'] ?? '')) ?>
                            · <?= htmlspecialchars(t('support.cat_' . ($ticket['category'] ?? 'general'))) ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-1 truncate"><?= htmlspecialchars((string) ($ticket['last_preview'] ?? '')) ?></p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="inline-flex px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $statusClass($status) ?>">
                            <?= htmlspecialchars($statusLabel($status)) ?>
                        </span>
                        <?php if ($unread > 0): ?>
                            <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-accent-500 text-white text-[10px] font-bold flex items-center justify-center"><?= $unread > 99 ? '99+' : $unread ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
