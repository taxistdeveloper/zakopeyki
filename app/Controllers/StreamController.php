<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Models\Product;
use App\Models\Stream;

class StreamController extends Controller
{
    /** Старт Live — без файла. После завершения не хранится. */
    public function startLive(): void
    {
        Auth::requireLogin();

        $user = Auth::user();
        $title = 'Стрим — ' . ($user['name'] ?? 'Пользователь');
        $id = (new Stream())->startLive(Auth::id(), $title);

        $this->json([
            'ok' => true,
            'id' => $id,
            'title' => $title,
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
        $products = [];
        foreach ((new Product())->activeShopByUser($hostId, 24) as $p) {
            if (!ProductHelper::isPurchasable($p)) {
                continue;
            }
            $products[] = $this->mapShopProduct($p);
        }

        $featuredId = (int) ($stream['featured_product_id'] ?? 0);
        $featured = null;
        if ($featuredId > 0) {
            foreach ($products as $p) {
                if ((int) $p['id'] === $featuredId) {
                    $featured = $p;
                    break;
                }
            }
            if (!$featured) {
                $row = (new Product())->find($featuredId);
                if ($row && (int) $row['user_id'] === $hostId && ProductHelper::isPurchasable($row)) {
                    $featured = $this->mapShopProduct($row);
                }
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
            $model->delete((int) $id);
            $_SESSION['flash'] = 'Стрим закрыт';
        }

        $this->redirect('/');
    }

    /** @param array<string,mixed> $p */
    private function mapShopProduct(array $p): array
    {
        $price = (int) ($p['price'] ?? 0);
        return [
            'id' => (int) $p['id'],
            'title' => (string) ($p['title'] ?? ''),
            'price' => $price,
            'price_label' => ProductHelper::formatPrice($p),
            'image' => ProductHelper::imageUrl($p),
            'url' => ProductHelper::url('/product/' . (int) $p['id']),
            'buy_url' => ProductHelper::checkoutUrl((int) $p['id']),
        ];
    }
}
