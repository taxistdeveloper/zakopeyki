<?php

declare(strict_types=1);

namespace App\Services\AI\Learning;

use App\Core\Database;
use App\Services\AI\SelfLearningService;
use PDO;

/**
 * Обрабатывает pending ai_learning_events.
 */
final class LearningPipeline
{
    private PDO $pdo;
    private EvaluationService $evaluation;
    private PromptOptimizer $optimizer;
    private SelfLearningService $selfLearning;

    public function __construct(
        ?PDO $pdo = null,
        ?EvaluationService $evaluation = null,
        ?PromptOptimizer $optimizer = null,
        ?SelfLearningService $selfLearning = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->evaluation = $evaluation ?? new EvaluationService($this->pdo);
        $this->optimizer = $optimizer ?? new PromptOptimizer($this->pdo, $this->evaluation);
        $this->selfLearning = $selfLearning ?? new SelfLearningService();
    }

    /**
     * @return array{processed:int, failed:int, skipped:int, candidates:list<string>}
     */
    public function processPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, event_type, payload_json FROM ai_learning_events
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $candidates = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $type = (string) $row['event_type'];
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload)) {
                $this->mark($id, 'failed');
                $failed++;
                continue;
            }

            try {
                $result = $this->handle($type, $payload);
                if (($result['status'] ?? '') === 'skipped') {
                    $this->mark($id, 'skipped');
                    $skipped++;
                } else {
                    $this->mark($id, 'processed');
                    $processed++;
                    if (!empty($result['candidate_version'])) {
                        $candidates[] = (string) $result['candidate_version'];
                    }
                }
            } catch (\Throwable $e) {
                $this->mark($id, 'failed');
                $failed++;
            }
        }

        // После пачки feedback — попробовать candidate (без auto-activate)
        $opt = $this->optimizer->maybeProposeCandidate();
        if (!empty($opt['created']) && !empty($opt['version'])) {
            $candidates[] = (string) $opt['version'];
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'candidates' => array_values(array_unique($candidates)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string, candidate_version?:string}
     */
    private function handle(string $type, array $payload): array
    {
        return match ($type) {
            'feedback' => $this->handleFeedback($payload),
            'operator_resolution' => $this->handleOperator($payload),
            'propose_prompt' => $this->handlePropose(),
            default => ['status' => 'skipped'],
        };
    }

    /** @param array<string, mixed> $payload */
    private function handleFeedback(array $payload): array
    {
        $this->evaluation->evaluateFeedback($payload);
        return ['status' => 'processed'];
    }

    /** @param array<string, mixed> $payload */
    private function handleOperator(array $payload): array
    {
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            return ['status' => 'skipped'];
        }
        $this->selfLearning->learnFromOperatorResolution($conversationId);
        return ['status' => 'processed'];
    }

    private function handlePropose(): array
    {
        $opt = $this->optimizer->maybeProposeCandidate();
        if (!empty($opt['created'])) {
            return [
                'status' => 'processed',
                'candidate_version' => (string) $opt['version'],
            ];
        }
        return ['status' => 'skipped'];
    }

    private function mark(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_learning_events SET status = ?, processed_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$status, $id]);
    }
}
