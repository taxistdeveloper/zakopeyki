<?php

declare(strict_types=1);

namespace App\Services\TryOn;

use App\Helpers\UploadHelper;

/**
 * Проверка и нормализация изображений примерки (docs/tryon/04-storage-security.md §2):
 * размер, реальный формат, габариты до декодирования (pixel bomb), поворот по EXIF,
 * ресайз и перекодирование в JPEG (удаляет EXIF/GPS и посторонние данные).
 */
final class TryOnImageValidator
{
    private const ALLOWED = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /** @param array<string, int> $limits */
    public function __construct(private readonly array $limits)
    {
    }

    /**
     * @return array{bytes:string,width:int,height:int,sha256:string}
     * @throws TryOnException
     */
    public function processUpload(string $path, int $size): array
    {
        $maxBytes = (int) ($this->limits['max_bytes'] ?? 10485760);
        if ($size <= 0 || !is_file($path)) {
            throw new TryOnException('unsupported_format', 415);
        }
        if ($size > $maxBytes || filesize($path) > $maxBytes) {
            throw new TryOnException('file_too_large', 413, ['max_bytes' => $maxBytes]);
        }

        $info = @getimagesize($path);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new TryOnException('unsupported_format', 415);
        }
        $mime = UploadHelper::detectMime($path);
        if ($mime !== null && $mime !== self::ALLOWED[$info[2]]) {
            throw new TryOnException('unsupported_format', 415);
        }

        $this->assertDimensions((int) $info[0], (int) $info[1]);

        $img = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        };
        if (!$img instanceof \GdImage) {
            throw new TryOnException('unsupported_format', 415);
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $img = self::applyExifOrientation($img, $path);
        }

        return $this->finalize($img);
    }

    /**
     * Нормализация результата провайдера (уже скачанные байты).
     * @return array{bytes:string,width:int,height:int,sha256:string}
     * @throws TryOnException
     */
    public function processBytes(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new TryOnException('result_fetch_failed', 502);
        }
        $this->assertDimensions((int) $info[0], (int) $info[1], false);
        $img = @imagecreatefromstring($bytes);
        if (!$img instanceof \GdImage) {
            throw new TryOnException('result_fetch_failed', 502);
        }

        return $this->finalize($img);
    }

    private function assertDimensions(int $w, int $h, bool $checkMin = true): void
    {
        $min = (int) ($this->limits['min_side_px'] ?? 512);
        $max = (int) ($this->limits['max_side_px'] ?? 6000);
        $maxPixels = (int) ($this->limits['max_pixels'] ?? 36000000);
        if ($w <= 0 || $h <= 0) {
            throw new TryOnException('unsupported_format', 415);
        }
        if ($w > $max || $h > $max || $w * $h > $maxPixels) {
            throw new TryOnException('image_too_large_dimensions', 422, ['max_side_px' => $max]);
        }
        if ($checkMin && min($w, $h) < $min) {
            throw new TryOnException('image_too_small', 422, ['min_side_px' => $min]);
        }
    }

    /** @return array{bytes:string,width:int,height:int,sha256:string} */
    private function finalize(\GdImage $img): array
    {
        $maxSide = (int) ($this->limits['store_max_side_px'] ?? 2048);
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        // Белый фон: прозрачность PNG/WebP не должна превращаться в чёрный.
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        ob_start();
        imagejpeg($out, null, (int) ($this->limits['jpeg_quality'] ?? 90));
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        return ['bytes' => $bytes, 'width' => $nw, 'height' => $nh, 'sha256' => hash('sha256', $bytes)];
    }

    private static function applyExifOrientation(\GdImage $img, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };

        return $rotated instanceof \GdImage ? $rotated : $img;
    }

    /**
     * Эвристика: результат практически совпадает с исходным фото (провайдер не применил товар).
     * Оба изображения приводятся к одному кадру (центр-кроп по пропорциям результата, 32 px по ширине,
     * яркость), затем берётся 95-й перцентиль разницы по клеткам. Настоящая примерка меняет
     * заметную часть кадра (одежда), неизменённое фото даёт только шум перекодирования.
     * Порог проверяется на реальных результатах в PHASE 2.
     */
    public static function looksUnchanged(string $personBytes, string $resultBytes, int $p95Threshold = 12): bool
    {
        $a = @imagecreatefromstring($personBytes);
        $b = @imagecreatefromstring($resultBytes);
        if (!$a instanceof \GdImage || !$b instanceof \GdImage) {
            return false;
        }
        $bw = imagesx($b);
        $bh = imagesy($b);
        $gw = 32;
        $gh = max(1, (int) round($gw * $bh / max(1, $bw)));
        $ga = self::grayGrid($a, $bw / max(1, $bh), $gw, $gh);
        $gb = self::grayGrid($b, $bw / max(1, $bh), $gw, $gh);
        imagedestroy($a);
        imagedestroy($b);
        $diffs = [];
        foreach ($ga as $i => $v) {
            $diffs[] = abs($v - $gb[$i]);
        }
        sort($diffs);
        $p95 = $diffs[(int) floor(0.95 * (count($diffs) - 1))] ?? 255;

        return $p95 < $p95Threshold;
    }

    /** @return list<int> */
    private static function grayGrid(\GdImage $img, float $targetRatio, int $gw, int $gh): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        // центр-кроп под пропорцию цели
        if ($w / $h > $targetRatio) {
            $cw = (int) round($h * $targetRatio);
            $ch = $h;
        } else {
            $cw = $w;
            $ch = (int) round($w / $targetRatio);
        }
        $sx = (int) (($w - $cw) / 2);
        $sy = (int) (($h - $ch) / 2);
        $s = imagecreatetruecolor($gw, $gh);
        imagecopyresampled($s, $img, 0, 0, $sx, $sy, $gw, $gh, $cw, $ch);
        $out = [];
        for ($y = 0; $y < $gh; $y++) {
            for ($x = 0; $x < $gw; $x++) {
                $rgb = imagecolorat($s, $x, $y);
                $out[] = (int) (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));
            }
        }
        imagedestroy($s);

        return $out;
    }
}
