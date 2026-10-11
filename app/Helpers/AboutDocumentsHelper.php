<?php

namespace App\Helpers;

use App\Core\Lang;

/**
 * PDF-документы из app/Views/about/documents/.
 * Казахские версии лежат в подкаталоге kaz/ и показываются при языке kk.
 */
class AboutDocumentsHelper
{
    /** @var array<string, array<string, string>> */
    private const FILES = [
        'ru' => [
            'user_guide' => 'Инструкция пользователя.pdf',
            'seller_memo' => 'Краткая памятка продавца.pdf',
            'seller_manual' => 'Мануал продавца.pdf',
            'privacy' => 'Политика конфиденциальности и обработки персональных данных.pdf',
            'offer' => 'Публичная оферта.pdf',
        ],
        'kk' => [
            'user_guide' => 'Пайдаланушы_нұсқаулығы_Zakopeyki.pdf',
            'seller_memo' => 'Қысқаша_сатушы_жадынамасы_Zakopeyki.kz.pdf',
            'seller_manual' => 'Сатушының_нұсқаулығы_Zakopeyki.pdf',
            'privacy' => 'Zakopeyki_Privacy_Policy_KZ_01-10-2026.pdf',
            'offer' => 'Zakopeyki_Public_Offer_KZ_01-10-2026.pdf',
        ],
    ];

    /** @var array<string, string> */
    private const TITLE_KEYS = [
        'user_guide' => 'about.doc_user_guide',
        'seller_memo' => 'about.doc_seller_memo',
        'seller_manual' => 'about.doc_seller_manual',
        'privacy' => 'about.doc_privacy',
        'offer' => 'about.doc_offer',
    ];

    public static function directory(?string $locale = null): string
    {
        $base = dirname(__DIR__) . '/Views/about/documents';
        if (self::locale($locale) === 'kk') {
            return $base . DIRECTORY_SEPARATOR . 'kaz';
        }

        return $base;
    }

    /**
     * @return list<array{slug: string, title: string, file: string, url: string}>
     */
    public static function all(?string $locale = null): array
    {
        $locale = self::locale($locale);
        $dir = self::directory($locale);
        if (!is_dir($dir)) {
            return $locale === 'ru' ? [] : self::all('ru');
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
        $catalog = self::FILES[$locale] ?? self::FILES['ru'];
        $rank = array_flip(array_keys(self::FILES['ru']));
        $keyByFile = array_flip($catalog);

        usort($files, static function (string $a, string $b) use ($keyByFile, $rank): int {
            $fa = basename($a);
            $fb = basename($b);
            $oa = $rank[$keyByFile[$fa] ?? ''] ?? 100;
            $ob = $rank[$keyByFile[$fb] ?? ''] ?? 100;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcmp($fa, $fb);
        });

        $docs = [];
        foreach ($files as $path) {
            $file = basename($path);
            if ($file === '' || !is_file($path)) {
                continue;
            }
            $slug = self::slugFor($file);
            $docs[] = [
                'slug' => $slug,
                'title' => self::titleFor($file, $locale),
                'file' => $file,
                'url' => ProductHelper::url('/about/document/' . rawurlencode($slug)),
            ];
        }

        return $docs;
    }

    /**
     * @return array{slug: string, title: string, file: string, path: string}|null
     */
    public static function find(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-f0-9]{12}$/', $slug)) {
            return null;
        }

        foreach (['ru', 'kk'] as $locale) {
            foreach (self::all($locale) as $doc) {
                if ($doc['slug'] !== $slug) {
                    continue;
                }
                $path = self::directory($locale) . DIRECTORY_SEPARATOR . $doc['file'];
                if (!is_file($path)) {
                    return null;
                }

                return [
                    'slug' => $doc['slug'],
                    'title' => $doc['title'],
                    'file' => $doc['file'],
                    'path' => $path,
                ];
            }
        }

        return null;
    }

    public static function slugFor(string $file): string
    {
        return substr(hash('sha256', $file), 0, 12);
    }

    public static function slugForKey(string $key, ?string $locale = null): string
    {
        $file = self::fileFor($key, $locale);
        return $file === null ? '' : self::slugFor($file);
    }

    public static function fileFor(string $key, ?string $locale = null): ?string
    {
        $locale = self::locale($locale);
        $file = self::FILES[$locale][$key] ?? self::FILES['ru'][$key] ?? null;
        if ($file === null) {
            return null;
        }
        $path = self::directory($locale) . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            return $file;
        }
        $fallback = self::FILES['ru'][$key] ?? null;
        if ($fallback !== null && is_file(self::directory('ru') . DIRECTORY_SEPARATOR . $fallback)) {
            return $fallback;
        }

        return $file;
    }

    public static function titleFor(string $file, ?string $locale = null): string
    {
        $locale = self::locale($locale);
        $key = self::keyForFile($file, $locale);
        if ($key !== null && isset(self::TITLE_KEYS[$key]) && function_exists('t')) {
            $title = t(self::TITLE_KEYS[$key]);
            if ($title !== '' && $title !== self::TITLE_KEYS[$key]) {
                return $title;
            }
        }

        $title = pathinfo($file, PATHINFO_FILENAME);
        $title = str_replace('_', ' ', $title);

        return $title !== '' ? $title : $file;
    }

    private static function keyForFile(string $file, string $locale): ?string
    {
        $catalog = self::FILES[$locale] ?? [];
        $key = array_search($file, $catalog, true);
        if (is_string($key)) {
            return $key;
        }
        foreach (self::FILES as $map) {
            $found = array_search($file, $map, true);
            if (is_string($found)) {
                return $found;
            }
        }

        return null;
    }

    private static function locale(?string $locale): string
    {
        if ($locale === 'kk' || $locale === 'ru') {
            return $locale;
        }
        if (class_exists(Lang::class) && Lang::current() === 'kk') {
            return 'kk';
        }

        return 'ru';
    }
}
