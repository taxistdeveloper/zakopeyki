<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\SpeechToTextInterface;
use App\Services\AI\Core\LanguageDetector;
use App\Services\AI\DTO\SttResult;

/**
 * Mock-провайдер для тестирования STT без внешних бинарников и сети.
 */
final class MockSttProvider implements SpeechToTextInterface
{
    private LanguageDetector $languageDetector;

    /** @var array<string, array{text: string, language?: string, confidence?: float}> */
    private array $cannedResponses = [];

    private bool $available = true;
    private ?string $forcedError = null;

    public function __construct(array $cannedResponses = [])
    {
        $this->cannedResponses = $cannedResponses;
        $this->languageDetector = new LanguageDetector();
    }

    public function providerName(): string
    {
        return 'mock_stt';
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

    public function setCannedResponse(string $key, string $text, ?string $language = null, float $confidence = 0.95): void
    {
        $this->cannedResponses[$key] = [
            'text' => $text,
            'language' => $language,
            'confidence' => $confidence,
        ];
    }

    public function transcribe(string $audioPathOrBase64OrText, ?string $mime = null, array $options = []): SttResult
    {
        $start = microtime(true);
        $requestId = (string) ($options['request_id'] ?? ('stt_mock_' . bin2hex(random_bytes(4))));

        if ($this->forcedError !== null) {
            return SttResult::failure(
                error: $this->forcedError,
                provider: $this->providerName(),
                requestId: $requestId
            );
        }

        if (!$this->available) {
            return SttResult::failure(
                error: 'Mock STT провайдер недоступен.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['provider_offline']
            );
        }

        // Если есть предустановленный ответ
        $basename = basename($audioPathOrBase64OrText);
        $resp = $this->cannedResponses[$basename]
            ?? $this->cannedResponses[$audioPathOrBase64OrText]
            ?? ($this->cannedResponses['*'] ?? null);

        if ($resp !== null) {
            $text = $resp['text'];
            $lang = $resp['language'] ?? $this->languageDetector->detect($text);
            $confidence = $resp['confidence'] ?? 0.95;

            return SttResult::success(
                text: $text,
                language: $lang,
                confidence: $confidence,
                provider: $this->providerName(),
                durationMs: (int) round((microtime(true) - $start) * 1000),
                requestId: $requestId
            );
        }

        // По умолчанию возвращаем тестовый транскрипт
        $defaultText = 'Найди iPhone 15 до трёхсот тысяч тенге в Алматы';
        return SttResult::success(
            text: $defaultText,
            language: 'ru',
            confidence: 0.96,
            provider: $this->providerName(),
            durationMs: 45,
            requestId: $requestId
        );
    }
}

