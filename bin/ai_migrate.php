#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Создаёт таблицы AI-поддержки и сидит FAQ (через Models::ensure*).
 * php bin/ai_migrate.php
 */

require __DIR__ . '/bootstrap.php';

use App\Models\AiKnowledge;
use App\Models\AiQueue;
use App\Models\AiSupport;

echo "Creating AI support tables...\n";

new AiSupport();
new AiKnowledge();
new AiQueue();

$count = (new AiKnowledge())->pdo()->query('SELECT COUNT(*) FROM ai_knowledge_base')->fetchColumn();
echo "OK. Knowledge articles: {$count}\n";
