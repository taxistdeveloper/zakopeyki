<?php

declare(strict_types=1);

namespace App\Services\AI\Learning;

use App\Core\Database;
use PDO;

/**
 * Пишет события в ai_learning_events (очередь для Evaluation / PromptOptimizer).
 */
final class LearningEventRecorder
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /** @param array<string, mixed> $payload */
    public function record(string $eventType, array $payload): int
    {
        $eventType = trim($eventType);
        if ($eventType === '') {
            throw new \InvalidArgumentException('event_type required');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_learning_events (event_type, payload_json, status) VALUES (?, ?, \'pending\')'
        );
        $stmt->execute([
            mb_substr($eventType, 0, 64, 'UTF-8'),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function recordFeedback(
        int $messageId,
        int $rating,
        ?string $reason = null,
        ?string $comment = null,
        ?int $userId = null,
        ?int $conversationId = null
    ): int {
        return $this->record('feedback', [
            'message_id' => $messageId,
            'rating' => $rating,
            'reason' => $reason,
            'comment' => $comment,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
        ]);
    }
}
