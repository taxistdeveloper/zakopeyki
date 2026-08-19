<?php

declare(strict_types=1);

namespace App;

use App\Controllers\MicroTaskCompletionController;
use App\Controllers\MicroTaskController;
use App\Controllers\MicroTaskOfferController;

class ApiRouter
{
    private MicroTaskController $taskController;
    private MicroTaskOfferController $offerController;
    private MicroTaskCompletionController $completionController;

    public function __construct(
        MicroTaskController $taskController,
        MicroTaskOfferController $offerController,
        MicroTaskCompletionController $completionController
    ) {
        $this->taskController = $taskController;
        $this->offerController = $offerController;
        $this->completionController = $completionController;
    }

    public function handleRequest(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($method === 'POST' && $path === '/api/v1/micro-tasks/create') {
            $this->taskController->create();
        }

        if ($method === 'GET' && $path === '/api/v1/micro-tasks/list') {
            $this->taskController->list();
        }

        if ($method === 'GET' && $path === '/api/v1/micro-tasks/mine') {
            $this->taskController->mine();
        }

        if ($method === 'POST' && preg_match('#^/api/v1/micro-tasks/(\d+)/offer$#', $path, $matches)) {
            $this->offerController->submitOffer((int) $matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/micro-tasks/offers/(\d+)/select$#', $path, $matches)) {
            $this->offerController->selectOffer((int) $matches[1]);
        }

        if ($method === 'POST' && preg_match('#^/api/v1/micro-tasks/(\d+)/complete$#', $path, $matches)) {
            $this->completionController->complete((int) $matches[1]);
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Эндпоинт не найден']);
        exit;
    }
}
