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

    public static function frameUrl(bool $retina = false): string
    {
        return ProductHelper::url($retina
            ? 'public/assets/img/medal-frame@2x.png'
            : 'public/assets/img/medal-frame.png');
    }

    /**
     * Аватар в золотой медали (буква / фото / логотип).
     */
    public static function html(?array $user, string $sizeClass = 'w-10 h-10', string $textClass = 'text-sm', string $rounded = 'rounded-full'): string
    {
        unset($rounded); // форма всегда круг в медали
        $url = self::url($user);
        $initial = htmlspecialchars(self::initial($user), ENT_QUOTES, 'UTF-8');
        $sizeClass = htmlspecialchars($sizeClass, ENT_QUOTES, 'UTF-8');
        $textClass = htmlspecialchars($textClass, ENT_QUOTES, 'UTF-8');
        $frame = htmlspecialchars(self::frameUrl(false), ENT_QUOTES, 'UTF-8');
        $frame2x = htmlspecialchars(self::frameUrl(true), ENT_QUOTES, 'UTF-8');

        if ($url) {
            $src = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $face = '<img src="' . $src . '" alt="" class="zk-medal-avatar__photo" loading="lazy" decoding="async">';
        } else {
            $face = '<span class="zk-medal-avatar__letter ' . $textClass . '">' . $initial . '</span>';
        }

        $html = '<span class="zk-medal-avatar ' . $sizeClass . ' shrink-0" aria-hidden="true">'
            . '<span class="zk-medal-avatar__face">' . $face . '</span>'
            . '<img class="zk-medal-avatar__ring" src="' . $frame . '" srcset="' . $frame2x . ' 2x" alt="" decoding="async">'
            . '</span>';

        if ($user && ProductHelper::sellerIsBusiness($user)) {
            return '<span class="biz-avatar shrink-0">' . $html . '</span>';
        }

        return $html;
    }
}
