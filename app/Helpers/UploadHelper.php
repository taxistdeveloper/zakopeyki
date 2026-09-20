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

    /** @var array<string, list<string>> */
    private const DOCUMENT_MIME = [
        'pdf' => ['application/pdf'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ],
    ];

    public const LIBRARY_EXT = ['pdf', 'docx', 'xlsx', 'png', 'jpg', 'jpeg'];
    public const MAX_STAFF_FILE_BYTES = 15728640; // 15 MB

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
            self::VIDEO_MIME[$ext] ?? [],
            self::DOCUMENT_MIME[$ext] ?? []
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

        if (isset(self::DOCUMENT_MIME[$ext])) {
            if ($ext === 'pdf') {
                return $mimeOk || self::hasMagic($tmpPath, '%PDF');
            }
            // docx/xlsx are ZIP containers
            return $mimeOk || self::hasMagic($tmpPath, "PK\x03\x04");
        }

        return $mimeOk;
    }

    /**
     * Store an uploaded staff file (library / ops tasks).
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     * @param list<string> $allowedExt
     * @return array{ok:true,stored:string,original:string,mime:?string,size:int}|array{ok:false,error:string}
     */
    public static function storeStaffFile(array $file, string $dir, array $allowedExt = self::LIBRARY_EXT, int $maxBytes = self::MAX_STAFF_FILE_BYTES): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'empty'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'upload'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $original = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || $original === '' || $size <= 0) {
            return ['ok' => false, 'error' => 'upload'];
        }
        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'size'];
        }
        if (!self::isAllowedUpload($tmp, $original, $allowedExt)) {
            return ['ok' => false, 'error' => 'type'];
        }

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'save'];
        }

        $ext = self::normalizeExt($original);
        $stored = 'f_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $stored;
        if (!@move_uploaded_file($tmp, $dest)) {
            return ['ok' => false, 'error' => 'save'];
        }

        return [
            'ok' => true,
            'stored' => $stored,
            'original' => self::safeOriginalName($original),
            'mime' => self::detectMime($dest),
            'size' => (int) filesize($dest),
        ];
    }

    public static function safeOriginalName(string $name): string
    {
        $base = basename(str_replace(["\0", '\\'], '', $name));
        $base = preg_replace('/[^\p{L}\p{N}._ ()\-\[\]]+/u', '_', $base) ?? 'file';
        $base = trim($base, '._ ');
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'file';
        }

        return mb_substr($base, 0, 180);
    }

    private static function hasMagic(string $path, string $magic): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, strlen($magic));
        fclose($fh);

        return is_string($head) && $head === $magic;
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
