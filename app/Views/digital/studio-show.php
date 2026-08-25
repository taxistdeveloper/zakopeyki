<?php
use App\Helpers\ProductHelper;
$row = $row ?? [];
$product = $product ?? [];
$id = (int) ($row['id'] ?? 0);
$input = 'w-full h-11 px-3.5 rounded-xl border border-black/10 dark:border-white/10 bg-white dark:bg-white/5 text-sm';
$starts = !empty($row['starts_at']) ? date('Y-m-d\TH:i', strtotime((string) $row['starts_at'])) : '';
$kindSelected = ($row['kind'] ?? '') === 'course' ? 'vod' : (string) ($row['kind'] ?? '');
?>
<section class="max-w-3xl mx-auto pb-16 space-y-6">
    <div>
        <a href="<?= ProductHelper::url('/digital/studio') ?>" class="text-sm text-gray-400 hover:text-violet-600">← <?= htmlspecialchars(t('digital.studio_title')) ?></a>
        <h1 class="font-display text-2xl font-bold mt-2"><?= htmlspecialchars((string) ($product['title'] ?? t('digital.studio_item'))) ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(t('digital.pick_lead')) ?></p>
        <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars(t('digital.status_' . ($row['live_status'] ?? 'idle'))) ?></p>
    </div>
    <?php
    $lessons = $lessons ?? [];
    $sessions = $sessions ?? [];
    $emptyStudio = $lessons === [] && $sessions === [];
    ?>
    <div class="grid sm:grid-cols-2 gap-3">
        <a href="#lessons" class="rounded-2xl border-2 border-violet-200 bg-violet-50/70 dark:bg-violet-950/20 p-5 hover:border-violet-400 transition">
            <div class="text-[10px] font-bold uppercase tracking-wider text-violet-600 mb-1"><?= htmlspecialchars(t('digital.pick_lessons_kicker')) ?></div>
            <div class="font-display text-lg font-bold"><?= htmlspecialchars(t('digital.pick_lessons_title')) ?></div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mt-2"><?= htmlspecialchars(t('digital.pick_lessons_text')) ?></p>
            <span class="inline-flex mt-4 h-10 px-4 items-center rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.pick_lessons_btn')) ?></span>
        </a>
        <a href="#live" class="rounded-2xl border-2 border-red-200 bg-red-50/60 dark:bg-red-950/20 p-5 hover:border-red-400 transition">
            <div class="text-[10px] font-bold uppercase tracking-wider text-red-600 mb-1"><?= htmlspecialchars(t('digital.pick_live_kicker')) ?></div>
            <div class="font-display text-lg font-bold"><?= htmlspecialchars(t('digital.pick_live_title')) ?></div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mt-2"><?= htmlspecialchars(t('digital.pick_live_text')) ?></p>
            <span class="inline-flex mt-4 h-10 px-4 items-center rounded-xl bg-red-600 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.pick_live_btn')) ?></span>
        </a>
    </div>
    <?php if ($emptyStudio): ?>
        <p class="text-sm text-violet-800 bg-violet-50 border border-violet-100 rounded-xl px-4 py-3"><?= htmlspecialchars(t('digital.pick_empty')) ?></p>
    <?php endif; ?>
    <?php if (!empty($flash)): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $flash) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm font-semibold"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>
    <?php if (empty($cfReady)): ?>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm"><?= htmlspecialchars(t('digital.cf_admin_hint')) ?></div>
    <?php endif; ?>

    <details class="rounded-2xl border border-black/[0.06] p-5 bg-white dark:bg-white/[0.04]">
        <summary class="font-display font-bold cursor-pointer"><?= htmlspecialchars(t('digital.extra_settings')) ?></summary>
        <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/save') ?>" class="space-y-4 mt-4">
        <?= csrf_field() ?>
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.schedule')) ?></h2>
        <div>
            <label class="block text-xs font-bold mb-1"><?= htmlspecialchars(t('digital.kind')) ?></label>
            <select name="kind" class="<?= $input ?>">
                <?php foreach (($kinds ?? []) as $kind): ?>
                    <option value="<?= htmlspecialchars($kind) ?>" <?= $kindSelected === $kind ? 'selected' : '' ?>><?= htmlspecialchars(t('digital.kind_' . $kind)) ?></option>
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
    </details>

    <div id="live" class="space-y-3 rounded-2xl border-2 border-red-100 p-5 bg-white dark:bg-white/[0.04] scroll-mt-24">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.pick_live_title')) ?></h2>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('digital.pick_live_text')) ?></p>
        <h3 class="font-semibold text-sm pt-2"><?= htmlspecialchars(t('digital.obs_title')) ?></h3>
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

    <?php $stats = $stats ?? ['viewers' => 0, 'seconds' => 0, 'tickets' => 0, 'chat' => 0]; ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-2xl border border-black/5 p-3 text-center">
            <div class="text-xl font-display font-bold"><?= (int) $stats['viewers'] ?></div>
            <div class="text-[11px] text-gray-400"><?= htmlspecialchars(t('digital.stat_viewers')) ?></div>
        </div>
        <div class="rounded-2xl border border-black/5 p-3 text-center">
            <div class="text-xl font-display font-bold"><?= (int) round(((int) $stats['seconds']) / 60) ?></div>
            <div class="text-[11px] text-gray-400"><?= htmlspecialchars(t('digital.stat_minutes')) ?></div>
        </div>
        <div class="rounded-2xl border border-black/5 p-3 text-center">
            <div class="text-xl font-display font-bold"><?= (int) $stats['tickets'] ?></div>
            <div class="text-[11px] text-gray-400"><?= htmlspecialchars(t('digital.stat_tickets')) ?></div>
        </div>
        <div class="rounded-2xl border border-black/5 p-3 text-center">
            <div class="text-xl font-display font-bold"><?= (int) $stats['chat'] ?></div>
            <div class="text-[11px] text-gray-400"><?= htmlspecialchars(t('digital.stat_chat')) ?></div>
        </div>
    </div>

    <div id="lessons" class="rounded-2xl border-2 border-violet-100 p-5 bg-white dark:bg-white/[0.04] space-y-4 scroll-mt-24">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.pick_lessons_title')) ?></h2>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('digital.pick_lessons_text')) ?></p>
        <?php foreach (($lessons ?? []) as $lesson): ?>
            <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/lessons') ?>" enctype="multipart/form-data" class="space-y-2 border border-black/5 rounded-xl p-3">
                <?= csrf_field() ?>
                <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
                <input name="title" class="<?= $input ?>" value="<?= htmlspecialchars((string) $lesson['title']) ?>">
                <div class="grid grid-cols-2 gap-2">
                    <select name="kind" class="<?= $input ?>">
                        <?php foreach (['video', 'pdf', 'text', 'live_session'] as $lk): ?>
                            <option value="<?= $lk ?>" <?= ($lesson['kind'] ?? '') === $lk ? 'selected' : '' ?>><?= htmlspecialchars(t('digital.lesson_' . $lk)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input name="sort_order" type="number" class="<?= $input ?>" value="<?= (int) ($lesson['sort_order'] ?? 0) ?>">
                </div>
                <textarea name="body" rows="3" class="<?= $input ?> h-auto py-2"><?= htmlspecialchars((string) ($lesson['body'] ?? '')) ?></textarea>
                <input name="cf_video_uid" class="<?= $input ?>" placeholder="Stream UID" value="<?= htmlspecialchars((string) ($lesson['cf_video_uid'] ?? '')) ?>">
                <label class="text-sm flex gap-2 items-center"><input type="checkbox" name="is_preview" value="1" <?= !empty($lesson['is_preview']) ? 'checked' : '' ?>> <?= htmlspecialchars(t('digital.preview')) ?></label>
                <input type="file" name="file" accept=".pdf,image/*">
                <div class="flex gap-2">
                    <button class="h-10 px-4 rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.save')) ?></button>
                </div>
            </form>
            <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/lessons/' . (int) $lesson['id'] . '/delete') ?>"><?= csrf_field() ?>
                <button class="text-xs text-red-600"><?= htmlspecialchars(t('digital.delete')) ?></button>
            </form>
        <?php endforeach; ?>
        <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/lessons') ?>" enctype="multipart/form-data" class="space-y-2 pt-2">
            <?= csrf_field() ?>
            <h3 class="text-sm font-semibold"><?= htmlspecialchars(t('digital.lesson_add')) ?></h3>
            <input name="title" required class="<?= $input ?>" placeholder="<?= htmlspecialchars(t('digital.lesson_title')) ?>">
            <select name="kind" class="<?= $input ?>">
                <option value="video"><?= htmlspecialchars(t('digital.lesson_video')) ?></option>
                <option value="pdf"><?= htmlspecialchars(t('digital.lesson_pdf')) ?></option>
                <option value="text"><?= htmlspecialchars(t('digital.lesson_text')) ?></option>
                <option value="live_session"><?= htmlspecialchars(t('digital.lesson_live_session')) ?></option>
            </select>
            <textarea name="body" rows="3" class="<?= $input ?> h-auto py-2"></textarea>
            <input name="cf_video_uid" class="<?= $input ?>" placeholder="Stream UID">
            <label class="text-sm flex gap-2"><input type="checkbox" name="is_preview" value="1"> <?= htmlspecialchars(t('digital.preview')) ?></label>
            <input type="file" name="file" accept=".pdf,image/*">
            <button class="h-11 px-5 rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.lesson_add')) ?></button>
        </form>
    </div>

    <div class="rounded-2xl border border-black/[0.06] p-5 bg-white dark:bg-white/[0.04] space-y-4">
        <h2 class="font-display font-bold"><?= htmlspecialchars(t('digital.sessions_title')) ?></h2>
        <p class="text-sm text-gray-500"><?= htmlspecialchars(t('digital.pick_live_text')) ?></p>
        <?php foreach (($sessions ?? []) as $s): ?>
            <div class="border border-black/5 rounded-xl p-3 space-y-2">
                <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/sessions') ?>" class="space-y-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="session_id" value="<?= (int) $s['id'] ?>">
                    <input name="title" class="<?= $input ?>" value="<?= htmlspecialchars((string) $s['title']) ?>">
                    <input type="datetime-local" name="starts_at" class="<?= $input ?>" value="<?= !empty($s['starts_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $s['starts_at']))) : '' ?>">
                    <input type="number" name="duration_minutes" class="<?= $input ?>" value="<?= (int) ($s['duration_minutes'] ?? 90) ?>">
                    <button class="h-10 px-4 rounded-xl border text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.save')) ?></button>
                </form>
                <?php if (!empty($s['rtmps_url'])): ?>
                    <input readonly class="<?= $input ?> font-mono text-xs" value="<?= htmlspecialchars((string) $s['rtmps_url']) ?>">
                    <input readonly class="<?= $input ?> font-mono text-xs" value="<?= htmlspecialchars((string) $s['stream_key']) ?>">
                <?php endif; ?>
                <div class="flex flex-wrap gap-2">
                    <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/sessions/' . (int) $s['id'] . '/provision') ?>"><?= csrf_field() ?>
                        <button class="h-10 px-3 rounded-xl border text-xs font-bold"><?= htmlspecialchars(t('digital.get_key')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/sessions/' . (int) $s['id'] . '/go-live') ?>"><?= csrf_field() ?>
                        <button class="h-10 px-3 rounded-xl bg-red-600 text-white text-xs font-bold"><?= htmlspecialchars(t('digital.go_live')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/sessions/' . (int) $s['id'] . '/end') ?>"><?= csrf_field() ?>
                        <button class="h-10 px-3 rounded-xl border text-xs font-bold"><?= htmlspecialchars(t('digital.end_live')) ?></button>
                    </form>
                    <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/sessions/' . (int) $s['id'] . '/delete') ?>"><?= csrf_field() ?>
                        <button class="h-10 px-3 text-xs text-red-600"><?= htmlspecialchars(t('digital.delete')) ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
        <form method="post" action="<?= ProductHelper::url('/digital/studio/' . $id . '/sessions') ?>" class="space-y-2">
            <?= csrf_field() ?>
            <h3 class="text-sm font-semibold"><?= htmlspecialchars(t('digital.session_add')) ?></h3>
            <input name="title" required class="<?= $input ?>" placeholder="<?= htmlspecialchars(t('digital.session_title')) ?>">
            <input type="datetime-local" name="starts_at" class="<?= $input ?>">
            <input type="number" name="duration_minutes" class="<?= $input ?>" value="90">
            <button class="h-11 px-5 rounded-xl bg-violet-700 text-white text-xs font-bold uppercase"><?= htmlspecialchars(t('digital.session_add')) ?></button>
        </form>
    </div>
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
