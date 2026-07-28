<?php

namespace App\Services\AI;

class IntentClassifier
{
    public const INTENT_FAQ = 'FAQ_QUERY';
    public const INTENT_GREETING = 'GREETING';
    public const INTENT_ESCALATE = 'HUMAN_ESCALATE';
    public const INTENT_ACTION = 'ACTION_QUERY';
    public const INTENT_CATALOG = 'CATALOG_SEARCH';

    private OllamaClient $ollama;

    public function __construct(?OllamaClient $ollama = null)
    {
        $this->ollama = $ollama ?? OllamaClient::fromConfig();
    }

    /**
     * @return array{intent:string,confidence:float,method:string}
     */
    public function classify(string $userMessage): array
    {
        $fast = $this->checkFastPath($userMessage);
        if ($fast !== null) {
            return [
                'intent' => $fast,
                'confidence' => 1.0,
                'method' => 'heuristic',
            ];
        }

        try {
            $systemPrompt = <<<'PROMPT'
Вы — классификатор намерений сообщений в поддержке маркетплейса zakopeyki.kz.
Проанализируйте сообщение пользователя и верните JSON строго следующей структуры:
{
  "intent": "GREETING" | "FAQ_QUERY" | "ACTION_QUERY" | "CATALOG_SEARCH" | "HUMAN_ESCALATE",
  "confidence": 0.0
}

Определения:
- "GREETING": приветствия, прощания, благодарности без вопроса.
- "FAQ_QUERY": правила, доставка, оплата, эскроу, модерация, безопасность.
- "ACTION_QUERY": статус конкретного заказа/объявления с номером (#123).
- "CATALOG_SEARCH": поиск товаров/услуг в каталоге («ищу телефон», «бесплатно», «аукционы»).
- "HUMAN_ESCALATE": живой оператор, мошенничество, агрессия, сложный спор.
PROMPT;

            $response = $this->ollama->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                0.0,
                true
            );

            $parsed = json_decode($response['content'], true);
            if (isset($parsed['intent'], $parsed['confidence'])) {
                $intent = (string) $parsed['intent'];
                $allowed = [
                    self::INTENT_GREETING,
                    self::INTENT_FAQ,
                    self::INTENT_ACTION,
                    self::INTENT_CATALOG,
                    self::INTENT_ESCALATE,
                ];
                if (!in_array($intent, $allowed, true)) {
                    $intent = self::INTENT_FAQ;
                }

                return [
                    'intent' => $intent,
                    'confidence' => max(0.0, min(1.0, (float) $parsed['confidence'])),
                    'method' => 'llm',
                ];
            }
        } catch (\Throwable $e) {
            // fallback
        }

        return [
            'intent' => self::INTENT_FAQ,
            'confidence' => 0.70,
            'method' => 'fallback',
        ];
    }

    private function checkFastPath(string $message): ?string
    {
        $lower = mb_strtolower(trim($message), 'UTF-8');

        $escalateKeywords = [
            'оператор', 'человек', 'живой', 'специалист', 'позови оператора',
            'соедини с человеком', 'жалоба', 'украли', 'мошенник', 'обман', 'полиция',
            'арбитр', 'арбитраж',
        ];
        foreach ($escalateKeywords as $keyword) {
            if (mb_strpos($lower, $keyword) !== false) {
                return self::INTENT_ESCALATE;
            }
        }

        $greetings = [
            'привет', 'здравствуйте', 'здравствуй', 'добрый день', 'добрый вечер',
            'доброе утро', 'салам', 'сәлеметсіз бе', 'сәлем', 'хай', 'hello', 'hi',
        ];
        if (in_array($lower, $greetings, true)) {
            return self::INTENT_GREETING;
        }

        if (preg_match('/\b(заказ|объявлен|тикет|лот)\s*#?\s*\d+/u', $lower)
            || preg_match('/#\d{2,}/u', $lower)) {
            return self::INTENT_ACTION;
        }

        $catalogHints = [
            'ищу', 'найди', 'покажи', 'есть ли', 'бесплатно', 'даром', 'обмен',
            'аукцион', 'услуг', 'курсы', 'б/у', 'телефон', 'ноутбук',
        ];
        $supportHints = [
            'эскроу', 'безопасн', 'возврат', 'доставк', 'модерац', 'заблокир',
            'отклон', 'мошен', 'оплат', 'спор', 'арбитраж', 'пароль', 'верификац',
        ];

        $hasCatalog = false;
        foreach ($catalogHints as $h) {
            if (mb_strpos($lower, $h) !== false) {
                $hasCatalog = true;
                break;
            }
        }
        $hasSupport = false;
        foreach ($supportHints as $h) {
            if (mb_strpos($lower, $h) !== false) {
                $hasSupport = true;
                break;
            }
        }

        if ($hasCatalog && !$hasSupport) {
            return self::INTENT_CATALOG;
        }

        return null;
    }
}
