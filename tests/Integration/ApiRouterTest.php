<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\ApiRouter;
use App\Controllers\MicroTaskCompletionController;
use App\Controllers\MicroTaskController;
use App\Controllers\MicroTaskOfferController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiRouterTest extends TestCase
{
    private MicroTaskController|MockObject $taskControllerMock;
    private MicroTaskOfferController|MockObject $offerControllerMock;
    private MicroTaskCompletionController|MockObject $completionControllerMock;
    private ApiRouter $router;

    protected function setUp(): void
    {
        $this->taskControllerMock = $this->createMock(MicroTaskController::class);
        $this->offerControllerMock = $this->createMock(MicroTaskOfferController::class);
        $this->completionControllerMock = $this->createMock(MicroTaskCompletionController::class);

        $this->router = new ApiRouter(
            $this->taskControllerMock,
            $this->offerControllerMock,
            $this->completionControllerMock
        );
    }

    public function testRoutesToCreateTask(): void
    {
        $this->taskControllerMock
            ->expects($this->once())
            ->method('create');

        $this->router->handleRequest('POST', '/api/v1/micro-tasks/create');
    }

    public function testRoutesToTaskListWithQueryString(): void
    {
        $this->taskControllerMock
            ->expects($this->once())
            ->method('list');

        $this->router->handleRequest('GET', '/api/v1/micro-tasks/list?category_id=2&sort=desc');
    }

    public function testRoutesToSubmitOfferWithDynamicTaskId(): void
    {
        $taskId = 42;

        $this->offerControllerMock
            ->expects($this->once())
            ->method('submitOffer')
            ->with($taskId);

        $this->router->handleRequest('POST', "/api/v1/micro-tasks/{$taskId}/offer");
    }

    public function testRoutesToSelectOfferWithDynamicOfferId(): void
    {
        $offerId = 15;

        $this->offerControllerMock
            ->expects($this->once())
            ->method('selectOffer')
            ->with($offerId);

        $this->router->handleRequest('POST', "/api/v1/micro-tasks/offers/{$offerId}/select");
    }

    public function testRoutesToCompleteTaskWithDynamicTaskId(): void
    {
        $taskId = 99;

        $this->completionControllerMock
            ->expects($this->once())
            ->method('complete')
            ->with($taskId);

        $this->router->handleRequest('POST', "/api/v1/micro-tasks/{$taskId}/complete");
    }

    /**
     * @runInSeparateProcess
     */
    public function testReturns404ResponseForNonExistentRoute(): void
    {
        ob_start();

        try {
            $this->router->handleRequest('GET', '/api/v1/non-existent-endpoint');
        } catch (\Throwable $e) {
        }

        $output = ob_get_clean();
        $response = json_decode($output, true);

        $this->assertSame(404, http_response_code());
        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertEquals('Эндпоинт не найден', $response['error']);
    }
}
