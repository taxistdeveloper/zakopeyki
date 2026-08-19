<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MicroTaskService;

class MicroTaskOfferController extends BaseApiController
{
    private MicroTaskService $taskService;

    public function __construct(?MicroTaskService $taskService = null)
    {
        $this->taskService = $taskService ?? MicroTaskService::make();
    }

    public function submitOffer(int $id): void
    {
        $taskId = $id;
        $executorId = $this->getAuthenticatedUserId();
        if ($executorId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $input = $this->getJsonInput();
        $offerType = (string) ($input['offer_type'] ?? 'accept');
        $customPrice = isset($input['custom_price']) ? (float) $input['custom_price'] : null;

        $allowedTypes = ['accept', 'discount_20', 'raise_20', 'custom'];
        if (!in_array($offerType, $allowedTypes, true)) {
            $this->jsonResponse(false, null, t('gigs.err_offer_type'), 400);
        }

        $result = $this->taskService->submitOffer($executorId, $taskId, $offerType, $customPrice);

        if (!$result['success']) {
            $this->jsonResponse(false, null, (string) ($result['error'] ?? t('gigs.err_offer')), 400);
        }

        $this->jsonResponse(true, $result, null, 200);
    }

    public function selectOffer(int $offerId): void
    {
        $customerId = $this->getAuthenticatedUserId();
        if ($customerId <= 0) {
            $this->jsonResponse(false, null, t('gigs.err_auth'), 401);
        }

        $success = $this->taskService->selectOffer($customerId, (int) $offerId);

        if (!$success) {
            $this->jsonResponse(false, null, t('gigs.err_select'), 400);
        }

        $this->jsonResponse(true, [
            'message' => t('gigs.select_ok'),
        ]);
    }
}
