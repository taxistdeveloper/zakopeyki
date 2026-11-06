<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\SttResult;

interface SpeechToTextInterface
{
    /**
     * Преобразует аудио (файл, base64) или входящий клиентский transcript в нормализованный результат.
     *
     * @param string $audioPathOrBase64OrText Путь к аудиофайлу, base64/raw или текст с клиента
     * @param string|null $mime MIME-тип аудио (если применимо)
     * @param array<string, mixed> $options Дополнительные параметры (request_id, language_hint, timeout_s и т.д.)
     * @return SttResult|array{text:string, language:?string, confidence:?float, ok?:bool, provider?:string, duration_ms?:int, request_id?:string, warnings?:list<string>}
     */
    public function transcribe(string $audioPathOrBase64OrText, ?string $mime = null, array $options = []): SttResult|array;

    /**
     * Внутренний идентификатор провайдера (e.g. 'web_speech', 'whisper', 'mock').
     */
    public function providerName(): string;

    /**
     * Проверка доступности провайдера в текущем окружении.
     */
    public function isAvailable(): bool;
}
