<?php

declare(strict_types=1);

/**
 * AI Platform migrations (идемпотентно через Models).
 * php bin/ai_migrate.php
 */

require __DIR__ . '/bootstrap.php';

use App\Models\AiKnowledge;
use App\Models\AiPlatformSchema;
use App\Models\AiQueue;
use App\Models\AiSupport;

echo "Creating AI support tables...\n";

new AiSupport();
new AiKnowledge();
new AiQueue();

echo "Creating AI platform tables...\n";
(new AiPlatformSchema())->ensure();

$count = (new AiKnowledge())->pdo()->query('SELECT COUNT(*) FROM ai_knowledge_base')->fetchColumn();
echo "OK. Knowledge articles: {$count}\n";
echo "AI platform schema ready.\n";
