<?php
use App\Helpers\ProductHelper;

$ticket = $ticket ?? [];
$messages = $messages ?? [];
$status = (string) ($ticket['status'] ?? 'open');
$closed = $status === 'closed';

$statusLabel = match ($status) {
    'answered' => t('support.status_answered'),
    'closed' => t('support.status_closed'),
    default => t('support.status_open'),
};
$statusClass = match ($status) {
    'answered' => 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
    'closed' => 'bg-gray-100 text-gray-500 dark:bg-white/10',
    default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
};
?>
<section class="max-w-3xl mx-auto fade-up pb-4 flex flex-col" style="min-height: calc(100vh - 8rem);">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div class="flex items-start gap-3 min-w-0">
            <a href="<?= ProductHelper::url('/admin/tickets') ?>" class="p-2 rounded-xl text-gray-400 hover:text-brand-600 hover:bg-black/[0.04] dark:hover:bg-white/5 transition">←</a>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold text-brand-600 dark:text-brand-400"><?= htmlspecialchars((string) $ticket['ticket_number']) ?></p>
                <h1 class="font-display font-bold text-ink-900 dark:text-white"><?= htmlspecialchars((string) $ticket['subject']) ?></h1>
                <p class="text-[11px] text-gray-400 mt-1">
                    <?= htmlspecialchars((string) ($ticket['user_name'] ?? '')) ?>
                    · <?= htmlspecialchars((string) ($ticket['user_email'] ?? '')) ?>
                    · <?= htmlspecialchars(t('support.cat_' . ($ticket['category'] ?? 'general'))) ?>
                </p>
                <span class="inline-flex mt-2 px-2 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if ($closed): ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id'] . '/reopen') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="h-9 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold hover:border-brand-400/50 transition"><?= htmlspecialchars(t('admin.ticket_reopen')) ?></button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id'] . '/close') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="h-9 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 transition"><?= htmlspecialchars(t('admin.ticket_close')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="mb-3 bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="mb-3 bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="flex-1 overflow-y-auto space-y-2.5 bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-4 sm:p-5 shadow-soft mb-3" style="max-height: min(58vh, 520px);">
        <?php foreach ($messages as $m):
            $type = (string) ($m['sender_type'] ?? 'user');
            $mine = $type === 'admin';
            $system = $type === 'system';
        ?>
            <?php if ($system): ?>
                <div class="flex justify-center py-1">
                    <div class="max-w-[90%] rounded-2xl px-3.5 py-2.5 text-xs leading-relaxed bg-ink-50 dark:bg-white/[0.06] text-gray-500 border border-black/[0.04] dark:border-white/5 text-center">
                        <p class="whitespace-pre-wrap break-words"><?= nl2br(htmlspecialchars((string) $m['body'])) ?></p>
                        <p class="text-[10px] mt-1 text-gray-400"><?= htmlspecialchars(substr((string) $m['created_at'], 11, 5)) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="flex <?= $mine ? 'justify-end' : 'justify-start' ?>">
                    <div class="max-w-[80%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed <?= $mine ? 'bg-brand-600 text-white rounded-br-md' : 'bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-gray-200 rounded-bl-md' ?>">
                        <?php if (!$mine): ?>
                            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1 text-amber-700 dark:text-amber-300"><?= htmlspecialchars((string) ($ticket['user_name'] ?? t('support.from_user'))) ?></p>
                        <?php else: ?>
                            <p class="text-[10px] font-semibold uppercase tracking-wide mb-1 text-white/70"><?= htmlspecialchars(t('support.from_support')) ?></p>
                        <?php endif; ?>
                        <p class="whitespace-pre-wrap break-words"><?= nl2br(htmlspecialchars((string) $m['body'])) ?></p>
                        <p class="text-[10px] mt-1 <?= $mine ? 'text-white/60' : 'text-gray-400' ?>"><?= htmlspecialchars(substr((string) $m['created_at'], 11, 5)) ?></p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if (!$closed): ?>
        <form method="post" action="<?= ProductHelper::url('/admin/tickets/' . (int) $ticket['id'] . '/reply') ?>" class="flex gap-2 items-end">
            <?= csrf_field() ?>
            <textarea name="body" rows="2" required maxlength="4000" placeholder="<?= htmlspecialchars(t('admin.ticket_reply_placeholder')) ?>"
                      class="ui-input flex-1 min-h-[44px] max-h-40 px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm resize-y"></textarea>
            <button type="submit" class="h-11 px-5 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider transition flex-shrink-0"><?= htmlspecialchars(t('support.send')) ?></button>
        </form>
    <?php else: ?>
        <div class="text-center text-sm text-gray-400 py-3 rounded-2xl border border-dashed border-black/10 dark:border-white/10">
            <?= htmlspecialchars(t('support.closed_hint')) ?>
        </div>
    <?php endif; ?>
</section>
