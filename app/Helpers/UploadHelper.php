<?php

namespace App\Helpers;

class UploadHelper
{
    /** @var array<string, list<string>> */
    private const IMAGE_MIME = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
    ];

    /** @var array<string, list<string>> */
    private const VIDEO_MIME = [
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
    ];

    /**
     * Validate uploaded file by extension whitelist and real MIME / image contents.
     * @param list<string> $allowedExt
     */
    public static function isAllowedUpload(string $tmpPath, string $originalName, array $allowedExt): bool
    {
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return false;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            return false;
        }

        $allowedMimes = array_merge(
            self::IMAGE_MIME[$ext] ?? [],
            self::VIDEO_MIME[$ext] ?? []
        );
        if ($allowedMimes === []) {
            return false;
        }

        $mime = self::detectMime($tmpPath);
        $mimeOk = $mime !== null && in_array($mime, $allowedMimes, true);

        if (isset(self::IMAGE_MIME[$ext])) {
            $info = @getimagesize($tmpPath);
            if ($info === false) {
                return false;
            }
            // Accept when MIME detectors fail but GD can read a matching image type.
            if (!$mimeOk) {
                $gdMime = isset($info['mime']) ? strtolower((string) $info['mime']) : '';
                return $gdMime !== '' && in_array($gdMime, $allowedMimes, true);
            }
            return true;
        }

        return $mimeOk;
    }

    public static function detectMime(string $path): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = @\mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        return null;
    }

    /** Safe extension for storage (maps jpeg → jpg). */
    public static function normalizeExt(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }

    /**
     * Burn a diagonal "zakopeyki.kz" watermark into an image file (GD).
     * Safe no-op if GD cannot process the file.
     */
    public static function applyWatermark(string $path, string $text = 'zakopeyki.kz'): bool
    {
        if ($path === '' || !is_file($path) || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($path);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return false;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $mime = strtolower((string) ($info['mime'] ?? ''));

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
        if ($src === false) {
            return false;
        }

        imagealphablending($src, true);
        imagesavealpha($src, true);

        $font = self::watermarkFont();
        $minSide = min($width, $height);
        $fontSize = max(14, (int) round($minSide * 0.045));
        $angle = 28;

        if ($font && function_exists('imagettfbbox') && function_exists('imagettftext')) {
            $bbox = imagettfbbox($fontSize, $angle, $font, $text);
            $tw = abs(($bbox[2] ?? 0) - ($bbox[0] ?? 0));
            $th = abs(($bbox[7] ?? 0) - ($bbox[1] ?? 0));
            $x = (int) round(($width - $tw) / 2);
            $y = (int) round(($height + $th) / 2);

            $shadow = imagecolorallocatealpha($src, 0, 0, 0, 70);
            $fill = imagecolorallocatealpha($src, 255, 255, 255, 55);
            if ($shadow !== false) {
                imagettftext($src, $fontSize, $angle, $x + 2, $y + 2, $shadow, $font, $text);
            }
            if ($fill !== false) {
                imagettftext($src, $fontSize, $angle, $x, $y, $fill, $font, $text);
            }
        } else {
            // Fallback without FreeType: tiled built-in font.
            $white = imagecolorallocatealpha($src, 255, 255, 255, 60);
            $black = imagecolorallocatealpha($src, 0, 0, 0, 80);
            if ($white !== false && $black !== false) {
                $label = $text;
                $fw = imagefontwidth(5) * strlen($label);
                $fh = imagefontheight(5);
                $x = (int) max(0, ($width - $fw) / 2);
                $y = (int) max(0, ($height - $fh) / 2);
                imagestring($src, 5, $x + 1, $y + 1, $label, $black);
                imagestring($src, 5, $x, $y, $label, $white);
            }
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($src, $path, 90),
            'image/png' => imagepng($src, $path, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($src, $path, 90) : false,
            'image/gif' => imagegif($src, $path),
            default => false,
        };
        imagedestroy($src);

        return (bool) $ok;
    }

    private static function watermarkFont(): ?string
    {
        $candidates = [
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\segoeuib.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return null;
    }
}
