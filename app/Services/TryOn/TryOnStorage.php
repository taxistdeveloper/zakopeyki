<?php

declare(strict_types=1);

namespace App\Services\TryOn;

/**
 * Файлы примерки вне webroot: storage/tryon/YYYY/MM/<random>.jpg.
 * Путь не содержит user_id и исходного имени; выдача — только через контроллер с проверкой владельца.
 */
final class TryOnStorage
{
    public function __construct(private readonly string $baseDir)
    {
    }

    /** @return string относительный путь */
    public function put(string $bytes): string
    {
        $this->ensureBase();
        $rel = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.jpg';
        $abs = $this->absolute($rel);
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('tryon: storage dir not writable');
        }
        if (@file_put_contents($abs, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($abs);
            throw new \RuntimeException('tryon: storage write failed');
        }
        @chmod($abs, 0600);

        return $rel;
    }

    public function read(string $rel): ?string
    {
        $abs = $this->absolute($rel);
        if (!is_file($abs)) {
            return null;
        }
        $data = @file_get_contents($abs);

        return $data === false ? null : $data;
    }

    public function path(string $rel): ?string
    {
        $abs = $this->absolute($rel);

        return is_file($abs) ? $abs : null;
    }

    public function delete(?string $rel): void
    {
        if ($rel === null || $rel === '') {
            return;
        }
        $abs = $this->absolute($rel);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    private function absolute(string $rel): string
    {
        // Только пути, созданные put(): YYYY/MM/<32hex>.jpg — без обхода каталогов.
        if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.jpg$#', $rel)) {
            throw new \InvalidArgumentException('tryon: bad storage path');
        }

        return rtrim($this->baseDir, '/') . '/' . $rel;
    }

    private function ensureBase(): void
    {
        $base = rtrim($this->baseDir, '/');
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('tryon: storage dir not writable');
        }
        $ht = $base . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Фото примерки — только через авторизованный endpoint\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }
    }
}
