<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\UploadHelper;
use App\Models\Follow;
use App\Models\Product;
use App\Models\Story;

class StoryController extends Controller
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const VIDEO_EXT = ['mp4', 'webm', 'mov'];
    private const MAX_IMAGE = 5 * 1024 * 1024;
    private const MAX_VIDEO = 50 * 1024 * 1024;

    public function store(): void
    {
        Auth::requireLogin();

        $caption = trim($_POST['caption'] ?? '');

        if (mb_strlen($caption) > 280) {
            $caption = mb_substr($caption, 0, 280);
        }

        $productMode = (string) ($_POST['product_mode'] ?? 'auto');
        if (!in_array($productMode, ['auto', 'pick', 'none'], true)) {
            $productMode = 'auto';
        }
        $pickedProductId = null;
        if ($productMode === 'pick') {
            $pickedProductId = (int) ($_POST['product_id'] ?? 0);
            $picked = $pickedProductId > 0 ? (new Product())->find($pickedProductId) : null;
            $owned = $picked
                && (int) ($picked['user_id'] ?? 0) === (int) Auth::id()
                && (string) ($picked['status'] ?? '') === 'active';
            if (!$owned) {
                $_SESSION['flash'] = t('home.story_create_product_pick_need');
                $this->redirect('/');
                return;
            }
        }

        $image = $this->uploadMedia();

        if ($image === null) {
            $this->redirect('/');
        }

        $audience = (string) ($_POST['audience'] ?? 'all');
        if (!in_array($audience, ['all', 'followers'], true)) {
            $audience = 'all';
        }
        $visibility = (string) ($_POST['visibility'] ?? 'public');
        if (!in_array($visibility, ['public', 'private'], true)) {
            $visibility = 'public';
        }
        $commentsEnabled = !isset($_POST['comments_enabled'])
            || in_array((string) $_POST['comments_enabled'], ['1', 'true', 'on', 'yes'], true);
        $attachProduct = $productMode !== 'none';

        (new Story())->create([
            'user_id' => Auth::id(),
            'caption' => $caption !== '' ? $caption : null,
            'image' => $image,
            'bg_color' => '#7c3aed',
            'emoji' => '✨',
            'audience' => $audience,
            'comments_enabled' => $commentsEnabled ? 1 : 0,
            'visibility' => $visibility,
            'attach_product' => $attachProduct ? 1 : 0,
            'product_id' => $productMode === 'pick' ? $pickedProductId : null,
        ]);

        $notifySubs = $visibility !== 'private' && (isset($_POST['notify_subs'])
            ? in_array((string) $_POST['notify_subs'], ['1', 'true', 'on', 'yes'], true)
            : true);
        if ($notifySubs) {
            $name = (string) (Auth::user()['name'] ?? 'Продавец');
            (new Follow())->notifyFollowers(
                Auth::id(),
                t('seller.notify_story', ['name' => $name])
            );
        }

        $_SESSION['flash'] = 'История опубликована на 24 часа!';
        $this->redirect('/');
    }

    public function delete(string $id): void
    {
        Auth::requireLogin();
        $model = new Story();
        $story = $model->find((int) $id);

        if ($story && ((int) $story['user_id'] === Auth::id() || Auth::isAdmin())) {
            if (!empty($story['image'])) {
                $file = __DIR__ . '/../../public/uploads/stories/' . basename($story['image']);
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $model->delete((int) $id);
            $_SESSION['flash'] = 'История удалена';
        }

        $this->redirect('/');
    }

    private function uploadMedia(): ?string
    {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || empty($file['name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash'] = t('home.story_create_need_photo');
            return null;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $_SESSION['flash'] = t('home.story_create_file_big');
            return null;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $_SESSION['flash'] = t('home.story_create_media_bad');
            return null;
        }

        $original = (string) $file['name'];
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $isVideo = in_array($ext, self::VIDEO_EXT, true);
        $isImage = in_array($ext, self::IMAGE_EXT, true);
        if (!$isVideo && !$isImage) {
            $_SESSION['flash'] = t('home.story_create_media_bad');
            return null;
        }

        $max = $isVideo ? self::MAX_VIDEO : self::MAX_IMAGE;
        if ((int) ($file['size'] ?? 0) > $max) {
            $_SESSION['flash'] = t('home.story_create_file_big');
            return null;
        }

        $tmp = (string) $file['tmp_name'];
        $allowed = $isVideo ? self::VIDEO_EXT : self::IMAGE_EXT;
        if (!UploadHelper::isAllowedUpload($tmp, $original, $allowed)) {
            $_SESSION['flash'] = t('home.story_create_media_bad');
            return null;
        }

        $dir = __DIR__ . '/../../public/uploads/stories';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = UploadHelper::normalizeExt($original);
        $name = 'story_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (!move_uploaded_file($tmp, $dest)) {
            $_SESSION['flash'] = t('home.story_create_media_bad');
            return null;
        }

        return $name;
    }
}
