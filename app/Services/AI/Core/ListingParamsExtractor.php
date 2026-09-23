<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

/**
 * Извлечение полей объявления из естественной фразы.
 */
final class ListingParamsExtractor
{
    /**
     * @return array<string, mixed>
     */
    public function extract(string $message): array
    {
        $params = [];
        $lower = mb_strtolower($message, 'UTF-8');

        if (preg_match('/(iphone|айфон)\s*(15|15\s*pro|14|13|12|11)?/ui', $message, $m)) {
            $params['brand'] = 'Apple';
            $params['model'] = 'iPhone' . (!empty($m[2]) ? ' ' . trim(preg_replace('/\s+/', ' ', $m[2]) ?? '') : '');
            $params['title'] = $params['model'];
            $params['category'] = 'Электроника / Телефоны';
            $params['type'] = 'used';
        }

        if (preg_match('/(\d+)\s*(гб|gb|гиг)/ui', $message, $m)) {
            $params['storage'] = $m[1] . ' ГБ';
        }

        if (preg_match('/батаре[яи]\s*(\d+)\s*%/ui', $message, $m)) {
            $params['battery'] = $m[1] . '%';
        }

        if (preg_match('/(отличн|хорош|новое|новый|идеальн|норм)/u', $lower, $m)) {
            $params['condition'] = match (true) {
                str_contains($m[1], 'нов') => 'новое',
                str_contains($m[1], 'отлич') || str_contains($m[1], 'идеаль') => 'отличное',
                str_contains($m[1], 'хорош') => 'хорошее',
                default => 'нормальное',
            };
        }

        // цена: явные маркеры или «N тысяч/к»
        if (preg_match('/(?:цена|за|прода[юём]\s+за)\s*(\d[\d\s]*)\s*(тысяч|тыс\.?|к|k)?/ui', $lower, $m)
            || preg_match('/(\d[\d\s]*)\s*(тысяч|тыс\.?)\b/ui', $lower, $m)
        ) {
            if (!preg_match('/до\s+' . preg_quote(trim($m[1]), '/') . '/u', $lower)) {
                $num = (int) preg_replace('/\s+/', '', $m[1]);
                $mul = $m[2] ?? '';
                if ($mul !== '' && preg_match('/тысяч|тыс|к|k/ui', $mul)) {
                    $num *= 1000;
                }
                if ($num >= 1000) {
                    $params['price'] = $num;
                }
            }
        }

        $cities = ['алматы' => 'Алматы', 'астана' => 'Астана', 'караганда' => 'Караганда'];
        foreach ($cities as $needle => $label) {
            if (mb_strpos($lower, $needle) !== false) {
                $params['location'] = $label;
                break;
            }
        }

        return $params;
    }
}
