<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

final class LanguageDetector
{
    public function detect(string $text, ?string $hint = null): string
    {
        if ($hint !== null && in_array($hint, ['ru', 'kk', 'en'], true)) {
            return $hint;
        }

        $sample = mb_strtolower(trim($text), 'UTF-8');
        if ($sample === '') {
            return 'ru';
        }

        // Казахские специфичные буквы
        if (preg_match('/[әғқңөұүһі]/u', $sample)) {
            return 'kk';
        }

        // Латиница без кириллицы → en
        $hasCyr = (bool) preg_match('/\p{Cyrillic}/u', $sample);
        $hasLat = (bool) preg_match('/[a-z]/i', $sample);
        if ($hasLat && !$hasCyr) {
            return 'en';
        }

        // Казахские маркеры словами
        $kkWords = ['сәлем', 'қалай', 'рақмет', 'изде', 'тап', 'жеткізу', 'тауар'];
        foreach ($kkWords as $w) {
            if (mb_strpos($sample, $w) !== false) {
                return 'kk';
            }
        }

        return 'ru';
    }
}
