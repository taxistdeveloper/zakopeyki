<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\SpeechToTextInterface;
use InvalidArgumentException;

/**
 * Принимает уже распознанный текст с клиента (Web Speech API).
 * Для server-side audio — расширяется Whisper/Ollama отдельно.
 */
final class ClientTranscriptSttProvider implements SpeechToTextInterface
{
    public function transcribe(string $audioPathOrBase64, ?string $mime = null): array
    {
        $text = trim($audioPathOrBase64);
        if ($text === '' || str_starts_with($text, 'data:') || is_file($text)) {
            throw new InvalidArgumentException(
                'Server STT для сырого аудио не настроен. Передайте transcript с клиента (Web Speech API).'
            );
        }

        return [
            'text' => $text,
            'language' => null,
            'confidence' => 1.0,
        ];
    }
}
