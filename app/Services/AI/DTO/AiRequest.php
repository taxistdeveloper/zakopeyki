<?php

declare(strict_types=1);

namespace App\Services\AI\DTO;

readonly class AiRequest
{
    /**
     * @param array<string, mixed> $pageContext
     * @param list<string> $imagePaths
     */
    public function __construct(
        public string $requestId,
        public string $message,
        public ?int $userId,
        public ?int $conversationId,
        public ?string $guestToken,
        public array $pageContext = [],
        public ?string $languageHint = null,
        public array $imagePaths = [],
        public ?string $audioTranscript = null,
    ) {
    }

    public function effectiveMessage(): string
    {
        if ($this->audioTranscript !== null && trim($this->audioTranscript) !== '') {
            return trim($this->audioTranscript);
        }
        return trim($this->message);
    }
}
