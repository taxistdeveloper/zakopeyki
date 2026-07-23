<?php
use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;

$conversations = $conversations ?? [];
?>
<section class="max-w-2xl mx-auto space-y-5 fade-up pb-8">
    <div>
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-gray-400"><?= htmlspecialchars(t('chat.eyebrow')) ?></p>
        <h1 class="font-display text-2xl sm:text-3xl font-bold text-ink-900 dark:text-white mt-1"><?= htmlspecialchars(t('chat.title')) ?></h1>
        <p class="text-sm text-gray-500 mt-1.5"><?= htmlspecialchars(t('chat.subtitle')) ?></p>
    </div>

    <?php if (empty($conversations)): ?>
        <div class="text-center py-16 rounded-2xl border border-dashed border-black/10 dark:border-white/10 text-gray-400 text-sm">
            <?= htmlspecialchars(t('chat.empty_list')) ?>
        </div>
    <?php else: ?>
        <div class="bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 overflow-hidden shadow-soft divide-y divide-black/[0.04] dark:divide-white/5">
            <?php foreach ($conversations as $c):
                $peer = [
                    'name' => $c['peer_name'] ?? '',
                    'avatar' => $c['peer_avatar'] ?? null,
                    'avatar_file' => $c['peer_avatar_file'] ?? null,
                ];
                $unread = (int) ($c['unread_count'] ?? 0);
            ?>
                <a href="<?= ProductHelper::url('/chat/' . (int) $c['id']) ?>"
                   data-chat-open
                   data-conversation-id="<?= (int) $c['id'] ?>"
                   class="flex gap-3 p-4 hover:bg-brand-50/40 dark:hover:bg-white/[0.03] transition">
                    <?= AvatarHelper::html($peer, 'w-12 h-12', 'text-sm', 'rounded-2xl') ?>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-sm text-ink-900 dark:text-white truncate"><?= htmlspecialchars($peer['name']) ?></p>
                            <?php if (!empty($c['last_message_at'])): ?>
                                <span class="text-[10px] text-gray-400 flex-shrink-0"><?= htmlspecialchars(substr((string) $c['last_message_at'], 11, 5)) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($c['product_title'])): ?>
                            <p class="text-[11px] text-brand-600 dark:text-brand-400 truncate mt-0.5"><?= htmlspecialchars($c['product_title']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center justify-between gap-2 mt-1">
                            <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($c['last_preview'] ?? t('chat.no_messages')) ?></p>
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
