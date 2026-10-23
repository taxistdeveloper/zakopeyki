<?php

declare(strict_types=1);

namespace App\Services\Business;

/**
 * Чистый парсер/валидатор строк импорта без доступа к БД.
 * Поля согласованы с Product::create / формой профиля.
 */
final class CatalogImportParser
{
    /**
     * @return list<array<string, mixed>>|null
     */
    public static function parseCsv(string $contents): ?array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $lines = array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));
        if ($lines === []) {
            return null;
        }

        $header = str_getcsv(array_shift($lines));
        if ($header === false || $header === [null] || $header === []) {
            return null;
        }
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);
        foreach (['title', 'description', 'type', 'category', 'location'] as $col) {
            if (!in_array($col, $header, true)) {
                return null;
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if ($cols === false) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $cols[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public static function parseXml(string $contents): ?array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                return null;
            }
            $rows = [];
            $products = $xml->product ?? null;
            if ($products === null && isset($xml->products)) {
                $products = $xml->products->product ?? null;
            }
            if ($products === null) {
                if (isset($xml->title)) {
                    $products = [$xml];
                } else {
                    return null;
                }
            }
            foreach ($products as $node) {
                $rows[] = [
                    'external_key' => (string) ($node->external_key ?? $node->sku ?? ''),
                    'title' => (string) ($node->title ?? ''),
                    'description' => (string) ($node->description ?? ''),
                    'type' => (string) ($node->type ?? 'used'),
                    'category' => (string) ($node->category ?? 'Разное'),
                    'price' => (string) ($node->price ?? '0'),
                    'location' => (string) ($node->location ?? ''),
                    'quantity' => (string) ($node->quantity ?? '1'),
                    'whatsapp' => (string) ($node->whatsapp ?? ''),
                    'exchange_for' => (string) ($node->exchange_for ?? ''),
                ];
            }

            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok: bool, row?: array<string, mixed>, error?: string}
     */
    public static function normalizeRow(array $row, int $line = 0): array
    {
        unset($line);
        $out = [
            'external_key' => trim((string) ($row['external_key'] ?? $row['sku'] ?? '')),
            'title' => trim((string) ($row['title'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'type' => strtolower(trim((string) ($row['type'] ?? 'used'))),
            'category' => trim((string) ($row['category'] ?? 'Разное')) ?: 'Разное',
            'price' => (int) preg_replace('/\D/', '', (string) ($row['price'] ?? '0')),
            'location' => trim((string) ($row['location'] ?? '')),
            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
            'whatsapp' => trim((string) ($row['whatsapp'] ?? '')) ?: null,
            'exchange_for' => trim((string) ($row['exchange_for'] ?? '')) ?: null,
        ];

        if (!in_array($out['type'], BusinessPackagePolicy::IMPORT_ALLOWED_TYPES, true)) {
            return ['ok' => false, 'error' => t('business.err_import_type')];
        }

        foreach (BusinessPackagePolicy::IMPORT_REQUIRED_FIELDS as $field) {
            if ($out[$field] === '' || $out[$field] === null) {
                return ['ok' => false, 'error' => t('business.err_import_required', ['field' => $field])];
            }
        }

        if (!in_array($out['type'], ['free', 'exchange'], true) && $out['price'] <= 0) {
            return ['ok' => false, 'error' => t('business.err_import_price')];
        }

        if (mb_strlen($out['title']) > 200) {
            $out['title'] = mb_substr($out['title'], 0, 200);
        }

        return ['ok' => true, 'row' => $out];
    }
}
