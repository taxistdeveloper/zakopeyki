<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\PriceRecommendationService;

final class SuggestPriceTool extends AbstractTool
{
    public function __construct(private readonly PriceRecommendationService $prices = new PriceRecommendationService())
    {
    }

    public function name(): string
    {
        return 'suggest_price';
    }

    public function description(): string
    {
        return 'Рекомендация цены по медиане активных объявлений-аналогов.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'brand' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'location' => ['type' => 'string'],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'guest';
    }

    public function execute(array $input, array $ctx): array
    {
        $rec = $this->prices->recommend($input);
        return $this->ok($rec);
    }
}
