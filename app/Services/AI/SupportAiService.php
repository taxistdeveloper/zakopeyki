<?php

namespace App\Services\AI;

use App\Models\AiSupport;
use App\Services\CatalogAiAssistant;

/**
 * Оркестратор локального AI-саппорта:
 * Intent → Catalog / RAG+Few-Shot / Escalate → Ollama → Self-Learning hooks.
 */
class SupportAiService
{
    private OllamaClient $ollama;
    private RagEngine $rag;
    private IntentClassifier $classifier;
    private SelfLearningService $learning;
    private AiSupport $support;
    private array $config;

    public function __construct(
        ?OllamaClient $ollama = null,
        ?RagEngine $rag = null,
        ?IntentClassifier $classifier = null,
        ?SelfLearningService $learning = null,
        ?AiSupport $support = null
    ) {
        $this->config = $this->loadConfig();
        $this->ollama = $ollama ?? OllamaClient::fromConfig();
        $this->rag = $rag ?? new RagEngine();
        $this->classifier = $classifier ?? new IntentClassifier($this->ollama);
        $this->learning = $learning ?? new SelfLearningService();
        $this->support = $support ?? new AiSupport();
    }

    /**
     * @return array{
     *   action:string,
     *   response:string,
     *   confidence:float,
     *   intent:string,
     *   products:list,
     *   suggestions:list,
     *   ai_message_id?:int
     * }
     */
    public function processMessage(int $conversationId, int $userMessageId, string $messageText): array
    {
        $classification = $this->classifier->classify($messageText);
        $intent = $classification['intent'];
        $confidence = $classification['confidence'];
        $method = $classification['method'];

        $this->support->logIntent(
            $userMessageId,
            $intent,
            $confidence,
            $method,
            $messageText,
            null
        );

        $threshold = (float) ($this->config['confidence_threshold'] ?? 0.70);
        if ($intent === IntentClassifier::INTENT_ESCALATE) {
            return $this->escalate($conversationId, $userMessageId, $intent, $confidence, $messageText);
        }

        // Низкая уверенность LLM на ACTION — безопаснее отдать оператору
        if ($intent === IntentClassifier::INTENT_ACTION
            && $classification['method'] === 'llm'
            && $confidence < $threshold
        ) {
            return $this->escalate($conversationId, $userMessageId, $intent, $confidence, $messageText);
        }

        if ($intent === IntentClassifier::INTENT_GREETING) {
            $responseText = 'Здравствуйте! Я официальный AI-ассистент zakopeyki.kz. '
                . 'Помогу с безопасными сделками, доставкой, модерацией, поиском в каталоге или вызову оператора. Чем помочь?';
            $aiId = $this->support->addMessage($conversationId, 'ai', $responseText, $confidence);
            $this->support->logIntent($userMessageId, $intent, $confidence, $method, $messageText, $responseText);

            return [
                'action' => 'replied',
                'response' => $responseText,
                'confidence' => $confidence,
                'intent' => $intent,
                'products' => [],
                'suggestions' => $this->supportSuggestions(),
                'ai_message_id' => $aiId,
            ];
        }

        if ($intent === IntentClassifier::INTENT_CATALOG) {
            return $this->handleCatalog($conversationId, $userMessageId, $messageText, $confidence, $intent);
        }

        if ($intent === IntentClassifier::INTENT_ACTION) {
            return $this->handleActionQuery($conversationId, $userMessageId, $messageText, $confidence, $intent);
        }

        return $this->handleFaq($conversationId, $userMessageId, $messageText, $confidence, $intent);
    }

    private function handleCatalog(
        int $conversationId,
        int $userMessageId,
        string $messageText,
        float $confidence,
        string $intent
    ): array {
        $catalog = (new CatalogAiAssistant())->reply($messageText);
        $responseText = (string) ($catalog['reply'] ?? '');
        $products = $catalog['products'] ?? [];
        $suggestions = $catalog['suggestions'] ?? [];

        $aiId = $this->support->addMessage(
            $conversationId,
            'ai',
            $responseText,
            $confidence,
            null,
            ['products' => $products]
        );

        return [
            'action' => 'replied',
            'response' => $responseText,
            'confidence' => $confidence,
            'intent' => $intent,
            'products' => $products,
            'suggestions' => $suggestions,
            'ai_message_id' => $aiId,
        ];
    }

    private function handleActionQuery(
        int $conversationId,
        int $userMessageId,
        string $messageText,
        float $confidence,
        string $intent
    ): array {
        $orderId = null;
        if (preg_match('/#?\s*(\d{2,})/u', $messageText, $m)) {
            $orderId = (int) $m[1];
        }

        if ($orderId) {
            try {
                $stmt = $this->support->pdo()->prepare(
                    'SELECT id, status, buyer_id, seller_id FROM orders WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();
                if ($order) {
                    $status = (string) $order['status'];
                    $responseText = "Заказ #{$orderId}: текущий статус — «{$status}». "
                        . 'Подробности смотрите в разделе «Мои заказы». '
                        . 'Если нужна помощь со спором или возвратом — напишите «оператор».';
                    $aiId = $this->support->addMessage($conversationId, 'ai', $responseText, $confidence);
                    return [
                        'action' => 'replied',
                        'response' => $responseText,
                        'confidence' => $confidence,
                        'intent' => $intent,
                        'products' => [],
                        'suggestions' => [
                            ['label' => 'Открыть спор', 'message' => 'как открыть спор'],
                            ['label' => 'Оператор', 'message' => 'оператор'],
                        ],
                        'ai_message_id' => $aiId,
                    ];
                }
            } catch (\Throwable $e) {
                // таблица orders может отличаться — уйдём в FAQ
            }
        }

        return $this->handleFaq($conversationId, $userMessageId, $messageText, $confidence, $intent);
    }

    private function handleFaq(
        int $conversationId,
        int $userMessageId,
        string $messageText,
        float $confidence,
        string $intent
    ): array {
        $articles = $this->rag->searchContext($messageText);
        $formattedContext = $this->rag->formatContextForPrompt($articles);

        $fewShotLimit = (int) ($this->config['few_shot_limit'] ?? 2);
        $fewShots = $this->learning->getDynamicFewShots($messageText, $fewShotLimit);
        $formattedFewShots = $this->learning->formatFewShotsForPrompt($fewShots);

        $escalateEmpty = (bool) ($this->config['escalate_on_empty_rag'] ?? true);
        if ($escalateEmpty && $articles === [] && $fewShots === []) {
            // Локальный FAQ fallback через CatalogAiAssistant.matchFaq недоступен напрямую —
            // пробуем каталог-помощника (у него есть FAQ), иначе эскалация
            $catalog = (new CatalogAiAssistant())->reply($messageText);
            $reply = (string) ($catalog['reply'] ?? '');
            if ($reply !== '' && !str_contains(mb_strtolower($reply, 'UTF-8'), 'не нашл')) {
                $aiId = $this->support->addMessage($conversationId, 'ai', $reply, 0.6, null, [
                    'products' => $catalog['products'] ?? [],
                ]);
                return [
                    'action' => 'replied',
                    'response' => $reply,
                    'confidence' => 0.6,
                    'intent' => $intent,
                    'products' => $catalog['products'] ?? [],
                    'suggestions' => $catalog['suggestions'] ?? $this->supportSuggestions(),
                    'ai_message_id' => $aiId,
                ];
            }

            return $this->escalate($conversationId, $userMessageId, $intent, $confidence, $messageText);
        }

        $systemPrompt = <<<PROMPT
Вы — интеллектуальный ассистент поддержки казахстанского маркетплейса zakopeyki.kz.
Ваша цель: дать клиенту точный, вежливый и короткий ответ (до 3–4 предложений).

Официальная База Знаний zakopeyki.kz:
{$formattedContext}

{$formattedFewShots}

Инструкции:
1. Используйте ТОЛЬКО базу знаний и образцы ответов операторов.
2. Если информации недостаточно, напишите ровно эту фразу:
«К сожалению, у меня нет точной информации по этому вопросу. Перевожу вас на живого оператора.»
3. Запрещено придумывать правила, цены, комиссии или условия.
4. Отвечайте на языке пользователя (русский или казахский).
PROMPT;

        try {
            if (!$this->ollama->isAvailable()) {
                // Без Ollama отвечаем лучшей RAG-статьёй напрямую
                if ($articles !== []) {
                    $best = $articles[0];
                    $responseText = (string) $best['title'] . "\n\n" . (string) $best['content'];
                    $aiId = $this->support->addMessage($conversationId, 'ai', $responseText, 0.55);
                    return [
                        'action' => 'replied',
                        'response' => $responseText,
                        'confidence' => 0.55,
                        'intent' => $intent,
                        'products' => [],
                        'suggestions' => $this->supportSuggestions(),
                        'ai_message_id' => $aiId,
                    ];
                }
                return $this->escalate($conversationId, $userMessageId, 'OLLAMA_OFFLINE', 0.0, $messageText);
            }

            $aiResult = $this->ollama->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $messageText],
                ],
                (float) ($this->config['temperature'] ?? 0.1)
            );
            $responseText = $aiResult['content'];

            if (str_contains($responseText, 'Перевожу вас на живого оператора')) {
                return $this->escalate($conversationId, $userMessageId, $intent, $confidence, $messageText);
            }

            $aiId = $this->support->addMessage($conversationId, 'ai', $responseText, $confidence);
            $this->support->logIntent($userMessageId, $intent, $confidence, 'rag_llm', $messageText, $responseText);

            return [
                'action' => 'replied',
                'response' => $responseText,
                'confidence' => $confidence,
                'intent' => $intent,
                'products' => [],
                'suggestions' => $this->supportSuggestions(),
                'ai_message_id' => $aiId,
            ];
        } catch (\Throwable $e) {
            $this->support->logIntent($userMessageId, 'SYSTEM_ERROR', 0.0, 'exception', $messageText, $e->getMessage());
            return $this->escalate($conversationId, $userMessageId, 'SYSTEM_ERROR', 0.0, $messageText);
        }
    }

    /**
     * @return array{action:string,response:string,confidence:float,intent:string,products:list,suggestions:list,ai_message_id:int}
     */
    private function escalate(
        int $conversationId,
        int $userMessageId,
        string $intent,
        float $confidence,
        string $messageText
    ): array {
        $this->support->updateStatus($conversationId, 'human_escalated');
        $responseText = 'Ваш диалог переведён на оператора первой линии zakopeyki.kz. '
            . 'Специалист подключится к чату в течение 2–5 минут. Можете уточнить детали здесь.';

        $aiId = $this->support->addMessage($conversationId, 'system', $responseText, $confidence);
        $this->support->logIntent($userMessageId, IntentClassifier::INTENT_ESCALATE, $confidence, 'escalate', $messageText, $responseText);

        return [
            'action' => 'escalated',
            'response' => $responseText,
            'confidence' => $confidence,
            'intent' => $intent,
            'products' => [],
            'suggestions' => [],
            'ai_message_id' => $aiId,
        ];
    }

    private function supportSuggestions(): array
    {
        return [
            ['label' => 'Безопасная сделка', 'message' => 'как работает безопасная сделка'],
            ['label' => 'Доставка', 'message' => 'сроки доставки'],
            ['label' => 'Модерация', 'message' => 'почему отклонили объявление'],
            ['label' => 'Оператор', 'message' => 'оператор'],
        ];
    }

    private function loadConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/ai.php';
        return is_file($path) ? require $path : [];
    }
}
