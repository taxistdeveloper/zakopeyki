<?php

/**
 * Локальный AI поддержки (Ollama).
 * На MAMP по умолчанию sync — ответ в том же HTTP-запросе.
 * Для продакшена: process_mode = async + php bin/ai_worker.php
 */
return [
    'enabled' => true,
    'ollama_url' => getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434',
    'model' => getenv('OLLAMA_MODEL') ?: 'qwen2.5:7b-instruct',
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
];
