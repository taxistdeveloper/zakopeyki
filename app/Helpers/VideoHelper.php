<?php

namespace App\Helpers;

class VideoHelper
{
    /** Embed URL для iframe или null (тогда использовать video_file / прямую ссылку) */
    public static function embedUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        // YouTube
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]{6,})~', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1&rel=0';
        }

        // Vimeo
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1';
        }

        return null;
    }

    public static function isDirectVideo(?string $url): bool
    {
        return $url && (bool) preg_match('/\.(mp4|webm|ogg)(\?|$)/i', $url);
    }
}
