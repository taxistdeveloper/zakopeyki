<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Favorite;

class FavoriteController extends Controller
{
    public function toggle(string $id): void
    {
        if (!Auth::check()) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                $this->json(['ok' => false, 'error' => 'login', 'favorited' => false], 401);
            }
            $this->redirect('/login');
        }

        $result = (new Favorite())->toggle(Auth::id(), (int) $id);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $this->json($result, $result['ok'] ? 200 : 422);
        }

        $_SESSION['flash'] = $result['ok']
            ? ($result['favorited'] ? 'Добавлено в избранное' : 'Убрано из избранного')
            : ($result['error'] ?? 'Ошибка');
        $this->redirect('/product/' . (int) $id);
    }
}
