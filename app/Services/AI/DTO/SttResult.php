<?php

declare(strict_types=1);

namespace App\Services\AI\DTO;

use ArrayAccess;
use JsonSerializable;

/**
 * Нормализованный результат распознавания речи (Speech-to-Text).
 *
 * @implements ArrayAccess<string, mixed>
 */
final class SttResult implements JsonSerializable, ArrayAccess
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $recognizedText,
        public readonly ?string $language = null,
        public readonly ?float $confidence = null,
        public readonly string $provider = 'unknown',
        public readonly int $durationMs = 0,
        public readonly string $requestId = '',
        public readonly array $warnings = [],
        public readonly ?string $error = null,
    ) {
    }

    public static function success(
        string $text,
        ?string $language = null,
        ?float $confidence = null,
        string $provider = 'web_speech',
        int $durationMs = 0,
        string $requestId = '',
        array $warnings = []
    ): self {
        return new self(
            ok: true,
            recognizedText: trim($text),
            language: $language,
            confidence: $confidence !== null ? round(max(0.0, min(1.0, $confidence)), 4) : null,
            provider: $provider,
            durationMs: max(0, $durationMs),
            requestId: $requestId !== '' ? $requestId : 'stt_' . bin2hex(random_bytes(6)),
            warnings: $warnings,
            error: null
        );
    }

    public static function failure(
        string $error,
        string $provider = 'unknown',
        string $requestId = '',
        array $warnings = [],
        int $durationMs = 0
    ): self {
        return new self(
            ok: false,
            recognizedText: '',
            language: null,
            confidence: null,
            provider: $provider,
            durationMs: max(0, $durationMs),
            requestId: $requestId !== '' ? $requestId : 'stt_' . bin2hex(random_bytes(6)),
            warnings: $warnings,
            error: $error
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'recognized_text' => $this->recognizedText,
            'text' => $this->recognizedText, // совместимость со старым форматом SpeechToTextInterface
            'language' => $this->language,
            'confidence' => $this->confidence,
            'provider' => $this->provider,
            'duration_ms' => $this->durationMs,
            'request_id' => $this->requestId,
            'warnings' => $this->warnings,
            'error' => $this->error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Read-only DTO
    }

    public function offsetUnset(mixed $offset): void
    {
        // Read-only DTO
    }
}

