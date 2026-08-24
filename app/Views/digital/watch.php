<?php
use App\Helpers\ProductHelper;
$product = $product ?? [];
$digital = $digital ?? [];
$phase = $phase ?? 'countdown';
$watermark = $watermark ?? '';
$startsTs = (int) ($startsTs ?? 0);
$tokenUrl = $tokenUrl ?? '';
$lessons = $lessons ?? [];
$sessions = $sessions ?? [];
$chatPollUrl = $chatPollUrl ?? '';
$chatPostUrl = $chatPostUrl ?? '';
$completeUrl = $completeUrl ?? '';
$chatHideUrl = $chatHideUrl ?? '';
$progress = $progress ?? ['percent' => 0, 'done_ids' => [], 'completed' => 0, 'required' => 0];
$certificate = $certificate ?? null;
$productId = (int) ($product['id'] ?? 0);
$heartbeatUrl = ProductHelper::url('/digital/' . $productId . '/heartbeat');
$previewOnly = !empty($previewOnly);
?>
<section class="max-w-6xl mx-auto pb-16 grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-4">
        <a href="<?= ProductHelper::url('/digital') ?>" class="text-sm text-gray-400 hover:text-violet-600">← <?= htmlspecialchars(t('digital.library_title')) ?></a>
        <h1 class="font-display text-2xl font-bold"><?= htmlspecialchars((string) ($product['title'] ?? '')) ?></h1>
        <?php if (!empty($progress['required'])): ?>
            <p class="text-sm text-gray-500"><?= htmlspecialchars(t('digital.progress_line', [
                'done' => (string) ($progress['completed'] ?? 0),
                'total' => (string) $progress['required'],
                'pct' => (string) ($progress['percent'] ?? 0),
            ])) ?></p>
        <?php endif; ?>
        <?php if (!empty($certificate)): ?>
            <a href="<?= ProductHelper::url('/digital/' . $productId . '/certificate') ?>" class="inline-flex text-sm font-semibold text-violet-700"><?= htmlspecialchars(t('digital.cert_open')) ?></a>
        <?php endif; ?>
        <?php if (!empty($isAuthor)): ?>
            <p class="text-xs text-amber-700"><?= htmlspecialchars(t('digital.author_preview')) ?></p>
        <?php endif; ?>
        <?php if ($previewOnly): ?>
            <p class="text-xs text-violet-700"><?= htmlspecialchars(t('digital.preview_only')) ?></p>
        <?php endif; ?>

        <div class="relative rounded-2xl overflow-hidden bg-black aspect-video border border-black/20">
            <div id="zk-player-slot" class="absolute inset-0 flex items-center justify-center text-white">
                <?php if ($phase === 'countdown' && empty($lessons)): ?>
                    <div class="text-center px-4">
                        <div class="text-sm uppercase tracking-wider text-white/70 mb-2"><?= htmlspecialchars(t('digital.locked_until')) ?></div>
                        <div id="zk-countdown" class="font-display text-3xl font-bold" data-ts="<?= $startsTs ?>"><?= $startsTs ? htmlspecialchars(date('d.m.Y H:i', $startsTs)) : '—' ?></div>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-white/80 px-6 text-center" id="zk-player-hint"><?= htmlspecialchars(t('digital.pick_lesson')) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($watermark !== ''): ?>
                <div class="pointer-events-none absolute bottom-3 right-3 z-10 text-[11px] font-semibold text-white/80 drop-shadow-[0_1px_2px_rgba(0,0,0,.8)]"><?= htmlspecialchars($watermark) ?></div>
            <?php endif; ?>
        </div>
        <div id="zk-text-body" class="hidden rounded-2xl border border-black/10 p-4 text-sm whitespace-pre-wrap"></div>
        <p class="text-[12px] text-gray-400"><?= htmlspecialchars(t('digital.player_note')) ?></p>

        <?php if ($sessions): ?>
            <div class="rounded-2xl border border-black/[0.06] p-4 space-y-2">
                <h2 class="font-display font-bold text-sm"><?= htmlspecialchars(t('digital.webinars')) ?></h2>
                <?php foreach ($sessions as $s): ?>
                    <button type="button" class="zk-play w-full text-left px-3 py-2 rounded-xl border border-black/5 hover:border-violet-300 text-sm"
                            data-session="<?= (int) $s['id'] ?>">
                        <?= htmlspecialchars((string) $s['title']) ?>
                        <span class="text-[11px] text-gray-400"><?= htmlspecialchars(t('digital.status_' . ($s['live_status'] ?? 'idle'))) ?> · <?= htmlspecialchars(date('d.m.Y H:i', strtotime((string) $s['starts_at']))) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <aside class="space-y-4">
        <div class="rounded-2xl border border-black/[0.06] p-4">
            <h2 class="font-display font-bold mb-3"><?= htmlspecialchars(t('digital.program')) ?></h2>
            <?php if (!$lessons): ?>
                <button type="button" id="zk-play-btn" class="h-11 w-full rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars($phase === 'live' ? t('digital.watch_live') : t('digital.watch_vod')) ?></button>
            <?php else: ?>
                <ol class="space-y-2">
                    <?php foreach ($lessons as $i => $lesson):
                        $can = !$previewOnly || !empty($lesson['is_preview']);
                        $done = in_array((int) $lesson['id'], array_map('intval', $progress['done_ids'] ?? []), true);
                    ?>
                        <li>
                            <button type="button" <?= $can ? '' : 'disabled' ?>
                                    class="zk-play w-full text-left px-3 py-2 rounded-xl border <?= $can ? 'hover:border-violet-300' : 'opacity-50' ?> text-sm"
                                    data-lesson="<?= (int) $lesson['id'] ?>">
                                <?= $done ? '✓ ' : '' ?><?= ($i + 1) ?>. <?= htmlspecialchars((string) $lesson['title']) ?>
                                <?php if (!empty($lesson['is_preview'])): ?>
                                    <span class="text-[10px] text-violet-600"><?= htmlspecialchars(t('digital.preview')) ?></span>
                                <?php endif; ?>
                                <span class="block text-[11px] text-gray-400"><?= htmlspecialchars(t('digital.lesson_' . ($lesson['kind'] ?? 'video'))) ?></span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (!$previewOnly): ?>
                    <button type="button" id="zk-complete-btn" class="mt-3 h-10 w-full rounded-xl border border-violet-300 text-violet-800 text-xs font-bold uppercase hidden"><?= htmlspecialchars(t('digital.mark_done')) ?></button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-black/[0.06] p-4">
            <h2 class="font-display font-bold mb-2"><?= htmlspecialchars(t('digital.chat')) ?></h2>
            <div id="zk-chat" class="h-56 overflow-y-auto text-sm space-y-2 mb-2"></div>
            <?php if (!$previewOnly): ?>
                <form id="zk-chat-form" class="flex gap-2">
                    <input name="body" maxlength="400" class="flex-1 h-10 px-3 rounded-xl border border-black/10 text-sm" placeholder="<?= htmlspecialchars(t('digital.chat_ph')) ?>">
                    <button class="h-10 px-3 rounded-xl bg-violet-700 text-white text-xs font-bold"><?= htmlspecialchars(t('digital.send')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </aside>
</section>
<script>
(function () {
    var tokenUrl = <?= json_encode($tokenUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var heartbeatUrl = <?= json_encode($heartbeatUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var chatPoll = <?= json_encode($chatPollUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var chatPost = <?= json_encode($chatPostUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var completeUrl = <?= json_encode($completeUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var chatHideUrl = <?= json_encode($chatHideUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var canMod = <?= !empty($canModerate) ? 'true' : 'false' ?>;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrf ? csrf.getAttribute('content') : '';
    var slot = document.getElementById('zk-player-slot');
    var textBox = document.getElementById('zk-text-body');
    var currentLesson = 0;
    var currentSession = 0;
    var lastChat = 0;
    function post(url, body) {
        var fd = new FormData();
        if (csrfToken) fd.append('_csrf', csrfToken);
        Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function loadPlayer() {
        var payload = {};
        if (currentLesson) payload.lesson_id = String(currentLesson);
        if (currentSession) payload.session_id = String(currentSession);
        post(tokenUrl, payload).then(function (data) {
            if (!data || !data.ok) {
                alert((data && data.error) ? data.error : 'playback');
                return;
            }
            if (data.type === 'text') {
                textBox.classList.remove('hidden');
                textBox.textContent = data.body || '';
                slot.innerHTML = '';
                return;
            }
            if (data.type === 'pdf' && data.file) {
                textBox.classList.add('hidden');
                slot.innerHTML = '';
                var a = document.createElement('a');
                a.href = data.file;
                a.target = '_blank';
                a.className = 'text-white underline';
                a.textContent = <?= json_encode(t('digital.open_pdf')) ?>;
                slot.appendChild(a);
                return;
            }
            textBox.classList.add('hidden');
            slot.innerHTML = '';
            var iframe = document.createElement('iframe');
            iframe.src = data.iframe;
            iframe.allow = 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture';
            iframe.allowFullscreen = true;
            iframe.setAttribute('style', 'position:absolute;inset:0;width:100%;height:100%;border:0;');
            slot.appendChild(iframe);
            var ttl = Math.max(20, (data.ttl || 80) - 15) * 1000;
            setTimeout(loadPlayer, ttl);
        });
    }
    document.querySelectorAll('.zk-play').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentLesson = parseInt(btn.getAttribute('data-lesson') || '0', 10);
            currentSession = parseInt(btn.getAttribute('data-session') || '0', 10);
            var doneBtn = document.getElementById('zk-complete-btn');
            if (doneBtn) doneBtn.classList.toggle('hidden', !currentLesson);
            loadPlayer();
        });
    });
    var playBtn = document.getElementById('zk-play-btn');
    if (playBtn) playBtn.addEventListener('click', loadPlayer);
    setInterval(function () {
        if (!document.querySelector('#zk-player-slot iframe')) return;
        var hb = { seconds: '15' };
        if (currentLesson) hb.lesson_id = String(currentLesson);
        post(heartbeatUrl, hb).then(function (data) {
            if (data && data.certificate && data.certificate.public_code) {
                window.location.reload();
            }
        });
    }, 15000);
    function pollChat() {
        if (!chatPoll) return;
        var url = chatPoll + (chatPoll.indexOf('?') >= 0 ? '&' : '?') + 'after=' + lastChat;
        fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
            if (!data || !data.ok) return;
            var box = document.getElementById('zk-chat');
            (data.removed || []).forEach(function (rid) {
                var old = box.querySelector('[data-msg="' + rid + '"]');
                if (old && !canMod) old.remove();
            });
            (data.messages || []).forEach(function (m) {
                lastChat = Math.max(lastChat, parseInt(m.id, 10));
                if (box.querySelector('[data-msg="' + m.id + '"]')) return;
                var p = document.createElement('div');
                p.setAttribute('data-msg', String(m.id));
                p.className = 'flex gap-2 items-start';
                var text = document.createElement('div');
                text.innerHTML = '<strong></strong> <span></span>';
                text.querySelector('strong').textContent = m.name || '';
                text.querySelector('span').textContent = m.body || '';
                p.appendChild(text);
                if (canMod && chatHideUrl && !m.hidden) {
                    var hb = document.createElement('button');
                    hb.type = 'button';
                    hb.className = 'text-[10px] text-red-600 shrink-0';
                    hb.textContent = <?= json_encode(t('digital.chat_hide')) ?>;
                    hb.addEventListener('click', function () {
                        post(chatHideUrl, { message_id: String(m.id) }).then(function () { p.remove(); });
                    });
                    p.appendChild(hb);
                }
                box.appendChild(p);
                box.scrollTop = box.scrollHeight;
            });
        });
    }
    setInterval(pollChat, 4000);
    pollChat();
    var form = document.getElementById('zk-chat-form');
    if (form) form.addEventListener('submit', function (e) {
        e.preventDefault();
        var input = form.querySelector('input[name="body"]');
        post(chatPost, { body: input.value, session_id: currentSession ? String(currentSession) : '0' }).then(function (data) {
            if (data && data.ok) { input.value = ''; pollChat(); }
        });
    });
    var completeBtn = document.getElementById('zk-complete-btn');
    if (completeBtn) completeBtn.addEventListener('click', function () {
        if (!currentLesson) return;
        post(completeUrl, { lesson_id: String(currentLesson) }).then(function (data) {
            if (data && data.ok) window.location.reload();
        });
    });
})();
</script>
