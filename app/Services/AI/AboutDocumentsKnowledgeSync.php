<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Helpers\AboutDocumentsHelper;
use App\Models\AiKnowledge;
use App\Services\AI\Core\AiConfig;

/**
 * Синхронизирует PDF из «О нас» в ai_knowledge_base, чтобы ZAK отвечал по документам.
 */
final class AboutDocumentsKnowledgeSync
{
    private const SOURCE_PREFIX = 'about_pdf:';
    private const CHUNK_CHARS = 4500;

    public function __construct(
        private readonly AiKnowledge $knowledge = new AiKnowledge(),
        private readonly PdfTextExtractor $extractor = new PdfTextExtractor(),
    ) {
    }

    /**
     * @return array{synced:bool, documents:int, articles:int, reason?:string}
     */
    public function syncIfNeeded(bool $force = false): array
    {
        $docs = AboutDocumentsHelper::all();
        if ($docs === []) {
            return ['synced' => false, 'documents' => 0, 'articles' => 0, 'reason' => 'no_docs'];
        }

        $stamp = $this->docsStamp($docs);
        $cacheFile = $this->cachePath();
        if (!$force && is_file($cacheFile)) {
            $prev = trim((string) @file_get_contents($cacheFile));
            if ($prev !== '' && hash_equals($prev, $stamp)) {
                return ['synced' => false, 'documents' => count($docs), 'articles' => 0, 'reason' => 'fresh'];
            }
        }

        $articles = $this->sync($docs);
        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, $stamp);

        return ['synced' => true, 'documents' => count($docs), 'articles' => $articles];
    }

    /**
     * @param list<array{slug:string,title:string,file:string,url:string}> $docs
     */
    public function sync(array $docs): int
    {
        $pdo = $this->knowledge->pdo();
        $this->ensureSchema($pdo);
        $pdo->prepare("DELETE FROM ai_knowledge_base WHERE source LIKE ?")->execute([self::SOURCE_PREFIX . '%']);

        $inserted = 0;
        $nextId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ai_knowledge_base')->fetchColumn();
        $ins = $pdo->prepare(
            'INSERT INTO ai_knowledge_base (id, category, title, content, keywords, is_active, source)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );

        foreach ($docs as $doc) {
            $path = AboutDocumentsHelper::directory() . DIRECTORY_SEPARATOR . $doc['file'];
            if (!is_file($path)) {
                continue;
            }

            // Оферта/политика в PDF часто с кастомными шрифтами — берём канонический текст из lang
            $fallback = $this->langFallbackFor($doc['title']);
            if ($fallback !== '') {
                $text = $fallback;
            } else {
                $text = $this->normalizeText($this->extractor->extract($path));
                if (!$this->isReadableText($text)) {
                    $text = '';
                }
            }
            if (mb_strlen($text, 'UTF-8') < 40) {
                $text = 'Документ платформы Zakopeyki.kz: «' . $doc['title'] . '». '
                    . 'Откройте полный PDF: ' . $doc['url'];
            }

            $keywords = $this->keywordsFor($doc['title'], $doc['file']);
            $chunks = $this->splitChunks($text);
            $total = count($chunks);

            foreach ($chunks as $i => $chunk) {
                $title = $total > 1
                    ? ($doc['title'] . ' (' . ($i + 1) . '/' . $total . ')')
                    : $doc['title'];
                $body = $chunk;
                if ($i === 0) {
                    $body .= "\n\nСсылка на PDF: " . $doc['url'];
                }
                $source = self::SOURCE_PREFIX . $doc['slug'] . ($total > 1 ? ':' . ($i + 1) : '');
                $nextId++;
                $ins->execute([$nextId, 'about_docs', $title, $body, $keywords, $source]);
                $inserted++;
            }
        }

        return $inserted;
    }

    private function ensureSchema(\PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE ai_knowledge_base MODIFY source VARCHAR(96) NOT NULL DEFAULT \'seed\'');
        } catch (\Throwable) {
        }

        try {
            $col = $pdo->query("SHOW COLUMNS FROM ai_knowledge_base LIKE 'id'")->fetch(\PDO::FETCH_ASSOC);
            $extra = (string) ($col['Extra'] ?? '');
            if (!str_contains(strtolower($extra), 'auto_increment')) {
                // чиним схему без PRIMARY KEY / AUTO_INCREMENT (встречается на старых инсталляциях)
                try {
                    $pdo->exec('ALTER TABLE ai_knowledge_base ADD PRIMARY KEY (id)');
                } catch (\Throwable) {
                }
                $pdo->exec('ALTER TABLE ai_knowledge_base MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT');
            }
        } catch (\Throwable) {
        }

        try {
            $indexes = $pdo->query('SHOW INDEX FROM ai_knowledge_base')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $hasFt = false;
            foreach ($indexes as $idx) {
                if (($idx['Key_name'] ?? '') === 'ft_ai_knowledge_search') {
                    $hasFt = true;
                    break;
                }
            }
            if (!$hasFt) {
                $pdo->exec('ALTER TABLE ai_knowledge_base ADD FULLTEXT KEY ft_ai_knowledge_search (title, content, keywords)');
            }
        } catch (\Throwable) {
        }
    }

    /** @param list<array{slug:string,title:string,file:string,url:string}> $docs */
    private function docsStamp(array $docs): string
    {
        $parts = [];
        foreach ($docs as $doc) {
            $path = AboutDocumentsHelper::directory() . DIRECTORY_SEPARATOR . $doc['file'];
            $mtime = is_file($path) ? (string) filemtime($path) : '0';
            $size = is_file($path) ? (string) filesize($path) : '0';
            $parts[] = $doc['slug'] . ':' . $mtime . ':' . $size;
        }
        sort($parts);
        return hash('sha256', implode('|', $parts));
    }

    private function cachePath(): string
    {
        return dirname(__DIR__, 3) . '/storage/cache/ai/about_docs_sync.sha';
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /** @return list<string> */
    private function splitChunks(string $text): array
    {
        $max = self::CHUNK_CHARS;
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return [$text];
        }

        $chunks = [];
        $len = mb_strlen($text, 'UTF-8');
        $offset = 0;
        while ($offset < $len) {
            $slice = mb_substr($text, $offset, $max, 'UTF-8');
            // стараемся резать по абзацу/предложению
            if ($offset + $max < $len) {
                $break = max(
                    mb_strrpos($slice, "\n\n", 0, 'UTF-8') ?: 0,
                    mb_strrpos($slice, ". ", 0, 'UTF-8') ?: 0
                );
                if ($break > (int) ($max * 0.5)) {
                    $slice = mb_substr($slice, 0, $break + 1, 'UTF-8');
                }
            }
            $slice = trim($slice);
            if ($slice !== '') {
                $chunks[] = $slice;
            }
            $offset += max(1, mb_strlen($slice, 'UTF-8'));
        }

        return $chunks !== [] ? $chunks : [$text];
    }

    private function keywordsFor(string $title, string $file): string
    {
        $base = mb_strtolower($title . ' ' . pathinfo($file, PATHINFO_FILENAME), 'UTF-8');
        $extra = [
            'инструкция' => 'инструкция, пользователь, как пользоваться, гайд, руководство, инструкция пользователя',
            'мануал' => 'мануал, продавец, как продать, размещение, мануал продавца',
            'памятка' => 'памятка, продавец, кратко, памятка продавца',
            'оферта' => 'оферта, соглашение, договор, правила, пользовательское, публичная оферта',
            'конфиденциаль' => 'политика, конфиденциальность, персональные данные, пд, политика конфиденциальности',
            'privacy' => 'privacy, personal data',
        ];
        $parts = [$base, 'документ, документы, about, о нас, pdf'];
        foreach ($extra as $needle => $kw) {
            if (mb_strpos($base, $needle) !== false) {
                $parts[] = $kw;
            }
        }
        return implode(', ', $parts);
    }

    private function isReadableText(string $text): bool
    {
        if (mb_strlen($text, 'UTF-8') < 80) {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $text) ?: 0;
        $cyr = preg_match_all('/\p{Cyrillic}/u', $text) ?: 0;
        if ($letters < 40) {
            return false;
        }
        return ($cyr / $letters) >= 0.35;
    }

    private function langFallbackFor(string $title): string
    {
        if (!function_exists('t')) {
            return '';
        }
        $lower = mb_strtolower($title, 'UTF-8');
        if (str_contains($lower, 'оферт')) {
            return $this->composeLangDoc('offer', [
                's1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10',
            ]);
        }
        if (str_contains($lower, 'конфиденциаль') || str_contains($lower, 'персональн')) {
            return $this->composeLangDoc('privacy', [
                's1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9',
            ]);
        }
        return '';
    }

    /** @param list<string> $sectionIds */
    private function composeLangDoc(string $docKey, array $sectionIds): string
    {
        $parts = [
            (string) t($docKey . '.title'),
            (string) t($docKey . '.lead'),
            (string) t($docKey . '.effective'),
        ];
        foreach ($sectionIds as $id) {
            $parts[] = (string) t($docKey . '.' . $id . '_title');
            $parts[] = (string) t($docKey . '.' . $id . '_body');
        }
        $parts[] = (string) t($docKey . '.contacts_title');
        $parts[] = (string) t($docKey . '.contacts_body');
        $text = trim(implode("\n\n", array_filter($parts, static fn ($p) => trim($p) !== '')));
        return $this->normalizeText($text);
    }
}
