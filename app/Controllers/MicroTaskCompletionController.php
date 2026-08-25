<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MicroTaskService;

class MicroTaskCompletionController extends BaseApiController
{
    private MicroTaskService $taskService;

    public function __construct(?MicroTaskService $taskService = null)
    {
        $this->taskService = $taskService ?? MicroTaskService::make();
    }

    public function complete(int $id): void
    {
        $this->requireGigsAccess();
        $taskId = $id;
        $executorId = $this->getAuthenticatedUserId();
        if ($executorId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $input = $this->getJsonInput();
        $pin = (string) ($input['pin'] ?? '');

        if (mb_strlen($pin, 'UTF-8') !== 4 || !ctype_digit($pin)) {
            $this->jsonResponse(false, null, t('gigs.err_pin_format'), 400);
        }

        $result = $this->taskService->completeTaskWithPin($executorId, $taskId, $pin);

        if (!$result['success']) {
            $this->jsonResponse(false, null, (string) ($result['error'] ?? t('gigs.err_pin_generic')), 400);
        }

        $this->jsonResponse(true, $result, null, 200);
    }
}
