<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ProductHelper;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\EscrowService;

class AdminController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();
        (new EscrowService())->processDeadlines();

        $productModel = new Product();
        $userModel = new User();
        $orderModel = new Order();

        $items = $productModel->all('created_at DESC');
        $counts = $productModel->countByType();
        $userCount = $userModel->countAll();
        $disputes = $orderModel->findByStatus('dispute');

        $n = new Notification();
        $notifications = $n->forUser(Auth::id());
        $unread = $n->unreadCount(Auth::id());

        $this->view('admin/index', [
            'title' => t('admin.title'),
            'currentNav' => 'admin',
            'items' => $items,
            'counts' => $counts,
            'userCount' => $userCount,
            'disputes' => $disputes,
            'types' => ProductHelper::TYPES,
            'notifications' => $notifications,
            'unread' => $unread,
            'search' => '',
            'flash' => $_SESSION['flash'] ?? null,
        ]);
        unset($_SESSION['flash']);
    }

    public function delete(string $id): void
    {
        Auth::requireAdmin();
        (new Product())->delete((int) $id);
        $_SESSION['flash'] = 'Товар удалён';
        $this->redirect('/admin');
    }

    public function toggleStatus(string $id): void
    {
        Auth::requireAdmin();
        $model = new Product();
        $item = $model->find((int) $id);
        if ($item) {
            $status = $item['status'] === 'active' ? 'archived' : 'active';
            $model->updateProduct((int) $id, array_merge($item, ['status' => $status]));
            $_SESSION['flash'] = 'Статус обновлён';
        }
        $this->redirect('/admin');
    }
}
