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
            $storyPayload = null;
            if (!empty($m['story_ref_id']) && !empty($m['story_user_id'])) {
                $storyUser = [
                    'name' => (string) ($m['story_user_name'] ?? ''),
                    'avatar' => (string) ($m['story_user_avatar'] ?? ''),
                    'avatar_file' => $m['story_user_avatar_file'] ?? null,
                ];
                $storyPayload = [
                    'id' => (int) $m['story_ref_id'],
                    'user_id' => (int) $m['story_user_id'],
                    'user_name' => $storyUser['name'],
                    'user_avatar' => AvatarHelper::initial($storyUser),
                    'avatar_url' => AvatarHelper::url($storyUser),
                    'image' => $m['story_image'] ?? null,
                    'caption' => (string) ($m['story_caption'] ?? ''),
                    'bg_color' => (string) ($m['story_bg_color'] ?? '#2563EB'),
                    'emoji' => (string) ($m['story_emoji'] ?? '✨'),
                    'created_at' => (string) ($m['story_created_at'] ?? ''),
                    'comments_enabled' => (int) ($m['story_comments_enabled'] ?? 1),
                    'product' => null,
                ];
            }
            $storyImage = (string) ($storyPayload['image'] ?? '');
            $isStoryVideo = (bool) preg_match('/\.(mp4|webm|mov)(\?|$)/i', $storyImage);
            $storyBg = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($storyPayload['bg_color'] ?? ''))
                ? $storyPayload['bg_color']
                : '#2563EB';
        ?>
            <div class="chat-msg flex <?= $mine ? 'justify-end' : 'justify-start' ?>" data-id="<?= (int) $m['id'] ?>">
                <div class="max-w-[80%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed <?= $mine ? 'bg-brand-600 text-white rounded-br-md' : 'bg-ink-100 dark:bg-white/10 text-ink-800 dark:text-gray-200 rounded-bl-md' ?>">
                    <?php if ($storyPayload): ?>
                        <button type="button"
                                class="chat-story-card relative block w-[148px] overflow-hidden rounded-xl border <?= $mine ? 'border-white/25' : 'border-black/10 dark:border-white/15' ?> text-left mb-2"
                                data-chat-story
                                data-story="<?= htmlspecialchars(json_encode($storyPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="relative block aspect-[9/16] w-full bg-black overflow-hidden">
                                <?php if ($storyImage !== ''): ?>
                                    <?php if ($isStoryVideo): ?>
                                        <video src="<?= htmlspecialchars(ProductHelper::url('public/uploads/stories/' . basename($storyImage))) ?>" muted playsinline preload="metadata" class="absolute inset-0 w-full h-full object-cover"></video>
                                    <?php else: ?>
                                        <img src="<?= htmlspecialchars(ProductHelper::url('public/uploads/stories/' . basename($storyImage))) ?>" alt="" class="absolute inset-0 w-full h-full object-cover">
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="absolute inset-0 flex flex-col items-center justify-center px-3" style="background:linear-gradient(160deg,<?= htmlspecialchars($storyBg) ?>,#111)">
                                        <span class="text-3xl leading-none"><?= htmlspecialchars((string) $storyPayload['emoji']) ?></span>
                                        <?php if ($storyPayload['caption'] !== ''): ?>
                                            <span class="mt-2 text-[11px] text-white text-center line-clamp-3"><?= htmlspecialchars($storyPayload['caption']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5">
                                    <span class="block text-[10px] font-semibold text-white/95"><?= htmlspecialchars(t('chat.story_reply')) ?></span>
                                    <span class="block text-[9px] text-white/70"><?= htmlspecialchars(t('chat.story_open')) ?></span>
                                </span>
                            </span>
                        </button>
                    <?php endif; ?>
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
    const pollUrl = <?= js_encode($pollUrl) ?>;
    const meId = <?= (int) $meId ?>;
    let lastId = <?= (int) $lastId ?>;

    function scrollBottom() {
        if (thread) thread.scrollTop = thread.scrollHeight;
    }
    scrollBottom();

    function escapeHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function storyCardHtml(story, mine) {
        if (!story || !story.id) return '';
        const label = escapeHtml((window.__i18n && window.__i18n['chat.story_reply']) || 'Ответ на историю');
        const openLabel = escapeHtml((window.__i18n && window.__i18n['chat.story_open']) || 'Открыть историю');
        const base = window.__storyUploadBase || '';
        let media = '';
        if (story.image) {
            const src = escapeHtml(base + story.image);
            if (/\.(mp4|webm|mov)(\?|$)/i.test(String(story.image))) {
                media = '<video src="' + src + '" muted playsinline preload="metadata" class="absolute inset-0 w-full h-full object-cover"></video>';
            } else {
                media = '<img src="' + src + '" alt="" class="absolute inset-0 w-full h-full object-cover">';
            }
        } else {
            const c1 = /^#[0-9A-Fa-f]{6}$/.test(String(story.bg_color || '')) ? story.bg_color : '#2563EB';
            media = '<div class="absolute inset-0 flex flex-col items-center justify-center px-3" style="background:linear-gradient(160deg,' + escapeHtml(c1) + ',#111)">' +
                '<span class="text-3xl leading-none">' + escapeHtml(story.emoji || '✨') + '</span>' +
                (story.caption ? '<span class="mt-2 text-[11px] text-white text-center line-clamp-3">' + escapeHtml(story.caption) + '</span>' : '') +
                '</div>';
        }
        const border = mine ? 'border-white/25' : 'border-black/10 dark:border-white/15';
        return '<button type="button" class="chat-story-card relative block w-[148px] overflow-hidden rounded-xl border ' + border + ' text-left mb-2" data-chat-story>' +
            '<span class="relative block aspect-[9/16] w-full bg-black overflow-hidden">' + media +
            '<span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-2 py-1.5">' +
            '<span class="block text-[10px] font-semibold text-white/95">' + label + '</span>' +
            '<span class="block text-[9px] text-white/70">' + openLabel + '</span></span></span></button>';
    }

    function bindStoryCard(wrap, story) {
        const card = wrap.querySelector('[data-chat-story]');
        if (!card || !story) return;
        card.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof window.openStoryFromChat === 'function') window.openStoryFromChat(story);
        });
    }

    document.querySelectorAll('#chat-thread [data-chat-story]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            try {
                const story = JSON.parse(btn.getAttribute('data-story') || '{}');
                if (story && story.id && typeof window.openStoryFromChat === 'function') {
                    window.openStoryFromChat(story);
                }
            } catch (err) {}
        });
    });

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
        const body = escapeHtml(m.body).replace(/\n/g, '<br>');
        wrap.innerHTML = '<div class="max-w-[80%] rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed ' + bubbleClass + '">' +
            storyCardHtml(m.story, !!m.is_mine) +
            '<p class="whitespace-pre-wrap break-words">' + body + '</p>' +
            '<p class="text-[10px] mt-1 ' + timeClass + '">' + escapeHtml(time) + '</p></div>';
        bindStoryCard(wrap, m.story);
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
