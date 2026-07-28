<?php

namespace App\Services\AI;

use App\Models\AiKnowledge;

class RagEngine
{
    private AiKnowledge $knowledge;

    public function __construct(?AiKnowledge $knowledge = null)
    {
        $this->knowledge = $knowledge ?? new AiKnowledge();
    }

    /** @return list<array> */
    public function searchContext(string $userQuery, ?int $limit = null): array
    {
        $cfg = $this->config();
        $limit = $limit ?? (int) ($cfg['rag_limit'] ?? 3);
        return $this->knowledge->search($userQuery, $limit);
    }

    public function formatContextForPrompt(array $articles): string
    {
        if ($articles === []) {
            return 'Справочная информация не найдена.';
        }

        $formatted = [];
        foreach ($articles as $index => $article) {
            $num = $index + 1;
            $title = (string) ($article['title'] ?? '');
            $content = (string) ($article['content'] ?? '');
            $formatted[] = "[Статья #{$num}: {$title}]\n{$content}";
        }

        return implode("\n\n---\n\n", $formatted);
    }

    private function config(): array
    {
        static $cfg;
        if ($cfg === null) {
            $path = dirname(__DIR__, 3) . '/config/ai.php';
            $cfg = is_file($path) ? require $path : [];
        }
        return $cfg;
    }
}
