#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Индексация knowledge base → chunks → embeddings.
 * php bin/ai_index_knowledge.php
 */

require __DIR__ . '/bootstrap.php';

use App\Models\AiPlatformSchema;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\RagEngine;

echo "Ensuring AI platform schema...\n";
(new AiPlatformSchema())->ensure();

$rag = new RagEngine(null, new OllamaProvider());
echo "Reindexing knowledge base...\n";

$stats = $rag->reindexAll(static function (int $docId, int $chunkIdx): void {
    echo "  doc #{$docId} chunk {$chunkIdx}\n";
});

echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "Done.\n";
