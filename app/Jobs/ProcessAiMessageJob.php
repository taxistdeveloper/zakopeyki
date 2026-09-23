<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiSupport;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Core\AIOrchestrator;
use App\Services\AI\DTO\AiRequest;
use App\Services\AI\SupportAiService;

class ProcessAiMessageJob
{
    private SupportAiService $aiService;
    private AiSupport $support;
    private ?AIOrchestrator $orchestrator;

    public function __construct(
        ?SupportAiService $aiService = null,
        ?AiSupport $support = null,
        ?AIOrchestrator $orchestrator = null
    ) {
        $this->aiService = $aiService ?? new SupportAiService();
        $this->support = $support ?? new AiSupport();
        $this->orchestrator = $orchestrator;
    }

    public function handle(array $payload): array
    {
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $userMessageId = (int) ($payload['user_message_id'] ?? 0);
        $messageText = (string) ($payload['message_text'] ?? '');
        $pageContext = is_array($payload['page_context'] ?? null) ? $payload['page_context'] : [];
        $requestId = (string) ($payload['request_id'] ?? ('req_' . bin2hex(random_bytes(8))));
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        if ($userId !== null && $userId <= 0) {
            $userId = null;
        }
        $imagePaths = [];
        if (isset($payload['image_paths']) && is_array($payload['image_paths'])) {
            foreach ($payload['image_paths'] as $path) {
                if (is_string($path) && $path !== '' && is_file($path)) {
                    $imagePaths[] = $path;
                }
            }
        }

        if ($conversationId <= 0 || $userMessageId <= 0 || $messageText === '') {
            throw new \InvalidArgumentException('Некорректный payload ProcessAiMessageJob');
        }

        $conversation = $this->support->getConversationById($conversationId);
        if (!$conversation) {
            throw new \RuntimeException("Диалог #{$conversationId} не найден");
        }

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

        if (!empty(AiConfig::get('orchestrator_enabled', true))) {
            $orch = $this->orchestrator ?? new AIOrchestrator(support: $this->support);
            $aiRequest = new AiRequest(
                requestId: $requestId,
                message: $messageText,
                userId: $userId ?? (isset($conversation['user_id']) ? (int) $conversation['user_id'] : null),
                conversationId: $conversationId,
                guestToken: $conversation['guest_token'] ?? null,
                pageContext: $pageContext,
                languageHint: isset($payload['language']) ? (string) $payload['language'] : null,
                imagePaths: $imagePaths,
            );

            // Сообщение пользователя уже сохранено контроллером — оркестратор пишет только ответ AI.
            // Чтобы не дублировать AI-сообщение: временно без conversationId для addMessage,
            // затем сохраняем один раз здесь? Orchestrator сам пишет — OK один раз.

            $response = $orch->handle($aiRequest);

            $this->support->logIntent(
                $userMessageId,
                $response->intent,
                $response->confidence,
                'orchestrator',
                $messageText,
                $response->message
            );

            return [
                'action' => $response->action,
                'response' => $response->message,
                'confidence' => $response->confidence,
                'intent' => $response->intent,
                'products' => $response->products,
                'suggestions' => $response->suggestions,
                'actions' => $response->actions,
                'data' => $response->data,
                'response_type' => $response->responseType,
                'ai_message_id' => $response->aiMessageId,
                'request_id' => $requestId,
                'language' => $response->language,
            ];
        }

        return $this->aiService->processMessage($conversationId, $userMessageId, $messageText);
    }
}
