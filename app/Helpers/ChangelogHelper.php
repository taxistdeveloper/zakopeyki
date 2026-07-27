<?php

namespace App\Helpers;

/**
 * Changelog для модалки «Что нового».
 * Обновляется только при новом git-коммите (git pull / deploy).
 * Сохранение файлов без коммита модалку не открывает.
 */
class ChangelogHelper
{
    public static function path(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'changelog.json';
    }

    public static function fallbackPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'changelog.json';
    }

    /**
     * @return array{version: string, date: string, items: list<string>}|null
     */
    public static function load(): ?array
    {
        self::sync();

        foreach ([self::path(), self::fallbackPath()] as $path) {
            $data = self::readFile($path);
            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }

    /** Пересобрать changelog только если сменился HEAD. */
    public static function sync(bool $force = false): bool
    {
        $built = self::buildFromGit();
        if ($built === null) {
            return false;
        }

        $existing = self::readFile(self::path());
        if (!$force && $existing !== null && ($existing['version'] ?? '') === $built['version']) {
            return false;
        }

        return self::write($built);
    }

    /**
     * @return array{version: string, date: string, items: list<string>}|null
     */
    private static function buildFromGit(): ?array
    {
        $root = self::root();
        if (!is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
            return null;
        }

        $head = self::git('rev-parse --short HEAD');
        if ($head === null || $head === '') {
            return null;
        }

        $existing = self::readFile(self::path()) ?? self::readFile(self::fallbackPath());
        if ($existing !== null && ($existing['version'] ?? '') === $head) {
            return $existing;
        }

        $prevHead = null;
        if ($existing !== null && preg_match('/^([0-9a-f]{4,40})/i', (string) ($existing['version'] ?? ''), $m)) {
            $prevHead = $m[1];
        }

        $logRange = ($prevHead !== null && $prevHead !== $head && self::git('rev-parse --verify ' . $prevHead) !== null)
            ? "log {$prevHead}..{$head} --pretty=format:%s --no-merges"
            : 'log -6 --pretty=format:%s --no-merges';

        $items = self::commitSubjects($logRange);
        if ($items === []) {
            $items[] = self::msg('site_updated', $head);
        }

        return [
            'version' => $head,
            'date' => date('Y-m-d H:i'),
            'items' => array_values(array_unique($items)),
        ];
    }

    /** @return list<string> */
    private static function commitSubjects(string $logArgs): array
    {
        $raw = self::git($logArgs);
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^Merge\b/i', $line)) {
                continue;
            }
            if (preg_match('/\btest div\b/i', $line)) {
                continue;
            }
            $out[] = self::shorten($line);
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    private static function shorten(string $line): string
    {
        $line = trim($line);
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $line, $m) && mb_strlen($m[1]) >= 20) {
            $line = $m[1];
        }
        if (mb_strlen($line) > 120) {
            $line = rtrim(mb_substr($line, 0, 117)) . '…';
        }

        return $line;
    }

    private static function msg(string $key, string $arg = ''): string
    {
        $kk = class_exists(\App\Core\Lang::class) && \App\Core\Lang::current() === 'kk';
        $map = [
            'site_updated' => $kk ? 'Сайт жаңартылды (:a)' : 'Обновление сайта (:a)',
        ];
        $text = $map[$key] ?? ($kk ? 'Жаңарту' : 'Обновление');

        return str_replace(':a', $arg, $text);
    }

    /**
     * @param array{version: string, date: string, items: list<string>} $data
     */
    private static function write(array $data): bool
    {
        $dir = dirname(self::path());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }

        return file_put_contents(self::path(), $json . "\n") !== false;
    }

    /**
     * @return array{version: string, date: string, items: list<string>}|null
     */
    private static function readFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $version = trim((string) ($data['version'] ?? ''));
        $items = $data['items'] ?? [];
        if ($version === '' || !is_array($items) || $items === []) {
            return null;
        }

        $clean = [];
        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        if ($clean === []) {
            return null;
        }

        return [
            'version' => $version,
            'date' => (string) ($data['date'] ?? ''),
            'items' => array_values($clean),
        ];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function git(string $args): ?string
    {
        if (!function_exists('exec')) {
            return null;
        }

        $root = self::root();
        $cmd = 'git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return null;
        }

        return implode("\n", $out);
    }
}
