<?php

declare(strict_types=1);

namespace App\Services\AI\Memory;

/**
 * Решает: стоит ли запоминать фрагмент пользовательского контекста.
 */
final class MemoryScorer
{
    /**
     * @return array{should_store:bool,importance:float,type:string,content:?string,reason:string}
     */
    public function score(string $userMessage, string $intent, array $parameters = []): array
    {
        $lower = mb_strtolower(trim($userMessage), 'UTF-8');

        // Явная просьба запомнить
        if (preg_match('/(запомни|remember|есте сақта)/u', $lower)) {
            $content = trim(preg_replace('/^(запомни|remember|есте сақта)[:\s]*/ui', '', $userMessage) ?? $userMessage);
            return [
                'should_store' => $content !== '',
                'importance' => 0.9,
                'type' => 'preference',
                'content' => $content !== '' ? $content : null,
                'reason' => 'explicit',
            ];
        }

        // Стабильные предпочтения из поиска
        if ($intent === 'SEARCH_PRODUCT') {
            $parts = [];
            if (!empty($parameters['max_price'])) {
                $parts[] = 'Обычно ищет товары до ' . (int) $parameters['max_price'] . ' ₸';
            }
            if (!empty($parameters['location'])) {
                $parts[] = 'Предпочитает регион: ' . $parameters['location'];
            }
            if (!empty($parameters['brand'])) {
                $parts[] = 'Интересуется брендом: ' . $parameters['brand'];
            } elseif (!empty($parameters['category'])) {
                $parts[] = 'Интересуется категорией: ' . $parameters['category'];
            } elseif (!empty($parameters['query']) && mb_strlen((string) $parameters['query'], 'UTF-8') >= 3) {
                $parts[] = 'Интересуется: ' . $parameters['query'];
            }

            if ($parts !== []) {
                return [
                    'should_store' => true,
                    'importance' => 0.62,
                    'type' => 'preference',
                    'content' => implode('. ', $parts) . '.',
                    'reason' => 'search_preference',
                ];
            }
        }

        // Не хранить приветствия, одноразовые статусы, жалобы
        if (in_array($intent, ['GREETING', 'HUMAN_ESCALATE', 'UNKNOWN'], true)) {
            return [
                'should_store' => false,
                'importance' => 0.0,
                'type' => 'user',
                'content' => null,
                'reason' => 'ephemeral_intent',
            ];
        }

        return [
            'should_store' => false,
            'importance' => 0.0,
            'type' => 'user',
            'content' => null,
            'reason' => 'not_useful',
        ];
    }
}
