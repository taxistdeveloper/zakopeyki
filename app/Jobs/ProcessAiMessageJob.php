<?php

namespace App\Jobs;

use App\Models\AiSupport;
use App\Services\AI\SupportAiService;

class ProcessAiMessageJob
{
    private SupportAiService $aiService;
    private AiSupport $support;

    public function __construct(?SupportAiService $aiService = null, ?AiSupport $support = null)
    {
        $this->aiService = $aiService ?? new SupportAiService();
        $this->support = $support ?? new AiSupport();
    }

    public function handle(array $payload): array
    {
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $userMessageId = (int) ($payload['user_message_id'] ?? 0);
        $messageText = (string) ($payload['message_text'] ?? '');

        if ($conversationId <= 0 || $userMessageId <= 0 || $messageText === '') {
            throw new \InvalidArgumentException('Некорректный payload ProcessAiMessageJob');
        }

        $conversation = $this->support->getConversationById($conversationId);
        if (!$conversation) {
            throw new \RuntimeException("Диалог #{$conversationId} не найден");
        }

        // Если уже эскалирован/закрыт — AI не отвечает повторно
        if (($conversation['status'] ?? '') !== 'ai_active') {
            return [
                'action' => 'skipped',
                'response' => '',
                'confidence' => 0.0,
                'intent' => 'SKIPPED',
                'products' => [],
                'suggestions' => [],
            ];
        }

        return $this->aiService->processMessage($conversationId, $userMessageId, $messageText);
    }
}
