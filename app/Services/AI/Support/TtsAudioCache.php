<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

use App\Core\Auth;

/**
 * Закрытый кеш синтезированного аудио TTS.
 *
 * - Файлы лежат в storage/cache/tts (каталог storage/ закрыт для HTTP в корневом .htaccess),
 *   права 0700/0600. Публичного URL к файлу нет.
 * - Имя файла = HMAC-SHA256(секрет сервера, все параметры синтеза): по тексту ответа нельзя
 *   вычислить имя файла без секрета (в отличие от прежнего md5(voice_text)).
 * - Аудио выдаётся только через GET /ai/tts/audio/{token}. Токен подписан HMAC, имеет срок
 *   действия и привязан к владельцу (пользователь или PHP-сессия гостя): чужой токен
 *   с другим владельцем не проходит проверку.
 * - Один и тот же синтез переиспользуется между пользователями только на уровне файла
 *   (экономия запросов к движку); доступ к нему каждый получает своим токеном.
 * - Запись атомарна (временный файл + rename) и сериализована flock на ключ.
 * - Файлы старше TTL удаляются (purgeExpired: вероятностно при записи и из bin/ai_maintenance.php).
 */
final class TtsAudioCache
{
    public const DEFAULT_TTL_SECONDS = 86400;      // 24 ч хранения аудио
    public const DEFAULT_URL_TTL_SECONDS = 1800;   // 30 мин жизни ссылки
    private const MIN_AUDIO_BYTES = 500;
    private const ID_PATTERN = '/^[a-f0-9]{64}$/';

    private string $dir;
    private string $secret;
    private int $ttl;
    private int $urlTtl;

    public function __construct(
        ?string $dir = null,
        ?string $secret = null,
        ?int $ttlSeconds = null,
        ?int $urlTtlSeconds = null
    ) {
        $root = dirname(__DIR__, 4);
        $this->dir = rtrim($dir ?? ($root . '/storage/cache/tts'), '/');
        $this->ttl = max(60, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS);
        $this->urlTtl = max(30, $urlTtlSeconds ?? self::DEFAULT_URL_TTL_SECONDS);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
        $this->secret = $secret ?? self::loadOrCreateSecret($root . '/storage/keys/tts_cache.key');
    }

    /**
     * Секрет: AI_TTS_CACHE_SECRET из окружения или ключ, сгенерированный при первом запуске
     * в storage/keys/tts_cache.key (0600). В клиентский код не попадает.
     */
    private static function loadOrCreateSecret(string $keyFile): string
    {
        $env = getenv('AI_TTS_CACHE_SECRET');
        if (is_string($env) && strlen($env) >= 32) {
            return $env;
        }

        if (is_file($keyFile)) {
            $key = trim((string) @file_get_contents($keyFile));
            if (strlen($key) >= 64) {
                return $key;
            }
        }

        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $key = bin2hex(random_bytes(32));
        $fh = @fopen($keyFile, 'x');
        if ($fh !== false) {
            fwrite($fh, $key);
            fclose($fh);
            @chmod($keyFile, 0600);
            return $key;
        }
        // Параллельный процесс создал ключ первым — читаем его
        $existing = trim((string) @file_get_contents($keyFile));
        if (strlen($existing) >= 64) {
            return $existing;
        }

        throw new \RuntimeException('TTS cache secret is unavailable');
    }

    /**
     * Ключ кеша по всем параметрам, влияющим на результат синтеза.
     *
     * @param array<string, scalar|null> $params provider, engine, voice, language, rate, pitch, text …
     */
    public function cacheId(array $params): string
    {
        ksort($params);
        return hash_hmac('sha256', 'tts-cache-v1|' . json_encode($params, JSON_UNESCAPED_UNICODE), $this->secret);
    }

    public function pathFor(string $id): ?string
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return null;
        }
        return $this->dir . '/' . substr($id, 0, 2) . '/' . $id . '.mp3';
    }

    /**
     * Есть ли актуальный (не просроченный) аудиофайл.
     */
    public function has(string $id): bool
    {
        $path = $this->pathFor($id);
        if ($path === null || !is_file($path)) {
            return false;
        }
        clearstatcache(true, $path);
        if (filesize($path) < self::MIN_AUDIO_BYTES) {
            return false;
        }
        if (time() - (int) filemtime($path) > $this->ttl) {
            @unlink($path);
            return false;
        }
        return true;
    }

    /**
     * Получить аудио из кеша или создать его.
     * $producer(string $tmpPath): bool — записывает аудио в $tmpPath.
     * Параллельные запросы одного ключа ждут друг друга на flock: движок вызывается один раз.
     */
    public function getOrCreate(string $id, callable $producer): bool
    {
        if ($this->has($id)) {
            return true;
        }
        $path = $this->pathFor($id);
        if ($path === null) {
            return false;
        }
        $sub = dirname($path);
        if (!is_dir($sub)) {
            @mkdir($sub, 0700, true);
        }

        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            return false;
        }
        try {
            flock($lock, LOCK_EX);
            if ($this->has($id)) {
                return true; // создано параллельным запросом, пока ждали блокировку
            }

            $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
            $fh = @fopen($tmp, 'x');
            if ($fh === false) {
                return false;
            }
            fclose($fh);
            @chmod($tmp, 0600);

            $ok = false;
            try {
                $ok = (bool) $producer($tmp);
                clearstatcache(true, $tmp);
                $ok = $ok && is_file($tmp) && filesize($tmp) >= self::MIN_AUDIO_BYTES && @rename($tmp, $path);
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            if ($ok && random_int(1, 50) === 1) {
                $this->purgeExpired();
            }

            return $ok;
        } finally {
            // .lock не удаляем: иначе ожидающий процесс и новый могли бы заблокировать разные inode.
            // Старые .lock убирает purgeExpired().
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Владелец ссылки: авторизованный пользователь или PHP-сессия гостя.
     */
    public static function currentOwner(): string
    {
        if (Auth::check()) {
            return 'u' . (int) Auth::id();
        }
        $sid = session_id();
        return $sid !== '' ? 's' . $sid : 'anon';
    }

    /**
     * Подписанный токен доступа к аудио для конкретного владельца.
     */
    public function issueToken(string $id, string $owner, ?int $now = null): ?string
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return null;
        }
        $exp = ($now ?? time()) + $this->urlTtl;
        return $id . '.' . $exp . '.' . $this->sign($id, $exp, $owner);
    }

    /**
     * Проверка токена: формат, подпись (owner), срок действия, наличие файла.
     * Возвращает путь к файлу или null — без уточнения причины (не раскрываем, существует ли файл).
     */
    public function resolveToken(string $token, string $owner, ?int $now = null): ?string
    {
        if (!preg_match('/^([a-f0-9]{64})\.(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }
        [, $id, $expRaw, $sig] = $m;
        $exp = (int) $expRaw;
        if (!hash_equals($this->sign($id, $exp, $owner), $sig)) {
            return null;
        }
        if ($exp < ($now ?? time())) {
            return null;
        }
        if (!$this->has($id)) {
            return null;
        }
        return $this->pathFor($id);
    }

    private function sign(string $id, int $exp, string $owner): string
    {
        return hash_hmac('sha256', 'tts-url-v1|' . $id . '|' . $exp . '|' . $owner, $this->secret);
    }

    /**
     * Удаление просроченных файлов и «зависших» временных файлов/блокировок.
     *
     * @return int Количество удалённых файлов
     */
    public function purgeExpired(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $age = $now - (int) @filemtime($file);
            $isAudio = str_ends_with($file, '.mp3');
            if (($isAudio && $age > $this->ttl) || (!$isAudio && $age > 300)) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub); // удалится только пустой
        }
        return $removed;
    }

    /**
     * Полная очистка кеша.
     */
    public function clear(): int
    {
        $removed = 0;
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }
        foreach (glob($this->dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub);
        }
        return $removed;
    }

    public function urlTtlSeconds(): int
    {
        return $this->urlTtl;
    }
}
