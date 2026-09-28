<?php

namespace App\Helpers;

/** Reads container duration for story videos without an external binary. */
class VideoProbe
{
    public static function durationSeconds(string $path): ?float
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'mov', 'm4v'], true)) {
            return self::isoDuration($path);
        }
        if ($ext === 'webm') {
            return self::webmDuration($path);
        }

        return null;
    }

    private static function isoDuration(string $path): ?float
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size < 16) {
            fclose($fh);
            return null;
        }

        $seconds = self::findDuration($fh, 0, (int) $size);
        fclose($fh);

        return $seconds;
    }

    /** @param resource $fh */
    private static function findDuration($fh, int $start, int $end, int $depth = 0): ?float
    {
        if ($depth > 8 || $end - $start < 8) {
            return null;
        }

        $best = null;
        $pos = $start;
        while ($pos + 8 <= $end) {
            fseek($fh, $pos);
            $header = fread($fh, 8);
            if (!is_string($header) || strlen($header) < 8) {
                return $best;
            }

            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLen = 8;

            if ($boxSize === 1) {
                $ext = fread($fh, 8);
                if (!is_string($ext) || strlen($ext) < 8) {
                    return null;
                }
                $hi = unpack('N', substr($ext, 0, 4))[1];
                $lo = unpack('N', substr($ext, 4, 4))[1];
                $boxSize = ($hi << 32) + $lo;
                $headerLen = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $end - $pos;
            }

            if ($boxSize < $headerLen || $pos + $boxSize > $end) {
                return $best;
            }

            $payload = $pos + $headerLen;
            $boxEnd = $pos + $boxSize;

            if ($type === 'mvhd' || $type === 'mdhd') {
                $seconds = self::readMvhd($fh, $payload, $boxEnd);
                if ($seconds !== null && ($best === null || $seconds > $best)) {
                    $best = $seconds;
                }
            } elseif (in_array($type, ['moov', 'trak', 'mdia'], true)) {
                $found = self::findDuration($fh, $payload, $boxEnd, $depth + 1);
                if ($found !== null && ($best === null || $found > $best)) {
                    $best = $found;
                }
            }

            $pos = $boxEnd;
        }

        return $best;
    }

    /** @param resource $fh */
    private static function readMvhd($fh, int $start, int $end): ?float
    {
        if ($end - $start < 20) {
            return null;
        }
        fseek($fh, $start);
        $version = ord((string) fread($fh, 1));
        fseek($fh, $start);

        if ($version === 1) {
            if ($end - $start < 32) {
                return null;
            }
            $data = fread($fh, 32);
            if (!is_string($data) || strlen($data) < 32) {
                return null;
            }
            $timescale = unpack('N', substr($data, 20, 4))[1];
            $hi = unpack('N', substr($data, 24, 4))[1];
            $lo = unpack('N', substr($data, 28, 4))[1];
            $duration = ($hi << 32) + $lo;
        } else {
            $data = fread($fh, 20);
            if (!is_string($data) || strlen($data) < 20) {
                return null;
            }
            $timescale = unpack('N', substr($data, 12, 4))[1];
            $duration = unpack('N', substr($data, 16, 4))[1];
        }

        if ($timescale <= 0 || $duration <= 0) {
            return null;
        }

        return $duration / $timescale;
    }

    private static function webmDuration(string $path): ?float
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $chunk = fread($fh, 512 * 1024);
        fclose($fh);
        if (!is_string($chunk) || strlen($chunk) < 16) {
            return null;
        }
        if (substr($chunk, 0, 4) !== "\x1A\x45\xDF\xA3") {
            return null;
        }

        $cluster = strpos($chunk, "\x1F\x43\xB6\x75");
        $limit = $cluster === false ? strlen($chunk) : $cluster;
        $scale = 1000000;

        $scalePos = strpos(substr($chunk, 0, $limit), "\x2A\xD7\xB1");
        if ($scalePos !== false) {
            $parsed = self::readEbmlUnsigned($chunk, $scalePos + 3);
            if ($parsed !== null && $parsed > 0) {
                $scale = $parsed;
            }
        }

        $offset = 0;
        $head = substr($chunk, 0, $limit);
        while ($offset < $limit) {
            $pos = strpos($head, "\x44\x89", $offset);
            if ($pos === false) {
                break;
            }
            $value = self::readEbmlFloat($chunk, $pos + 2);
            $offset = $pos + 2;
            if ($value === null || $value <= 0) {
                continue;
            }
            $seconds = $value * $scale / 1000000000;
            if ($seconds > 0 && $seconds < 6 * 3600) {
                return $seconds;
            }
        }

        return null;
    }

    private static function readEbmlUnsigned(string $data, int $pos): ?int
    {
        $vint = self::readVint($data, $pos);
        if ($vint === null || $vint['value'] <= 0 || $vint['value'] > 8) {
            return null;
        }
        $start = $pos + $vint['len'];
        $size = $vint['value'];
        if ($start + $size > strlen($data)) {
            return null;
        }
        $bytes = substr($data, $start, $size);
        $n = 0;
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $n = ($n << 8) | ord($bytes[$i]);
        }

        return $n;
    }

    private static function readEbmlFloat(string $data, int $pos): ?float
    {
        $vint = self::readVint($data, $pos);
        if ($vint === null || ($vint['value'] !== 4 && $vint['value'] !== 8)) {
            return null;
        }
        $start = $pos + $vint['len'];
        $size = $vint['value'];
        if ($start + $size > strlen($data)) {
            return null;
        }
        $bytes = substr($data, $start, $size);
        if ($size === 4) {
            $unpacked = unpack('G', $bytes);
        } else {
            $unpacked = unpack('E', $bytes);
        }
        if (!is_array($unpacked)) {
            return null;
        }
        $value = (float) $unpacked[1];

        return is_finite($value) ? $value : null;
    }

    /** @return array{value:int,len:int}|null */
    private static function readVint(string $data, int $pos): ?array
    {
        if ($pos >= strlen($data)) {
            return null;
        }
        $first = ord($data[$pos]);
        $len = 0;
        $mask = 0x80;
        while ($len < 8 && ($first & $mask) === 0) {
            $len++;
            $mask >>= 1;
        }
        $len++;
        if ($mask === 0 || $pos + $len > strlen($data)) {
            return null;
        }
        $value = $first & ($mask - 1);
        for ($i = 1; $i < $len; $i++) {
            $value = ($value << 8) | ord($data[$pos + $i]);
        }

        return ['value' => $value, 'len' => $len];
    }
}
