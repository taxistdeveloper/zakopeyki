<?php

declare(strict_types=1);

namespace App\Services\AI\DTO;

readonly class AiResponse
{
    /**
     * @param list<array> $products
     * @param list<array> $suggestions
     * @param list<array> $actions
     * @param array<string, mixed> $data
     * @param list<string> $citations
     */
    public function __construct(
        public string $responseType,
        public string $message,
        public array $products = [],
        public array $suggestions = [],
        public array $actions = [],
        public array $data = [],
        public array $citations = [],
        public float $confidence = 0.0,
        public string $intent = 'UNKNOWN',
        public string $action = 'replied',
        public ?int $aiMessageId = null,
        public ?string $language = null,
        public string $requestId = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'ok' => true,
            'response_type' => $this->responseType,
            'reply' => $this->message,
            'message' => $this->message,
            'products' => $this->products,
            'suggestions' => $this->suggestions,
            'actions' => $this->actions,
            'data' => $this->data,
            'citations' => $this->citations,
            'confidence' => $this->confidence,
            'intent' => $this->intent,
            'action' => $this->action,
            'ai_message_id' => $this->aiMessageId,
            'language' => $this->language,
            'request_id' => $this->requestId,
        ];
    }
}
