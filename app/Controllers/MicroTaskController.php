<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Models\MicroTask;
use App\Models\Review;
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
        $taskIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $leftReviews = (new Review())->leftTaskIdsForAuthor($userId, $taskIds);
        $ratingIds = [];
        $allOffers = [];
        foreach ($rows as $row) {
            $ratingIds[] = (int) $row['customer_id'];
            if (!empty($row['executor_id'])) {
                $ratingIds[] = (int) $row['executor_id'];
            }
            $role = (int) $row['customer_id'] === $userId ? 'customer' : 'executor';
            $offers = [];
            if ($role === 'customer' && $row['status'] === 'open') {
                $offers = $this->taskService->offersForCustomerTask($userId, (int) $row['id']);
                foreach ($offers as $offer) {
                    $ratingIds[] = (int) $offer['executor_id'];
                }
            }
            $allOffers[(int) $row['id']] = $offers;
        }
        $ratings = (new Review())->statsForMany($ratingIds);

        $items = [];
        foreach ($rows as $row) {
            $role = (int) $row['customer_id'] === $userId ? 'customer' : 'executor';
            $offers = $allOffers[(int) $row['id']] ?? [];
            $formattedOffers = [];
            foreach ($offers as $offer) {
                $eid = (int) $offer['executor_id'];
                $formattedOffers[] = [
                    'id' => (int) $offer['id'],
                    'proposed_price' => (int) $offer['proposed_price'],
                    'executor_id' => $eid,
                    'executor_name' => (string) ($offer['executor_name'] ?? ''),
                    'rating' => $ratings[$eid] ?? ['avg' => 0, 'count' => 0],
                ];
            }
            $counterpartId = $role === 'customer' ? (int) ($row['executor_id'] ?? 0) : (int) $row['customer_id'];
            $counterpartName = $role === 'customer'
                ? (string) ($row['executor_name'] ?? '')
                : (string) ($row['customer_name'] ?? '');
            $canReview = (string) $row['status'] === 'completed'
                && $counterpartId > 0
                && empty($leftReviews[(int) $row['id']]);

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
                'offers' => $formattedOffers,
                'counterpart' => $counterpartId > 0 ? [
                    'id' => $counterpartId,
                    'name' => $counterpartName,
                    'role' => $role === 'customer' ? 'executor' : 'customer',
                    'rating' => $ratings[$counterpartId] ?? ['avg' => 0, 'count' => 0],
                ] : null,
                'can_review' => $canReview,
                'can_edit' => $role === 'customer' && (string) $row['status'] === 'open',
                'can_cancel' => $role === 'customer' && in_array((string) $row['status'], ['open', 'locked', 'in_progress'], true),
                'can_delete' => $role === 'customer',
            ];
        }

        $this->jsonResponse(true, ['tasks' => $items]);
    }

    public function review(int $id): void
    {
        $userId = $this->getAuthenticatedUserId();
        if ($userId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $input = $this->getJsonInput();
        $rating = (int) ($input['rating'] ?? 0);
        $body = (string) ($input['body'] ?? '');
        $result = (new Review())->createForMicroTask($id, $userId, $rating, $body);
        if (!$result['ok']) {
            $this->jsonResponse(false, null, (string) ($result['error'] ?? t('reviews.save_fail')), 400);
        }

        $this->jsonResponse(true, [
            'message' => t('reviews.saved'),
        ]);
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
