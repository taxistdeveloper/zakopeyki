<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Models\AiSupport;
use App\Services\AI\DTO\AiRequest;

final class ContextManager
{
    public function __construct(private readonly AiSupport $support = new AiSupport())
    {
    }

    /**
     * @return array{
     *   user_id:?int,
     *   role:string,
     *   page:array,
     *   product_id:?int,
     *   order_id:?int,
     *   conversation_id:?int,
     *   history:list<array{role:string,content:string}>,
     *   memories:list<string>
     * }
     */
    public function build(AiRequest $request, string $role, array $memories = []): array
    {
        $maxHistory = max(2, (int) AiConfig::get('performance.max_history_messages', 8));
        $maxHistoryChars = max(500, (int) AiConfig::get('performance.max_history_chars', 4000));
        $maxMsgChars = max(100, (int) AiConfig::get('performance.max_message_chars', 800));
        $maxMemories = max(0, (int) AiConfig::get('performance.max_memory_items', 5));

        $page = $request->pageContext;
        $history = [];
        if ($request->conversationId) {
            $msgs = $this->support->getMessages($request->conversationId, $maxHistory);
            foreach ($msgs as $m) {
                $roleMap = match ($m['sender_type'] ?? '') {
                    'user' => 'user',
                    'ai', 'agent', 'system' => 'assistant',
                    default => 'user',
                };
                $content = $this->truncate((string) $m['message'], $maxMsgChars);
                $history[] = [
                    'role' => $roleMap,
                    'content' => $content,
                ];
            }
            $history = $this->fitHistoryBudget($history, $maxHistoryChars);
        }

        if ($maxMemories > 0 && count($memories) > $maxMemories) {
            $memories = array_slice($memories, 0, $maxMemories);
        }
        $memories = array_map(
            fn ($m) => $this->truncate(is_string($m) ? $m : (string) $m, $maxMsgChars),
            $memories
        );

        return [
            'user_id' => $request->userId,
            'role' => $role,
            'page' => $page,
            'product_id' => isset($page['product_id']) ? (int) $page['product_id'] : null,
            'order_id' => isset($page['order_id']) ? (int) $page['order_id'] : null,
            'conversation_id' => $request->conversationId,
            'history' => $history,
            'memories' => $memories,
        ];
    }

    /**
     * @param list<array{role:string,content:string}> $history
     * @return list<array{role:string,content:string}>
     */
    private function fitHistoryBudget(array $history, int $maxChars): array
    {
        $total = 0;
        foreach ($history as $h) {
            $total += mb_strlen($h['content'], 'UTF-8');
        }
        if ($total <= $maxChars) {
            return $history;
        }

        // Обрезаем с начала (старые сообщения)
        while ($history !== [] && $total > $maxChars) {
            $first = array_shift($history);
            $total -= mb_strlen((string) ($first['content'] ?? ''), 'UTF-8');
        }
        return $history;
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }
}
