<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\TextToSpeechInterface;
use App\Services\AI\DTO\TtsResult;
use App\Services\AI\Support\SpeechTextNormalizer;

/**
 * Провайдер синтеза речи через браузерный Web Speech Synthesis API.
 * Осуществляет серверную очистку разметки Markdown, нормализацию чисел и валют.
 */
final class BrowserTtsProvider implements TextToSpeechInterface
{
    private SpeechTextNormalizer $normalizer;
    private int $maxChars;

    public function __construct(?SpeechTextNormalizer $normalizer = null, ?int $maxChars = null)
    {
        $this->maxChars = $maxChars ?? 1500;
        $this->normalizer = $normalizer ?? new SpeechTextNormalizer($this->maxChars);
    }

    public function providerName(): string
    {
        return 'browser';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function synthesize(string $text, string $language = 'ru', array $options = []): TtsResult
    {
        $cleanText = $this->normalizer->normalize($text, $language);
        $requestId = (string) ($options['request_id'] ?? ('tts_' . bin2hex(random_bytes(6))));
        $voice = isset($options['voice']) && is_string($options['voice']) ? $options['voice'] : null;

        if ($cleanText === '') {
            return TtsResult::failure(
                error: 'Пустой текст для озвучивания.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['empty_text']
            );
        }

        $warnings = [];
        if (mb_strlen($text, 'UTF-8') > $this->maxChars) {
            $warnings[] = 'text_truncated_to_max_chars';
        }

        $durationMs = TtsResult::estimateDurationMs($cleanText);

        return TtsResult::success(
            cleanText: $cleanText,
            originalText: $text,
            language: $language,
            voice: $voice,
            provider: $this->providerName(),
            useBrowser: true,
            audioBase64: null,
            audioUrl: null,
            mime: 'audio/speech-synthesis',
            durationMs: $durationMs,
            requestId: $requestId,
            warnings: $warnings
        );
    }
}
