<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Локальный генератор QR-кодов (ISO/IEC 18004) без внешних зависимостей и сервисов.
 * Режим байтов (UTF-8), уровень коррекции M, версии 1–10 (до 213 байт), SVG-вывод.
 *
 * Данные не покидают сервер (в отличие от api.qrserver.com).
 */
final class QrCode
{
    /** version => [ec codewords per block, [[blocks, data codewords], ...]] для уровня M */
    private const BLOCKS_M = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private const REMAINDER_BITS = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

    private const FORMAT_BITS_M = 0;

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];
    private int $size = 0;
    private int $version = 1;

    /** @return list<list<bool>> матрица [row][col], true = тёмный модуль */
    public static function matrix(string $text): array
    {
        $qr = new self();
        $qr->encode($text);
        return $qr->modules;
    }

    public static function svg(string $text, int $pixelSize = 220, string $dark = '#111827', string $light = '#ffffff'): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $quiet = 4;
        $total = $n + 2 * $quiet;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $path .= 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }
        $dark = preg_match('/^#[0-9a-fA-F]{3,8}$/', $dark) ? $dark : '#111827';
        $light = preg_match('/^#[0-9a-fA-F]{3,8}$/', $light) ? $light : '#ffffff';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" width="' . $pixelSize
            . '" height="' . $pixelSize . '" shape-rendering="crispEdges" role="img" aria-label="QR">'
            . '<rect width="100%" height="100%" fill="' . $light . '"/>'
            . '<path d="' . $path . '" fill="' . $dark . '"/></svg>';
    }

    private function encode(string $text): void
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $len = count($bytes);

        $this->version = 0;
        for ($v = 1; $v <= 10; $v++) {
            if ($len <= $this->dataCapacity($v) - ($v < 10 ? 2 : 3)) {
                $this->version = $v;
                break;
            }
        }
        if ($this->version === 0) {
            throw new \InvalidArgumentException('QR: text too long (max ~213 bytes)');
        }
        $this->size = $this->version * 4 + 17;
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        // Поток бит: режим байтов, длина, данные, терминатор, выравнивание, заполнение
        $bits = [];
        $this->appendBits($bits, 0b0100, 4);
        $this->appendBits($bits, $len, $this->version < 10 ? 8 : 16);
        foreach ($bytes as $b) {
            $this->appendBits($bits, $b, 8);
        }
        $capacityBits = $this->dataCapacity($this->version) * 8;
        $this->appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        $this->appendBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $this->appendBits($bits, $pad, 8);
        }
        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $data[] = $byte;
        }

        $codewords = $this->addEccAndInterleave($data);
        $this->drawFunctionPatterns();
        $this->drawCodewords($codewords);

        // Выбор маски с минимальным штрафом
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $p = $this->penalty();
            if ($p < $bestPenalty) {
                $bestPenalty = $p;
                $bestMask = $mask;
            }
            $this->applyMask($mask); // XOR — отмена
        }
        $this->applyMask($bestMask);
        $this->drawFormatBits($bestMask);
    }

    private function dataCapacity(int $v): int
    {
        $n = 0;
        foreach (self::BLOCKS_M[$v][1] as [$blocks, $dc]) {
            $n += $blocks * $dc;
        }
        return $n;
    }

    /** @param list<int> $bits */
    private function appendBits(array &$bits, int $val, int $len): void
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    }

    /** @param list<int> $data @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        [$ecLen, $groups] = self::BLOCKS_M[$this->version];
        $divisor = $this->rsDivisor($ecLen);
        $dataBlocks = [];
        $eccBlocks = [];
        $k = 0;
        foreach ($groups as [$blocks, $dc]) {
            for ($b = 0; $b < $blocks; $b++) {
                $block = array_slice($data, $k, $dc);
                $k += $dc;
                $dataBlocks[] = $block;
                $eccBlocks[] = $this->rsRemainder($block, $divisor);
            }
        }
        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $blk) {
                if ($i < count($blk)) {
                    $out[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($eccBlocks as $blk) {
                $out[] = $blk[$i];
            }
        }
        return $out;
    }

    /** @return list<int> */
    private function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->gfMul($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = $this->gfMul($root, 0x02);
        }
        return $result;
    }

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= $this->gfMul($coef, $factor);
            }
        }
        return $result;
    }

    private function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $pos = self::ALIGN[$this->version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->drawFormatBits(0); // резервирование областей формата
        $this->drawVersion();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $d = max(abs($dx), abs($dy));
                    $this->setFunction($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::FORMAT_BITS_M << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) !== 0;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                    // Оставшиеся (remainder) биты остаются светлыми — как требует стандарт
                }
            }
        }
        unset($total);
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->isFunction[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
                };
                if ($invert) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $dark = 0;

        // Правило 1 (серии ≥5) и правило 3 (шаблон 1:1:3:1:1) — по строкам и столбцам
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $line = '';
                $run = 0;
                $prev = null;
                for ($b = 0; $b < $n; $b++) {
                    $v = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $line .= $v ? '1' : '0';
                    if ($v === $prev) {
                        $run++;
                        if ($run === 5) {
                            $score += 3;
                        } elseif ($run > 5) {
                            $score++;
                        }
                    } else {
                        $run = 1;
                        $prev = $v;
                    }
                }
                $padded = '0000' . $line . '0000';
                $score += 40 * (substr_count($padded, '00001011101') + substr_count($padded, '10111010000'));
            }
        }
        // Правило 2 (блоки 2×2) и подсчёт тёмных
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $dark++;
                }
                if ($y < $n - 1 && $x < $n - 1) {
                    $c = $m[$y][$x];
                    if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        // Правило 4 (баланс тёмных)
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $score + max(0, $k) * 10;
    }
}
