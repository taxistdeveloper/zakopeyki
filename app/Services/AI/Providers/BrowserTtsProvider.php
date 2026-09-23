<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\TextToSpeechInterface;

/** TTS через браузер (speechSynthesis). Сервер возвращает флаг. */
final class BrowserTtsProvider implements TextToSpeechInterface
{
    public function synthesize(string $text, string $language = 'ru'): array
    {
        return [
            'audio_base64' => null,
            'mime' => null,
            'use_browser' => true,
            'text' => $text,
            'language' => $language,
        ];
    }
}
