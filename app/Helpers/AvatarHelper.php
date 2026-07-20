<?php

namespace App\Helpers;

class AvatarHelper
{
    public static function url(?array $user): ?string
    {
        $file = $user['avatar_file'] ?? $user['user_avatar_file'] ?? null;
        if (!$file) {
            return null;
        }
        // если это просто буква, не файл
        if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $file)) {
            return null;
        }
        return ProductHelper::url('public/uploads/avatars/' . basename($file));
    }

    public static function initial(?array $user, string $fallback = 'U'): string
    {
        if (!empty($user['avatar']) && mb_strlen($user['avatar']) <= 2 && !preg_match('/\./', $user['avatar'])) {
            return $user['avatar'];
        }
        $name = $user['name'] ?? $user['user_name'] ?? $user['author_name'] ?? '';
        return $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : $fallback;
    }

    public static function html(?array $user, string $sizeClass = 'w-10 h-10', string $textClass = 'text-sm', string $rounded = 'rounded-full'): string
    {
        $url = self::url($user);
        $initial = htmlspecialchars(self::initial($user));
        $base = "{$sizeClass} {$rounded} flex items-center justify-center flex-shrink-0 overflow-hidden";

        if ($url) {
            $src = htmlspecialchars($url);
            return "<div class=\"{$base} bg-gray-200\"><img src=\"{$src}\" alt=\"\" class=\"w-full h-full object-cover\"></div>";
        }

        return "<div class=\"{$base} bg-brand-400 dark:bg-brand-600 font-black text-gray-900 dark:text-white {$textClass}\">{$initial}</div>";
    }
}
