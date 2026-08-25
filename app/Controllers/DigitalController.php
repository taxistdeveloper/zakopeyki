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
        $model = new DigitalProduct();
        $uid = (int) Auth::id();
        $items = $model->libraryForUser($uid);
        foreach ($items as &$row) {
            $dpId = (int) ($row['digital_product_id'] ?? 0);
            $row['progress'] = $dpId ? $model->progressSummary($uid, $dpId) : ['percent' => 0];
            $row['certificate'] = $dpId ? $model->findCertificate($uid, $dpId) : null;
        }
        unset($row);
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
        $model = new DigitalProduct();
        $lessons = $model->lessons((int) $dp['id']);
        $sessions = $model->sessions((int) $dp['id']);
        $progress = $model->progressSummary((int) Auth::id(), (int) $dp['id']);
        $certificate = $model->findCertificate((int) Auth::id(), (int) $dp['id']);

        $this->view('digital/watch', [
            'title' => (string) ($viewer['product']['title'] ?? t('digital.watch_title')),
            'currentNav' => 'digital',
            'product' => $viewer['product'],
            'digital' => $dp,
            'access' => $viewer['access'],
            'isAuthor' => !empty($viewer['is_author']),
            'canModerate' => !empty($viewer['is_author']) || Auth::isAdmin(),
            'previewOnly' => !empty($viewer['preview_only']),
            'phase' => $phase,
            'watermark' => $watermark,
            'lessons' => $lessons,
            'sessions' => $sessions,
            'progress' => $progress,
            'certificate' => $certificate,
            'completeUrl' => ProductHelper::url('/digital/' . $productId . '/complete'),
            'chatHideUrl' => ProductHelper::url('/digital/' . $productId . '/chat/hide'),
            'tokenUrl' => ProductHelper::url('/digital/' . $productId . '/playback'),
            'chatPollUrl' => ProductHelper::url('/digital/' . $productId . '/chat'),
            'chatPostUrl' => ProductHelper::url('/digital/' . $productId . '/chat'),
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
        $lessonId = (int) ($_POST['lesson_id'] ?? $_POST['lessonId'] ?? 0);
        $sessionId = (int) ($_POST['session_id'] ?? $_POST['sessionId'] ?? 0);
        $phase = $stream->viewerPhase($dp);
        if ($lessonId < 1 && $sessionId < 1) {
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
        }
        if (!empty($viewer['preview_only']) && $lessonId !== (int) ($viewer['preview_lesson_id'] ?? 0)) {
            $this->json(['ok' => false, 'error' => t('digital.no_access')], 403);
            return;
        }

        $playable = $stream->resolvePlayable($dp, $lessonId > 0 ? $lessonId : null, $sessionId > 0 ? $sessionId : null);
        if (!$playable['ok']) {
            $this->json(['ok' => false, 'error' => $playable['error'] ?? t('digital.video_missing')], 404);
            return;
        }
        if (($playable['type'] ?? '') !== 'video') {
            $this->json(['ok' => true, 'type' => $playable['type'], 'body' => $playable['body'] ?? null, 'file' => !empty($playable['file']) ? ProductHelper::url($playable['file']) : null]);
            return;
        }

        $uid = (string) ($playable['uid'] ?? '');
        if ($uid === '') {
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
                $exp,
                $lessonId > 0 ? $lessonId : null
            );
        }

        $this->json([
            'ok' => true,
            'phase' => $playable['phase'] ?? $phase,
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
        $lessonId = (int) ($_POST['lesson_id'] ?? $_POST['lessonId'] ?? 0);
        $model = new DigitalProduct();
        $model->logWatch(
            (int) Auth::id(),
            (int) $viewer['digital']['id'],
            $seconds,
            $lessonId > 0 ? $lessonId : null
        );
        $progress = ['ok' => true, 'completed' => false, 'certificate' => null];
        if ($lessonId > 0 && empty($viewer['preview_only'])) {
            $progress = $model->bumpLessonProgress((int) Auth::id(), (int) $viewer['digital']['id'], $lessonId, $seconds);
        }
        $this->json([
            'ok' => true,
            'completed' => !empty($progress['completed']),
            'certificate' => $progress['certificate'] ?? null,
        ]);
    }

    public function studio(): void
    {
        Auth::requireLogin();
        if (!Auth::isCourseAuthor() && !Auth::isAdmin()) {
            $_SESSION['error'] = t('digital.studio_forbidden');
            $this->redirect('/profile');
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
        $model = new DigitalProduct();
        $this->view('digital/studio-show', [
            'title' => t('digital.studio_item'),
            'currentNav' => 'profile',
            'row' => $row,
            'product' => $product,
            'lessons' => $model->lessons((int) $row['id']),
            'sessions' => $model->sessions((int) $row['id']),
            'stats' => $model->authorStats((int) $row['id']),
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

    public function chatPoll(string $id): void
    {
        Auth::requireLogin();
        $viewer = (new DigitalAccessService())->resolveViewer((int) $id, (int) Auth::id());
        if (!$viewer['ok']) {
            $this->json(['ok' => false], 403);
            return;
        }
        $after = (int) ($_GET['after'] ?? 0);
        $sessionId = (int) ($_GET['session_id'] ?? 0);
        $isMod = !empty($viewer['is_author']) || Auth::isAdmin();
        $model = new DigitalProduct();
        $dpId = (int) $viewer['digital']['id'];
        $rows = $model->chatAfter($dpId, $after, $sessionId > 0 ? $sessionId : null, $isMod);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['user_name'] ?? ''),
                'body' => !empty($row['is_hidden']) ? t('digital.chat_hidden') : (string) $row['body'],
                'at' => (string) $row['created_at'],
                'hidden' => !empty($row['is_hidden']),
            ];
        }
        $this->json([
            'ok' => true,
            'messages' => $out,
            'removed' => $model->hiddenChatIds($dpId),
            'can_moderate' => $isMod,
        ]);
    }

    public function chatPost(string $id): void
    {
        Auth::requireLogin();
        $viewer = (new DigitalAccessService())->resolveViewer((int) $id, (int) Auth::id());
        if (!$viewer['ok'] || !empty($viewer['preview_only'])) {
            $this->json(['ok' => false, 'error' => t('digital.no_access')], 403);
            return;
        }
        $res = (new DigitalProduct())->addChatMessage(
            (int) $viewer['digital']['id'],
            (int) Auth::id(),
            (string) ($_POST['body'] ?? ''),
            (int) ($_POST['session_id'] ?? 0) ?: null
        );
        $this->json($res, !empty($res['ok']) ? 200 : 400);
    }

    public function chatHide(string $id): void
    {
        Auth::requireLogin();
        $viewer = (new DigitalAccessService())->resolveViewer((int) $id, (int) Auth::id());
        if (!$viewer['ok'] || (empty($viewer['is_author']) && !Auth::isAdmin())) {
            $this->json(['ok' => false, 'error' => t('digital.forbidden')], 403);
            return;
        }
        $msgId = (int) ($_POST['message_id'] ?? $_POST['id'] ?? 0);
        $ok = (new DigitalProduct())->hideChatMessage($msgId, (int) $viewer['digital']['id'], (int) Auth::id());
        $this->json(['ok' => $ok], $ok ? 200 : 404);
    }

    public function completeLesson(string $id): void
    {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'error' => t('digital.method')], 405);
            return;
        }
        $viewer = (new DigitalAccessService())->resolveViewer((int) $id, (int) Auth::id());
        if (!$viewer['ok'] || !empty($viewer['preview_only'])) {
            $this->json(['ok' => false, 'error' => t('digital.no_access')], 403);
            return;
        }
        $lessonId = (int) ($_POST['lesson_id'] ?? 0);
        $res = (new DigitalProduct())->markLessonComplete(
            (int) Auth::id(),
            (int) $viewer['digital']['id'],
            $lessonId
        );
        $this->json($res, !empty($res['ok']) ? 200 : 400);
    }

    public function certificate(string $id): void
    {
        Auth::requireLogin();
        $viewer = (new DigitalAccessService())->resolveViewer((int) $id, (int) Auth::id());
        if (!$viewer['ok'] || !empty($viewer['preview_only'])) {
            $_SESSION['error'] = t('digital.no_access');
            $this->redirect('/digital');
            return;
        }
        $model = new DigitalProduct();
        $cert = $model->maybeIssueCertificate((int) Auth::id(), (int) $viewer['digital']['id']);
        if (!$cert) {
            $_SESSION['error'] = t('digital.cert_not_ready');
            $this->redirect('/digital/' . (int) $id . '/watch');
            return;
        }
        $this->view('digital/certificate', [
            'title' => t('digital.cert_title'),
            'currentNav' => 'digital',
            'certificate' => $cert,
            'product' => $viewer['product'],
            'publicUrl' => ProductHelper::url('/digital/certificate/' . $cert['public_code']),
        ]);
    }

    public function certificatePublic(string $code): void
    {
        $cert = (new DigitalProduct())->findCertificateByCode($code);
        if (!$cert) {
            http_response_code(404);
            $this->view('errors/404', ['title' => t('digital.not_found')]);
            return;
        }
        $this->view('digital/certificate', [
            'title' => t('digital.cert_title'),
            'currentNav' => 'digital',
            'certificate' => $cert,
            'product' => ['title' => $cert['product_title']],
            'public' => true,
            'publicUrl' => ProductHelper::url('/digital/certificate/' . $cert['public_code']),
        ]);
    }

    public function studioLessonSave(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->redirect('/digital/studio');
            return;
        }
        $lessonId = (int) ($_POST['lesson_id'] ?? 0) ?: null;
        $filePath = $this->storeDigitalFile((int) $row['id']);
        if ($filePath) {
            $_POST['file_path'] = $filePath;
        }
        $res = (new DigitalProduct())->saveLesson((int) $row['id'], $_POST, $lessonId);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.lesson_saved') : ($res['error'] ?? t('digital.save_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function studioLessonDelete(string $id, string $lessonId): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if ($row) {
            (new DigitalProduct())->deleteLesson((int) $row['id'], (int) $lessonId);
            $_SESSION['flash'] = t('digital.lesson_deleted');
        }
        $this->redirect('/digital/studio/' . (int) ($row['id'] ?? 0));
    }

    public function studioLessonUpload(string $id, string $lessonId): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->json(['ok' => false], 404);
            return;
        }
        $res = (new DigitalStreamingService())->createLessonUpload((int) $row['id'], (int) $lessonId, (int) Auth::id());
        $this->json($res, !empty($res['ok']) ? 200 : 400);
    }

    public function studioSessionSave(string $id): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->redirect('/digital/studio');
            return;
        }
        $sid = (int) ($_POST['session_id'] ?? 0) ?: null;
        $res = (new DigitalProduct())->saveSession((int) $row['id'], $_POST, $sid);
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t('digital.session_saved') : ($res['error'] ?? t('digital.save_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    public function studioSessionDelete(string $id, string $sessionId): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if ($row) {
            (new DigitalProduct())->deleteSession((int) $row['id'], (int) $sessionId);
            $_SESSION['flash'] = t('digital.session_deleted');
        }
        $this->redirect('/digital/studio/' . (int) ($row['id'] ?? 0));
    }

    public function studioSessionProvision(string $id, string $sessionId): void
    {
        $this->runSessionAction($id, $sessionId, 'provisionSession', 'digital.live_ready');
    }

    public function studioSessionGoLive(string $id, string $sessionId): void
    {
        $this->runSessionAction($id, $sessionId, 'startSession', 'digital.live_on');
    }

    public function studioSessionEnd(string $id, string $sessionId): void
    {
        $this->runSessionAction($id, $sessionId, 'endSession', 'digital.live_off');
    }

    private function runSessionAction(string $id, string $sessionId, string $method, string $okKey): void
    {
        Auth::requireLogin();
        $row = $this->studioRow((int) $id);
        if (!$row) {
            $this->redirect('/digital/studio');
            return;
        }
        $res = (new DigitalStreamingService())->{$method}((int) $row['id'], (int) $sessionId, (int) Auth::id());
        $_SESSION[$res['ok'] ? 'flash' : 'error'] = $res['ok'] ? t($okKey) : ($res['error'] ?? t('digital.live_fail'));
        $this->redirect('/digital/studio/' . (int) $row['id']);
    }

    private function storeDigitalFile(int $digitalId): ?string
    {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            return null;
        }
        $name = (string) ($_FILES['file']['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true)) {
            return null;
        }
        if ((int) ($_FILES['file']['size'] ?? 0) > 12 * 1024 * 1024) {
            return null;
        }
        $dir = dirname(__DIR__, 2) . '/public/uploads/digital/' . $digitalId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $safe = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $safe;
        if (!move_uploaded_file((string) $_FILES['file']['tmp_name'], $dest)) {
            return null;
        }
        return '/public/uploads/digital/' . $digitalId . '/' . $safe;
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
