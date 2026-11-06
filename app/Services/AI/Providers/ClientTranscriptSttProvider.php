<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\SpeechToTextInterface;
use App\Services\AI\Core\LanguageDetector;
use App\Services\AI\DTO\SttResult;

/**
 * Нормализатор транскрипта, полученного от клиентского Web Speech API.
 */
final class ClientTranscriptSttProvider implements SpeechToTextInterface
{
    private LanguageDetector $languageDetector;

    public function __construct(?LanguageDetector $languageDetector = null)
    {
        $this->languageDetector = $languageDetector ?? new LanguageDetector();
    }

    public function providerName(): string
    {
        return 'web_speech';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param string $audioPathOrBase64OrText Текст транскрипта с клиента
     * @param string|null $mime Не используется для текстового транскрипта
     * @param array<string, mixed> $options ['language_hint' => 'ru|kk|en', 'confidence' => float, 'request_id' => string, 'duration_ms' => int]
     */
    public function transcribe(string $audioPathOrBase64OrText, ?string $mime = null, array $options = []): SttResult
    {
        $start = microtime(true);
        $text = trim($audioPathOrBase64OrText);
        $requestId = (string) ($options['request_id'] ?? ('stt_' . bin2hex(random_bytes(6))));
        $warnings = [];

        // Проверка на передачу сырых данных вместо текста
        if (str_starts_with($text, 'data:') || is_file($text)) {
            return SttResult::failure(
                error: 'Для сырого аудио используйте ServerAudioSttProvider.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['raw_audio_passed_to_client_transcript_provider']
            );
        }

        // Проверка на пустой результат / тишину
        if ($text === '') {
            return SttResult::failure(
                error: 'Тишина или неразборчивая речь. Повторите запрос или воспользуйтесь текстовым вводом.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['silence_detected']
            );
        }

        // Удаление лишних пробелов, не изменяя смысл
        $normalizedText = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // Определение языка (если клиент передал hint, учитываем его)
        $lang = isset($options['language_hint']) && is_string($options['language_hint']) && $options['language_hint'] !== ''
            ? strtolower(trim($options['language_hint']))
            : null;

        if ($lang === null || !in_array($lang, ['ru', 'kk', 'en'], true)) {
            $lang = $this->languageDetector->detect($normalizedText);
        }

        // Доверительный коэффициент (confidence)
        $confidence = isset($options['confidence']) && is_numeric($options['confidence'])
            ? (float) $options['confidence']
            : null;

        if ($confidence !== null && $confidence < 0.45) {
            $warnings[] = 'low_confidence_recognition';
        }

        if (mb_strlen($normalizedText, 'UTF-8') < 2) {
            $warnings[] = 'very_short_transcript';
        }

        $durationMs = isset($options['duration_ms']) && is_numeric($options['duration_ms'])
            ? (int) $options['duration_ms']
            : (int) round((microtime(true) - $start) * 1000);

        return SttResult::success(
            text: $normalizedText,
            language: $lang,
            confidence: $confidence,
            provider: $this->providerName(),
            durationMs: $durationMs,
            requestId: $requestId,
            warnings: $warnings
        );
    }
}
