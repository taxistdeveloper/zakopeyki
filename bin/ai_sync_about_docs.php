#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Синхронизация PDF из «О нас» в базу знаний AI.
 * php bin/ai_sync_about_docs.php
 */

require __DIR__ . '/bootstrap.php';

use App\Services\AI\AboutDocumentsKnowledgeSync;
use App\Services\AI\RagEngine;

echo "Syncing About documents into AI knowledge base...\n";
$sync = new AboutDocumentsKnowledgeSync();
$result = $sync->syncIfNeeded(true);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

(new RagEngine())->bustSearchCache();
echo "Done.\n";
