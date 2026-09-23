<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Jobs\ProcessAiMessageJob;
use App\Models\AiQueue;
use App\Models\AiSupport;
use App\Services\AI\Core\RateLimiter;
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
        $pageContext = [];
        if (isset($json['context']) && is_array($json['context'])) {
            $pageContext = $json['context'];
        } elseif (isset($json['page_context']) && is_array($json['page_context'])) {
            $pageContext = $json['page_context'];
        }
        $languageHint = isset($json['language']) ? trim((string) $json['language']) : null;
        $requestId = 'req_' . bin2hex(random_bytes(8));

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

        $userIdEarly = Auth::check() ? Auth::id() : null;
        if (!$this->enforceRateLimit($userIdEarly, $guestToken)) {
            return;
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
                    'page_context' => $pageContext,
                    'request_id' => $requestId,
                    'user_id' => $userId,
                    'language' => $languageHint,
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
                    'request_id' => $requestId,
                ]);
            }

            $result = (new ProcessAiMessageJob())->handle([
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'message_text' => $message,
                'page_context' => $pageContext,
                'request_id' => $requestId,
                'user_id' => $userId,
                'language' => $languageHint,
            ]);

            $fresh = $support->getConversationById($conversationId);

            $this->json([
                'ok' => true,
                'reply' => (string) ($result['response'] ?? ''),
                'products' => $result['products'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
                'actions' => $result['actions'] ?? [],
                'data' => $result['data'] ?? [],
                'response_type' => $result['response_type'] ?? 'TEXT',
                'conversation_id' => $conversationId,
                'message_id' => $userMessageId,
                'ai_message_id' => $result['ai_message_id'] ?? null,
                'conversation_status' => $fresh['status'] ?? ($result['action'] === 'escalated' ? 'human_escalated' : 'ai_active'),
                'action' => $result['action'] ?? 'replied',
                'confidence' => $result['confidence'] ?? null,
                'intent' => $result['intent'] ?? null,
                'pending' => false,
                'guest_token' => $guestToken,
                'request_id' => $requestId,
                'language' => $result['language'] ?? null,
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
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

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

        $userId = Auth::check() ? Auth::id() : null;
        (new SelfLearningService())->recordFeedback($messageId, $rating, $comment, $reason, $userId);

        $this->json(['ok' => true, 'message' => 'Спасибо за оценку!']);
    }

    /**
     * SSE-стриминг ответа: после tool pipeline текст отдаётся чанками, затем event done.
     */
    public function stream(): void
    {
        $raw = file_get_contents('php://input');
        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            $json = $_POST;
        }

        $cfg = $this->aiConfig();
        $maxLen = (int) ($cfg['max_message_length'] ?? 1000);
        $message = trim((string) ($json['message'] ?? ''));
        $guestToken = isset($json['guest_token']) ? trim((string) $json['guest_token']) : null;
        if ($guestToken === '') {
            $guestToken = null;
        }
        $pageContext = is_array($json['context'] ?? null) ? $json['context'] : [];
        $languageHint = isset($json['language']) ? trim((string) $json['language']) : null;
        $requestId = 'req_' . bin2hex(random_bytes(8));

        if ($message === '' || mb_strlen($message, 'UTF-8') > $maxLen) {
            $this->sseHeaders();
            $this->sseEvent('error', ['message' => 'Некорректное сообщение']);
            exit;
        }

        $userIdEarly = Auth::check() ? Auth::id() : null;
        $limiter = new RateLimiter();
        $rl = $limiter->attempt($limiter->clientKey($userIdEarly, $guestToken));
        if (empty($rl['allowed'])) {
            $this->sseHeaders();
            $this->sseEvent('error', [
                'message' => 'Слишком много запросов. Подождите немного.',
                'retry_after' => $rl['retry_after'] ?? 60,
            ]);
            exit;
        }

        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        $this->sseHeaders();

        try {
            $support = new AiSupport();
            $userId = Auth::check() ? Auth::id() : null;
            if ($userId === null && $guestToken === null) {
                $guestToken = 'gt_' . bin2hex(random_bytes(16));
            }
            $conversation = $support->getOrCreateConversation($userId, $guestToken);
            $conversationId = (int) $conversation['id'];
            $userMessageId = $support->addMessage($conversationId, 'user', $message, null, $userId);

            $this->sseEvent('meta', [
                'conversation_id' => $conversationId,
                'message_id' => $userMessageId,
                'guest_token' => $guestToken,
                'request_id' => $requestId,
            ]);

            $result = (new ProcessAiMessageJob())->handle([
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'message_text' => $message,
                'page_context' => $pageContext,
                'request_id' => $requestId,
                'user_id' => $userId,
                'language' => $languageHint,
            ]);

            $reply = (string) ($result['response'] ?? '');
            $chunkSize = 24;
            $len = mb_strlen($reply, 'UTF-8');
            for ($i = 0; $i < $len; $i += $chunkSize) {
                $delta = mb_substr($reply, $i, $chunkSize, 'UTF-8');
                $this->sseEvent('delta', ['text' => $delta]);
                if (function_exists('flush')) {
                    flush();
                }
                usleep(12000);
            }

            $this->sseEvent('done', [
                'ok' => true,
                'reply' => $reply,
                'products' => $result['products'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
                'actions' => $result['actions'] ?? [],
                'data' => $result['data'] ?? [],
                'response_type' => $result['response_type'] ?? 'TEXT',
                'conversation_id' => $conversationId,
                'ai_message_id' => $result['ai_message_id'] ?? null,
                'intent' => $result['intent'] ?? null,
                'guest_token' => $guestToken,
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            $this->sseEvent('error', ['message' => 'Сейчас не удалось обработать запрос.']);
        }
        exit;
    }

    private function sseHeaders(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /** @param array<string, mixed> $payload */
    private function sseEvent(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (function_exists('flush')) {
            flush();
        }
    }

    /**
     * Голосовой ввод: клиент шлёт transcript (Web Speech API).
     * Обрабатывается тем же pipeline, что и текст.
     */
    public function voice(): void
    {
        $raw = file_get_contents('php://input');
        $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            $json = $_POST;
        }

        $transcript = trim((string) ($json['transcript'] ?? $json['message'] ?? ''));
        if ($transcript === '') {
            $this->json(['ok' => false, 'reply' => 'Не удалось распознать речь. Повторите или введите текст.'], 422);
        }

        // Переиспользуем chat(): подменяем php://input через POST-совместимый путь
        $_POST['message'] = $transcript;
        if (isset($json['guest_token'])) {
            $_POST['guest_token'] = $json['guest_token'];
        }
        // chat() читает php://input первым — передаём через временный поток невозможно,
        // поэтому вызываем внутреннюю логику через повторный decode: проще проксировать.
        $payload = json_encode([
            'message' => $transcript,
            'guest_token' => $json['guest_token'] ?? null,
            'context' => $json['context'] ?? [],
            'language' => $json['language'] ?? null,
            'from_voice' => true,
        ], JSON_UNESCAPED_UNICODE);

        // Прямой вызов: эмулируем body
        $this->chatWithPayload(is_array(json_decode((string) $payload, true)) ? json_decode((string) $payload, true) : []);
    }

    public function confirmAction(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'error' => 'Требуется вход'], 401);
        }

        $raw = file_get_contents('php://input');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = $_POST;
        }

        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '') {
            $this->json(['ok' => false, 'error' => 'Укажите token'], 422);
        }

        $service = new \App\Services\AI\Core\ActionConfirmationService(
            (int) (\App\Services\AI\Core\AiConfig::get('confirmation_ttl_seconds', 900))
        );
        $result = $service->confirm($token, Auth::id());
        if (empty($result['ok'])) {
            $this->json(['ok' => false, 'error' => $result['error'] ?? 'Ошибка подтверждения'], 400);
        }

        $action = (string) ($result['action'] ?? '');
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

        if ($action === 'publish_listing_draft') {
            $draftId = (int) ($payload['draft_id'] ?? 0);
            $publish = (new \App\Services\Listing\ListingDraftService())->publishDraft(Auth::id(), $draftId);
            if (empty($publish['ok'])) {
                $this->json(['ok' => false, 'error' => $publish['error'] ?? 'Не удалось опубликовать'], 400);
            }
            $productId = (int) ($publish['product_id'] ?? 0);
            $this->json([
                'ok' => true,
                'action' => $action,
                'product_id' => $productId,
                'url' => $productId > 0 ? url('/product/' . $productId) : null,
                'message' => 'Объявление опубликовано.',
            ]);
        }

        $this->json([
            'ok' => true,
            'action' => $action,
            'payload' => $payload,
            'message' => 'Действие подтверждено.',
        ]);
    }

    /**
     * Загрузка фото → vision (+ опционально draft объявления).
     */
    public function image(): void
    {
        if (!Auth::check()) {
            $this->json(['ok' => false, 'error' => 'Войдите в аккаунт для анализа фото.'], 401);
        }

        $message = trim((string) ($_POST['message'] ?? 'Проанализируй товар на фото'));
        $guestToken = isset($_POST['guest_token']) ? trim((string) $_POST['guest_token']) : null;
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $cfg = $this->aiConfig();

        if (!$this->enforceRateLimit(Auth::id(), isset($_POST['guest_token']) ? trim((string) $_POST['guest_token']) : null)) {
            return;
        }

        if (empty($_FILES['image']) || !is_uploaded_file((string) ($_FILES['image']['tmp_name'] ?? ''))) {
            $this->json(['ok' => false, 'error' => 'Прикрепите изображение (image).'], 422);
        }

        $tmp = (string) $_FILES['image']['tmp_name'];
        $name = (string) ($_FILES['image']['name'] ?? 'photo.jpg');
        if (!\App\Helpers\UploadHelper::isAllowedUpload($tmp, $name, ['jpg', 'jpeg', 'png', 'webp'])) {
            $this->json(['ok' => false, 'error' => 'Допустимы JPG, PNG, WEBP.'], 422);
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/ai';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg');
        $filename = 'ai_' . Auth::id() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            $this->json(['ok' => false, 'error' => 'Не удалось сохранить файл.'], 500);
        }

        try {
            $support = new AiSupport();
            $userId = Auth::id();
            $conversation = $support->getOrCreateConversation($userId, $guestToken);
            $conversationId = (int) $conversation['id'];
            $userMessageId = $support->addMessage($conversationId, 'user', $message . ' [фото]', null, $userId);

            $payload = [
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'message_text' => $message,
                'page_context' => [],
                'request_id' => $requestId,
                'user_id' => $userId,
                'image_paths' => [$dest],
            ];

            $visionAsync = !empty($cfg['performance']['vision_async'])
                || (string) ($cfg['process_mode'] ?? 'sync') === 'async';

            if ($visionAsync) {
                (new AiQueue())->push(ProcessAiMessageJob::class, $payload, 'vision');
                $this->json([
                    'ok' => true,
                    'reply' => '',
                    'pending' => true,
                    'conversation_id' => $conversationId,
                    'message_id' => $userMessageId,
                    'request_id' => $requestId,
                    'response_type' => 'IMAGE_ANALYSIS',
                    'image' => 'public/uploads/ai/' . $filename,
                    'hint' => 'Анализ фото в очереди. Ответ появится в чате через несколько секунд.',
                ]);
            }

            $result = (new ProcessAiMessageJob())->handle($payload);

            $this->json([
                'ok' => true,
                'reply' => (string) ($result['response'] ?? ''),
                'products' => $result['products'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
                'actions' => $result['actions'] ?? [],
                'data' => $result['data'] ?? [],
                'response_type' => $result['response_type'] ?? 'IMAGE_ANALYSIS',
                'conversation_id' => $conversationId,
                'ai_message_id' => $result['ai_message_id'] ?? null,
                'intent' => $result['intent'] ?? null,
                'request_id' => $requestId,
                'pending' => false,
                'image' => 'public/uploads/ai/' . $filename,
            ]);
        } catch (\Throwable $e) {
            $this->json([
                'ok' => false,
                'reply' => 'Сейчас не удалось проанализировать фото. Попробуйте ещё раз.',
            ], 500);
        }
    }

    /** @param array<string, mixed> $json */
    private function chatWithPayload(array $json): void
    {
        // Минимальный прокси: кладём в глобал для chat() — проще переписать chat body.
        // Здесь дублируем точку входа voice → тот же ProcessAiMessageJob.
        $cfg = $this->aiConfig();
        $maxLen = (int) ($cfg['max_message_length'] ?? 1000);
        $message = trim((string) ($json['message'] ?? ''));
        $guestToken = isset($json['guest_token']) ? trim((string) $json['guest_token']) : null;
        if ($guestToken === '') {
            $guestToken = null;
        }
        $pageContext = is_array($json['context'] ?? null) ? $json['context'] : [];
        $languageHint = isset($json['language']) ? trim((string) $json['language']) : null;
        $requestId = 'req_' . bin2hex(random_bytes(8));

        if ($message === '') {
            $this->json(['ok' => false, 'reply' => 'Пустое сообщение.', 'products' => [], 'suggestions' => []], 422);
        }
        if (mb_strlen($message, 'UTF-8') > $maxLen) {
            $this->json(['ok' => false, 'reply' => "Слишком длинное сообщение (макс. {$maxLen}).", 'products' => []], 422);
        }

        $userId = Auth::check() ? Auth::id() : null;
        if (!$this->enforceRateLimit($userId, $guestToken)) {
            return;
        }

        try {
            $support = new AiSupport();
            if ($userId === null && $guestToken === null) {
                $guestToken = 'gt_' . bin2hex(random_bytes(16));
            }
            $conversation = $support->getOrCreateConversation($userId, $guestToken);
            $conversationId = (int) $conversation['id'];
            $userMessageId = $support->addMessage($conversationId, 'user', $message, null, $userId);

            $result = (new ProcessAiMessageJob())->handle([
                'conversation_id' => $conversationId,
                'user_message_id' => $userMessageId,
                'message_text' => $message,
                'page_context' => $pageContext,
                'request_id' => $requestId,
                'user_id' => $userId,
                'language' => $languageHint,
            ]);

            $this->json([
                'ok' => true,
                'reply' => (string) ($result['response'] ?? ''),
                'products' => $result['products'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
                'actions' => $result['actions'] ?? [],
                'data' => $result['data'] ?? [],
                'response_type' => $result['response_type'] ?? 'TEXT',
                'conversation_id' => $conversationId,
                'message_id' => $userMessageId,
                'ai_message_id' => $result['ai_message_id'] ?? null,
                'intent' => $result['intent'] ?? null,
                'from_voice' => true,
                'tts' => (new \App\Services\AI\Providers\BrowserTtsProvider())->synthesize(
                    (string) ($result['response'] ?? ''),
                    (string) ($result['language'] ?? 'ru')
                ),
                'guest_token' => $guestToken,
                'request_id' => $requestId,
                'pending' => false,
            ]);
        } catch (\Throwable $e) {
            $this->json([
                'ok' => false,
                'reply' => 'Сейчас не удалось обработать голосовой запрос. Попробуйте ещё раз.',
                'products' => [],
            ], 500);
        }
    }

    private function enforceRateLimit(?int $userId, ?string $guestToken): bool
    {
        $limiter = new RateLimiter();
        $result = $limiter->attempt($limiter->clientKey($userId, $guestToken));
        if (!empty($result['allowed'])) {
            return true;
        }
        $retry = (int) ($result['retry_after'] ?? 60);
        header('Retry-After: ' . $retry);
        $this->json([
            'ok' => false,
            'reply' => 'Слишком много запросов. Подождите немного и попробуйте снова.',
            'error' => 'rate_limited',
            'retry_after' => $retry,
            'products' => [],
            'suggestions' => [],
        ], 429);
        return false;
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
