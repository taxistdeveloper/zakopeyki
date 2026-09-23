<?php

declare(strict_types=1);

namespace App\Services\AI\Learning;

use App\Core\Database;
use PDO;

/**
 * Создаёт только candidate-версии промпта.
 * Активация — только через admin (rollback / approve). Никакого auto-deploy.
 */
final class PromptOptimizer
{
    private PDO $pdo;
    private EvaluationService $evaluation;

    public function __construct(?PDO $pdo = null, ?EvaluationService $evaluation = null)
    {
        $this->pdo = $pdo ?? Database::connect();
        $this->evaluation = $evaluation ?? new EvaluationService($this->pdo);
    }

    /**
     * Если негатива достаточно — предложить candidate поверх active.
     *
     * @return array{created:bool, version?:string, reason?:string, summary?:array}
     */
    public function maybeProposeCandidate(string $slug = 'assistant_system', int $minNegative = 5): array
    {
        $summary = $this->evaluation->summary(7);
        if ($summary['negative_count'] < $minNegative) {
            return [
                'created' => false,
                'reason' => 'insufficient_negative_feedback',
                'summary' => $summary,
            ];
        }

        $promptId = $this->promptId($slug);
        if ($promptId <= 0) {
            return ['created' => false, 'reason' => 'prompt_not_found', 'summary' => $summary];
        }

        $active = $this->activeContent($promptId);
        if ($active === null || $active === '') {
            return ['created' => false, 'reason' => 'no_active_prompt', 'summary' => $summary];
        }

        // Не плодим кандидатов: один открытый candidate на slug
        $open = $this->pdo->prepare(
            "SELECT id FROM ai_prompt_versions WHERE prompt_id = ? AND status = 'candidate' LIMIT 1"
        );
        $open->execute([$promptId]);
        if ($open->fetch()) {
            return ['created' => false, 'reason' => 'candidate_already_exists', 'summary' => $summary];
        }

        $addon = $this->buildAddon($summary);
        $content = rtrim($active) . "\n\n" . $addon;
        $version = 'cand_' . date('Ymd_His');

        $score = $summary['avg_csat'] !== null
            ? round((float) $summary['avg_csat'] * 100, 2)
            : null;

        $ins = $this->pdo->prepare(
            "INSERT INTO ai_prompt_versions (prompt_id, version, content, status, evaluation_score, author)
             VALUES (?, ?, ?, 'candidate', ?, 'learning_pipeline')"
        );
        $ins->execute([$promptId, $version, $content, $score]);

        return [
            'created' => true,
            'version' => $version,
            'summary' => $summary,
        ];
    }

    /**
     * Admin: candidate → active (предыдущий active → archived).
     */
    public function approveCandidate(string $version, string $slug = 'assistant_system'): array
    {
        $promptId = $this->promptId($slug);
        if ($promptId <= 0) {
            return ['ok' => false, 'error' => 'prompt_not_found'];
        }

        $check = $this->pdo->prepare(
            "SELECT id FROM ai_prompt_versions
             WHERE prompt_id = ? AND version = ? AND status = 'candidate' LIMIT 1"
        );
        $check->execute([$promptId, $version]);
        if (!$check->fetch()) {
            return ['ok' => false, 'error' => 'candidate_not_found'];
        }

        $this->pdo->prepare(
            "UPDATE ai_prompt_versions SET status = 'archived' WHERE prompt_id = ? AND status = 'active'"
        )->execute([$promptId]);
        $this->pdo->prepare(
            "UPDATE ai_prompt_versions SET status = 'active' WHERE prompt_id = ? AND version = ?"
        )->execute([$promptId, $version]);

        return ['ok' => true, 'version' => $version];
    }

    /** @return list<array<string, mixed>> */
    public function listCandidates(string $slug = 'assistant_system'): array
    {
        $promptId = $this->promptId($slug);
        if ($promptId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT version, evaluation_score, created_at, LEFT(content, 200) AS preview
             FROM ai_prompt_versions
             WHERE prompt_id = ? AND status = 'candidate'
             ORDER BY id DESC"
        );
        $stmt->execute([$promptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function promptId(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ai_prompts WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function activeContent(int $promptId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT content FROM ai_prompt_versions WHERE prompt_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$promptId]);
        $c = $stmt->fetchColumn();
        return $c === false ? null : (string) $c;
    }

    /** @param array{top_reasons:list<array{reason:string,count:int}>} $summary */
    private function buildAddon(array $summary): string
    {
        $lines = [
            '---',
            '[Learning candidate — требует approve админа]',
            'Усильте краткость и точность. При отсутствии данных из tools — не выдумывайте.',
            'При ошибках поиска предлагайте уточнить бренд, город и бюджет.',
        ];
        if (!empty($summary['top_reasons'])) {
            $lines[] = 'Частые жалобы пользователей:';
            foreach (array_slice($summary['top_reasons'], 0, 5) as $r) {
                $lines[] = '- ' . $r['reason'] . ' (' . $r['count'] . ')';
            }
        }
        return implode("\n", $lines);
    }
}
