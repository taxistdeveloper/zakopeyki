<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\TextToSpeechInterface;
use App\Services\AI\DTO\TtsResult;
use App\Services\AI\Support\SpeechTextNormalizer;

/**
 * Mock-провайдер синтеза речи для тестирования.
 */
final class MockTtsProvider implements TextToSpeechInterface
{
    private SpeechTextNormalizer $normalizer;
    private bool $available = true;
    private ?string $forcedError = null;
    /** @var array<string, string> */
    private array $cannedAudio = [];

    public function __construct(?SpeechTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new SpeechTextNormalizer();
    }

    public function providerName(): string
    {
        return 'mock';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function setForcedError(?string $error): void
    {
        $this->forcedError = $error;
    }

    public function setCannedAudio(string $key, string $base64Audio): void
    {
        $this->cannedAudio[$key] = $base64Audio;
    }

    public function synthesize(string $text, string $language = 'ru', array $options = []): TtsResult
    {
        $requestId = (string) ($options['request_id'] ?? ('tts_mock_' . bin2hex(random_bytes(4))));

        if ($this->forcedError !== null) {
            return TtsResult::failure(
                error: $this->forcedError,
                provider: $this->providerName(),
                requestId: $requestId
            );
        }

        if (!$this->available) {
            return TtsResult::failure(
                error: 'Mock TTS провайдер недоступен.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['provider_offline']
            );
        }

        $cleanText = $this->normalizer->normalize($text, $language);
        if ($cleanText === '') {
            return TtsResult::failure(
                error: 'Пустой текст для озвучивания.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['empty_text']
            );
        }

        $audio = $this->cannedAudio[$text] ?? ($this->cannedAudio['*'] ?? null);

        return TtsResult::success(
            cleanText: $cleanText,
            originalText: $text,
            language: $language,
            voice: $options['voice'] ?? 'mock_voice',
            provider: $this->providerName(),
            useBrowser: $audio === null,
            audioBase64: $audio,
            audioUrl: $audio !== null ? 'mock://audio/' . $requestId : null,
            mime: 'audio/mp3',
            durationMs: 120,
            requestId: $requestId
        );
    }
}
