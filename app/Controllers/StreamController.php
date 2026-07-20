<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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

    public function delete(string $id): void
    {
        Auth::requireLogin();
        $model = new Stream();
        $stream = $model->find((int) $id);

        if ($stream && ((int) $stream['user_id'] === Auth::id() || Auth::isAdmin())) {
            $model->delete((int) $id);
            $_SESSION['flash'] = 'Стрим закрыт';
        }

        $this->redirect('/');
    }
}
