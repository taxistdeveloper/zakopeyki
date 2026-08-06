<?php

namespace App\Helpers;

/**
 * PDF-документы из app/Views/about/documents/.
 */
class AboutDocumentsHelper
{
    public static function directory(): string
    {
        return dirname(__DIR__) . '/Views/about/documents';
    }

    /**
     * @return list<array{slug: string, title: string, file: string, url: string}>
     */
    public static function all(): array
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
        sort($files, SORT_STRING);

        $docs = [];
        foreach ($files as $path) {
            $file = basename($path);
            if ($file === '' || !is_file($path)) {
                continue;
            }
            $slug = self::slugFor($file);
            $docs[] = [
                'slug' => $slug,
                'title' => self::titleFor($file),
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

        foreach (self::all() as $doc) {
            if ($doc['slug'] !== $slug) {
                continue;
            }
            $path = self::directory() . DIRECTORY_SEPARATOR . $doc['file'];
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

        return null;
    }

    public static function slugFor(string $file): string
    {
        return substr(hash('sha256', $file), 0, 12);
    }

    public static function titleFor(string $file): string
    {
        $title = pathinfo($file, PATHINFO_FILENAME);
        return $title !== '' ? $title : $file;
    }
}
