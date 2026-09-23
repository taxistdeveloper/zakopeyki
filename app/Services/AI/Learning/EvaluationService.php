<?php

declare(strict_types=1);

namespace App\Services\AI\Learning;

use App\Core\Database;
use PDO;

/**
 * Метрики качества ответов → ai_evaluations.
 * Не меняет промпты; только пишет scores.
 */
final class EvaluationService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{metric:string,score:float}>
     */
    public function evaluateFeedback(array $payload): array
    {
        $rating = (int) ($payload['rating'] ?? 0);
        $messageId = isset($payload['message_id']) ? (int) $payload['message_id'] : null;
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : null;
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        if ($rating < 1 || $rating > 5) {
            return [];
        }

        // Нормализуем 1–5 → 0..1
        $csat = round(($rating - 1) / 4, 4);
        $results = [
            ['metric' => 'csat', 'score' => $csat],
        ];

        if ($rating <= 2) {
            $results[] = ['metric' => 'negative_feedback', 'score' => 1.0];
            if ($reason !== null && $reason !== '') {
                $results[] = [
                    'metric' => 'negative_reason:' . mb_substr($reason, 0, 40, 'UTF-8'),
                    'score' => 1.0,
                ];
            }
        } elseif ($rating >= 4) {
            $results[] = ['metric' => 'positive_feedback', 'score' => 1.0];
        }

        foreach ($results as $row) {
            $this->write($requestId, $messageId, $row['metric'], $row['score'], [
                'rating' => $rating,
                'reason' => $reason,
                'comment' => $payload['comment'] ?? null,
            ]);
        }

        return $results;
    }

    /**
     * Агрегат негативных оценок за период (для PromptOptimizer).
     *
     * @return array{negative_count:int, positive_count:int, avg_csat:?float, top_reasons:list<array{reason:string,count:int}>}
     */
    public function summary(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $neg = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ai_evaluations
             WHERE metric = 'negative_feedback'
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        )->fetchColumn();
        $pos = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM ai_evaluations
             WHERE metric = 'positive_feedback'
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        )->fetchColumn();

        $avg = $this->pdo->query(
            "SELECT AVG(score) FROM ai_evaluations
             WHERE metric = 'csat'
               AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        )->fetchColumn();

        $reasons = [];
        try {
            $stmt = $this->pdo->query(
                "SELECT metric, COUNT(*) AS c FROM ai_evaluations
                 WHERE metric LIKE 'negative_reason:%'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 GROUP BY metric
                 ORDER BY c DESC
                 LIMIT 10"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $reasons[] = [
                    'reason' => str_replace('negative_reason:', '', (string) $row['metric']),
                    'count' => (int) $row['c'],
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return [
            'negative_count' => $neg,
            'positive_count' => $pos,
            'avg_csat' => $avg !== null && $avg !== false ? round((float) $avg, 4) : null,
            'top_reasons' => $reasons,
        ];
    }

    /** @param array<string, mixed> $meta */
    private function write(?string $requestId, ?int $messageId, string $metric, float $score, array $meta): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_evaluations (request_id, message_id, metric, score, meta_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $requestId,
            $messageId,
            mb_substr($metric, 0, 64, 'UTF-8'),
            $score,
            json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
