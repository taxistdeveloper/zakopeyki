<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\TtsResult;

interface TextToSpeechInterface
{
    /**
     * Преобразует текст ответа Zak в нормализованный результат озвучивания (TTS).
     *
     * @param string $text Исходный текст ответа Zak
     * @param string $language Код языка ('ru', 'kk', 'en')
     * @param array<string, mixed> $options Дополнительные параметры (voice, request_id, rate и т.д.)
     * @return TtsResult|array{audio_base64:?string, mime:?string, use_browser:bool, text:string, clean_text?:string, language?:string}
     */
    public function synthesize(string $text, string $language = 'ru', array $options = []): TtsResult|array;

    /**
     * Внутренний идентификатор провайдера (e.g. 'browser', 'mock', 'server_tts').
     */
    public function providerName(): string;

    /**
     * Проверка доступности механизма TTS в текущем окружении.
     */
    public function isAvailable(): bool;
}
