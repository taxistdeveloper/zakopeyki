<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface TextToSpeechInterface
{
    /**
     * @return array{audio_base64:?string,mime:?string,use_browser:bool,text:string}
     */
    public function synthesize(string $text, string $language = 'ru'): array;
}
