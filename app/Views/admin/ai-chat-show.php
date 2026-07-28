<?php use App\Helpers\ProductHelper; ?>
<section class="space-y-6 fade-up max-w-3xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="<?= ProductHelper::url('/admin/ai-chats') ?>" class="inline-flex text-sm text-gray-400 hover:text-brand-600 mb-2">← <?= htmlspecialchars(t('admin.ai_chats')) ?></a>
            <h1 class="font-display text-xl font-bold text-ink-900 dark:text-white">
                <?= htmlspecialchars(t('admin.ai_chat')) ?> #<?= (int) $conversation['id'] ?>
            </h1>
            <p class="text-sm text-gray-500 mt-1">
                <?= htmlspecialchars($userName ?? t('admin.ai_guest')) ?>
                <?php if (!empty($userEmail)): ?>
                    · <?= htmlspecialchars($userEmail) ?>
                <?php endif; ?>
                · <?= htmlspecialchars((string) $conversation['status']) ?>
            </p>
        </div>
        <?php if (($conversation['status'] ?? '') !== 'closed'): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/ai-chats/' . (int) $conversation['id'] . '/close') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="h-9 px-3 rounded-xl border border-black/[0.08] dark:border-white/10 text-xs font-semibold text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 transition">
                    <?= htmlspecialchars(t('admin.ai_close_learn')) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="bg-emerald-50 dark:bg-emerald-900/25 text-emerald-800 dark:text-emerald-300 border border-emerald-100 dark:border-emerald-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/25 text-red-800 dark:text-red-300 border border-red-100 dark:border-red-800/40 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="bg-white/90 dark:bg-white/[0.04] rounded-[22px] border border-black/[0.06] dark:border-white/10 shadow-soft overflow-hidden">
        <div class="max-h-[55vh] overflow-y-auto p-4 space-y-3">
            <?php foreach ($messages as $m): ?>
                <?php
                $type = (string) $m['sender_type'];
                $align = $type === 'user' ? 'justify-end' : 'justify-start';
                $bubble = match ($type) {
                    'user' => 'bg-brand-500 text-white',
                    'agent' => 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
                    'system' => 'bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100 border border-amber-200/60',
                    default => 'bg-ink-100 text-ink-900 dark:bg-white/10 dark:text-gray-100',
                };
                $label = match ($type) {
                    'user' => t('admin.ai_sender_user'),
                    'agent' => t('admin.ai_sender_agent'),
                    'system' => t('admin.ai_sender_system'),
                    default => 'AI',
                };
                ?>
                <div class="flex <?= $align ?>">
                    <div class="max-w-[85%] rounded-2xl px-3.5 py-2.5 <?= $bubble ?>">
                        <p class="text-[10px] font-semibold uppercase tracking-wide opacity-70 mb-1"><?= htmlspecialchars($label) ?></p>
                        <p class="text-sm whitespace-pre-wrap leading-snug"><?= htmlspecialchars((string) $m['message']) ?></p>
                        <p class="text-[10px] opacity-60 mt-1"><?= htmlspecialchars((string) $m['created_at']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (($conversation['status'] ?? '') !== 'closed'): ?>
            <form method="post" action="<?= ProductHelper::url('/admin/ai-chats/' . (int) $conversation['id'] . '/reply') ?>" class="border-t border-black/[0.06] dark:border-white/10 p-3 space-y-2">
                <?= csrf_field() ?>
                <textarea name="body" rows="3" required maxlength="4000"
                          placeholder="<?= htmlspecialchars(t('admin.ai_reply_placeholder')) ?>"
                          class="ui-input w-full rounded-xl border border-ink-900/10 dark:border-white/10 bg-white/80 dark:bg-ink-900/40 px-3 py-2.5 text-sm"></textarea>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="h-9 px-4 rounded-xl bg-accent-500 hover:bg-accent-600 text-white text-sm font-semibold transition">
                        <?= htmlspecialchars(t('admin.ai_send')) ?>
                    </button>
                    <button type="submit" name="close" value="1" class="h-9 px-4 rounded-xl border border-emerald-300 text-emerald-700 dark:text-emerald-300 text-sm font-semibold hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition">
                        <?= htmlspecialchars(t('admin.ai_send_close')) ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
