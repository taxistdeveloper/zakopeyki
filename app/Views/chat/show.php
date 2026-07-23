<?php
use App\Core\Auth;
use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;

$meId = Auth::id();
$lastId = 0;
foreach ($messages as $m) {
    $lastId = max($lastId, (int) $m['id']);
}
$pollUrl = ProductHelper::url('/chat/' . (int) $conversation['id'] . '/poll');
$sendUrl = ProductHelper::url('/chat/' . (int) $conversation['id'] . '/send');
?>
<section class="max-w-2xl mx-auto fade-up pb-4 flex flex-col" style="min-height: calc(100vh - 8rem);">
    <div class="flex items-center gap-3 mb-4">
        <a href="<?= ProductHelper::url('/chat') ?>" class="p-2 rounded-xl text-gray-400 hover:text-brand-600 hover:bg-black/[0.04] dark:hover:bg-white/5 transition" aria-label="<?= htmlspecialchars(t('chat.back')) ?>">←</a>
        <?= AvatarHelper::html($peer, 'w-10 h-10', 'text-sm', 'rounded-xl') ?>
        <div class="min-w-0 flex-1">
            <h1 class="font-display font-bold text-ink-900 dark:text-white truncate"><?= htmlspecialchars($peer['name']) ?></h1>
            <?php if (!empty($conversation['product_title'])): ?>
                <p class="text-[11px] text-brand-600 truncate">
                    <?php if ((int) ($conversation['product_id'] ?? 0) > 0): ?>
                        <a href="<?= ProductHelper::url('/product/' . (int) $conversation['product_id']) ?>" class="hover:underline"><?= htmlspecialchars($conversation['product_title']) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($conversation['product_title']) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php if ((int) ($conversation['order_id'] ?? 0) > 0): ?>
            <a href="<?= ProductHelper::url('/orders/' . (int) $conversation['order_id']) ?>" class="text-[11px] font-semibold text-gray-500 hover:text-brand-600"><?= htmlspecialchars(t('chat.open_deal')) ?></a>
        <?php endif; ?>
    </div>

    <?php if (!empty($error)): ?>
        <div class="mb-3 bg-red-50 text-red-700 border border-red-100 px-4 py-3 rounded-2xl text-sm font-semibold"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div id="chat-thread" class="flex-1 overflow-y-auto space-y-2.5 bg-white/90 dark:bg-white/[0.04] rounded-[24px] border border-black/[0.06] dark:border-white/10 p-4 sm:p-5 shadow-soft mb-3" style="max-height: min(58vh, 520px);">
        <?php if (empty($messages)): ?>
            <p id="chat-empty" class="text-center text-sm text-gray-400 py-10"><?= htmlspecialchars(t('chat.start_hint')) ?></p>
        <?php endif; ?>
        <?php foreach ($messages as $m):
            $mine = (int) $m['sender_id'] === (int) $meId;
        ?>
            <div class="chat-msg flex <?= $mine ? 'justify-end' : 'justify-start' ?>" data-id="<?= (int) $m['id'] ?>">
                <div class="max-w-[80%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed <?= $mine ? 'bg-brand-600 text-white rounded-br-md' : 'bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-gray-200 rounded-bl-md' ?>">
                    <p class="whitespace-pre-wrap break-words"><?= nl2br(htmlspecialchars($m['body'])) ?></p>
                    <p class="text-[10px] mt-1 <?= $mine ? 'text-white/60' : 'text-gray-400' ?>"><?= htmlspecialchars(substr((string) $m['created_at'], 11, 5)) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form id="chat-form" method="post" action="<?= $sendUrl ?>" class="flex gap-2 items-end">
        <textarea id="chat-input" name="body" rows="1" required maxlength="2000" placeholder="<?= htmlspecialchars(t('chat.placeholder')) ?>" class="ui-input flex-1 min-h-[44px] max-h-32 px-4 py-3 rounded-2xl border border-black/[0.1] dark:border-white/10 bg-white dark:bg-white/5 text-sm resize-none"></textarea>
        <button type="submit" class="h-11 px-5 rounded-2xl bg-brand-600 hover:bg-brand-500 text-white font-display font-bold text-xs uppercase tracking-wider transition flex-shrink-0"><?= htmlspecialchars(t('chat.send')) ?></button>
    </form>
</section>

<script>
(function () {
    const thread = document.getElementById('chat-thread');
    const form = document.getElementById('chat-form');
    const input = document.getElementById('chat-input');
    const pollUrl = <?= json_encode($pollUrl, JSON_UNESCAPED_UNICODE) ?>;
    const meId = <?= (int) $meId ?>;
    let lastId = <?= (int) $lastId ?>;

    function scrollBottom() {
        if (thread) thread.scrollTop = thread.scrollHeight;
    }
    scrollBottom();

    function appendMessage(m) {
        if (!thread || !m || !m.id) return;
        if (thread.querySelector('[data-id="' + m.id + '"]')) return;
        const empty = document.getElementById('chat-empty');
        if (empty) empty.remove();

        const wrap = document.createElement('div');
        wrap.className = 'chat-msg flex ' + (m.is_mine ? 'justify-end' : 'justify-start');
        wrap.dataset.id = String(m.id);
        const time = (m.created_at || '').substr(11, 5);
        const bubbleClass = m.is_mine
            ? 'bg-brand-600 text-white rounded-br-md'
            : 'bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-gray-200 rounded-bl-md';
        const timeClass = m.is_mine ? 'text-white/60' : 'text-gray-400';
        const body = String(m.body || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/\n/g, '<br>');
        wrap.innerHTML = '<div class="max-w-[80%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed ' + bubbleClass + '">' +
            '<p class="whitespace-pre-wrap break-words">' + body + '</p>' +
            '<p class="text-[10px] mt-1 ' + timeClass + '">' + time + '</p></div>';
        thread.appendChild(wrap);
        lastId = Math.max(lastId, m.id);
        scrollBottom();
    }

    async function poll() {
        try {
            const res = await fetch(pollUrl + '?after=' + lastId, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok || !Array.isArray(data.messages)) return;
            data.messages.forEach(appendMessage);
        } catch (e) {}
    }

    setInterval(poll, 3000);

    form?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const text = (input?.value || '').trim();
        if (!text) return;
        const fd = new FormData(form);
        input.value = '';
        input.style.height = 'auto';
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.ok && data.message) {
                appendMessage(data.message);
            } else if (data.error) {
                alert(data.error);
            }
        } catch (err) {
            form.submit();
        }
    });

    input?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form?.requestSubmit();
        }
    });

    input?.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 128) + 'px';
    });
})();
</script>
