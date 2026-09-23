<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;

/**
 * Рекомендация цены по реальным объявлениям каталога.
 * Не подменяет цену продажи; явно указывает ограничения данных.
 */
final class PriceRecommendationService
{
    public function __construct(private readonly Product $products = new Product())
    {
    }

    /**
     * @param array{
     *   query?:string,
     *   brand?:string,
     *   model?:string,
     *   category?:string,
     *   type?:string,
     *   location?:string,
     *   condition?:string
     * } $criteria
     * @return array{
     *   recommended_price: ?int,
     *   price_range: array{min:?int,max:?int},
     *   confidence: float,
     *   factors: list<string>,
     *   sample_size: int,
     *   samples: list<array>,
     *   data_timestamp: string,
     *   limitation: ?string,
     *   note: string
     * }
     */
    public function recommend(array $criteria): array
    {
        $queryParts = array_filter([
            $criteria['brand'] ?? null,
            $criteria['model'] ?? null,
            $criteria['query'] ?? null,
        ]);
        $query = trim(implode(' ', $queryParts));

        $rows = $this->products->searchAdvanced([
            'query' => $query !== '' ? $query : null,
            'category' => $criteria['category'] ?? null,
            'type' => $criteria['type'] ?? null,
            'location' => $criteria['location'] ?? null,
            'limit' => 40,
        ]);

        // Только платные лоты с ценой > 0
        $priced = array_values(array_filter($rows, static fn (array $r) => (int) ($r['price'] ?? 0) > 0));
        $prices = array_map(static fn (array $r) => (int) $r['price'], $priced);
        sort($prices);

        $factors = [];
        $limitation = null;
        $confidence = 0.0;
        $recommended = null;
        $min = null;
        $max = null;

        if ($prices === []) {
            $limitation = 'Нет активных объявлений с ценой по этим критериям. Рекомендация недоступна.';
            $factors[] = 'sample_size=0';
        } else {
            $n = count($prices);
            $min = $prices[0];
            $max = $prices[$n - 1];
            $mid = (int) round($this->percentile($prices, 0.5));
            $recommended = $mid;
            $factors[] = "sample_size={$n}";
            $factors[] = 'metric=median_listing_price';
            $factors[] = 'source=active_listings';
            if (!empty($criteria['location'])) {
                $factors[] = 'location_filter=' . $criteria['location'];
            }
            $confidence = min(0.9, 0.35 + ($n * 0.05));
            if ($n < 3) {
                $limitation = 'Мало аналогов (' . $n . '). Диапазон ориентировочный; реальных продаж в выборке нет.';
                $confidence = min($confidence, 0.45);
            } else {
                $limitation = 'Это цены объявлений, а не подтверждённых продаж. Фактическая цена сделки может отличаться.';
            }
        }

        $samples = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'price' => (int) $r['price'],
                'location' => (string) ($r['location'] ?? ''),
            ];
        }, array_slice($priced, 0, 8));

        return [
            'recommended_price' => $recommended,
            'price_range' => ['min' => $min, 'max' => $max],
            'confidence' => round($confidence, 2),
            'factors' => $factors,
            'sample_size' => count($prices),
            'samples' => $samples,
            'data_timestamp' => date('c'),
            'limitation' => $limitation,
            'note' => 'recommended_price основан на медиане цен активных объявлений, не на истории продаж.',
        ];
    }

    /** @param list<int> $sorted */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $idx = ($n - 1) * $p;
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        if ($lo === $hi) {
            return (float) $sorted[$lo];
        }
        $w = $idx - $lo;
        return $sorted[$lo] * (1 - $w) + $sorted[$hi] * $w;
    }
}
