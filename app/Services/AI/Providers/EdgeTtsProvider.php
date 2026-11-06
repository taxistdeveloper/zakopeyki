<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Helpers\ProductHelper;
use App\Services\AI\Contracts\TextToSpeechInterface;
use App\Services\AI\DTO\TtsResult;
use App\Services\AI\Support\SpeechTextNormalizer;
use App\Services\AI\Support\TtsAudioCache;

/**
 * Серверный синтез речи через python-библиотеку edge-tts (голоса Microsoft Edge «Read Aloud»).
 *
 * ВНИМАНИЕ: edge-tts обращается к неофициальному endpoint онлайн-чтения Microsoft Edge, а не к
 * платному Azure AI Speech. Коммерческие условия этого использования официально не подтверждены
 * (см. docs/AI_SETUP.md, раздел «TTS: коммерческие ограничения»). Провайдер не является
 * обязательным: по умолчанию используется браузерный TTS (`AI_TTS_PROVIDER=browser`).
 *
 * - Голос выбирается только из белого списка `ai.tts.voices[lang]` (проверено реальным синтезом).
 * - Аудио хранится в закрытом кеше TtsAudioCache, клиент получает подписанную ссылку
 *   /ai/tts/audio/{token}, привязанную к пользователю/сессии и ограниченную по времени.
 * - При сбое движка возвращается честный браузерный fallback (use_browser=true) с предупреждением
 *   `server_tts_failed` — без ложного «успешного» аудио.
 */
final class EdgeTtsProvider implements TextToSpeechInterface
{
    /** Версия формата синтеза: входит в ключ кеша, смена версии инвалидирует кеш. */
    private const ENGINE_ID = 'edge-tts/7';
    private const DEFAULT_RATE = '+0%';

    /** @var array<string, list<string>> */
    private const DEFAULT_VOICES = [
        'ru' => ['ru-RU-DmitryNeural', 'ru-RU-SvetlanaNeural'],
        'kk' => ['kk-KZ-AigulNeural', 'kk-KZ-DauletNeural'],
        'en' => ['en-US-JennyNeural', 'en-US-GuyNeural'],
    ];

    private SpeechTextNormalizer $normalizer;
    private BrowserTtsProvider $fallbackProvider;
    private TtsAudioCache $cache;
    private string $pythonBin;
    /** @var array<string, list<string>> */
    private array $voices;
    private int $timeoutSeconds;

    /**
     * @param array<string, mixed> $config Секция `tts` из config/ai.php
     */
    public function __construct(
        ?SpeechTextNormalizer $normalizer = null,
        ?TtsAudioCache $cache = null,
        array $config = [],
        ?string $pythonBin = null
    ) {
        $this->normalizer = $normalizer ?? new SpeechTextNormalizer();
        $this->fallbackProvider = new BrowserTtsProvider($this->normalizer);
        $this->cache = $cache ?? new TtsAudioCache(
            null,
            null,
            isset($config['cache_ttl_seconds']) ? (int) $config['cache_ttl_seconds'] : null,
            isset($config['url_ttl_seconds']) ? (int) $config['url_ttl_seconds'] : null
        );
        $voices = $config['voices'] ?? self::DEFAULT_VOICES;
        $this->voices = is_array($voices) && $voices !== [] ? $voices : self::DEFAULT_VOICES;
        $this->timeoutSeconds = max(5, (int) ($config['edge_timeout_seconds'] ?? 20));
        $configured = $pythonBin ?? (string) ($config['python_bin'] ?? '');
        $this->pythonBin = $configured !== '' ? $configured : $this->detectPython();
    }

    private function detectPython(): string
    {
        foreach (['/usr/local/bin/python3', '/opt/homebrew/bin/python3', '/usr/bin/python3'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return 'python3';
    }

    public function providerName(): string
    {
        return 'edge_neural';
    }

    public function isAvailable(): bool
    {
        return $this->pythonBin !== '';
    }

    /**
     * @return array<string, list<string>>
     */
    public function supportedVoices(): array
    {
        return $this->voices;
    }

    public function synthesize(string $text, string $language = 'ru', array $options = []): TtsResult
    {
        $lang = strtolower(trim($language)) ?: 'ru';
        if (!isset($this->voices[$lang])) {
            $lang = 'ru';
        }
        $cleanText = $this->normalizer->normalize($text, $lang);
        $requestId = (string) ($options['request_id'] ?? ('tts_edge_' . bin2hex(random_bytes(6))));

        if ($cleanText === '') {
            return TtsResult::failure(
                error: 'Пустой текст для озвучивания.',
                provider: $this->providerName(),
                requestId: $requestId,
                warnings: ['empty_text']
            );
        }

        $warnings = [];
        $voice = $this->voices[$lang][0];
        $preferred = isset($options['voice']) ? trim((string) $options['voice']) : '';
        if ($preferred !== '') {
            if (in_array($preferred, $this->voices[$lang], true)) {
                $voice = $preferred;
            } else {
                $warnings[] = 'voice_not_allowed';
            }
        }

        $cacheId = $this->cache->cacheId([
            'provider' => $this->providerName(),
            'engine' => self::ENGINE_ID,
            'voice' => $voice,
            'language' => $lang,
            'rate' => self::DEFAULT_RATE,
            'text' => $cleanText,
        ]);

        $ok = $this->cache->getOrCreate($cacheId, fn (string $tmpPath): bool => $this->runEdgeTts($voice, $cleanText, $tmpPath));

        if ($ok) {
            $owner = (string) ($options['audio_owner'] ?? TtsAudioCache::currentOwner());
            $token = $this->cache->issueToken($cacheId, $owner);
            if ($token !== null) {
                return TtsResult::success(
                    cleanText: $cleanText,
                    originalText: $text,
                    language: $lang,
                    voice: $voice,
                    provider: $this->providerName(),
                    useBrowser: false,
                    audioUrl: ProductHelper::url('/ai/tts/audio/' . $token),
                    mime: 'audio/mpeg',
                    durationMs: TtsResult::estimateDurationMs($cleanText),
                    requestId: $requestId,
                    warnings: $warnings
                );
            }
        }

        // Сервер не смог синтезировать: честный переход на браузерный синтез
        error_log("[tts] {$requestId} edge-tts failed for voice {$voice}, fallback to browser");
        $fallback = $this->fallbackProvider->synthesize($text, $lang, $options);

        return new TtsResult(
            ok: $fallback->ok,
            cleanText: $fallback->cleanText,
            originalText: $fallback->originalText,
            language: $fallback->language,
            voice: $fallback->voice,
            provider: $fallback->provider,
            useBrowser: true,
            audioBase64: null,
            audioUrl: null,
            mime: null,
            durationMs: $fallback->durationMs,
            requestId: $requestId,
            warnings: array_values(array_unique(array_merge($fallback->warnings, $warnings, ['server_tts_failed', 'fallback_browser']))),
            error: $fallback->error
        );
    }

    /**
     * Запуск edge-tts без shell, с тайм-аутом; текст передаётся через временный файл 0600.
     */
    private function runEdgeTts(string $voice, string $cleanText, string $outPath): bool
    {
        $textFile = sys_get_temp_dir() . '/zk_tts_' . bin2hex(random_bytes(8)) . '.txt';
        $fh = @fopen($textFile, 'x');
        if ($fh === false) {
            return false;
        }
        fwrite($fh, $cleanText);
        fclose($fh);
        @chmod($textFile, 0600);

        try {
            $cmd = [
                $this->pythonBin, '-m', 'edge_tts',
                '--voice', $voice,
                '--rate', self::DEFAULT_RATE,
                '-f', $textFile,
                '--write-media', $outPath,
            ];
            $proc = @proc_open($cmd, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes);
            if (!is_resource($proc)) {
                return false;
            }

            $deadline = microtime(true) + $this->timeoutSeconds;
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    proc_close($proc);
                    return (int) $status['exitcode'] === 0;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($proc, 9);
                    proc_close($proc);
                    return false;
                }
                usleep(20000);
            }
        } finally {
            @unlink($textFile);
        }
    }
}
