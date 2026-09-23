<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Product;

/**
 * Спрос по категории/запросу на основе активных объявлений и просмотров.
 */
final class AnalyzeDemandTool extends AbstractTool
{
    public function __construct(private readonly Product $products = new Product())
    {
    }

    public function name(): string
    {
        return 'analyze_demand';
    }

    public function description(): string
    {
        return 'Анализ спроса: число аналогов, медиана цены, просмотры по запросу/категории.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'category' => ['type' => 'string'],
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
        $query = trim((string) ($input['query'] ?? ''));
        $category = trim((string) ($input['category'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));

        if ($query === '' && $category === '') {
            return $this->fail('Укажите товар или категорию для анализа спроса.');
        }

        $rows = $this->products->searchAdvanced([
            'query' => $query !== '' ? $query : null,
            'category' => $category !== '' ? $category : null,
            'location' => $location !== '' ? $location : null,
            'limit' => 50,
        ]);

        $priced = array_values(array_filter($rows, static fn ($r) => (int) ($r['price'] ?? 0) > 0));
        $prices = array_map(static fn ($r) => (int) $r['price'], $priced);
        sort($prices);
        $n = count($prices);
        $median = $n > 0 ? $prices[(int) floor(($n - 1) / 2)] : null;
        $views = 0;
        foreach ($rows as $r) {
            $views += (int) ($r['view_count'] ?? 0);
        }

        $competition = count($rows);
        $demandHint = match (true) {
            $competition === 0 => 'Нет активных аналогов — спрос по каталогу не подтверждён данными.',
            $competition < 5 => 'Мало предложений — ниша относительно свободная, но спрос нужно проверять вниманием к просмотрам.',
            $competition < 20 => 'Умеренная конкуренция.',
            default => 'Высокая конкуренция по активным объявлениям.',
        };

        $recommendations = [];
        if ($median !== null) {
            $recommendations[] = 'Ориентир цены объявлений (медиана): ' . number_format($median, 0, '', ' ') . ' ₸';
        }
        if ($competition >= 20) {
            $recommendations[] = 'Выделите товар фото/описанием и ценой чуть ниже медианы.';
        }
        if ($views > 0 && $competition > 0) {
            $avgViews = (int) round($views / $competition);
            $recommendations[] = 'Средние просмотры на лот в выборке: ~' . $avgViews;
        }
        $recommendations[] = 'Это данные объявлений, не подтверждённых продаж.';

        return $this->ok([
            'query' => $query,
            'category' => $category,
            'location' => $location,
            'active_listings' => $competition,
            'median_listing_price' => $median,
            'price_min' => $n ? $prices[0] : null,
            'price_max' => $n ? $prices[$n - 1] : null,
            'total_views' => $views,
            'demand_hint' => $demandHint,
            'recommendations' => $recommendations,
            'source' => 'mysql.products',
            'data_timestamp' => date('c'),
        ]);
    }
}
