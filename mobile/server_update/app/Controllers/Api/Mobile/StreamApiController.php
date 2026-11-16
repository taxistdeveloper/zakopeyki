<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Helpers\AvatarHelper;
use App\Helpers\ProductHelper;
use App\Helpers\UploadHelper;
use App\Models\Follow;
use App\Models\Product;
use App\Models\Stream;
use App\Models\User;
use App\Services\MobileApi\Presenter;
use App\Services\Security\AuthThrottle;
use App\Services\Streaming\LiveGiveawayPolicy;

/**
 * Прямые эфиры в приложении (то же, что StreamController сайта, но по Bearer-токену).
 *
 * Видео идёт P2P по WebRTC: ведущий создаёт по соединению на каждого зрителя.
 * Сервер — только «почтовый ящик» сигналов (stream_signals):
 *   зритель → join → ведущий шлёт offer (+ice) → зритель шлёт answer (+ice) → leave при выходе.
 * Эфиры не записываются.
 */
final class StreamApiController extends BaseMobileController
{
    private const COVER_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_COVER = 5 * 1024 * 1024;
    private const DURATIONS = [1800, 3600, 7200, 10800, 14400];

    /** GET /streams — идущие сейчас эфиры */
    public function index(): void
    {
        $this->guardSiteOpen();
        $uid = $this->optionalUserId();
        $model = new Stream();
        $users = new User();
        $items = [];
        foreach ($model->allActive(30) as $s) {
            $host = $users->find((int) $s['user_id']) ?? [];
            $setup = Stream::decodeSetup($s['live_setup'] ?? null) ?? [];
            if (($setup['visibility'] ?? 'all') === 'followers' && $uid !== (int) $s['user_id']
                && ($uid === null || !(new Follow())->isFollowing($uid, (int) $s['user_id']))) {
                continue;
            }
            $items[] = [
                'id' => (int) $s['id'],
                'title' => (string) $s['title'],
                'cover_url' => $this->cover($s['cover'] ?? null),
                'host' => $this->host($host),
                'viewers' => $model->countViewers((int) $s['id']),
                'likes' => (int) ($s['likes_count'] ?? 0),
                'started_at' => Presenter::date($s['created_at'] ?? null),
                'is_demo' => !empty($s['is_demo']),
                'is_mine' => $uid !== null && $uid === (int) $s['user_id'],
            ];
        }
        $this->ok(['items' => $items]);
    }

    /** GET /streams/{id}?viewer_key= — состояние эфира: зрители, лайки, товары, розыгрыш */
    public function show(string $id): void
    {
        $this->guardSiteOpen();
        $uid = $this->optionalUserId();
        $model = new Stream();
        $stream = $model->findLive((int) $id);
        if (!$stream) {
            $this->fail('stream_ended', 404, 'Эфир завершён');
        }
        $viewerKey = $this->queryString('viewer_key', 64);
        if ($viewerKey !== '' && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $viewerKey)) {
            $model->touchViewer((int) $id, $viewerKey);
        }

        $hostId = (int) $stream['user_id'];
        $setup = Stream::decodeSetup($stream['live_setup'] ?? null) ?? [];
        $products = $this->shelf($hostId, $setup);
        $byId = [];
        foreach ($products as $p) {
            $byId[$p['id']] = $p;
        }
        $featuredId = (int) ($stream['featured_product_id'] ?? 0) ?: (int) ($setup['featured_product_id'] ?? 0);
        $featured = $byId[$featuredId] ?? ($products[0] ?? null);

        $giveaway = null;
        if (LiveGiveawayPolicy::isEnabled() && !empty($setup['giveaway']['title'])) {
            $winner = $model->giveawayDraw((int) $id);
            $giveaway = [
                'title' => mb_substr((string) $setup['giveaway']['title'], 0, 120),
                'goal' => max(50, min(5000, (int) ($setup['giveaway']['goal'] ?? 500))),
                'participants' => $model->countGiveawayParticipants((int) $id),
                'winner_name' => is_array($winner) ? (string) ($winner['user_name'] ?? $winner['name'] ?? '') : null,
            ];
        }

        $follow = new Follow();
        $this->ok(['stream' => [
            'id' => (int) $stream['id'],
            'title' => (string) $stream['title'],
            'cover_url' => $this->cover($stream['cover'] ?? null),
            'host' => $this->host((new User())->find($hostId) ?? []),
            'is_host' => $uid === $hostId,
            'is_demo' => !empty($stream['is_demo']),
            'viewers' => $model->countViewers((int) $id),
            'likes' => (int) ($stream['likes_count'] ?? 0),
            'started_at' => Presenter::date($stream['created_at'] ?? null),
            'duration' => max(0, (int) ($setup['duration'] ?? 0)),
            'chat_enabled' => !isset($setup['chat_enabled']) || (bool) $setup['chat_enabled'],
            'followers_count' => $follow->countFollowers($hostId),
            'is_following' => $uid !== null && $uid !== $hostId && $follow->isFollowing($uid, $hostId),
            'featured' => $featured,
            'products' => $products,
            'giveaway' => $giveaway,
            'ice_servers' => self::iceServers(),
        ]]);
    }

    // ---------------------------------------------------------------- WebRTC-сигналы

    /** POST /streams/{id}/signal {role: viewer|host, peer_id, type: join|offer|answer|ice|leave, payload?} */
    public function signal(string $id): void
    {
        $model = new Stream();
        $streamId = (int) $id;
        $peerId = $this->input('peer_id');
        $type = $this->input('type');
        $role = $this->input('role', 'viewer') === 'host' ? 'host' : 'viewer';
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $peerId) || !in_array($type, ['join', 'offer', 'answer', 'ice', 'leave'], true)) {
            $this->fail('validation_failed', 422);
        }
        if (!$model->isLiveActive($streamId)) {
            $this->fail('stream_ended', 404, 'Эфир завершён');
        }
        $payload = $this->body()['payload'] ?? null;
        if ($payload !== null && !is_array($payload)) {
            $this->fail('validation_failed', 422);
        }

        if ($role === 'host') {
            $uid = $this->requireUserId();
            if (!$model->isLiveOwned($streamId, $uid) || !in_array($type, ['offer', 'ice'], true)) {
                $this->fail('forbidden', 403, 'Нет доступа');
            }
            $this->ok(['id' => $model->pushSignal($streamId, $peerId, 'to_viewer', $type, $payload)]);
        }

        if ($type === 'offer') {
            $this->fail('forbidden', 403, 'Нет доступа');
        }
        if ($type === 'leave') {
            $model->clearPeerSignals($streamId, $peerId);
            $model->pushSignal($streamId, $peerId, 'to_host', 'leave', null);
            $this->ok(['id' => 0]);
        }
        $this->ok(['id' => $model->pushSignal($streamId, $peerId, 'to_host', $type, $payload)]);
    }

    /** GET /streams/{id}/signals?role=&peer_id=&after= */
    public function signals(string $id): void
    {
        $model = new Stream();
        $streamId = (int) $id;
        $after = $this->queryInt('after', 0, 0, PHP_INT_MAX);
        if (!$model->isLiveActive($streamId)) {
            $this->fail('stream_ended', 404, 'Эфир завершён');
        }
        if ($this->queryString('role', 10) === 'host') {
            $uid = $this->requireUserId();
            if (!$model->isLiveOwned($streamId, $uid)) {
                $this->fail('forbidden', 403, 'Нет доступа');
            }
            $this->ok(['signals' => $model->pollSignals($streamId, 'to_host', $after)]);
        }
        $peerId = $this->queryString('peer_id', 64);
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $peerId)) {
            $this->fail('validation_failed', 422);
        }
        $this->ok(['signals' => $model->pollSignals($streamId, 'to_viewer', $after, $peerId)]);
    }

    // ---------------------------------------------------------------- чат, лайки, розыгрыш

    /** GET /streams/{id}/comments?after= */
    public function comments(string $id): void
    {
        $model = new Stream();
        if (!$model->isLiveActive((int) $id)) {
            $this->fail('stream_ended', 404, 'Эфир завершён');
        }
        $rows = $model->pollComments((int) $id, $this->queryInt('after', 0, 0, PHP_INT_MAX));
        $this->ok(['comments' => array_map(static fn (array $c): array => [
            'id' => (int) $c['id'],
            'user_name' => (string) ($c['user_name'] ?? ''),
            'body' => (string) ($c['body'] ?? ''),
            'is_host' => !empty($c['is_host']),
        ], $rows)]);
    }

    /** POST /streams/{id}/comments {body} */
    public function comment(string $id): void
    {
        $uid = $this->requireUserId();
        $this->throttle('stream_comment', 'u' . $uid);
        $model = new Stream();
        $stream = $model->findLive((int) $id);
        if (!$stream) {
            $this->fail('stream_ended', 404, 'Эфир завершён');
        }
        $setup = Stream::decodeSetup($stream['live_setup'] ?? null) ?? [];
        if (isset($setup['chat_enabled']) && !$setup['chat_enabled']) {
            $this->fail('chat_off', 403, 'Чат в этом эфире выключен');
        }
        $body = mb_substr(preg_replace('/\s+/u', ' ', $this->input('body')) ?? '', 0, 280);
        if ($body === '') {
            $this->fail('validation_failed', 422, 'Введите сообщение');
        }
        $name = (string) ((new User())->find($uid)['name'] ?? 'User');
        $isHost = (int) $stream['user_id'] === $uid;
        $cid = $model->addComment((int) $id, $uid, $name, $body, $isHost);
        $this->ok(['comment' => ['id' => $cid, 'user_name' => $name, 'body' => $body, 'is_host' => $isHost]], [], 201);
    }

    /** POST /streams/{id}/like */
    public function like(string $id): void
    {
        $model = new Stream();
        if (!$model->isLiveActive((int) $id)) {
            $this->fail('stream_ended', 404, 'Эфир завершён');
        }
        $uid = $this->optionalUserId();
        $this->throttle('stream_like', $uid !== null ? 'u' . $uid : 'ip' . AuthThrottle::ip());
        $this->ok(['likes' => $model->addLike((int) $id)]);
    }

    /** POST /streams/{id}/giveaway/join */
    public function joinGiveaway(string $id): void
    {
        $uid = $this->requireUserId();
        if (!LiveGiveawayPolicy::isEnabled()) {
            $this->fail('giveaway_disabled', 403, 'Розыгрыши выключены');
        }
        $res = (new Stream())->joinGiveaway((int) $id, $uid);
        if (empty($res['ok'])) {
            $this->fail('giveaway_failed', 422, (string) ($res['message'] ?? $res['error'] ?? 'Не удалось участвовать'));
        }
        $this->ok($res);
    }

    // ---------------------------------------------------------------- ведущий

    /** GET /streams/my-products — товары, которые можно показать в эфире */
    public function myProducts(): void
    {
        $uid = $this->requireUserId();
        $items = [];
        foreach ((new Product())->activeShopByUser($uid, 100) as $p) {
            if (ProductHelper::isPurchasable($p)) {
                $items[] = $this->shopProduct($p, []);
            }
        }
        $this->ok([
            'items' => $items,
            'durations' => self::DURATIONS,
            'giveaway_enabled' => LiveGiveawayPolicy::isEnabled(),
        ]);
    }

    /**
     * POST /streams (multipart: setup — JSON {product_ids[], featured_product_id?, featured_price?, duration,
     * visibility: all|followers, chat_enabled, notify_subs, giveaway?: {title, goal}}; cover — файл, необязательно)
     */
    public function start(): void
    {
        $uid = $this->requireUserId();
        $this->guardSiteOpen();
        $raw = $this->body()['setup'] ?? '';
        $data = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
        if (!is_array($data)) {
            $this->fail('validation_failed', 422, 'Некорректные настройки эфира');
        }
        $setup = $this->parseSetup($uid, $data);
        $cover = $this->storeCover($uid);
        $user = (new User())->find($uid) ?? [];
        $title = 'Стрим — ' . ($user['name'] ?? 'Пользователь');
        // startLive сам закрывает прежние эфиры этого пользователя
        $model = new Stream();
        $id = $model->startLive($uid, $title, $cover, $setup);
        if (!empty($setup['notify_subs'])) {
            (new Follow())->notifyFollowers($uid, t('seller.notify_live', ['name' => (string) ($user['name'] ?? 'Продавец')]));
        }
        $this->ok(['stream_id' => $id], [], 201);
    }

    /** POST /streams/{id}/heartbeat — ведущий раз в 10–15 с, иначе эфир считается оборванным через 45 с */
    public function heartbeat(string $id): void
    {
        $uid = $this->requireUserId();
        (new Stream())->heartbeat((int) $id, $uid);
        $this->ok(['live' => (new Stream())->isLiveOwned((int) $id, $uid)]);
    }

    /** POST /streams/{id}/end */
    public function end(string $id): void
    {
        $uid = $this->requireUserId();
        (new Stream())->endLive((int) $id, $uid);
        $this->ok(['ended' => true]);
    }

    /** POST /streams/{id}/feature {product_id} — «товар дня» в эфире */
    public function feature(string $id): void
    {
        $uid = $this->requireUserId();
        $model = new Stream();
        if (!$model->isLiveOwned((int) $id, $uid)) {
            $this->fail('forbidden', 403, 'Нет доступа');
        }
        $product = (new Product())->find((int) $this->input('product_id', '0'));
        if (!$product || (int) $product['user_id'] !== $uid || !ProductHelper::isPurchasable($product)) {
            $this->fail('validation_failed', 422, 'Товар недоступен');
        }
        $model->setFeaturedProduct((int) $id, $uid, (int) $product['id']);
        $this->ok(['featured' => $this->shopProduct($product, [])]);
    }

    /** POST /streams/{id}/giveaway/draw — ведущий выбирает победителя */
    public function drawGiveaway(string $id): void
    {
        $uid = $this->requireUserId();
        if (!LiveGiveawayPolicy::isEnabled()) {
            $this->fail('giveaway_disabled', 403, 'Розыгрыши выключены');
        }
        $res = (new Stream())->drawGiveawayWinner((int) $id, $uid);
        if (empty($res['ok'])) {
            $this->fail('giveaway_failed', 422, (string) ($res['message'] ?? $res['error'] ?? 'Не удалось провести розыгрыш'));
        }
        $this->ok($res);
    }

    // ---------------------------------------------------------------- helpers

    /** STUN без TURN — как на сайте. За строгим NAT мобильного оператора видео может не дойти. */
    public static function iceServers(): array
    {
        $cfg = $GLOBALS['appConfig']['webrtc_ice_servers'] ?? null;
        if (is_array($cfg) && $cfg !== []) {
            return array_values($cfg);
        }

        return [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
        ];
    }

    /** @param array<string, mixed> $setup */
    private function shelf(int $hostId, array $setup): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($setup['product_ids'] ?? []))));
        $model = new Product();
        $rows = $ids !== [] ? $model->activeShopByUserAndIds($hostId, $ids) : $model->activeShopByUser($hostId, 24);
        $out = [];
        foreach ($rows as $p) {
            if (ProductHelper::isPurchasable($p)) {
                $out[] = $this->shopProduct($p, $setup);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $setup */
    private function shopProduct(array $p, array $setup): array
    {
        $id = (int) $p['id'];
        $out = [
            'id' => $id,
            'title' => (string) ($p['title'] ?? ''),
            'price_text' => ProductHelper::formatPrice($p),
            'old_price_text' => null,
            'image_url' => Presenter::media(ProductHelper::imageUrl($p)),
        ];
        $special = $setup['featured_price'] ?? null;
        if ((int) ($setup['featured_product_id'] ?? 0) === $id && $special !== null && (int) $special !== (int) ($p['price'] ?? 0)) {
            $fake = $p;
            $fake['price'] = (int) $special;
            $out['old_price_text'] = $out['price_text'];
            $out['price_text'] = ProductHelper::formatPrice($fake);
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function parseSetup(int $uid, array $data): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['product_ids'] ?? [])), static fn ($i) => $i > 0)));
        $owned = [];
        if ($ids !== []) {
            foreach ((new Product())->activeShopByUserAndIds($uid, $ids) as $p) {
                if (ProductHelper::isPurchasable($p)) {
                    $owned[(int) $p['id']] = true;
                }
            }
        }
        $ids = array_values(array_filter($ids, static fn ($i) => isset($owned[$i])));
        if ($ids === []) {
            $this->fail('validation_failed', 422, 'Добавьте минимум 1 товар для начала эфира', ['product_ids' => 'Добавьте минимум 1 товар']);
        }
        $featured = (int) ($data['featured_product_id'] ?? 0);
        if (!isset($owned[$featured])) {
            $featured = 0;
        }
        $price = ($featured > 0 && isset($data['featured_price']) && $data['featured_price'] !== '' && $data['featured_price'] !== null)
            ? max(0, (int) $data['featured_price']) : null;
        $duration = in_array((int) ($data['duration'] ?? 7200), self::DURATIONS, true) ? (int) $data['duration'] : 7200;

        $giveaway = null;
        if (LiveGiveawayPolicy::isEnabled() && !empty($data['giveaway']['title'])) {
            $giveaway = [
                'title' => mb_substr(trim((string) $data['giveaway']['title']), 0, 120),
                'goal' => max(50, min(5000, (int) ($data['giveaway']['goal'] ?? 500))),
            ];
        }

        return [
            'product_ids' => $ids,
            'featured_product_id' => $featured > 0 ? $featured : null,
            'featured_price' => $price,
            'duration' => $duration,
            'visibility' => ($data['visibility'] ?? 'all') === 'followers' ? 'followers' : 'all',
            'chat_enabled' => !isset($data['chat_enabled']) || (bool) $data['chat_enabled'],
            'notify_subs' => !isset($data['notify_subs']) || (bool) $data['notify_subs'],
            'giveaway' => $giveaway,
        ];
    }

    private function storeCover(int $uid): ?string
    {
        $file = $_FILES['cover'] ?? null;
        if (!is_array($file) || empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > self::MAX_COVER) {
            $this->fail('cover_too_big', 422, 'Обложка слишком большая (макс. 5 МБ)');
        }
        if (!UploadHelper::isAllowedUpload((string) $file['tmp_name'], (string) $file['name'], self::COVER_EXT)) {
            $this->fail('cover_format', 422, 'Формат обложки: JPG, PNG или WebP');
        }
        $dir = dirname(__DIR__, 4) . '/public/uploads/streams';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $name = 'cover_' . $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . UploadHelper::normalizeExt((string) $file['name']);
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            $this->fail('cover_save', 500, 'Не удалось сохранить обложку');
        }

        return $name;
    }

    private function cover(mixed $file): ?string
    {
        return is_string($file) && $file !== '' ? Presenter::media(ProductHelper::url('public/uploads/streams/' . basename($file))) : null;
    }

    /** @param array<string, mixed> $u */
    private function host(array $u): array
    {
        return [
            'id' => (int) ($u['id'] ?? 0),
            'name' => (string) ($u['name'] ?? ''),
            'avatar_url' => Presenter::media(AvatarHelper::url($u)),
        ];
    }

    private function throttle(string $action, string $key): void
    {
        $res = (new AuthThrottle())->hitAll([[$action, $key]]);
        if (empty($res['allowed'])) {
            $this->fail('too_many_attempts', 429, 'Слишком часто, подождите немного', [], ['Retry-After' => (string) ((int) ($res['retry_after'] ?? 60))]);
        }
    }
}
