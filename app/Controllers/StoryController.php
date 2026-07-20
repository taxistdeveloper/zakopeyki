<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Story;

class StoryController extends Controller
{
    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB

    public function store(): void
    {
        Auth::requireLogin();

        $caption = trim($_POST['caption'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '✨') ?: '✨';
        $bg = $_POST['bg_color'] ?? '#f59e0b';
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $bg)) {
            $bg = '#f59e0b';
        }

        if (mb_strlen($caption) > 280) {
            $caption = mb_substr($caption, 0, 280);
        }

        $image = $this->uploadImage();

        if ($caption === '' && !$image) {
            $_SESSION['flash'] = 'Добавьте текст или фото для истории';
            $this->redirect('/');
        }

        (new Story())->create([
            'user_id' => Auth::id(),
            'caption' => $caption !== '' ? $caption : null,
            'image' => $image,
            'bg_color' => $bg,
            'emoji' => mb_substr($emoji, 0, 8),
        ]);

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

        $dir = __DIR__ . '/../../public/uploads/stories';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = 'story_' . Auth::id() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return null;
        }

        return $name;
    }
}
