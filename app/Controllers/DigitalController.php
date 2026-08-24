<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Models\DigitalProduct;
use App\Models\Product;
use App\Models\User;
use App\Services\Digital\DigitalAccessService;
use App\Services\Digital\DigitalStreamingService;
use App\Services\Streaming\CloudflareStreamClient;

class DigitalController extends Controller
{
    public function library(): void
    {
        Auth::requireLogin();
        $items = (new DigitalProduct())->libraryForUser((int) Auth::id());
        $this->view('digital/library', [
            'title' => t('digital.library_title'),
            'currentNav' => 'digital',
            'items' => $items,
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function watch(string $id): void
    {
        Auth::requireLogin();
        $productId = (int) $id;
        $viewer = (new DigitalAccessService())->resolveViewer($productId, (int) Auth::id());
        if (!$viewer['ok']) {
            $_SESSION['error'] = $viewer['error'] ?? t('digital.no_access');
            $this->redirect('/product/' . $productId);
            return;
        }

        $dp = $viewer['digital'];
        $stream = new DigitalStreamingService();
        $phase = $stream->viewerPhase($dp);
        $user = Auth::user() ?? [];
        $watermark = (new DigitalAccessService())->watermarkText($viewer, $user);
        $lessons = (new DigitalProduct())->lessons((int) $dp['id']);

        $this->view('digital/watch', [
            'title' => (string) ($viewer['product']['title'] ?? t('digital.watch_title')),
            'currentNav' => 'digital',
            'product' => $viewer['product'],
            'digital' => $dp,
            'access' => $viewer['access'],
            'isAuthor' => !empty($viewer['is_author']),
            'phase' => $phase,
            'watermark' => $watermark,
            'lessons' => $lessons,
            'tokenUrl' => ProductHelper::url('/digital/' . $productId . '/playback'),
            'startsTs' => !empty($dp['starts_at']) ? strtotime((string) $dp['starts_at']) : 0,
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function playback(string $id): void
    {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => t('digital.method')], 405);
            return;
        }

        $productId = (int) $id;
        $userId = (int) Auth::id();
        $viewer = (new DigitalAccessService())->resolveViewer($productId, $userId);
        if (!$viewer['ok']) {
            $this->json(['ok' => false, 'error' => $viewer['error'] ?? t('digital.no_access')], 403);
            return;
        }

        $dp = $viewer['digital'];
        $stream = new DigitalStreamingService();
        $phase = $stream->viewerPhase($dp);
        if ($phase === 'countdown') {
            $this->json(['ok' => false, 'phase' => $phase, 'error' => t('digital.not_started')], 409);
            return;
        }
        if ($phase === 'waiting') {
            $this->json(['ok' => false, 'phase' => $phase, 'error' => t('digital.waiting_host')], 409);
            return;
        }
        if ($phase === 'processing') {
            $this->json(['ok' => false, 'phase' => $phase, 'error' => t('digital.recording_wait')], 409);
            return;
        }

        $uid = $stream->playbackUid($dp);
        if (!$uid) {
            $this->json(['ok' => false, 'error' => t('digital.video_missing')], 404);
            return;
        }

        $cf = new CloudflareStreamClient();
        $token = null;
        $exp = time() + 90;
        if ($cf->requireSignedPlayback()) {
            $signed = $cf->signPlayback($uid, 90);
            if (!$signed['ok']) {
                $this->json(['ok' => false, 'error' => $signed['error'] ?? t('digital.cf_signing_missing')], 503);
                return;
            }
            $token = $signed['token'];
            $exp = (int) $signed['exp'];
        }

        $accessId = (int) ($viewer['access']['id'] ?? 0);
        if ($accessId > 0) {
            (new DigitalProduct())->storePlaybackTicket(
                $accessId,
                $userId,
                (int) $dp['id'],
                $uid,
                $token ?: ('unsigned-' . $uid),
                $exp
            );
        }

        $this->json([
            'ok' => true,
            'phase' => $phase,
            'iframe' => $cf->iframeUrl($uid, $token),
            'exp' => $exp,
            'ttl' => max(15, $exp - time()),
        ]);
    }

    public function heartbeat(string $id): void
    {
        Auth::requireLogin();
        $productId = (int) $id;
        $viewer = (new DigitalAccessService())->resolveViewer($productId, (int) Auth::id());
        if (!$viewer['ok']) {
            $this->json(['ok' => false], 403);
            return;
        }
        $seconds = max(0, min(120, (int) ($_POST['seconds'] ?? 15)));
        (new DigitalProduct())->logWatch(
            (int) Auth::id(),
            (int) $viewer['digital']['id'],
            $seconds
        );
        $this->json(['ok' => true]);
    }

    public function studio(): void
    {
        Auth::requireLogin();
        if (!Auth::isCourseAuthor() && !Auth::isAdmin()) {
            $_SESSION['error'] = t('digital.studio_forbidden');
            $this->redirect('/profile?tab=author');
            return;
        }
        $rows = (new DigitalProduct())->forAuthor((int) Auth::id());
        $this->view('digital/studio', [
            'title' => t('digital.studio_title'),
            'currentNav' => 'profile',
            'rows' => $rows,
            'cfReady' => (new CloudflareStreamClient())->isConfigured(),
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function studioShow(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $_SESSION['error'] = t('digital.not_found');
            $this->redirect('/digital/studio');
            return;
        }
        $product = (new Product())->find((int) $row['product_id']);
        $this->view('digital/studio-show', [
            'title' => t('digital.studio_item'),
            'currentNav' => 'profile',
            'row' => $row,
            'product' => $product,
            'cfReady' => (new CloudflareStreamClient())->isConfigured(),
            'canSign' => (new CloudflareStreamClient())->canSignPlayback(),
            'kinds' => DigitalProduct::KINDS,
            'flash' => $_SESSION['flash'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['flash'], $_SESSION['error']);
    }

    public function studioSave(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $_SESSION['error'] = t('digital.not_found');
            $this->redirect('/digital/studio');
            return;
        }
        $res = (new DigitalStreamingService())->saveSchedule((int) $row['id'], (int) Auth::id(), $_POST);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.saved') : ($res['error'] ?? t('digital.save_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function studioProvision(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $_SESSION['error'] = t('digital.not_found');
            $this->redirect('/digital/studio');
            return;
        }
        $res = (new DigitalStreamingService())->provisionLive((int) $row['id'], (int) Auth::id());
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.live_ready') : ($res['error'] ?? t('digital.live_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function studioGoLive(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->redirect('/digital/studio');
            return;
        }
        $res = (new DigitalStreamingService())->markLiveStarted((int) $row['id'], (int) Auth::id());
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.live_on') : ($res['error'] ?? t('digital.live_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function studioEnd(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->redirect('/digital/studio');
            return;
        }
        $res = (new DigitalStreamingService())->markLiveEnded((int) $row['id'], (int) Auth::id());
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.live_off') : ($res['error'] ?? t('digital.live_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function studioUpload(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->json(['ok' => false, 'error' => t('digital.not_found')], 404);
            return;
        }
        $title = trim((string) ($_POST['title'] ?? ($row['title'] ?? 'vod')));
        $res = (new DigitalStreamingService())->createVodUpload((int) $row['id'], (int) Auth::id(), $title);
        $this->json($res, !empty($res['ok']) ? 200 : 400);
    }

    public function studioAttachUid(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $_SESSION['error'] = t('digital.not_found');
            $this->redirect('/digital/studio');
            return;
        }
        $res = (new DigitalStreamingService())->attachVideoUid(
            (int) $row['id'],
            (int) Auth::id(),
            (string) ($_POST['video_uid'] ?? '')
        );
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.vod_attached') : ($res['error'] ?? t('digital.save_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function webhook(): void
    {
        $raw = (string) file_get_contents('php://input');
        $sig = (string) ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? $_SERVER['HTTP_CF_WEBHOOK_SIGNATURE'] ?? '');
        $cf = new CloudflareStreamClient();
        $secret = trim((string) ((new \App\Models\Setting())->get('cf_stream_webhook_secret', '')));
        if ($secret !== '' && !$cf->verifyWebhookSignature($raw, $sig)) {
            $this->json(['ok' => false, 'error' => 'invalid_signature'], 401);
            return;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->json(['ok' => false, 'error' => 'invalid_json'], 400);
            return;
        }
        (new DigitalStreamingService())->applyWebhook($payload);
        $this->json(['ok' => true]);
    }

    private function studioRow(int $id): ?array
    {
        $row = (new DigitalProduct())->find($id);
        if (!$row) {
            return null;
        }
        if ((int) $row['author_id'] !== (int) Auth::id() && !Auth::isAdmin()) {
            return null;
        }
        return $row;
    }
}
