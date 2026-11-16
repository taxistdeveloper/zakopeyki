<?php

declare(strict_types=1);

namespace App\Controllers\Api\Mobile;

use App\Jobs\ProcessAiMessageJob;
use App\Models\AiQueue;
use App\Models\AiSupport;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Core\RateLimiter;
use App\Services\AI\SelfLearningService;
use App\Services\CatalogAiAssistant;
use App\Services\MobileApi\Presenter;

/**
 * ИИ-помощник ZAK в приложении: тот же движок, что /ai/chat на сайте
 * (AiSupport + ProcessAiMessageJob), но с авторизацией приложения.
 * Гость идентифицируется по X-Device-Id (хеш), вошедший — по user_id.
 */
final class AssistantApiController extends BaseMobileController
{
    /** GET /assistant — приветствие, подсказки и история открытого диалога. */
    public function index(): void
    {
        [$uid, $guest] = $this->identity();
        $support = new AiSupport();
        $conversation = $this->openConversation($support, $uid, $guest);

        $this->ok([
            'title' => t('ai.title'),
            'status' => t('ai.status_ai'),
            'welcome' => t('ai.welcome'),
            'placeholder' => t('ai.placeholder'),
            'avatar_url' => Presenter::media('/public/assets/img/zak-avatar@2x.png'),
            'enabled' => true,
            'suggestions' => $this->quickSuggestions(),
            'conversation' => $conversation ? $this->conversation($conversation) : null,
            'messages' => $conversation
                ? array_map(fn (array $m): array => $this->messageOut($m), $support->getMessages((int) $conversation['id'], 60))
                : [],
        ]);
    }

    /** GET /assistant/messages?after_id= — новые сообщения (ответ ИИ в async-режиме, ответ оператора). */
    public function poll(): void
    {
        [$uid, $guest] = $this->identity();
        $support = new AiSupport();
        $conversation = $this->openConversation($support, $uid, $guest);
        if (!$conversation) {
            $this->ok(['conversation' => null, 'messages' => []]);
        }
        $after = $this->queryInt('after_id', 0, 0, PHP_INT_MAX);
        $this->ok([
            'conversation' => $this->conversation($conversation),
            'messages' => array_map(fn (array $m): array => $this->messageOut($m), $support->getMessages((int) $conversation['id'], 100, $after)),
        ]);
    }

    /** POST /assistant/messages {message} */
    public function sendMessage(): void
    {
        [$uid, $guest] = $this->identity();
        $cfg = AiConfig::all();
        $maxLen = (int) ($cfg['max_message_length'] ?? 1000);
        $message = trim((string) ($this->body()['message'] ?? ''));
        if ($message === '') {
            $this->fail('validation_failed', 422, 'Введите сообщение.', ['message' => 'required']);
        }
        if (mb_strlen($message, 'UTF-8') > $maxLen) {
            $this->fail('validation_failed', 422, "Слишком длинное сообщение. Сократите до {$maxLen} символов.", ['message' => 'too_long']);
        }

        $limiter = new RateLimiter();
        $rl = $limiter->attempt($limiter->clientKey($uid, $guest));
        if (empty($rl['allowed'])) {
            $retry = (int) ($rl['retry_after'] ?? 60);
            $this->fail('rate_limited', 429, 'Слишком много запросов. Подождите немного и попробуйте снова.', [], ['Retry-After' => (string) $retry]);
        }

        // ИИ выключен в настройках — каталог-помощник без LLM
        if (empty($cfg['enabled'])) {
            $this->ok($this->reply((new CatalogAiAssistant())->reply($message), null, 'ai_active', null));
        }

        $requestId = 'req_' . bin2hex(random_bytes(8));
        $support = new AiSupport();
        try {
            $conversation = $support->getOrCreateConversation($uid, $uid === null ? $guest : null);
            $cid = (int) $conversation['id'];
            $userMessageId = $support->addMessage($cid, 'user', $message, null, $uid);

            $operatorOn = !empty($cfg['human_operator_enabled']);
            if (($conversation['status'] ?? '') === 'human_escalated' && !$operatorOn) {
                $support->updateStatus($cid, 'ai_active');
                $conversation['status'] = 'ai_active';
            }
            if (($conversation['status'] ?? '') === 'human_escalated') {
                $this->ok($this->reply(
                    ['reply' => 'Сообщение передано оператору. Ожидайте ответа в этом чате.'],
                    $cid,
                    'human_escalated',
                    $userMessageId,
                ));
            }

            $job = [
                'conversation_id' => $cid,
                'user_message_id' => $userMessageId,
                'message_text' => $message,
                'page_context' => ['page' => 'mobile_app'],
                'request_id' => $requestId,
                'user_id' => $uid,
                'language' => \App\Core\Lang::current(),
            ];

            if ((string) ($cfg['process_mode'] ?? 'sync') === 'async') {
                (new AiQueue())->push(ProcessAiMessageJob::class, $job);
                $out = $this->reply(['reply' => ''], $cid, 'ai_active', $userMessageId);
                $out['pending'] = true;
                $this->ok($out);
            }

            $result = (new ProcessAiMessageJob())->handle($job);
            $fresh = $support->getConversationById($cid);
            $out = $this->reply([
                'reply' => (string) ($result['response'] ?? ''),
                'products' => $result['products'] ?? [],
                'suggestions' => $result['suggestions'] ?? [],
            ], $cid, (string) ($fresh['status'] ?? 'ai_active'), $userMessageId);
            $out['ai_message_id'] = isset($result['ai_message_id']) ? (int) $result['ai_message_id'] : null;
            $this->ok($out);
        } catch (\Throwable $e) {
            error_log('[mobile assistant] ' . $e->getMessage());
            try {
                $this->ok($this->reply((new CatalogAiAssistant())->reply($message), null, 'ai_active', null));
            } catch (\Throwable) {
                $this->fail('assistant_unavailable', 503, 'Сейчас не удалось обработать запрос. Попробуйте ещё раз.');
            }
        }
    }

    /** POST /assistant/clear — закрыть диалог и начать новый. */
    public function clear(): void
    {
        [$uid, $guest] = $this->identity();
        $support = new AiSupport();
        $conversation = $this->openConversation($support, $uid, $guest);
        if ($conversation && ($conversation['status'] ?? '') !== 'closed') {
            $support->updateStatus((int) $conversation['id'], 'closed');
        }
        $this->ok(['cleared' => true]);
    }

    /** POST /assistant/feedback {message_id, rating 1..5} */
    public function feedback(): void
    {
        [$uid, $guest] = $this->identity();
        $b = $this->body();
        $messageId = (int) ($b['message_id'] ?? 0);
        $rating = (int) ($b['rating'] ?? 0);
        if ($messageId <= 0 || $rating < 1 || $rating > 5) {
            $this->fail('validation_failed', 422);
        }
        $support = new AiSupport();
        $msg = $support->getMessageById($messageId);
        $conversation = $msg ? $support->getConversationById((int) $msg['conversation_id']) : null;
        if (!$conversation || !$this->owns($conversation, $uid, $guest)) {
            $this->fail('not_found', 404);
        }
        (new SelfLearningService())->recordFeedback($messageId, $rating, null, null, $uid);
        $this->ok(['message' => 'Спасибо за оценку!']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: ?int, 1: ?string} */
    private function identity(): array
    {
        $uid = $this->optionalUserId();
        if ($uid !== null) {
            return [$uid, null];
        }
        $device = (string) ($this->device()['device_id'] ?? '');
        if ($device === '') {
            $this->fail('device_id_required', 400);
        }

        // guest_token VARCHAR(64): префикс + 56 символов хеша устройства
        return [null, 'gm_' . substr(hash('sha256', 'zak-mobile|' . $device), 0, 56)];
    }

    private function openConversation(AiSupport $support, ?int $uid, ?string $guest): ?array
    {
        $db = \App\Core\Database::connect();
        if ($uid !== null) {
            $st = $db->prepare("SELECT * FROM ai_conversations WHERE user_id = ? AND status != 'closed' ORDER BY id DESC LIMIT 1");
            $st->execute([$uid]);
        } else {
            $st = $db->prepare("SELECT * FROM ai_conversations WHERE guest_token = ? AND status != 'closed' ORDER BY id DESC LIMIT 1");
            $st->execute([$guest]);
        }
        $row = $st->fetch();

        return $row ?: null;
    }

    private function owns(array $conversation, ?int $uid, ?string $guest): bool
    {
        if ($uid !== null) {
            return (int) ($conversation['user_id'] ?? 0) === $uid;
        }
        $stored = (string) ($conversation['guest_token'] ?? '');

        return $guest !== null && $stored !== '' && hash_equals($stored, $guest);
    }

    private function conversation(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'status' => (string) ($c['status'] ?? 'ai_active'),
        ];
    }

    private function messageOut(array $m): array
    {
        $meta = !empty($m['meta_json']) ? json_decode((string) $m['meta_json'], true) : null;
        $meta = is_array($meta) ? $meta : [];

        return [
            'id' => (int) $m['id'],
            'from' => (string) $m['sender_type'], // user | ai | agent | system
            'text' => (string) $m['message'],
            'products' => $this->products($meta['products'] ?? []),
            'suggestions' => $this->suggestions($meta['suggestions'] ?? []),
            'created_at' => Presenter::date($m['created_at'] ?? null),
        ];
    }

    private function reply(array $r, ?int $cid, string $status, ?int $userMessageId): array
    {
        return [
            'reply' => (string) ($r['reply'] ?? ''),
            'products' => $this->products($r['products'] ?? []),
            'suggestions' => $this->suggestions($r['suggestions'] ?? []),
            'conversation_id' => $cid,
            'conversation_status' => $status,
            'message_id' => $userMessageId,
            'ai_message_id' => null,
            'pending' => false,
        ];
    }

    /** Товары от ИИ приводим к короткой карточке приложения. */
    private function products(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_slice($items, 0, 8) as $p) {
            if (!is_array($p) || empty($p['id'])) {
                continue;
            }
            $image = (string) ($p['image'] ?? $p['image_url'] ?? '');
            $out[] = [
                'id' => (int) $p['id'],
                'title' => (string) ($p['title'] ?? ''),
                'price_text' => (string) ($p['price'] ?? $p['price_text'] ?? ''),
                'type_label' => (string) ($p['type_label'] ?? ''),
                'location' => (string) ($p['location'] ?? ''),
                'image' => $image !== '' ? (preg_match('~^https?://~', $image) ? $image : Presenter::media($image)) : null,
            ];
        }

        return $out;
    }

    private function suggestions(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_slice($items, 0, 6) as $s) {
            if (is_string($s) && $s !== '') {
                $out[] = ['label' => $s, 'message' => $s];
            } elseif (is_array($s) && !empty($s['label'])) {
                $out[] = ['label' => (string) $s['label'], 'message' => (string) ($s['message'] ?? $s['label'])];
            }
        }

        return $out;
    }

    private function quickSuggestions(): array
    {
        return [
            ['label' => t('ai.suggest_free'), 'message' => t('ai.msg_free')],
            ['label' => t('ai.suggest_exchange'), 'message' => t('ai.msg_exchange')],
            ['label' => t('ai.suggest_services'), 'message' => t('ai.msg_services')],
            ['label' => t('ai.suggest_sell'), 'message' => t('ai.msg_sell')],
            ['label' => t('ai.suggest_auctions'), 'message' => t('ai.msg_auctions')],
        ];
    }
}
