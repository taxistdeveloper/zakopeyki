<?php

namespace App\Helpers;

/**
 * Changelog для модалки «Что нового».
 * Автоматически обновляется при новом коммите или незакоммиченных правках в коде.
 */
class ChangelogHelper
{
    private const SECRET_NAME_RE = '/(^|\/)(\.env|google\.php|mail\.php|credentials|secret|password|private)/i';

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

    /** Пересобрать changelog, если код/коммит изменились. */
    public static function sync(bool $force = false): bool
    {
        $built = self::buildFromGit() ?? self::buildFromFiles();
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

        $status = self::git('status --porcelain --untracked-files=all -- . ":(exclude)storage"');
        $dirtyRaw = $status ?? '';
        $dirty = trim($dirtyRaw) !== '';

        $version = $head;
        if ($dirty) {
            $diff = (string) (self::git('diff --no-ext-diff -- . ":(exclude)storage"') ?? '');
            $staged = (string) (self::git('diff --cached --no-ext-diff -- . ":(exclude)storage"') ?? '');
            $version = $head . '-d' . substr(md5($dirtyRaw . "\n" . $diff . "\n" . $staged), 0, 8);
        }

        $prevHead = null;
        $existing = self::readFile(self::path()) ?? self::readFile(self::fallbackPath());
        if ($existing !== null && preg_match('/^([0-9a-f]{4,40})/i', (string) ($existing['version'] ?? ''), $m)) {
            $prevHead = $m[1];
        }

        $items = [];

        if ($dirty) {
            $fileItems = self::itemsFromStatus($dirtyRaw);
            $items = array_merge($items, $fileItems);
        }

        $logRange = ($prevHead !== null && $prevHead !== $head && self::git('rev-parse --verify ' . $prevHead) !== null)
            ? "log {$prevHead}..{$head} --pretty=format:%s --no-merges"
            : 'log -6 --pretty=format:%s --no-merges';

        foreach (self::commitSubjects($logRange) as $subject) {
            $items[] = $subject;
            if (count($items) >= 12) {
                break;
            }
        }

        if ($items === []) {
            $items[] = self::msg('site_updated', $head);
        }

        return [
            'version' => $version,
            'date' => date('Y-m-d H:i'),
            'items' => array_values(array_unique($items)),
        ];
    }

    /**
     * Fallback без git: хеш по времени изменения ключевых папок.
     *
     * @return array{version: string, date: string, items: list<string>}|null
     */
    private static function buildFromFiles(): ?array
    {
        $root = self::root();
        $dirs = ['app', 'lang', 'public' . DIRECTORY_SEPARATOR . 'assets', 'config'];
        $stamp = '';
        $changed = [];

        foreach ($dirs as $rel) {
            $dir = $root . DIRECTORY_SEPARATOR . $rel;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (preg_match(self::SECRET_NAME_RE, str_replace('\\', '/', $path))) {
                    continue;
                }
                $mtime = (int) $file->getMTime();
                $relPath = str_replace('\\', '/', substr($path, strlen($root) + 1));
                $stamp .= $relPath . ':' . $mtime . ';';
                $changed[$relPath] = $mtime;
            }
        }

        if ($stamp === '') {
            return null;
        }

        $version = 'f' . substr(md5($stamp), 0, 10);
        $existing = self::readFile(self::path());
        if ($existing !== null && ($existing['version'] ?? '') === $version) {
            return $existing;
        }

        arsort($changed);
        $items = [self::msg('code_updated')];
        $n = 0;
        foreach ($changed as $relPath => $mtime) {
            // Только свежие (за последние 2 дня) — иначе слишком шумно
            if ($mtime < time() - 2 * 86400) {
                continue;
            }
            $label = self::labelPath($relPath);
            if ($label === null) {
                continue;
            }
            $items[] = $label;
            if (++$n >= 8) {
                break;
            }
        }

        return [
            'version' => $version,
            'date' => date('Y-m-d H:i'),
            'items' => array_values(array_unique($items)),
        ];
    }

    /** @return list<string> */
    private static function itemsFromStatus(string $porcelain): array
    {
        $items = [self::msg('code_changed')];
        $seen = [];
        foreach (preg_split("/\r\n|\n|\r/", $porcelain) ?: [] as $line) {
            $line = rtrim($line, "\r\n");
            if (trim($line) === '') {
                continue;
            }
            // porcelain: XY<space>PATH — не trim() слева, иначе съедается статус
            $path = $line;
            if (preg_match('/^.{2} (.+)$/', $line, $m)) {
                $path = $m[1];
            } elseif (preg_match('/^[MADRCU?!~]{1,2}\s+(.+)$/', trim($line), $m)) {
                $path = $m[1];
            }
            if (str_contains($path, ' -> ')) {
                $parts = explode(' -> ', $path);
                $path = end($parts) ?: $path;
            }
            $path = str_replace('\\', '/', trim($path, " \t\""));
            if ($path === '' || preg_match(self::SECRET_NAME_RE, $path)) {
                continue;
            }
            if (str_starts_with($path, 'storage/')) {
                continue;
            }
            $label = self::labelPath($path);
            if ($label === null || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $items[] = $label;
            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
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

    private static function labelPath(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $base = basename($path);

        if (str_starts_with($path, 'app/Views/')) {
            return self::msg('file_view', $base);
        }
        if (str_starts_with($path, 'app/Controllers/')) {
            return self::msg('file_controller', $base);
        }
        if (str_starts_with($path, 'app/Models/')) {
            return self::msg('file_model', $base);
        }
        if (str_starts_with($path, 'app/Helpers/') || str_starts_with($path, 'app/Core/')) {
            return self::msg('file_code', $base);
        }
        if (str_starts_with($path, 'lang/')) {
            return self::msg('file_lang', $base);
        }
        if (str_starts_with($path, 'public/assets/js/')) {
            return self::msg('file_js', $base);
        }
        if (str_starts_with($path, 'public/assets/css/')) {
            return self::msg('file_css', $base);
        }
        if (str_starts_with($path, 'config/')) {
            if (preg_match(self::SECRET_NAME_RE, $path)) {
                return null;
            }
            return self::msg('file_config', $base);
        }
        if (str_starts_with($path, 'database/')) {
            return self::msg('file_db', $base);
        }
        if (str_starts_with($path, 'bin/')) {
            return self::msg('file_bin', $base);
        }

        return self::msg('file_generic', $path);
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
        $kk = \App\Core\Lang::current() === 'kk';
        $map = [
            'code_changed' => $kk ? 'Кодта өзгерістер бар' : 'В коде есть незакоммиченные изменения',
            'code_updated' => $kk ? 'Сайт файлдары жаңартылды' : 'Обновлены файлы сайта',
            'site_updated' => $kk ? 'Сайт жаңартылды (:a)' : 'Обновление сайта (:a)',
            'file_view' => $kk ? 'Бет/шаблон: :a' : 'Страница/шаблон: :a',
            'file_controller' => $kk ? 'Логика: :a' : 'Логика: :a',
            'file_model' => $kk ? 'Деректер: :a' : 'Модель: :a',
            'file_code' => $kk ? 'Код: :a' : 'Код: :a',
            'file_lang' => $kk ? 'Аударма: :a' : 'Переводы: :a',
            'file_js' => $kk ? 'Скрипт: :a' : 'Скрипт: :a',
            'file_css' => $kk ? 'Стиль: :a' : 'Стили: :a',
            'file_config' => $kk ? 'Конфиг: :a' : 'Конфиг: :a',
            'file_db' => $kk ? 'БД: :a' : 'База/миграция: :a',
            'file_bin' => $kk ? 'Скрипт: :a' : 'Скрипт: :a',
            'file_generic' => $kk ? 'Файл: :a' : 'Файл: :a',
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
