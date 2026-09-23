<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Helpers\ProductHelper;
use App\Models\Product;
use App\Services\AI\Support\CategoryIndex;

final class SearchProductsTool extends AbstractTool
{
    public function __construct(
        private readonly Product $products = new Product(),
        private readonly CategoryIndex $categories = new CategoryIndex(),
    ) {
    }

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Поиск реальных объявлений в каталоге zakopeyki по запросу, цене, городу, типу.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'max_price' => ['type' => 'integer'],
                'min_price' => ['type' => 'integer'],
                'location' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'default' => 8],
            ],
        ];
    }

    public function requiredPermission(): string
    {
        return 'guest';
    }

    public function execute(array $input, array $ctx): array
    {
        $limit = max(1, min(24, (int) ($input['limit'] ?? 8)));
        $category = isset($input['category']) && is_string($input['category'])
            ? trim($input['category']) : '';
        if ($category !== '' && in_array($category, ['smartphone', 'laptop'], true)) {
            $category = '';
        }
        if ($category === '' && !empty($input['query'])) {
            $matched = $this->categories->match((string) $input['query']);
            if ($matched !== null && !str_contains(mb_strtolower((string) $input['query'], 'UTF-8'), 'iphone')) {
                // Не форсируем category на брендовые запросы — LIKE по title надёжнее
                if (!preg_match('/iphone|samsung|xiaomi|macbook|airpods/ui', (string) $input['query'])) {
                    $category = $matched;
                }
            }
        }

        $filters = [
            'query' => isset($input['query']) ? trim((string) $input['query']) : null,
            'type' => isset($input['type']) && $input['type'] !== '' ? (string) $input['type'] : null,
            'category' => $category !== '' ? $category : null,
            'min_price' => isset($input['min_price']) ? (int) $input['min_price'] : null,
            'max_price' => isset($input['max_price']) ? (int) $input['max_price'] : null,
            'location' => isset($input['location']) ? trim((string) $input['location']) : null,
            'limit' => $limit,
        ];

        // Для category smartphone/laptop ищем по query (модель), category в БД текстовая на русском
        $rows = $this->products->searchAdvanced($filters);

        $serialized = array_map(static function (array $item): array {
            $out = [
                'id' => (int) $item['id'],
                'title' => (string) $item['title'],
                'price' => ProductHelper::formatPrice($item),
                'price_raw' => (int) ($item['price'] ?? 0),
                'type' => (string) $item['type'],
                'type_label' => ProductHelper::label((string) $item['type']),
                'location' => (string) ($item['location'] ?? ''),
                'url' => ProductHelper::url('/product/' . (int) $item['id']),
                'image' => ProductHelper::imageUrl($item),
                'category' => (string) ($item['category'] ?? ''),
            ];
            if (($item['type'] ?? '') === 'exchange' && !empty($item['exchange_for'])) {
                $out['exchange_for'] = (string) $item['exchange_for'];
            }
            return $out;
        }, $rows);

        return $this->ok([
            'products' => $serialized,
            'count' => count($serialized),
            'filters_applied' => array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
            'source' => 'mysql.products',
            'data_timestamp' => date('c'),
        ]);
    }
}
