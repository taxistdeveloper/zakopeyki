<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Helpers\ActivityLogger;
use App\Models\SupportTicket;
use App\Services\BusinessPackageService;

/**
 * Обращение к персональному менеджеру через существующие support tickets.
 * Не назначает менеджера автоматически — процесс назначения не утверждён.
 */
final class BusinessManagerRequestService
{
    private BusinessPackageService $packages;
    private SupportTicket $tickets;

    public function __construct(
        ?BusinessPackageService $packages = null,
        ?SupportTicket $tickets = null
    ) {
        $this->packages = $packages ?? new BusinessPackageService();
        $this->tickets = $tickets ?? new SupportTicket();
    }

    /**
     * @return array{ok: bool, error?: string, code?: string, ticket_id?: int, ticket_number?: string, manager_assigned?: bool}
     */
    public function requestContact(int $userId, string $message = ''): array
    {
        $gate = $this->packages->assertFeature($userId, BusinessPackagePolicy::FEATURE_PERSONAL_MANAGER);
        if (!$gate['ok']) {
            return $gate;
        }

        $body = trim($message);
        if ($body === '') {
            $body = t('business.manager_default_message');
        }
        $body = mb_substr($body, 0, 4000);

        $result = $this->tickets->createTicket(
            $userId,
            t('business.manager_ticket_subject'),
            "[business_personal_manager]\n" . $body,
            'technical'
        );

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'code' => 'ticket_failed',
                'error' => (string) ($result['error'] ?? t('business.err_manager_ticket')),
            ];
        }

        $ticketId = (int) ($result['ticket_id'] ?? $result['id'] ?? 0);
        $ticketNumber = (string) ($result['ticket_number'] ?? '');
        if ($ticketId <= 0) {
            return [
                'ok' => false,
                'code' => 'ticket_failed',
                'error' => t('business.err_manager_ticket'),
            ];
        }

        ActivityLogger::info('business.manager_request', 'Запрос персонального менеджера', 'support_ticket', $ticketId, [
            'user_id' => $userId,
            'manager_assigned' => false,
        ]);

        return [
            'ok' => true,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            // Явно: автоназначения нет
            'manager_assigned' => false,
        ];
    }
}
