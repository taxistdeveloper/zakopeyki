<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Helpers\UploadHelper;
use App\Models\Product;
use App\Models\Stream;

class StreamController extends Controller
{
    private const COVER_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_COVER = 5 * 1024 * 1024;

    /** Список активных товаров продавца для настройки эфира */
    public function myProducts(): void
    {
        Auth::requireLogin();
        $products = [];
        foreach ((new Product())->activeShopByUser(Auth::id(), 100) as $p) {
            if (!ProductHelper::isPurchasable($p)) {
                continue;
            }
            $products[] = $this->mapShopProduct($p);
        }
        $this->json(['ok' => true, 'products' => $products]);
    }

    /** Старт Live — без файла. После завершения не хранится. */
    public function startLive(): void
    {
        Auth::requireLogin();

        $user = Auth::user();
        $title = 'Стрим — ' . ($user['name'] ?? 'Пользователь');
        $setup = $this->parseSetupFromRequest();
        if (isset($setup['error'])) {
            $this->json(['ok' => false, 'message' => $setup['error']], 422);
        }

        $cover = $this->storeCoverUpload();
        if (isset($cover['error'])) {
            $this->json(['ok' => false, 'message' => $cover['error']], 422);
        }

        $id = (new Stream())->startLive(
            Auth::id(),
            $title,
            $cover['file'] ?? null,
            $setup
        );

        $this->json([
            'ok' => true,
            'id' => $id,
            'title' => $title,
            'cover' => $cover['file'] ?? null,
            'setup' => $setup,
            'message' => 'Стрим начат. После завершения ничего не сохраняется.',
        ]);
    }

    public function heartbeat(): void
    {
        Auth::requireLogin();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->json(['ok' => false], 422);
        }
        (new Stream())->heartbeat($id, Auth::id());
        $this->json(['ok' => true]);
    }

    public function endLive(): void
    {
        Auth::requireLogin();
        $id = (int) ($_POST['id'] ?? 0);
        $model = new Stream();

        if ($id > 0) {
            $model->endLive($id, Auth::id());
        } else {
            $model->endAllLiveForUser(Auth::id());
        }

        $this->json(['ok' => true, 'message' => 'Стрим завершён, запись не сохранялась']);
    }

    /**
     * WebRTC signaling: join / offer / answer / ice / leave
     * Host posts offer+ice; viewers post join+answer+ice.
     */
    public function signal(): void
    {
        $model = new Stream();
        $streamId = (int) ($_POST['stream_id'] ?? 0);
        $peerId = trim((string) ($_POST['peer_id'] ?? ''));
        $type = trim((string) ($_POST['type'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'viewer'));

        if ($streamId <= 0 || $peerId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $peerId)) {
            $this->json(['ok' => false, 'message' => 'bad request'], 422);
        }

        $allowed = ['join', 'offer', 'answer', 'ice', 'leave'];
        if (!in_array($type, $allowed, true)) {
            $this->json(['ok' => false, 'message' => 'bad type'], 422);
        }

        if (!$model->isLiveActive($streamId)) {
            $this->json(['ok' => false, 'message' => 'not live'], 404);
        }

        $payloadRaw = $_POST['payload'] ?? null;
        $payload = null;
        if (is_string($payloadRaw) && $payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (!is_array($decoded)) {
                $this->json(['ok' => false, 'message' => 'bad payload'], 422);
            }
            $payload = $decoded;
        }

        if ($role === 'host') {
            Auth::requireLogin();
            if (!$model->isLiveOwned($streamId, Auth::id())) {
                $this->json(['ok' => false, 'message' => 'forbidden'], 403);
            }
            if (!in_array($type, ['offer', 'ice'], true)) {
                $this->json(['ok' => false, 'message' => 'bad host type'], 422);
            }
            $id = $model->pushSignal($streamId, $peerId, 'to_viewer', $type, $payload);
            $this->json(['ok' => true, 'id' => $id]);
        }

        // viewer
        if (in_array($type, ['offer'], true)) {
            $this->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        if ($type === 'leave') {
            $model->clearPeerSignals($streamId, $peerId);
            $model->pushSignal($streamId, $peerId, 'to_host', 'leave', null);
            $this->json(['ok' => true]);
        }

        $direction = 'to_host';
        $id = $model->pushSignal($streamId, $peerId, $direction, $type, $payload);
        $this->json(['ok' => true, 'id' => $id]);
    }

    /** Poll signaling messages for host or viewer. */
    public function signalPoll(): void
    {
        $model = new Stream();
        $streamId = (int) ($_GET['stream_id'] ?? $_POST['stream_id'] ?? 0);
        $afterId = (int) ($_GET['after'] ?? $_POST['after'] ?? 0);
        $role = trim((string) ($_GET['role'] ?? $_POST['role'] ?? 'viewer'));
        $peerId = trim((string) ($_GET['peer_id'] ?? $_POST['peer_id'] ?? ''));

        if ($streamId <= 0) {
            $this->json(['ok' => false], 422);
        }

        if (!$model->isLiveActive($streamId)) {
            $this->json(['ok' => false, 'live' => false, 'signals' => []], 404);
        }

        if ($role === 'host') {
            Auth::requireLogin();
            if (!$model->isLiveOwned($streamId, Auth::id())) {
                $this->json(['ok' => false, 'message' => 'forbidden'], 403);
            }
            $signals = $model->pollSignals($streamId, 'to_host', $afterId);
            $this->json(['ok' => true, 'live' => true, 'signals' => $signals]);
        }

        if ($peerId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $peerId)) {
            $this->json(['ok' => false], 422);
        }

        $signals = $model->pollSignals($streamId, 'to_viewer', $afterId, $peerId);
        $this->json(['ok' => true, 'live' => true, 'signals' => $signals]);
    }

    /** Мета эфира: зрители, лайки, товар дня, товары полки */
    public function shop(): void
    {
        $model = new Stream();
        $streamId = (int) ($_GET['stream_id'] ?? 0);
        $viewerKey = trim((string) ($_GET['viewer_key'] ?? ''));

        if ($streamId <= 0) {
            $this->json(['ok' => false], 422);
        }

        $stream = $model->findLive($streamId);
        if (!$stream) {
            $this->json(['ok' => false, 'live' => false], 404);
        }

        if ($viewerKey !== '' && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $viewerKey)) {
            $model->touchViewer($streamId, $viewerKey);
        }

        $hostId = (int) $stream['user_id'];
        $setup = Stream::decodeSetup($stream['live_setup'] ?? null) ?? [];
        $selectedIds = [];
        if (!empty($setup['product_ids']) && is_array($setup['product_ids'])) {
            $selectedIds = array_values(array_filter(array_map('intval', $setup['product_ids'])));
        }

        $productModel = new Product();
        $rawProducts = $selectedIds !== []
            ? $productModel->activeShopByUserAndIds($hostId, $selectedIds)
            : $productModel->activeShopByUser($hostId, 24);

        $products = [];
        $byId = [];
        foreach ($rawProducts as $p) {
            if (!ProductHelper::isPurchasable($p)) {
                continue;
            }
            $mapped = $this->mapShopProduct($p, $setup);
            $products[] = $mapped;
            $byId[(int) $mapped['id']] = $mapped;
        }

        $featuredId = (int) ($stream['featured_product_id'] ?? 0);
        if ($featuredId <= 0 && !empty($setup['featured_product_id'])) {
            $featuredId = (int) $setup['featured_product_id'];
        }
        $featured = $featuredId > 0 ? ($byId[$featuredId] ?? null) : null;
        if (!$featured && $featuredId > 0) {
            $row = $productModel->find($featuredId);
            if ($row && (int) $row['user_id'] === $hostId && ProductHelper::isPurchasable($row)) {
                $featured = $this->mapShopProduct($row, $setup);
            }
        }
        if (!$featured && $products !== []) {
            $featured = $products[0];
        }

        $started = $stream['created_at'] ?? null;
        $elapsed = 0;
        if ($started) {
            $elapsed = max(0, time() - strtotime((string) $started));
        }

        $giveaway = null;
        if (!empty($setup['giveaway']) && is_array($setup['giveaway'])) {
            $gTitle = trim((string) ($setup['giveaway']['title'] ?? ''));
            if ($gTitle !== '') {
                $giveaway = [
                    'title' => mb_substr($gTitle, 0, 120),
                    'goal' => max(50, min(5000, (int) ($setup['giveaway']['goal'] ?? 500))),
                ];
            }
        }

        $this->json([
            'ok' => true,
            'live' => true,
            'viewers' => $model->countViewers($streamId),
            'likes' => (int) ($stream['likes_count'] ?? 0),
            'elapsed' => $elapsed,
            'featured_id' => $featured ? (int) $featured['id'] : 0,
            'featured' => $featured,
            'products' => $products,
            'host_name' => (string) ($stream['author_name'] ?? ''),
            'chat_enabled' => !isset($setup['chat_enabled']) || (bool) $setup['chat_enabled'],
            'duration' => max(0, (int) ($setup['duration'] ?? 0)),
            'visibility' => (string) ($setup['visibility'] ?? 'all'),
            'giveaway' => $giveaway,
        ]);
    }

    public function featureProduct(): void
    {
        Auth::requireLogin();
        $model = new Stream();
        $streamId = (int) ($_POST['stream_id'] ?? 0);
        $productId = (int) ($_POST['product_id'] ?? 0);

        if ($streamId <= 0 || $productId <= 0) {
            $this->json(['ok' => false], 422);
        }
        if (!$model->isLiveOwned($streamId, Auth::id())) {
            $this->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        $product = (new Product())->find($productId);
        if (!$product || (int) $product['user_id'] !== Auth::id() || !ProductHelper::isPurchasable($product)) {
            $this->json(['ok' => false, 'message' => 'bad product'], 422);
        }

        $model->setFeaturedProduct($streamId, Auth::id(), $productId);
        $this->json(['ok' => true, 'featured' => $this->mapShopProduct($product)]);
    }

    public function like(): void
    {
        $model = new Stream();
        $streamId = (int) ($_POST['stream_id'] ?? 0);
        if ($streamId <= 0 || !$model->isLiveActive($streamId)) {
            $this->json(['ok' => false], 422);
        }
        $likes = $model->addLike($streamId);
        $this->json(['ok' => true, 'likes' => $likes]);
    }

    public function comments(): void
    {
        $model = new Stream();
        $streamId = (int) ($_GET['stream_id'] ?? 0);
        $after = (int) ($_GET['after'] ?? 0);
        if ($streamId <= 0 || !$model->isLiveActive($streamId)) {
            $this->json(['ok' => false, 'comments' => []], 404);
        }
        $this->json([
            'ok' => true,
            'comments' => $model->pollComments($streamId, $after),
        ]);
    }

    public function comment(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'message' => 'login'], 401);
        }
        $model = new Stream();
        $streamId = (int) ($_POST['stream_id'] ?? 0);
        $body = trim((string) ($_POST['body'] ?? ''));
        $body = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 280);

        if ($streamId <= 0 || $body === '') {
            $this->json(['ok' => false, 'message' => 'empty'], 422);
        }

        $stream = $model->findLive($streamId);
        if (!$stream) {
            $this->json(['ok' => false, 'live' => false], 404);
        }

        $setup = Stream::decodeSetup($stream['live_setup'] ?? null) ?? [];
        if (isset($setup['chat_enabled']) && !$setup['chat_enabled']) {
            $this->json(['ok' => false, 'message' => 'chat_off'], 403);
        }

        $user = Auth::user();
        $name = (string) ($user['name'] ?? 'User');
        $isHost = (int) $stream['user_id'] === Auth::id();
        $id = $model->addComment($streamId, Auth::id(), $name, $body, $isHost);

        $this->json([
            'ok' => true,
            'comment' => [
                'id' => $id,
                'user_name' => $name,
                'body' => $body,
                'is_host' => $isHost,
            ],
        ]);
    }

    public function delete(string $id): void
    {
        Auth::requireLogin();
        $model = new Stream();
        $stream = $model->find((int) $id);

        if ($stream && ((int) $stream['user_id'] === Auth::id() || Auth::isAdmin())) {
            $model->clearLiveData((int) $id);
            $model->deleteCoverFile($stream['cover'] ?? null);
            $model->delete((int) $id);
            $_SESSION['flash'] = 'Стрим закрыт';
        }

        $this->redirect('/');
    }

    /**
     * @return array<string,mixed>|array{error:string}
     */
    private function parseSetupFromRequest(): array
    {
        $raw = $_POST['setup'] ?? '';
        $data = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['error' => 'Некорректные настройки стрима'];
            }
            $data = $decoded;
        }

        $productIds = [];
        if (!empty($data['product_ids']) && is_array($data['product_ids'])) {
            $productIds = array_values(array_unique(array_filter(
                array_map('intval', $data['product_ids']),
                static fn ($id) => $id > 0
            )));
        }
        if ($productIds === []) {
            return ['error' => 'Добавьте минимум 1 товар для начала стрима'];
        }

        $productModel = new Product();
        $owned = [];
        foreach ($productModel->activeShopByUserAndIds(Auth::id(), $productIds) as $p) {
            if (ProductHelper::isPurchasable($p)) {
                $owned[(int) $p['id']] = $p;
            }
        }
        $productIds = array_values(array_filter($productIds, static fn ($id) => isset($owned[$id])));
        if ($productIds === []) {
            return ['error' => 'Добавьте минимум 1 товар для начала стрима'];
        }

        $featuredId = (int) ($data['featured_product_id'] ?? 0);
        if ($featuredId > 0 && !isset($owned[$featuredId])) {
            $featuredId = 0;
        }

        $featuredPrice = null;
        if ($featuredId > 0 && isset($data['featured_price']) && $data['featured_price'] !== '' && $data['featured_price'] !== null) {
            $featuredPrice = max(0, (int) $data['featured_price']);
        }

        $duration = (int) ($data['duration'] ?? 7200);
        $allowedDurations = [1800, 3600, 7200, 10800, 14400];
        if (!in_array($duration, $allowedDurations, true)) {
            $duration = 7200;
        }

        $visibility = (string) ($data['visibility'] ?? 'all');
        if (!in_array($visibility, ['all', 'followers'], true)) {
            $visibility = 'all';
        }

        $chatEnabled = !isset($data['chat_enabled']) || (bool) $data['chat_enabled'];
        $notifySubs = !isset($data['notify_subs']) || (bool) $data['notify_subs'];

        $giveaway = null;
        if (!empty($data['giveaway']) && is_array($data['giveaway'])) {
            $gTitle = trim((string) ($data['giveaway']['title'] ?? ''));
            if ($gTitle !== '') {
                $giveaway = [
                    'title' => mb_substr($gTitle, 0, 120),
                    'goal' => max(50, min(5000, (int) ($data['giveaway']['goal'] ?? 500))),
                ];
            }
        }

        return [
            'product_ids' => $productIds,
            'featured_product_id' => $featuredId > 0 ? $featuredId : null,
            'featured_price' => $featuredPrice,
            'duration' => $duration,
            'visibility' => $visibility,
            'chat_enabled' => $chatEnabled,
            'notify_subs' => $notifySubs,
            'giveaway' => $giveaway,
        ];
    }

    /**
     * @return array{file?:string}|array{error:string}
     */
    private function storeCoverUpload(): array
    {
        if (empty($_FILES['cover']['name']) || ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        $file = $_FILES['cover'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > self::MAX_COVER) {
            return ['error' => 'Обложка слишком большая (макс. 5 МБ)'];
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::COVER_EXT, true)) {
            return ['error' => 'Формат обложки: JPG, PNG или WebP'];
        }
        if (!UploadHelper::isAllowedUpload((string) $file['tmp_name'], (string) $file['name'], self::COVER_EXT)) {
            return ['error' => 'Формат обложки: JPG, PNG или WebP'];
        }

        $ext = UploadHelper::normalizeExt((string) $file['name']);
        $dir = __DIR__ . '/../../public/uploads/streams';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = 'cover_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            return ['error' => 'Не удалось сохранить обложку'];
        }

        return ['file' => $name];
    }

    /**
     * @param array<string,mixed> $p
     * @param array<string,mixed> $setup
     */
    private function mapShopProduct(array $p, array $setup = []): array
    {
        $price = (int) ($p['price'] ?? 0);
        $id = (int) $p['id'];
        $out = [
            'id' => $id,
            'title' => (string) ($p['title'] ?? ''),
            'price' => $price,
            'price_label' => ProductHelper::formatPrice($p),
            'image' => ProductHelper::imageUrl($p),
            'url' => ProductHelper::url('/product/' . $id),
            'buy_url' => ProductHelper::checkoutUrl($id),
        ];

        $featuredId = (int) ($setup['featured_product_id'] ?? 0);
        $featuredPrice = $setup['featured_price'] ?? null;
        if ($featuredId === $id && $featuredPrice !== null && (int) $featuredPrice >= 0 && (int) $featuredPrice !== $price) {
            $special = (int) $featuredPrice;
            $fake = $p;
            $fake['price'] = $special;
            $out['old_price'] = $price;
            $out['old_price_label'] = ProductHelper::formatPrice($p);
            $out['price'] = $special;
            $out['price_label'] = ProductHelper::formatPrice($fake);
        }

        return $out;
    }
}
