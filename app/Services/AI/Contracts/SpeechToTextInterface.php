<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface SpeechToTextInterface
{
    /**
     * @return array{text:string,language:?string,confidence:float}
     */
    public function transcribe(string $audioPathOrBase64, ?string $mime = null): array;
}
