<?php

declare(strict_types=1);

/**
 * Локальный AI платформы (Ollama) + оркестратор.
 */
return [
    'enabled' => true,
    'ollama_url' => getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434',
    'model' => getenv('OLLAMA_MODEL') ?: 'qwen2.5:7b-instruct',
    'vision_model' => getenv('OLLAMA_VISION_MODEL') ?: 'llava',
    'embedding_model' => getenv('OLLAMA_EMBED_MODEL') ?: 'nomic-embed-text',
    'timeout' => 60,
    'temperature' => 0.1,
    'num_predict' => 512,
    /** sync | async */
    'process_mode' => 'sync',
    'confidence_threshold' => 0.70,
    'rag_limit' => 3,
    'few_shot_limit' => 2,
    'max_message_length' => 1000,
    'escalate_on_empty_rag' => true,

    /** Платформенный оркестратор (вместо узкого support-only) */
    'orchestrator_enabled' => true,

    'rate_limit' => [
        'per_minute' => 20,
        'per_hour' => 200,
    ],

    'memory' => [
        'max_user_memories' => 50,
        'min_importance' => 0.55,
        'ttl_days_episodic' => 90,
    ],

    'retention' => [
        'messages_days' => 365,
        'audit_days' => 180,
        'learning_events_days' => 365,
    ],

    'vector' => [
        'enabled' => true,
        'top_k' => 5,
        'min_score' => 0.35,
    ],

    'confirmation_ttl_seconds' => 900,

    'performance' => [
        /** Vision / тяжёлые image-запросы класть в очередь (нужен ai_worker при async) */
        'vision_async' => (bool) (getenv('AI_VISION_ASYNC') ?: false),
        'max_history_messages' => 8,
        'max_history_chars' => 4000,
        'max_message_chars' => 800,
        'max_memory_items' => 5,
        'max_rag_chars_per_chunk' => 1200,
        'cache_kb_ttl' => 120,
        'cache_categories_ttl' => 3600,
        'cache_embed_ttl' => 86400,
    ],

    'redis' => [
        'enabled' => (bool) (getenv('AI_REDIS') ?: false),
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'prefix' => 'zakopeyki:ai:',
    ],
];
