<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Models\MicroTask;
use App\Services\MicroTaskService;
use PDO;

class MicroTaskController extends BaseApiController
{
    private MicroTaskService $taskService;
    private PDO $pdo;

    public function __construct(?MicroTaskService $taskService = null, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
        (new MicroTask())->ensureSchema();
        $this->taskService = $taskService ?? MicroTaskService::make();
    }

    public function create(): void
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $input = $this->getJsonInput();
        if ($input === []) {
            $input = $_POST;
        }

        if (empty($input['description']) || empty($input['category_id']) || empty($input['initial_price'])) {
            $this->jsonResponse(false, null, t('gigs.err_required'), 400);
        }

        $result = $this->taskService->createTask($userId, [
            'description' => (string) $input['description'],
            'category_id' => (int) $input['category_id'],
            'address' => (string) ($input['address'] ?? ''),
            'initial_price' => (float) $input['initial_price'],
        ]);

        if (!$result['success']) {
            $this->jsonResponse(false, null, implode(' ', $result['errors'] ?? []), 422);
        }

        $this->jsonResponse(true, [
            'task_id' => $result['task_id'],
            'completion_pin' => $result['pin'],
            'message' => t('gigs.created'),
        ], null, 201);
    }

    public function list(): void
    {
        $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
        $tasks = $this->taskService->listOpen($categoryId);
        $formatted = $this->taskService->formatCatalog($tasks);

        $this->jsonResponse(true, [
            'tasks' => $formatted,
            'total' => count($formatted),
            'categories' => $this->taskService->categories(),
        ]);
    }

    public function mine(): void
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $rows = $this->taskService->listForUser($userId);
        $items = [];
        foreach ($rows as $row) {
            $role = (int) $row['customer_id'] === $userId ? 'customer' : 'executor';
            $offers = [];
            if ($role === 'customer' && $row['status'] === 'open') {
                $offers = $this->taskService->offersForCustomerTask($userId, (int) $row['id']);
            }
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) ($row['category_name'] ?: $row['title']),
                'status' => $row['status'],
                'role' => $role,
                'initial_price' => (int) $row['initial_price'],
                'final_price' => $row['final_price'] !== null ? (int) $row['final_price'] : null,
                'completion_pin' => $role === 'customer' ? $row['completion_pin'] : null,
                'address' => $row['address'],
                'expires_at' => $row['expires_at'],
                'offers' => $offers,
                'can_edit' => $role === 'customer' && (string) $row['status'] === 'open',
                'can_cancel' => $role === 'customer' && in_array((string) $row['status'], ['open', 'locked', 'in_progress'], true),
                'can_delete' => $role === 'customer',
            ];
        }

        $this->jsonResponse(true, ['tasks' => $items]);
    }

    public function cancel(int $id): void
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $result = $this->taskService->cancelTask($userId, $id);

        if (!$result['success']) {
            $this->jsonResponse(false, null, (string) ($result['error'] ?? t('gigs.err_cancel')), 400);
        }

        $this->jsonResponse(true, [
            'message' => $result['message'] ?? t('gigs.cancel_ok'),
        ]);
    }

    public function delete(int $id): void
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $result = $this->taskService->deleteTask($userId, $id);

        if (!$result['success']) {
            $this->jsonResponse(false, null, (string) ($result['error'] ?? t('gigs.err_delete')), 400);
        }

        $this->jsonResponse(true, [
            'message' => $result['message'] ?? t('gigs.delete_ok'),
        ]);
    }
}
