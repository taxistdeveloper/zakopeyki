<?php

namespace App\Services\AI;

use App\Models\AiKnowledge;
use App\Models\AiSupport;

class SelfLearningService
{
    private AiSupport $support;
    private AiKnowledge $knowledge;

    public function __construct(?AiSupport $support = null, ?AiKnowledge $knowledge = null)
    {
        $this->support = $support ?? new AiSupport();
        $this->knowledge = $knowledge ?? new AiKnowledge();
    }

    /** @return list<array{user_query:string,operator_response:string}> */
    public function getDynamicFewShots(string $userQuery, int $limit = 2): array
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $userQuery) ?? '';
        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? '');
        if (mb_strlen($cleaned, 'UTF-8') < 4) {
            return [];
        }

        $limit = max(1, min(5, $limit));
        $pdo = $this->support->pdo();

        try {
            $sql = "SELECT user_query, operator_response
                    FROM ai_few_shots
                    WHERE is_approved = 1
                      AND MATCH(user_query, operator_response) AGAINST(? IN BOOLEAN MODE)
                    ORDER BY quality_score DESC
                    LIMIT {$limit}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cleaned]);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows) {
                return $rows;
            }
        } catch (\Throwable $e) {
            // fallback
        }

        $like = '%' . $cleaned . '%';
        $sql = "SELECT user_query, operator_response
                FROM ai_few_shots
                WHERE is_approved = 1 AND (user_query LIKE ? OR operator_response LIKE ?)
                ORDER BY quality_score DESC
                LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll() ?: [];
    }

    public function formatFewShotsForPrompt(array $fewShots): string
    {
        if ($fewShots === []) {
            return '';
        }

        $formatted = ["\nНиже эталонные ответы операторов zakopeyki.kz на похожие вопросы:"];
        foreach ($fewShots as $index => $item) {
            $num = $index + 1;
            $q = (string) ($item['user_query'] ?? '');
            $a = (string) ($item['operator_response'] ?? '');
            $formatted[] = "Пример #{$num}:\nВопрос: {$q}\nИдеальный ответ: {$a}";
        }

        return implode("\n\n", $formatted) . "\n\nИспользуйте стиль этих ответов.\n";
    }

    public function learnFromOperatorResolution(int $conversationId): void
    {
        $messages = $this->support->getMessages($conversationId, 200);
        $lastUserQuery = null;
        $lastOperatorResponse = null;

        foreach ($messages as $msg) {
            if ($msg['sender_type'] === 'user') {
                $lastUserQuery = (string) $msg['message'];
            } elseif ($msg['sender_type'] === 'agent') {
                $lastOperatorResponse = (string) $msg['message'];
            }
        }

        if ($lastUserQuery === null || $lastUserQuery === '' || $lastOperatorResponse === null || $lastOperatorResponse === '') {
            return;
        }

        $pdo = $this->support->pdo();

        $insFew = $pdo->prepare(
            'INSERT INTO ai_few_shots (category, user_query, operator_response, quality_score, is_approved)
             VALUES (\'auto_learned\', ?, ?, 1.00, 1)'
        );
        $insFew->execute([$lastUserQuery, $lastOperatorResponse]);

        $title = 'Решение по запросу: ' . mb_substr($lastUserQuery, 0, 50, 'UTF-8') . '...';
        $this->knowledge->addArticle(
            'auto_learned',
            $title,
            "Вопрос: {$lastUserQuery}\nРешение: {$lastOperatorResponse}",
            mb_substr($lastUserQuery, 0, 200, 'UTF-8'),
            'auto_learned'
        );

        $insDs = $pdo->prepare(
            'INSERT INTO ai_training_datasets
             (conversation_id, system_prompt, user_input, ideal_output, source)
             VALUES (?, ?, ?, ?, \'operator_resolution\')'
        );
        $insDs->execute([
            $conversationId,
            'Вы — саппорт zakopeyki.kz',
            $lastUserQuery,
            $lastOperatorResponse,
        ]);
    }

    public function recordFeedback(int $messageId, int $rating, ?string $comment): void
    {
        $rating = max(1, min(5, $rating));
        $pdo = $this->support->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO ai_feedback (message_id, rating, comment) VALUES (?, ?, ?)'
        );
        $stmt->execute([$messageId, $rating, $comment]);

        if ($rating !== 5) {
            return;
        }

        $aiMsg = $this->support->getMessageById($messageId);
        if (!$aiMsg || $aiMsg['sender_type'] !== 'ai') {
            return;
        }

        $userStmt = $pdo->prepare(
            "SELECT message FROM ai_messages
             WHERE conversation_id = ? AND sender_type = 'user' AND id < ?
             ORDER BY id DESC LIMIT 1"
        );
        $userStmt->execute([(int) $aiMsg['conversation_id'], $messageId]);
        $userMsg = $userStmt->fetch();
        if (!$userMsg) {
            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO ai_training_datasets
             (conversation_id, system_prompt, user_input, ideal_output, source)
             VALUES (?, ?, ?, ?, \'high_csat_ai\')'
        );
        $ins->execute([
            (int) $aiMsg['conversation_id'],
            'Вы — саппорт zakopeyki.kz',
            (string) $userMsg['message'],
            (string) $aiMsg['message'],
        ]);
    }

    public function exportJsonlDataset(): string
    {
        $pdo = $this->support->pdo();
        $rows = $pdo->query(
            'SELECT id, system_prompt, user_input, ideal_output
             FROM ai_training_datasets WHERE is_exported = 0'
        )->fetchAll() ?: [];

        if ($rows === []) {
            return '';
        }

        $lines = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            $item = [
                'messages' => [
                    ['role' => 'system', 'content' => $row['system_prompt']],
                    ['role' => 'user', 'content' => $row['user_input']],
                    ['role' => 'assistant', 'content' => $row['ideal_output']],
                ],
            ];
            $lines[] = json_encode($item, JSON_UNESCAPED_UNICODE);
        }

        if ($ids) {
            $in = implode(',', $ids);
            $pdo->exec("UPDATE ai_training_datasets SET is_exported = 1 WHERE id IN ({$in})");
        }

        return implode("\n", $lines);
    }
}
