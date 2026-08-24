<?php
use App\Helpers\ProductHelper;
$row = $row ?? [];
$product = $product ?? [];
$id = (int) ($row['id'] ?? 0);
$input = 'w-full h-11 px-3.5 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$starts = !empty($row['starts_at']) ? date('Y-m-d\TH:i', strtotime((string) $row['starts_at'])) : '';
?>
<section class="max-w-3xl mx-auto pb-16 space-y-6">
    <div>
        <a href="<?= ProductHelper::url('/digital/studio') ?>" class="text-sm text-gray-400 hover:text-violet-600">← <?= htmlspecialchars(t('digital.studio_title')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars((string) ($product['title'] ?? t('digital.studio_item'))) ?></h1>
        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('digital.status_' . ($row['live_status'] ?? 'idle'))) ?></p>
    </div>
    <?php if (!empty($flash)): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>
    <?php if (empty($cfReady)): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm"><?= htmlspecialchars(t('digital.cf_admin_hint')) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/save') ?>" class="space-y-4 rounded-2xl border border-black/[0.06] p-5 bg-white dark:bg-white/[0.04]">
        <?= csrf_field() ?>
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.schedule')) ?></h2>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('digital.kind')) ?></label>
            <select name="kind" class="<?= $input ?>">
                <?php foreach (($kinds ?? []) as $kind): ?>
                    <option value="<?= htmlspecialchars($kind) ?>" <?= ($row['kind'] ?? '') === $kind ? 'selected' : '' ?>><?= htmlspecialchars(t('digital.kind_' . $kind)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('digital.starts_at')) ?></label>
                <input type="datetime-local" name="starts_at" value="<?= htmlspecialchars($starts) ?>" class="<?= $input ?>">
            </div>
            <div>
                <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('digital.duration')) ?></label>
                <input type="number" name="duration_minutes" min="15" max="720" value="<?= (int) ($row['duration_minutes'] ?? 120) ?>" class="<?= $input ?>">
            </div>
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('digital.access_days')) ?></label>
            <input type="number" name="access_days" min="1" max="3650" value="<?= (int) ($row['access_days'] ?? 365) ?>" class="<?= $input ?>">
        </div>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('digital.watermark')) ?></label>
            <select name="watermark_mode" class="<?= $input ?>">
                <?php foreach (['none', 'name', 'order', 'email'] as $wm): ?>
                    <option value="<?= $wm ?>" <?= ($row['watermark_mode'] ?? 'order') === $wm ? 'selected' : '' ?>><?= htmlspecialchars(t('digital.wm_' . $wm)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="record_enabled" value="1" <?= !empty($row['record_enabled']) ? 'checked' : '' ?>>
            <?= htmlspecialchars(t('digital.record_enabled')) ?>
        </label>
        <button class="h-11 px-5 rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.save')) ?></button>
    </form>

    <div class="space-y-3 rounded-2xl border border-black/[0.06] p-5 bg-white dark:bg-white/[0.04]">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.obs_title')) ?></h2>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('digital.obs_lead')) ?></p>
        <?php if (!empty($row['rtmps_url'])): ?>
            <div>
                <div class="text-[11px] font-bold text-gray-400 mb-1">RTMPS</div>
                <input readonly class="<?= $input ?> font-mono text-xs" value="<?= htmlspecialchars((string) $row['rtmps_url']) ?>">
            </div>
            <div>
                <div class="text-[11px] font-bold text-gray-400 mb-1">Stream Key</div>
                <input readonly class="<?= $input ?> font-mono text-xs" value="<?= htmlspecialchars((string) $row['stream_key']) ?>">
            </div>
        <?php endif; ?>
        <div class="flex flex-wrap gap-2">
            <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/provision') ?>"><?= csrf_field() ?>
                <button class="h-11 px-4 rounded-xl border border-violet-300 text-violet-800 text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.get_key')) ?></button>
            </form>
            <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/go-live') ?>"><?= csrf_field() ?>
                <button class="h-11 px-4 rounded-xl bg-red-600 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.go_live')) ?></button>
            </form>
            <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/end') ?>"><?= csrf_field() ?>
                <button class="h-11 px-4 rounded-xl border border-black/10 text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.end_live')) ?></button>
            </form>
        </div>
    </div>

    <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/attach-uid') ?>" class="space-y-3 rounded-2xl border border-black/[0.06] p-5 bg-white dark:bg-white/[0.04]">
        <?= csrf_field() ?>
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.vod_title')) ?></h2>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('digital.vod_lead')) ?></p>
        <input name="video_uid" class="<?= $input ?>" placeholder="Cloudflare Stream UID" value="<?= htmlspecialchars((string) ($row['cf_playback_uid'] ?? '')) ?>">
        <button class="h-11 px-5 rounded-xl bg-ink-900 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.attach_uid')) ?></button>
        <button type="button" id="zk-direct-upload" class="h-11 px-5 rounded-xl border text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.direct_upload')) ?></button>
        <p id="zk-upload-status" class="text-xs text-gray-400"></p>
    </form>
</section>
<script>
(function () {
    var btn = document.getElementById('zk-direct-upload');
    if (!btn) return;
    var status = document.getElementById('zk-upload-status');
    var csrf = document.querySelector('meta[name="csrf-token"]');
    var token = csrf ? csrf.getAttribute('content') : '';
    btn.addEventListener('click', function () {
        var fd = new FormData();
        fd.append('_csrf', token);
        fd.append('title', <?= json_encode((string) ($product['title'] ?? 'vod')) ?>);
        fetch(<?= json_encode(ProductHelper::url('/digital/studio/' . $id . '/upload')) ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { status.textContent = data.error || 'error'; return; }
                status.textContent = <?= json_encode(t('digital.upload_url_ready')) ?> + ' ' + (data.upload_url || '');
            });
    });
});
</script>
