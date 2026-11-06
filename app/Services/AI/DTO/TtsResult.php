<?php

declare(strict_types=1);

namespace App\Services\AI\DTO;

use ArrayAccess;
use JsonSerializable;

/**
 * Нормализованный результат синтеза речи (Text-to-Speech).
 *
 * @implements ArrayAccess<string, mixed>
 */
final class TtsResult implements JsonSerializable, ArrayAccess
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $ok = true,
        public readonly string $cleanText = '',
        public readonly string $originalText = '',
        public readonly string $language = 'ru',
        public readonly ?string $voice = null,
        public readonly string $provider = 'browser',
        public readonly bool $useBrowser = true,
        public readonly ?string $audioBase64 = null,
        public readonly ?string $audioUrl = null,
        public readonly ?string $mime = null,
        public readonly int $durationMs = 0,
        public readonly string $requestId = '',
        public readonly array $warnings = [],
        public readonly ?string $error = null,
    ) {
    }

    public static function estimateDurationMs(string $text, int $wordsPerMinute = 150): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $count = is_array($words) ? count($words) : 0;
        if ($count === 0) {
            return 0;
        }
        return (int) round(($count / max(1, $wordsPerMinute)) * 60 * 1000);
    }

    public static function success(
        string $cleanText,
        string $originalText = '',
        string $language = 'ru',
        ?string $voice = null,
        string $provider = 'browser',
        bool $useBrowser = true,
        ?string $audioBase64 = null,
        ?string $audioUrl = null,
        ?string $mime = null,
        int $durationMs = 0,
        string $requestId = '',
        array $warnings = []
    ): self {
        return new self(
            ok: true,
            cleanText: trim($cleanText),
            originalText: $originalText !== '' ? $originalText : $cleanText,
            language: strtolower(trim($language)),
            voice: $voice,
            provider: $provider,
            useBrowser: $useBrowser,
            audioBase64: $audioBase64,
            audioUrl: $audioUrl,
            mime: $mime,
            durationMs: max(0, $durationMs),
            requestId: $requestId !== '' ? $requestId : 'tts_' . bin2hex(random_bytes(6)),
            warnings: $warnings,
            error: null
        );
    }

    public static function failure(
        string $error,
        string $provider = 'browser',
        string $requestId = '',
        array $warnings = [],
        int $durationMs = 0
    ): self {
        return new self(
            ok: false,
            cleanText: '',
            originalText: '',
            language: 'ru',
            voice: null,
            provider: $provider,
            useBrowser: false,
            audioBase64: null,
            audioUrl: null,
            mime: null,
            durationMs: max(0, $durationMs),
            requestId: $requestId !== '' ? $requestId : 'tts_' . bin2hex(random_bytes(6)),
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
            'clean_text' => $this->cleanText,
            'text' => $this->cleanText, // backward compatibility
            'original_text' => $this->originalText,
            'language' => $this->language,
            'voice' => $this->voice,
            'provider' => $this->provider,
            'use_browser' => $this->useBrowser,
            'audio_base64' => $this->audioBase64,
            'audio_url' => $this->audioUrl,
            'mime' => $this->mime,
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
