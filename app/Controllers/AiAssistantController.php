<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Jobs\ProcessAiMessageJob;
use App\Models\AiQueue;
use App\Models\AiSupport;
use App\Services\AI\SelfLearningService;
use App\Services\AI\SupportAiService;
use App\Services\CatalogAiAssistant;

class AiAssistantController extends Controller
{
    public function chat(): void
    {
        $raw = file_get_contents('php://input');
        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            $json = [];
        }

        $cfg = $this->aiConfig();
        $maxLen = (int) ($cfg['max_message_length'] ?? 1000);

        $message = trim((string) ($json['message'] ?? $_POST['message'] ?? ''));
        $guestToken = isset($json['guest_token']) ? trim((string) $json['guest_token']) : null;
        if ($guestToken === '') {
            $guestToken = null;
        }

        if ($message === '') {
            $this->json([
                'ok' => false,
                'reply' => 'Введите сообщение.',
                'products' => [],
                'suggestions' => [],
            ], 422);
        }

        if (mb_strlen($message, 'UTF-8') > $maxLen) {
            $this->json([
                'ok' => false,
                'reply' => "Слишком длинное сообщение. Сократите до {$maxLen} символов.",
                'products' => [],
                'suggestions' => [],
            ], 422);
        }

        // AI отключён — старый каталог-помощник
        if (empty($cfg['enabled'])) {
            try {
                $this->json((new CatalogAiAssistant())->reply($message));
            } catch (\Throwable $e) {
                $this->json([
                    'ok' => false,
                    'reply' => 'Сейчас не удалось обработать запрос. Попробуйте ещё раз.',
                    'products' => [],
                    'suggestions' => [],
                ], 500);
            }
        }

        try {
            $support = new AiSupport();
            $userId = Auth::check() ? Auth::id() : null;

            if ($userId === null && $guestToken === null) {
                $guestToken = 'gt_' . bin2hex(random_bytes(16));
            }

            $conversation = $support->getOrCreateConversation($userId, $guestToken);
            $conversationId = (int) $conversation['id'];

            $userMessageId = $support->addMessage(
                $conversationId,
                'user',
                $message,
                null,
                $userId
            );

            // Если уже эскалирован — только сохраняем сообщение, ждём оператора
            if (($conversation['status'] ?? '') === 'human_escalated') {
                $this->json([
                    'ok' => true,
                    'reply' => 'Сообщение передано оператору. Ожидайте ответа в этом чате.',
                    'products' => [],
                    'suggestions' => [],
                    'conversation_id' => $conversationId,
                    'message_id' => $userMessageId,
                    'conversation_status' => 'human_escalated',
                    'pending' => false,
                    'guest_token' => $guestToken,
                ]);
            }

            if (($conversation['status'] ?? '') === 'closed') {
                $conversation = $support->getOrCreateConversation($userId, $guestToken);
                $conversationId = (int) $conversation['id'];
                $userMessageId = $support->addMessage($conversationId, 'user', $message, null, $userId);
            }

            $mode = (string) ($cfg['process_mode'] ?? 'sync');

            if ($mode === 'async') {
                (new AiQueue())->push(ProcessAiMessageJob::class, [
                    'conversation_id' => $conversationId,
                    'user_message_id' => $userMessageId,
                    'message_text' => $message,
                ]);

                $this->json([
                    'ok' => true,
                    'reply' => '',
                    'products' => [],
                    'suggestions' => [],
                    'conversation_id' => $conversationId,
                    'message_id' => $userMessageId,
                    'conversation_status' => 'ai_active',
                    'pending' => true,
                    'guest_token' => $guestToken,
                ]);
            }

            $result = (new ProcessAiMessageJob())->handle([
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'message_text' => $message,
            ]);

            $fresh = $support->getConversationById($conversationId);

            $this->json([
                'ok' => true,
                'reply' => (string) ($result['response'] ?? ''),
                'products' => $result['products'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
                'conversation_id' => $conversationId,
                'message_id' => $userMessageId,
                'ai_message_id' => $result['ai_message_id'] ?? null,
                'conversation_status' => $fresh['status'] ?? ($result['action'] === 'escalated' ? 'human_escalated' : 'ai_active'),
                'action' => $result['action'] ?? 'replied',
                'confidence' => $result['confidence'] ?? null,
                'intent' => $result['intent'] ?? null,
                'pending' => false,
                'guest_token' => $guestToken,
            ]);
        } catch (\Throwable $e) {
            // Фолбэк на каталог-помощник без LLM
            try {
                $fallback = (new CatalogAiAssistant())->reply($message);
                $fallback['ok'] = true;
                $fallback['conversation_status'] = 'ai_active';
                $fallback['pending'] = false;
                $this->json($fallback);
            } catch (\Throwable $e2) {
                $this->json([
                    'ok' => false,
                    'reply' => 'Сейчас не удалось обработать запрос. Попробуйте ещё раз или напишите в поддержку.',
                    'products' => [],
                    'suggestions' => [],
                ], 500);
            }
        }
    }

    public function messages(): void
    {
        $conversationId = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;
        $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : 0;

        if ($conversationId <= 0) {
            $this->json(['ok' => false, 'error' => 'Укажите conversation_id'], 400);
        }

        $support = new AiSupport();
        $conversation = $support->getConversationById($conversationId);
        if (!$conversation) {
            $this->json(['ok' => false, 'error' => 'Диалог не найден'], 404);
        }

        $guestToken = isset($_GET['guest_token']) ? trim((string) $_GET['guest_token']) : null;
        if (!$this->canAccessConversation($conversation, $guestToken)) {
            $this->json(['ok' => false, 'error' => 'Нет доступа'], 403);
        }

        $messages = $support->getMessages($conversationId, 100, $afterId);

        $this->json([
            'ok' => true,
            'conversation' => [
                'id' => (int) $conversation['id'],
                'status' => $conversation['status'],
                'assigned_agent_id' => $conversation['assigned_agent_id'],
            ],
            'messages' => array_map(static function (array $m): array {
                $meta = null;
                if (!empty($m['meta_json'])) {
                    $meta = json_decode((string) $m['meta_json'], true);
                }
                return [
                    'id' => (int) $m['id'],
                    'sender_type' => $m['sender_type'],
                    'message' => $m['message'],
                    'confidence_score' => $m['confidence_score'],
                    'meta' => $meta,
                    'created_at' => $m['created_at'],
                ];
            }, $messages),
        ]);
    }

    public function feedback(): void
    {
        $raw = file_get_contents('php://input');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = $_POST;
        }

        $messageId = (int) ($data['message_id'] ?? 0);
        $rating = (int) ($data['rating'] ?? 0);
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : null;

        if ($messageId <= 0 || $rating < 1 || $rating > 5) {
            $this->json(['ok' => false, 'error' => 'Некорректный message_id или rating (1–5)'], 400);
        }

        $support = new AiSupport();
        $msg = $support->getMessageById($messageId);
        if (!$msg) {
            $this->json(['ok' => false, 'error' => 'Сообщение не найдено'], 404);
        }

        $conversation = $support->getConversationById((int) $msg['conversation_id']);
        $guestFromBody = isset($data['guest_token']) ? trim((string) $data['guest_token']) : null;
        if (!$conversation || !$this->canAccessConversation($conversation, $guestFromBody)) {
            $this->json(['ok' => false, 'error' => 'Нет доступа'], 403);
        }

        (new SelfLearningService())->recordFeedback($messageId, $rating, $comment);

        $this->json(['ok' => true, 'message' => 'Спасибо за оценку!']);
    }

    private function canAccessConversation(array $conversation, ?string $guestToken = null): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        if (Auth::check() && (int) ($conversation['user_id'] ?? 0) === Auth::id()) {
            return true;
        }

        $guest = $guestToken;
        if ($guest === null || $guest === '') {
            $guest = isset($_GET['guest_token'])
                ? trim((string) $_GET['guest_token'])
                : (isset($_SERVER['HTTP_X_GUEST_TOKEN']) ? trim((string) $_SERVER['HTTP_X_GUEST_TOKEN']) : '');
        }

        $stored = (string) ($conversation['guest_token'] ?? '');
        if ($guest !== '' && $stored !== '' && hash_equals($stored, $guest)) {
            return true;
        }

        return false;
    }

    private function aiConfig(): array
    {
        $path = dirname(__DIR__, 2) . '/config/ai.php';
        return is_file($path) ? require $path : ['enabled' => true, 'process_mode' => 'sync'];
    }
}
