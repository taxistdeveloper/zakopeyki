<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Лёгкий экстрактор текста из PDF с ToUnicode CMap (без внешних библиотек).
 */
final class PdfTextExtractor
{
    public function extract(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return '';
        }

        $maps = $this->collectToUnicodeMaps($raw);
        $parts = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = $this->inflate($stream);
                if ($decoded === null) {
                    continue;
                }
                // content streams обычно содержат BT/ET и операторы текста
                if (!preg_match('/\bBT\b|\bTj\b|\bTJ\b/', $decoded)) {
                    continue;
                }
                $piece = $this->extractFromContent($decoded, $maps);
                if ($piece !== '') {
                    $parts[] = $piece;
                }
            }
        }

        $text = trim(preg_replace("/[ \t]+/u", ' ', implode("\n", $parts)) ?? '');
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @return list<array<int, string>> */
    private function collectToUnicodeMaps(string $raw): array
    {
        $maps = [];
        if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            return $maps;
        }

        foreach ($matches[1] as $stream) {
            $decoded = $this->inflate($stream);
            if ($decoded === null) {
                continue;
            }
            if (!str_contains($decoded, 'begincmap') && !str_contains($decoded, 'beginbfchar')) {
                continue;
            }
            $map = $this->parseCMap($decoded);
            if ($map !== []) {
                $maps[] = $map;
            }
        }

        return $maps;
    }

    /** @return array<int, string> */
    private function parseCMap(string $cmap): array
    {
        $map = [];

        if (preg_match_all('/beginbfchar\s*(.*?)\s*endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $src = hexdec($pair[1]);
                        $map[$src] = $this->hexToUtf8($pair[2]);
                    }
                }
            }
        }

        if (preg_match_all('/beginbfrange\s*(.*?)\s*endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                // <srcFrom> <srcTo> <dstStart>
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $triples, PREG_SET_ORDER)) {
                    foreach ($triples as $t) {
                        $from = hexdec($t[1]);
                        $to = hexdec($t[2]);
                        $dst = hexdec($t[3]);
                        for ($code = $from; $code <= $to; $code++, $dst++) {
                            $map[$code] = $this->codepointToUtf8($dst);
                        }
                    }
                }
                // <srcFrom> <srcTo> [<dst1> <dst2> ...]
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $block, $arrays, PREG_SET_ORDER)) {
                    foreach ($arrays as $a) {
                        $from = hexdec($a[1]);
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $a[3], $dsts);
                        foreach ($dsts[1] as $i => $hex) {
                            $map[$from + $i] = $this->hexToUtf8($hex);
                        }
                    }
                }
            }
        }

        return $map;
    }

    /** @param list<array<int, string>> $maps */
    private function extractFromContent(string $content, array $maps): string
    {
        $out = [];
        // literal strings: (Hello) Tj
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $content, $m)) {
            foreach ($m[0] as $op) {
                if (preg_match('/^\((.*)\)\s*Tj$/s', $op, $mm)) {
                    $out[] = $this->unescapePdfString($mm[1]);
                }
            }
        }

        // hex strings: <0024> Tj  or arrays TJ
        if (preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $content, $m)) {
            foreach ($m[1] as $hex) {
                $out[] = $this->mapHexString($hex, $maps);
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $m)) {
            foreach ($m[1] as $arr) {
                $chunk = '';
                if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<([0-9A-Fa-f]+)>/s', $arr, $items, PREG_SET_ORDER)) {
                    foreach ($items as $item) {
                        if (isset($item[1]) && $item[1] !== '') {
                            $chunk .= $this->mapHexString($item[1], $maps);
                        } elseif (preg_match('/^\((.*)\)$/s', $item[0], $lit)) {
                            $chunk .= $this->unescapePdfString($lit[1]);
                        }
                    }
                }
                if ($chunk !== '') {
                    $out[] = $chunk;
                }
            }
        }

        // soft line breaks / paragraph hints
        $text = implode('', $out);
        $text = str_replace(["\x00", "\r"], '', $text);
        // heuristic: long runs without spaces get spaced by common PDF word breaks via T*
        return trim($text);
    }

    /** @param list<array<int, string>> $maps */
    private function mapHexString(string $hex, array $maps): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? $hex;
        if ($hex === '' || (strlen($hex) % 2) === 1) {
            return '';
        }

        $out = '';
        $len = strlen($hex);
        // Identity-H / CID: 2-byte units
        if (($len % 4) === 0) {
            for ($i = 0; $i < $len; $i += 4) {
                $code = hexdec(substr($hex, $i, 4));
                $out .= $this->lookupCode($code, $maps) ?? '';
            }
            return $out;
        }

        for ($i = 0; $i < $len; $i += 2) {
            $code = hexdec(substr($hex, $i, 2));
            $out .= $this->lookupCode($code, $maps) ?? '';
        }

        return $out;
    }

    /** @param list<array<int, string>> $maps */
    private function lookupCode(int $code, array $maps): ?string
    {
        foreach ($maps as $map) {
            if (isset($map[$code])) {
                return $map[$code];
            }
        }
        return null;
    }

    private function inflate(string $stream): ?string
    {
        $decoded = @gzuncompress($stream);
        if ($decoded !== false) {
            return $decoded;
        }
        $decoded = @gzinflate($stream);
        if ($decoded !== false) {
            return $decoded;
        }
        // иногда после stream идёт лишний \r
        $trimmed = ltrim($stream, "\r\n");
        if ($trimmed !== $stream) {
            return $this->inflate($trimmed);
        }
        return null;
    }

    private function unescapePdfString(string $s): string
    {
        $s = str_replace(["\\n", "\\r", "\\t", "\\b", "\\f", "\\(", "\\)", "\\\\"], ["\n", "\r", "\t", "\x08", "\x0c", '(', ')', '\\'], $s);
        $s = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $m): string {
            return chr(octdec($m[1]));
        }, $s) ?? $s;
        return $s;
    }

    private function hexToUtf8(string $hex): string
    {
        $hex = preg_replace('/\s+/', '', $hex) ?? $hex;
        if ($hex === '') {
            return '';
        }
        // UTF-16BE code units
        if ((strlen($hex) % 4) === 0) {
            $out = '';
            for ($i = 0; $i < strlen($hex); $i += 4) {
                $out .= $this->codepointToUtf8(hexdec(substr($hex, $i, 4)));
            }
            return $out;
        }
        if ((strlen($hex) % 2) === 0) {
            $bin = hex2bin($hex);
            return $bin !== false ? $bin : '';
        }
        return '';
    }

    private function codepointToUtf8(int $cp): string
    {
        if ($cp <= 0) {
            return '';
        }
        if (function_exists('mb_chr')) {
            $ch = mb_chr($cp, 'UTF-8');
            return is_string($ch) ? $ch : '';
        }
        return html_entity_decode('&#' . $cp . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
