<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

use App\Helpers\ProductHelper;
use App\Services\AI\Core\AiConfig;

/**
 * Кэшированный индекс категорий каталога для AI (маппинг query → category).
 */
final class CategoryIndex
{
    private AiCache $cache;

    public function __construct(?AiCache $cache = null)
    {
        $this->cache = $cache ?? new AiCache();
    }

    /**
     * @return list<string> flat parent / child labels
     */
    public function flatLabels(): array
    {
        $ttl = (int) AiConfig::get('performance.cache_categories_ttl', 3600);
        $cached = $this->cache->remember('categories:flat_v1', $ttl, static function () {
            $out = [];
            foreach (ProductHelper::PRODUCT_CATEGORY_TREE as $parent => $children) {
                $out[] = (string) $parent;
                foreach ($children as $child) {
                    $out[] = (string) $parent . ' / ' . (string) $child;
                    $out[] = (string) $child;
                }
            }
            return array_values(array_unique($out));
        });

        return is_array($cached) ? $cached : [];
    }

    /**
     * Подобрать category path по свободному тексту (best effort).
     */
    public function match(?string $query): ?string
    {
        if ($query === null) {
            return null;
        }
        $q = mb_strtolower(trim($query), 'UTF-8');
        if ($q === '' || mb_strlen($q, 'UTF-8') < 2) {
            return null;
        }

        $best = null;
        $bestLen = 0;
        foreach ($this->flatLabels() as $label) {
            $l = mb_strtolower($label, 'UTF-8');
            if ($l === $q) {
                return $label;
            }
            if (str_contains($q, $l) || str_contains($l, $q)) {
                $len = mb_strlen($l, 'UTF-8');
                if ($len > $bestLen) {
                    $best = $label;
                    $bestLen = $len;
                }
            }
        }
        return $best;
    }
}
