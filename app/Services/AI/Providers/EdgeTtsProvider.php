<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Support\SpeechTextNormalizer;
use App\Services\AI\Support\TtsAudioCache;

/**
 * Серверный синтез через python-библиотеку edge-tts (голоса Microsoft Edge «Read Aloud»).
 *
 * ВНИМАНИЕ: edge-tts обращается к неофициальному endpoint онлайн-чтения Microsoft Edge, а не к
 * Azure AI Speech. Правового основания для коммерческого использования нет (условия Edge §2.8),
 * SLA нет, 2026-11-06 русские голоса отклоняли тексты с многозначными числами.
 * Для продакшена используйте AzureTtsProvider (AI_TTS_PROVIDER=azure). См. docs/AI_SETUP.md.
 */
final class EdgeTtsProvider extends AbstractCachedTtsProvider
{
    private const ENGINE_ID = 'edge-tts/7';
    private const DEFAULT_RATE = '+0%';

    /** @var array<string, list<string>> Проверено реальным синтезом 2026-11-06 */
    public const DEFAULT_VOICES = [
        'ru' => ['ru-RU-DmitryNeural', 'ru-RU-SvetlanaNeural'],
        'kk' => ['kk-KZ-AigulNeural', 'kk-KZ-DauletNeural'],
        'en' => ['en-US-JennyNeural', 'en-US-GuyNeural'],
    ];

    private string $pythonBin;
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
        $voices = $config['voices'] ?? self::DEFAULT_VOICES;
        parent::__construct(is_array($voices) && $voices !== [] ? $voices : self::DEFAULT_VOICES, $normalizer, $cache, $config);
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

    protected function engineId(): string
    {
        return self::ENGINE_ID . '/' . self::DEFAULT_RATE;
    }

    protected function isConfigured(): bool
    {
        return $this->pythonBin !== '';
    }

    /**
     * Запуск edge-tts без shell, с тайм-аутом; текст передаётся через временный файл 0600.
     */
    protected function produce(string $voice, string $language, string $text, string $outPath): bool
    {
        $textFile = sys_get_temp_dir() . '/zk_tts_' . bin2hex(random_bytes(8)) . '.txt';
        $fh = @fopen($textFile, 'x');
        if ($fh === false) {
            return false;
        }
        fwrite($fh, $text);
        fclose($fh);
        @chmod($textFile, 0600);

        try {
            $proc = @proc_open([
                $this->pythonBin, '-m', 'edge_tts',
                '--voice', $voice,
                '--rate', self::DEFAULT_RATE,
                '-f', $textFile,
                '--write-media', $outPath,
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes);
            if (!is_resource($proc)) {
                $this->lastFailure = 'process_start_failed';
                return false;
            }

            $deadline = microtime(true) + $this->timeoutSeconds;
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    proc_close($proc);
                    if ((int) $status['exitcode'] !== 0) {
                        $this->lastFailure = 'exit_' . (int) $status['exitcode'];
                        return false;
                    }
                    return true;
                }
                if (microtime(true) >= $deadline) {
                    proc_terminate($proc, 9);
                    proc_close($proc);
                    $this->lastFailure = 'timeout';
                    return false;
                }
                usleep(20000);
            }
        } finally {
            @unlink($textFile);
        }
    }
}
