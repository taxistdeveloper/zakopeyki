<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Database;
use PDO;

/**
 * Метрики AI для admin dashboard.
 */
final class AiMetricsService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function dashboard(int $hours = 24): array
    {
        $hours = max(1, min(720, $hours));

        return [
            'period_hours' => $hours,
            'requests' => $this->count('ai_usage_logs', $hours),
            'errors' => $this->countWhere('ai_usage_logs', "status <> 'ok'", $hours),
            'avg_latency_ms' => $this->avgLatency($hours),
            'intents' => $this->groupCount('ai_usage_logs', 'intent', $hours),
            'tool_calls' => $this->count('ai_tool_calls', $hours),
            'tool_denied' => $this->countWhere('ai_tool_calls', "status = 'denied'", $hours),
            'tool_errors' => $this->countWhere('ai_tool_calls', "status = 'error'", $hours),
            'feedback' => $this->feedbackStats($hours),
            'active_conversations' => $this->activeConversations(),
            'escalated' => $this->escalatedCount(),
            'learning_pending' => $this->safeCount("SELECT COUNT(*) FROM ai_learning_events WHERE status = 'pending'"),
            'models' => $this->activeModels(),
            'prompt' => $this->activePrompt(),
            'generated_at' => date('c'),
        ];
    }

    private function count(string $table, int $hours): int
    {
        return $this->safeCount(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= (NOW() - INTERVAL {$hours} HOUR)"
        );
    }

    private function countWhere(string $table, string $where, int $hours): int
    {
        return $this->safeCount(
            "SELECT COUNT(*) FROM {$table} WHERE {$where} AND created_at >= (NOW() - INTERVAL {$hours} HOUR)"
        );
    }

    private function avgLatency(int $hours): ?int
    {
        try {
            $v = $this->pdo->query(
                "SELECT AVG(latency_ms) FROM ai_usage_logs
                 WHERE latency_ms IS NOT NULL AND created_at >= (NOW() - INTERVAL {$hours} HOUR)"
            )->fetchColumn();
            return $v !== null && $v !== false ? (int) round((float) $v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{name:string,count:int}> */
    private function groupCount(string $table, string $col, int $hours): array
    {
        try {
            $rows = $this->pdo->query(
                "SELECT {$col} AS name, COUNT(*) AS cnt FROM {$table}
                 WHERE created_at >= (NOW() - INTERVAL {$hours} HOUR) AND {$col} IS NOT NULL
                 GROUP BY {$col} ORDER BY cnt DESC LIMIT 12"
            )->fetchAll() ?: [];
            return array_map(static fn ($r) => [
                'name' => (string) ($r['name'] ?? ''),
                'count' => (int) ($r['cnt'] ?? 0),
            ], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{count:int,avg:?float,positive:int,negative:int} */
    private function feedbackStats(int $hours): array
    {
        try {
            $row = $this->pdo->query(
                "SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating,
                        SUM(rating >= 4) AS pos, SUM(rating <= 2) AS neg
                 FROM ai_feedback
                 WHERE created_at >= (NOW() - INTERVAL {$hours} HOUR)"
            )->fetch();
            return [
                'count' => (int) ($row['cnt'] ?? 0),
                'avg' => isset($row['avg_rating']) ? round((float) $row['avg_rating'], 2) : null,
                'positive' => (int) ($row['pos'] ?? 0),
                'negative' => (int) ($row['neg'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['count' => 0, 'avg' => null, 'positive' => 0, 'negative' => 0];
        }
    }

    private function activeConversations(): int
    {
        return $this->safeCount("SELECT COUNT(*) FROM ai_conversations WHERE status = 'ai_active'");
    }

    private function escalatedCount(): int
    {
        return $this->safeCount("SELECT COUNT(*) FROM ai_conversations WHERE status = 'human_escalated'");
    }

    /** @return list<array> */
    private function activeModels(): array
    {
        try {
            return $this->pdo->query(
                'SELECT task_type, provider, model, is_active FROM ai_model_configs ORDER BY task_type'
            )->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{slug:?string,version:?string}|null */
    private function activePrompt(): ?array
    {
        try {
            $row = $this->pdo->query(
                "SELECT p.slug, v.version FROM ai_prompts p
                 JOIN ai_prompt_versions v ON v.prompt_id = p.id AND v.status = 'active'
                 WHERE p.slug = 'assistant_system' LIMIT 1"
            )->fetch();
            return $row ? ['slug' => $row['slug'], 'version' => $row['version']] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeCount(string $sql): int
    {
        try {
            return (int) $this->pdo->query($sql)->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
