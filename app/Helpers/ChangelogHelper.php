<?php

namespace App\Helpers;

class ChangelogHelper
{
    public static function path(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'changelog.json';
    }

    /**
     * @return array{version: string, date: string, items: list<string>}|null
     */
    public static function load(): ?array
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $version = trim((string) ($data['version'] ?? ''));
        $items = $data['items'] ?? [];
        if ($version === '' || !is_array($items) || $items === []) {
            return null;
        }

        $clean = [];
        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        if ($clean === []) {
            return null;
        }

        return [
            'version' => $version,
            'date' => (string) ($data['date'] ?? ''),
            'items' => array_values($clean),
        ];
    }
}
