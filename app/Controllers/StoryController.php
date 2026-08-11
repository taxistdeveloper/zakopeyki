<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Follow;
use App\Models\Story;

class StoryController extends Controller
{
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB

    public function store(): void
    {
        Auth::requireLogin();

        $caption = trim($_POST['caption'] ?? '');

        if (mb_strlen($caption) > 280) {
            $caption = mb_substr($caption, 0, 280);
        }

        $image = $this->uploadImage();

        if (!$image) {
            $_SESSION['flash'] = 'Загрузите фото для истории';
            $this->redirect('/');
        }

        (new Story())->create([
            'user_id' => Auth::id(),
            'caption' => $caption !== '' ? $caption : null,
            'image' => $image,
            'bg_color' => '#7c3aed',
            'emoji' => '✨',
        ]);

        $notifySubs = isset($_POST['notify_subs'])
            ? in_array((string) $_POST['notify_subs'], ['1', 'true', 'on', 'yes'], true)
            : true;
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

    private function uploadImage(): ?string
    {
        if (empty($_FILES['image']['name']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES['image'];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (($file['size'] ?? 0) > self::MAX_SIZE) {
            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return null;
        }
        if (!\App\Helpers\UploadHelper::isAllowedUpload((string) $file['tmp_name'], (string) $file['name'], self::ALLOWED_EXT)) {
            return null;
        }

        $dir = __DIR__ . '/../../public/uploads/stories';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = \App\Helpers\UploadHelper::normalizeExt((string) $file['name']);
        $name = 'story_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return null;
        }

        return $name;
    }
}
