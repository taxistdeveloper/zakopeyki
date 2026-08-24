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
        $uid = (string) (
            $payload['data']['uid']
            ?? $payload['uid']
            ?? $payload['liveInput']['uid']
            ?? $payload['video']['uid']
            ?? ''
        );
        $eventId = $this->digital->storeProviderEvent($type !== '' ? $type : 'unknown', $uid ?: null, $payload);

        $dp = $uid !== '' ? ($this->digital->findByLiveInputUid($uid) ?: $this->digital->findByVideoUid($uid)) : null;

        if ($dp) {
            if (str_contains(strtolower($type), 'connected') || $type === 'live_input.connected') {
                $this->digital->updateFields((int) $dp['id'], ['live_status' => 'live']);
            }
            if (str_contains(strtolower($type), 'disconnected') || $type === 'live_input.disconnected') {
                $this->digital->updateFields((int) $dp['id'], ['live_status' => 'ended']);
            }
            if (in_array($type, ['video.ready', 'readyToStream'], true) || !empty($payload['readyToStream'])) {
                $this->digital->updateFields((int) $dp['id'], [
                    'cf_recording_uid' => $uid,
                    'cf_playback_uid' => $uid,
                    'live_status' => $dp['live_status'] === 'live' ? 'ended' : $dp['live_status'],
                ]);
                $product = (new Product())->find((int) $dp['product_id']);
                $this->notifyBuyers((int) $dp['id'], t('digital.notify_recording', [
                    'title' => (string) ($product['title'] ?? ''),
                ]));
            }
        }

        $this->digital->markProviderEventProcessed($eventId);
    }

    /**
     * Какой uid отдавать плееру: live input, запись или VOD.
     */
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
