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
        if ($mime === null || !in_array($mime, $allowedMimes, true)) {
            return false;
        }

        if (isset(self::IMAGE_MIME[$ext])) {
            $info = @getimagesize($tmpPath);
            if ($info === false) {
                return false;
            }
        }

        return true;
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

        $mime = @mime_content_type($path);
        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    /** Safe extension for storage (maps jpeg → jpg). */
    public static function normalizeExt(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
}
