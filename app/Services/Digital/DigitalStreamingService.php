<?php

namespace App\Services\Digital;

use App\Helpers\ActivityLogger;
use App\Models\DigitalProduct;
use App\Models\Notification;
use App\Models\Product;
use App\Services\Streaming\CloudflareStreamClient;

class DigitalStreamingService
{
    private DigitalProduct $digital;
    private CloudflareStreamClient $cf;

    public function __construct(?DigitalProduct $digital = null, ?CloudflareStreamClient $cf = null)
    {
        $this->digital = $digital ?? new DigitalProduct();
        $this->cf = $cf ?? new CloudflareStreamClient();
    }

    /** @return array{ok: bool, error?: string} */
    public function saveSchedule(int $digitalId, int $authorId, array $post): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }

        $kind = (string) ($post['kind'] ?? $row['kind']);
        if (!in_array($kind, DigitalProduct::KINDS, true)) {
            $kind = $row['kind'];
        }

        $starts = trim((string) ($post['starts_at'] ?? ''));
        $startsAt = $starts !== '' ? date('Y-m-d H:i:s', strtotime($starts)) : null;
        $duration = max(15, min(720, (int) ($post['duration_minutes'] ?? $row['duration_minutes'])));
        $endsAt = $startsAt
            ? date('Y-m-d H:i:s', strtotime($startsAt) + $duration * 60)
            : null;
        $accessDays = max(1, min(3650, (int) ($post['access_days'] ?? $row['access_days'])));
        $wm = (string) ($post['watermark_mode'] ?? $row['watermark_mode']);
        if (!in_array($wm, ['none', 'name', 'order', 'email'], true)) {
            $wm = 'order';
        }

        $this->digital->updateFields($digitalId, [
            'kind' => $kind,
            'record_enabled' => !empty($post['record_enabled']) ? 1 : 0,
            'duration_minutes' => $duration,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'access_days' => $accessDays,
            'watermark_mode' => $wm,
        ]);

        return ['ok' => true];
    }

    /** Создаёт Live Input у Cloudflare и сохраняет Stream Key для OBS. */
    public function provisionLive(int $digitalId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }

        $product = (new Product())->find((int) $row['product_id']);
        $name = $product['title'] ?? ('live-' . $digitalId);

        if (!empty($row['cf_live_input_uid'])) {
            $existing = $this->cf->getLiveInput((string) $row['cf_live_input_uid']);
            if ($existing['ok']) {
                $this->digital->updateFields($digitalId, [
                    'rtmps_url' => $existing['rtmps_url'] ?? $row['rtmps_url'],
                    'stream_key' => $existing['stream_key'] ?? $row['stream_key'],
                    'srt_url' => $existing['srt_url'] ?? $row['srt_url'],
                    'live_status' => $row['live_status'] === 'idle' ? 'ready' : $row['live_status'],
                    'cf_playback_uid' => $existing['uid'] ?: $row['cf_playback_uid'],
                ]);
                return ['ok' => true];
            }
        }

        $created = $this->cf->createLiveInput((string) $name, (bool) $row['record_enabled']);
        if (!$created['ok']) {
            return $created;
        }

        $this->digital->updateFields($digitalId, [
            'cf_live_input_uid' => $created['uid'],
            'cf_playback_uid' => $created['uid'],
            'rtmps_url' => $created['rtmps_url'],
            'stream_key' => $created['stream_key'],
            'srt_url' => $created['srt_url'] ?? null,
            'live_status' => 'ready',
        ]);

        ActivityLogger::info('digital.live_provision', 'Live Input Cloudflare для «' . $name . '»', 'digital', $digitalId);

        return ['ok' => true];
    }

    public function markLiveStarted(int $digitalId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        if (empty($row['cf_live_input_uid'])) {
            return ['ok' => false, 'error' => t('digital.live_missing')];
        }
        $this->digital->updateFields($digitalId, ['live_status' => 'live']);
        $this->notifyBuyers((int) $row['id'], t('digital.notify_live', [
            'title' => (string) ((new Product())->find((int) $row['product_id'])['title'] ?? ''),
        ]));
        return ['ok' => true];
    }

    public function markLiveEnded(int $digitalId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $this->digital->updateFields($digitalId, [
            'live_status' => 'ended',
            'ends_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true];
    }

    public function createVodUpload(int $digitalId, int $authorId, string $title): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $up = $this->cf->createDirectUpload($title !== '' ? $title : ('vod-' . $digitalId));
        if (!$up['ok']) {
            return $up;
        }
        $this->digital->updateFields($digitalId, [
            'cf_playback_uid' => $up['uid'],
        ]);
        return $up;
    }

    public function attachVideoUid(int $digitalId, int $authorId, string $uid): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $uid = trim($uid);
        if (!preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $uid)) {
            return ['ok' => false, 'error' => t('digital.video_uid_invalid')];
        }
        $info = $this->cf->getVideo($uid);
        if (!$info['ok']) {
            return $info;
        }
        $this->digital->updateFields($digitalId, ['cf_playback_uid' => $uid]);
        return ['ok' => true];
    }

    public function applyWebhook(array $payload): void
    {
        $type = (string) ($payload['eventType'] ?? $payload['type'] ?? '');
        $videoUid = (string) (
            $payload['data']['uid']
            ?? $payload['uid']
            ?? $payload['video']['uid']
            ?? ''
        );
        $liveUid = (string) (
            $payload['liveInput']['uid']
            ?? $payload['data']['liveInput']['uid']
            ?? $payload['input']['uid']
            ?? ''
        );
        $uid = $videoUid !== '' ? $videoUid : $liveUid;
        $eventId = $this->digital->storeProviderEvent($type !== '' ? $type : 'unknown', $uid ?: null, $payload);

        $dp = null;
        if ($liveUid !== '') {
            $dp = $this->digital->findByLiveInputUid($liveUid);
        }
        if (!$dp && $videoUid !== '') {
            $dp = $this->digital->findByVideoUid($videoUid);
        }
        $session = $liveUid !== '' ? $this->digital->findSessionByLiveInputUid($liveUid) : null;
        if (!$session && $uid !== '') {
            $session = $this->digital->findSessionByVideoUid($uid);
        }
        if (!$dp && $session) {
            $dp = $this->digital->find((int) $session['digital_product_id']);
        }

        if ($session) {
            if (str_contains(strtolower($type), 'connected') || $type === 'live_input.connected') {
                $this->digital->updateSessionFields((int) $session['id'], ['live_status' => 'live']);
            }
            if (str_contains(strtolower($type), 'disconnected') || $type === 'live_input.disconnected') {
                $this->digital->updateSessionFields((int) $session['id'], ['live_status' => 'ended']);
            }
            if (in_array($type, ['video.ready', 'readyToStream'], true) || !empty($payload['readyToStream'])) {
                $hadRecording = trim((string) ($session['cf_recording_uid'] ?? '')) !== '';
                $this->digital->updateSessionFields((int) $session['id'], [
                    'cf_recording_uid' => $uid,
                    'cf_playback_uid' => $uid,
                    'live_status' => 'ended',
                ]);
                if (!$hadRecording && $dp) {
                    $product = (new Product())->find((int) $dp['product_id']);
                    $this->notifyBuyers((int) $dp['id'], t('digital.notify_recording_session', [
                        'title' => (string) ($product['title'] ?? ''),
                        'session' => (string) ($session['title'] ?? ''),
                    ]));
                }
            }
        }

        if ($dp) {
            if (str_contains(strtolower($type), 'connected') || $type === 'live_input.connected') {
                $this->digital->updateFields((int) $dp['id'], ['live_status' => 'live']);
            }
            if (str_contains(strtolower($type), 'disconnected') || $type === 'live_input.disconnected') {
                $this->digital->updateFields((int) $dp['id'], ['live_status' => 'ended']);
            }
            if (!$session && (in_array($type, ['video.ready', 'readyToStream'], true) || !empty($payload['readyToStream']))) {
                $hadRecording = trim((string) ($dp['cf_recording_uid'] ?? '')) !== '';
                $this->digital->updateFields((int) $dp['id'], [
                    'cf_recording_uid' => $uid,
                    'cf_playback_uid' => $uid,
                    'live_status' => $dp['live_status'] === 'live' ? 'ended' : $dp['live_status'],
                ]);
                if (!$hadRecording) {
                    $product = (new Product())->find((int) $dp['product_id']);
                    $this->notifyBuyers((int) $dp['id'], t('digital.notify_recording', [
                        'title' => (string) ($product['title'] ?? ''),
                    ]));
                }
            }
        }

        $this->digital->markProviderEventProcessed($eventId);
    }

    public function provisionSession(int $digitalId, int $sessionId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $session = $this->digital->findSession($sessionId);
        if (!$session || (int) $session['digital_product_id'] !== $digitalId) {
            return ['ok' => false, 'error' => t('digital.not_found')];
        }
        $name = (string) $session['title'];
        if (!empty($session['cf_live_input_uid'])) {
            $existing = $this->cf->getLiveInput((string) $session['cf_live_input_uid']);
            if ($existing['ok']) {
                $this->digital->updateSessionFields($sessionId, [
                    'rtmps_url' => $existing['rtmps_url'] ?? $session['rtmps_url'],
                    'stream_key' => $existing['stream_key'] ?? $session['stream_key'],
                    'live_status' => $session['live_status'] === 'idle' ? 'ready' : $session['live_status'],
                    'cf_playback_uid' => $existing['uid'] ?: $session['cf_playback_uid'],
                ]);
                return ['ok' => true];
            }
        }
        $created = $this->cf->createLiveInput($name, true);
        if (!$created['ok']) {
            return $created;
        }
        $this->digital->updateSessionFields($sessionId, [
            'cf_live_input_uid' => $created['uid'],
            'cf_playback_uid' => $created['uid'],
            'rtmps_url' => $created['rtmps_url'],
            'stream_key' => $created['stream_key'],
            'live_status' => 'ready',
        ]);
        return ['ok' => true];
    }

    public function startSession(int $digitalId, int $sessionId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $session = $this->digital->findSession($sessionId);
        if (!$session || empty($session['cf_live_input_uid'])) {
            return ['ok' => false, 'error' => t('digital.live_missing')];
        }
        $this->digital->updateSessionFields($sessionId, ['live_status' => 'live']);
        $this->digital->updateFields($digitalId, [
            'live_status' => 'live',
            'cf_live_input_uid' => $session['cf_live_input_uid'],
            'cf_playback_uid' => $session['cf_playback_uid'] ?? $session['cf_live_input_uid'],
            'rtmps_url' => $session['rtmps_url'],
            'stream_key' => $session['stream_key'],
        ]);
        $product = (new Product())->find((int) $row['product_id']);
        $this->notifyBuyers($digitalId, t('digital.notify_live', [
            'title' => (string) ($product['title'] ?? '') . ' — ' . (string) $session['title'],
        ]));
        return ['ok' => true];
    }

    public function endSession(int $digitalId, int $sessionId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $session = $this->digital->findSession($sessionId);
        if (!$session || (int) $session['digital_product_id'] !== $digitalId) {
            return ['ok' => false, 'error' => t('digital.not_found')];
        }
        $this->digital->updateSessionFields($sessionId, ['live_status' => 'ended']);
        $this->digital->updateFields($digitalId, ['live_status' => 'ended']);
        return ['ok' => true];
    }

    public function createLessonUpload(int $digitalId, int $lessonId, int $authorId): array
    {
        $row = $this->digital->find($digitalId);
        if (!$row || (int) $row['author_id'] !== $authorId) {
            return ['ok' => false, 'error' => t('digital.forbidden')];
        }
        $lesson = $this->digital->findLesson($lessonId);
        if (!$lesson || (int) $lesson['digital_product_id'] !== $digitalId) {
            return ['ok' => false, 'error' => t('digital.not_found')];
        }
        $up = $this->cf->createDirectUpload((string) $lesson['title']);
        if (!$up['ok']) {
            return $up;
        }
        $this->digital->updateLessonFields($lessonId, ['cf_video_uid' => $up['uid']]);
        return $up;
    }

    /**
     * @return array{ok: bool, type?: string, uid?: string, file?: string, body?: string, error?: string, phase?: string}
     */
    public function resolvePlayable(array $dp, ?int $lessonId, ?int $sessionId): array
    {
        if ($sessionId) {
            $session = $this->digital->findSession($sessionId);
            if (!$session || (int) $session['digital_product_id'] !== (int) $dp['id']) {
                return ['ok' => false, 'error' => t('digital.not_found')];
            }
            $uid = (string) ($session['cf_recording_uid'] ?: $session['cf_playback_uid'] ?: $session['cf_live_input_uid'] ?: '');
            if ($uid === '') {
                return ['ok' => false, 'error' => t('digital.video_missing')];
            }
            $phase = (string) $session['live_status'] === 'live' ? 'live' : 'vod';
            return ['ok' => true, 'type' => 'video', 'uid' => $uid, 'phase' => $phase];
        }
        if ($lessonId) {
            $lesson = $this->digital->findLesson($lessonId);
            if (!$lesson || (int) $lesson['digital_product_id'] !== (int) $dp['id']) {
                return ['ok' => false, 'error' => t('digital.not_found')];
            }
            if (($lesson['kind'] ?? '') === 'text') {
                return ['ok' => true, 'type' => 'text', 'body' => (string) ($lesson['body'] ?? '')];
            }
            if (($lesson['kind'] ?? '') === 'pdf') {
                $file = (string) ($lesson['file_path'] ?? '');
                if ($file === '') {
                    return ['ok' => false, 'error' => t('digital.file_missing')];
                }
                return ['ok' => true, 'type' => 'pdf', 'file' => $file];
            }
            if (($lesson['kind'] ?? '') === 'live_session' && !empty($lesson['live_session_id'])) {
                return $this->resolvePlayable($dp, null, (int) $lesson['live_session_id']);
            }
            $uid = (string) ($lesson['cf_video_uid'] ?? '');
            if ($uid === '') {
                return ['ok' => false, 'error' => t('digital.video_missing')];
            }
            return ['ok' => true, 'type' => 'video', 'uid' => $uid, 'phase' => 'vod'];
        }
        $uid = $this->playbackUid($dp);
        if (!$uid) {
            return ['ok' => false, 'error' => t('digital.video_missing')];
        }
        return ['ok' => true, 'type' => 'video', 'uid' => $uid, 'phase' => $this->viewerPhase($dp)];
    }

    public function playbackUid(array $dp): ?string
    {
        $status = (string) ($dp['live_status'] ?? 'idle');
        if ($status === 'live' && !empty($dp['cf_live_input_uid'])) {
            return (string) $dp['cf_live_input_uid'];
        }
        if (!empty($dp['cf_recording_uid'])) {
            return (string) $dp['cf_recording_uid'];
        }
        if (!empty($dp['cf_playback_uid'])) {
            return (string) $dp['cf_playback_uid'];
        }
        if (!empty($dp['cf_live_input_uid']) && in_array($status, ['ready', 'ended'], true)) {
            return (string) $dp['cf_live_input_uid'];
        }
        return null;
    }

    public function viewerPhase(array $dp): string
    {
        $status = (string) ($dp['live_status'] ?? 'idle');
        if ($status === 'live') {
            return 'live';
        }
        if (!empty($dp['cf_recording_uid']) || ($status === 'ended' && !empty($dp['cf_playback_uid']))) {
            return 'vod';
        }
        if (!empty($dp['cf_playback_uid']) && in_array((string) ($dp['kind'] ?? ''), ['vod', 'course', 'bundle', 'webinar'], true)) {
            return 'vod';
        }
        $starts = $dp['starts_at'] ?? null;
        if ($starts && strtotime((string) $starts) > time()) {
            return 'countdown';
        }
        if ($status === 'ready') {
            return 'waiting';
        }
        if ($status === 'ended') {
            return 'processing';
        }
        if ($this->playbackUid($dp)) {
            return 'vod';
        }
        return 'countdown';
    }

    private function notifyBuyers(int $digitalProductId, string $message): void
    {
        $n = new Notification();
        foreach ($this->digital->activeBuyerIds($digitalProductId) as $uid) {
            $n->createFor($uid, $message);
        }
    }
}
