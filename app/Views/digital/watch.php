<?php
use App\Helpers\ProductHelper;
$product = $product ?? [];
$digital = $digital ?? [];
$phase = $phase ?? 'countdown';
$watermark = $watermark ?? '';
$startsTs = (int) ($startsTs ?? 0);
$tokenUrl = $tokenUrl ?? '';
$heartbeatUrl = ProductHelper::url('/digital/' . (int) ($product['id'] ?? 0) . '/heartbeat');
?>
<section class="max-w-5xl mx-auto pb-16 space-y-5">
    <div>
        <a href="<?= ProductHelper::url('/digital') ?>" class="text-sm text-gray-400 hover:text-violet-600">← <?= htmlspecialchars(t('digital.library_title')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars((string) ($product['title'] ?? '')) ?></h1>
        <?php if (!empty($isAuthor)): ?>
            <p class="text-xs text-amber-700 mt-1"><?= htmlspecialchars(t('digital.author_preview')) ?></p>
        <?php endif; ?>
    </div>

    <div class="relative rounded-2xl overflow-hidden bg-black aspect-video border border-black/20">
        <div id="zk-player-slot" class="absolute inset-0 flex items-center justify-center text-white">
            <?php if ($phase === 'countdown'): ?>
                <div class="text-center px-4">
                    <div class="text-sm uppercase tracking-wider text-white/70 mb-2"><?= htmlspecialchars(t('digital.locked_until')) ?></div>
                    <div id="zk-countdown" class="font-display text-3xl font-bold" data-ts="<?= $startsTs ?>"><?= $startsTs ? htmlspecialchars(date('d.m.Y H:i', $startsTs)) : '—' ?></div>
                </div>
            <?php elseif ($phase === 'waiting'): ?>
                <p class="text-sm text-white/80 px-6 text-center"><?= htmlspecialchars(t('digital.waiting_host')) ?></p>
            <?php elseif ($phase === 'processing'): ?>
                <p class="text-sm text-white/80 px-6 text-center"><?= htmlspecialchars(t('digital.recording_wait')) ?></p>
            <?php else: ?>
                <button type="button" id="zk-play-btn" class="h-14 px-8 rounded-2xl bg-violet-600 font-bold"><?= htmlspecialchars($phase === 'live' ? t('digital.watch_live') : t('digital.watch_vod')) ?></button>
            <?php endif; ?>
        </div>
        <?php if ($watermark !== ''): ?>
            <div class="pointer-events-none absolute bottom-3 right-3 z-10 text-[11px] font-semibold text-white/80 drop-shadow-[0_1px_2px_rgba(0,0,0,.8)]"><?= htmlspecialchars($watermark) ?></div>
        <?php endif; ?>
    </div>
    <p class="text-[12px] text-gray-400"><?= htmlspecialchars(t('digital.player_note')) ?></p>
</section>
<script>
(function () {
    var tokenUrl = <?= json_encode($tokenUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var heartbeatUrl = <?= json_encode($heartbeatUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrf ? csrf.getAttribute('content') : '';
    var playBtn = document.getElementById('zk-play-btn');
    var slot = document.getElementById('zk-player-slot');
    function post(url, body) {
        var fd = new FormData();
        if (csrfToken) fd.append('_csrf', csrfToken);
        Object.keys(body || {}).forEach(function (k) { fd.append(k, body[k]); });
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function loadPlayer() {
        post(tokenUrl, {}).then(function (data) {
            if (!data || !data.ok || !data.iframe) {
                alert((data && data.error) ? data.error : 'playback');
                return;
            }
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
    if (playBtn) playBtn.addEventListener('click', loadPlayer);
    setInterval(function () {
        if (!document.querySelector('#zk-player-slot iframe')) return;
        post(heartbeatUrl, { seconds: '15' });
    }, 15000);
    var cd = document.getElementById('zk-countdown');
    if (cd && cd.getAttribute('data-ts')) {
        var ts = parseInt(cd.getAttribute('data-ts'), 10) * 1000;
        setInterval(function () {
            var left = ts - Date.now();
            if (left <= 0) { location.reload(); return; }
            var d = Math.floor(left / 86400000);
            var h = Math.floor((left % 86400000) / 3600000);
            var m = Math.floor((left % 3600000) / 60000);
            var s = Math.floor((left % 60000) / 1000);
            cd.textContent = (d ? d + 'д ' : '') + String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        }, 1000);
    }
})();
</script>
