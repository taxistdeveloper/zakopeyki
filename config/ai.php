<?php

declare(strict_types=1);

/**
 * Локальный AI платформы (Ollama) + оркестратор.
 */

/**
 * Булев флаг из окружения: '1'/'true'/'on'/'yes' → true, '0'/'false'/'off'/'no' → false,
 * переменная не задана или пустая → $default.
 */
$envBool = static function (string $name, bool $default): bool {
    $v = getenv($name);
    if ($v === false || trim($v) === '') {
        return $default;
    }
    $parsed = filter_var(trim($v), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
};

/** Первый исполняемый файл из списка кандидатов (для автообнаружения whisper-cli). */
$firstExecutable = static function (array $candidates): string {
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && is_file($c) && is_executable($c)) {
            return $c;
        }
    }
    return '';
};

$whisperModelsDir = dirname(__DIR__) . '/storage/models/whisper';

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
    /** Живой оператор в чате ZAK (пока выключен) */
    'human_operator_enabled' => false,

    /** Платформенный оркестратор (вместо узкого support-only) */
    'orchestrator_enabled' => true,

    /** Настройки голосового ввода и Speech-to-Text (STT) */
    'voice' => [
        'voice_input_enabled' => $envBool('AI_VOICE_ENABLED', true),
        'stt_enabled' => $envBool('AI_STT_ENABLED', true),
        'server_stt_fallback_enabled' => $envBool('AI_SERVER_STT_FALLBACK', true),
        'stt_debug_enabled' => $envBool('AI_STT_DEBUG', false),
        'stt_provider' => getenv('AI_STT_PROVIDER') ?: 'web_speech', // web_speech | server_stt | mock

        /*
         * Серверный STT на whisper.cpp (MIT), полностью локально, аудио не покидает сервер.
         * Модель по умолчанию — ggml-small-q5_1 (мультиязычная, ~190 МБ): base не справляется с казахским.
         * Silero VAD (ggml-silero-v5.1.2) отсекает тишину/шум — без него whisper «галлюцинирует»
         * фразы вроде «Редактор субтитров …» на пустой записи.
         */
        'whisper_cli_path' => getenv('WHISPER_CLI_PATH') ?: $firstExecutable([
            '/usr/local/bin/whisper-cli',
            '/opt/homebrew/bin/whisper-cli',
            '/usr/bin/whisper-cli',
        ]),
        'whisper_model_path' => getenv('WHISPER_MODEL_PATH') ?: $whisperModelsDir . '/ggml-small-q5_1.bin',
        'whisper_vad_model_path' => getenv('WHISPER_VAD_MODEL_PATH') ?: $whisperModelsDir . '/ggml-silero-v5.1.2.bin',
        'whisper_threads' => max(1, (int) (getenv('WHISPER_THREADS') ?: 4)),
        'whisper_timeout_seconds' => max(5, (int) (getenv('WHISPER_TIMEOUT_SECONDS') ?: 30)),
        /** Сколько процессов whisper может работать одновременно (защита CPU от перегрузки). */
        'whisper_max_concurrent' => max(1, (int) (getenv('WHISPER_MAX_CONCURRENT') ?: 2)),
        /** Необязательно: ffmpeg для перекодирования webm/ogg/mp4 → wav (клиент по умолчанию шлёт WAV). */
        'ffmpeg_path' => getenv('FFMPEG_PATH') ?: '',
        /** Язык распознавания: автоопределение whisper путает казахский с немецким, поэтому язык передаётся явно. */
        'stt_supported_languages' => ['ru', 'kk', 'en'],
        'stt_default_language' => 'ru',
        'max_audio_duration_seconds' => 60,
        'min_audio_duration_seconds' => 0.3,
        'max_audio_size_bytes' => 10 * 1024 * 1024, // 10 МБ
        'allowed_formats' => ['webm', 'ogg', 'wav', 'mp4', 'm4a'],
    ],

    /** Настройки озвучивания ответов и Text-to-Speech (TTS) */
    'tts' => [
        'enabled' => $envBool('AI_TTS_ENABLED', true),
        'provider' => getenv('AI_TTS_PROVIDER') ?: 'browser', // browser | edge_neural | mock
        'max_text_chars' => 1500,
        'default_language' => 'ru',
        /*
         * Белый список голосов edge_neural (проверены реальным синтезом 2026-11-06).
         * kk-KZ-DariyaNeural не существует (edge-tts: NoAudioReceived) — не использовать.
         */
        'voices' => [
            'ru' => ['ru-RU-DmitryNeural', 'ru-RU-SvetlanaNeural'],
            'kk' => ['kk-KZ-AigulNeural', 'kk-KZ-DauletNeural'],
            'en' => ['en-US-JennyNeural', 'en-US-GuyNeural'],
        ],
        /** Закрытый аудиокеш (storage/cache/tts): срок хранения файла и срок жизни ссылки. */
        'cache_ttl_seconds' => max(60, (int) (getenv('AI_TTS_CACHE_TTL') ?: 86400)),
        'url_ttl_seconds' => max(30, (int) (getenv('AI_TTS_URL_TTL') ?: 1800)),
        'edge_timeout_seconds' => 20,
    ],

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
