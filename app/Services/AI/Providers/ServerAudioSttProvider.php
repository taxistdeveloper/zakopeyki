<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\SpeechToTextInterface;
use App\Services\AI\Core\LanguageDetector;
use App\Services\AI\DTO\SttResult;
use App\Services\AI\Support\AudioValidator;

/**
 * Серверное распознавание речи по реальному аудио на whisper.cpp (whisper-cli).
 *
 * - Аудио обрабатывается локально на сервере и никуда не передаётся.
 * - Нативно принимается WAV (любая частота/каналы — whisper.cpp сам ресемплирует в 16 кГц моно).
 *   webm/ogg/mp4 перекодируются через ffmpeg, только если он настроен (`ffmpeg_path`).
 * - Язык передаётся явно (ru|kk|en): автоопределение whisper ошибочно принимает казахский за немецкий.
 * - Silero VAD (`whisper_vad_model_path`) отсекает тишину и шум: без него whisper выдаёт
 *   выдуманные фразы на пустой записи.
 * - confidence = среднее значение вероятностей текстовых токенов (поле `p` из -ojf JSON),
 *   а не выдуманное число. Если токенов нет — null.
 * - Все временные файлы удаляются в finally; содержимое аудио и текста не логируется.
 */
final class ServerAudioSttProvider implements SpeechToTextInterface
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.45;

    private AudioValidator $validator;
    private LanguageDetector $languageDetector;
    private ?SpeechToTextInterface $fallbackProvider;
    private ?SpeechToTextInterface $mockProvider;

    /**
     * @param array<string, mixed> $config Секция `voice` из config/ai.php
     */
    public function __construct(
        private readonly array $config = [],
        ?AudioValidator $validator = null,
        ?LanguageDetector $languageDetector = null,
        ?SpeechToTextInterface $fallbackProvider = null,
        ?SpeechToTextInterface $mockProvider = null
    ) {
        $this->validator = $validator ?? new AudioValidator([
            'max_size_bytes' => (int) ($config['max_audio_size_bytes'] ?? AudioValidator::DEFAULT_MAX_SIZE_BYTES),
            'min_size_bytes' => (int) ($config['min_audio_size_bytes'] ?? AudioValidator::DEFAULT_MIN_SIZE_BYTES),
            'max_duration_s' => (float) ($config['max_audio_duration_seconds'] ?? AudioValidator::DEFAULT_MAX_DURATION_SECONDS),
            'min_duration_s' => (float) ($config['min_audio_duration_seconds'] ?? AudioValidator::DEFAULT_MIN_DURATION_SECONDS),
            'allowed_formats' => (array) ($config['allowed_formats'] ?? ['webm', 'ogg', 'wav', 'mp4', 'm4a']),
        ]);
        $this->languageDetector = $languageDetector ?? new LanguageDetector();
        $this->fallbackProvider = $fallbackProvider;
        $this->mockProvider = $mockProvider;
    }

    public function providerName(): string
    {
        return 'server_stt';
    }

    public function isAvailable(): bool
    {
        if ($this->mockProvider !== null) {
            return $this->mockProvider->isAvailable();
        }

        return $this->isWhisperConfigured();
    }

    /**
     * whisper-cli исполняем и модель доступна для чтения.
     */
    public function isWhisperConfigured(): bool
    {
        $cli = (string) ($this->config['whisper_cli_path'] ?? '');
        $model = (string) ($this->config['whisper_model_path'] ?? '');

        return $cli !== '' && is_file($cli) && is_executable($cli)
            && $model !== '' && is_file($model) && is_readable($model);
    }

    /**
     * @param string $audioPathOrBase64OrText Путь к аудиофайлу на сервере или data:audio/...;base64
     * @param string|null $mime Заявленный клиентом MIME-тип (не доверяем, формат определяется по magic bytes)
     * @param array<string, mixed> $options request_id, language_hint
     */
    public function transcribe(string $audioPathOrBase64OrText, ?string $mime = null, array $options = []): SttResult
    {
        $start = microtime(true);
        $requestId = (string) ($options['request_id'] ?? ('stt_' . bin2hex(random_bytes(6))));

        $filePath = $audioPathOrBase64OrText;

        /** @var list<string> $tempFiles Временные файлы, удаляемые в finally */
        $tempFiles = [];

        if (str_starts_with($filePath, 'data:audio/') || (!is_file($filePath) && strlen($filePath) > 200)) {
            $binary = $this->decodeBase64Audio($filePath);
            if ($binary === null) {
                return SttResult::failure(
                    error: 'Некорректный base64 аудиопоток.',
                    provider: $this->providerName(),
                    requestId: $requestId,
                    warnings: ['corrupted_base64']
                );
            }
            $tempPath = $this->secureTempFile('.bin');
            if ($tempPath === null || file_put_contents($tempPath, $binary) === false) {
                if ($tempPath !== null && is_file($tempPath)) {
                    @unlink($tempPath);
                }
                return SttResult::failure(
                    error: 'Не удалось сохранить временный аудиофайл на сервере.',
                    provider: $this->providerName(),
                    requestId: $requestId
                );
            }
            unset($binary);
            $filePath = $tempPath;
            $tempFiles[] = $tempPath;
        }

        try {
            $validation = $this->validator->validateFile($filePath, $mime);
            if (!$validation['valid']) {
                return SttResult::failure(
                    error: $validation['error'] ?? 'Ошибка валидации аудиофайла.',
                    provider: $this->providerName(),
                    requestId: $requestId,
                    warnings: array_values(array_filter([$validation['error_code'] ?? null]))
                );
            }

            // Тестовый режим: изолированные тесты подставляют mock-бэкенд
            if ($this->mockProvider !== null) {
                if (!$this->mockProvider->isAvailable()) {
                    return SttResult::failure(
                        error: 'Сервис распознавания речи временно недоступен.',
                        provider: $this->providerName(),
                        requestId: $requestId,
                        warnings: ['provider_offline']
                    );
                }
                $result = $this->mockProvider->transcribe($filePath, $validation['mime'], array_merge($options, [
                    'request_id' => $requestId,
                    'detected_format' => $validation['format'],
                ]));
                return $result instanceof SttResult ? $result : SttResult::failure(
                    error: 'Некорректный ответ тестового провайдера.',
                    provider: $this->providerName(),
                    requestId: $requestId
                );
            }

            if (!$this->isWhisperConfigured()) {
                return SttResult::failure(
                    error: 'Серверный движок распознавания речи (whisper.cpp) не настроен на сервере. Используйте браузерный ввод или текст.',
                    provider: $this->providerName(),
                    requestId: $requestId,
                    warnings: ['server_stt_not_configured', 'fallback_to_client_recommended'],
                    durationMs: $this->elapsedMs($start)
                );
            }

            // whisper.cpp нативно читает WAV; остальное — только через ffmpeg
            $wavPath = $filePath;
            if ($validation['format'] !== 'wav') {
                $converted = $this->convertToWav($filePath);
                if ($converted === null) {
                    return SttResult::failure(
                        error: 'Формат записи не поддерживается сервером распознавания. Требуется WAV.',
                        provider: $this->providerName(),
                        requestId: $requestId,
                        warnings: ['unsupported_format', 'wav_required'],
                        durationMs: $this->elapsedMs($start)
                    );
                }
                $wavPath = $converted;
                $tempFiles[] = $converted;
            }

            $language = $this->resolveLanguage(isset($options['language_hint']) ? (string) $options['language_hint'] : null);

            $slot = $this->acquireSlot();
            if ($slot === null) {
                return SttResult::failure(
                    error: 'Сервер распознавания речи сейчас перегружен. Попробуйте через несколько секунд.',
                    provider: $this->providerName(),
                    requestId: $requestId,
                    warnings: ['stt_busy'],
                    durationMs: $this->elapsedMs($start)
                );
            }

            try {
                return $this->runWhisperCli($wavPath, $language, $requestId, $start, $tempFiles);
            } finally {
                flock($slot, LOCK_UN);
                fclose($slot);
            }
        } finally {
            foreach ($tempFiles as $tmp) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    /**
     * Запуск whisper-cli без shell (массив аргументов), с тайм-аутом и JSON-выводом во временный файл.
     *
     * @param list<string> $tempFiles
     */
    private function runWhisperCli(string $wavPath, string $language, string $requestId, float $start, array &$tempFiles): SttResult
    {
        $outBase = $this->tempPath('');
        $jsonPath = $outBase . '.json';
        $tempFiles[] = $jsonPath;
        // JSON содержит текст распознавания: создаём заранее с правами 0600 (whisper перезапишет содержимое)
        $this->createPrivateFile($jsonPath);

        $cmd = [
            (string) $this->config['whisper_cli_path'],
            '-m', (string) $this->config['whisper_model_path'],
            '-f', $wavPath,
            '-l', $language,
            '-t', (string) max(1, (int) ($this->config['whisper_threads'] ?? 4)),
            '-np',
            '-nt',
            '-ojf',
            '-of', $outBase,
        ];

        $vadModel = (string) ($this->config['whisper_vad_model_path'] ?? '');
        $vadUsed = $vadModel !== '' && is_file($vadModel) && is_readable($vadModel);
        if ($vadUsed) {
            array_push($cmd, '--vad', '-vm', $vadModel);
        }

        $timeout = max(5, (int) ($this->config['whisper_timeout_seconds'] ?? 30));
        $exit = $this->runProcess($cmd, $timeout);

        if ($exit === null) {
            error_log("[stt] {$requestId} whisper timeout after {$timeout}s");
            return SttResult::failure(
                error: 'Распознавание речи заняло слишком много времени.',
                provider: 'whisper_cpp',
                requestId: $requestId,
                warnings: ['stt_timeout'],
                durationMs: $this->elapsedMs($start)
            );
        }

        $raw = is_file($jsonPath) ? file_get_contents($jsonPath) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if ($exit !== 0 || !is_array($data)) {
            error_log("[stt] {$requestId} whisper failed, exit code {$exit}");
            return SttResult::failure(
                error: 'Ошибка при обработке аудио сервером распознавания.',
                provider: 'whisper_cpp',
                requestId: $requestId,
                warnings: ['whisper_exit_code_' . $exit],
                durationMs: $this->elapsedMs($start)
            );
        }

        $parsed = $this->parseWhisperJson($data);
        $text = $parsed['text'];

        if ($text === '') {
            return SttResult::failure(
                error: 'Речь не обнаружена. Попробуйте ещё раз.',
                provider: 'whisper_cpp',
                requestId: $requestId,
                warnings: ['no_speech_detected'],
                durationMs: $this->elapsedMs($start)
            );
        }

        $warnings = [];
        if (!$vadUsed) {
            $warnings[] = 'vad_model_missing';
        }
        if ($parsed['confidence'] !== null && $parsed['confidence'] < self::LOW_CONFIDENCE_THRESHOLD) {
            $warnings[] = 'low_confidence_recognition';
        }

        return SttResult::success(
            text: $text,
            language: $parsed['language'] ?? $language,
            confidence: $parsed['confidence'],
            provider: 'whisper_cpp',
            durationMs: $this->elapsedMs($start),
            requestId: $requestId,
            warnings: $warnings
        );
    }

    /**
     * Разбор JSON whisper-cli (-ojf).
     *
     * @param array<string, mixed> $data
     * @return array{text: string, language: ?string, confidence: ?float}
     */
    public function parseWhisperJson(array $data): array
    {
        $parts = [];
        $probSum = 0.0;
        $probCount = 0;

        foreach ((array) ($data['transcription'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $parts[] = trim((string) ($segment['text'] ?? ''));

            foreach ((array) ($segment['tokens'] ?? []) as $token) {
                if (!is_array($token) || !isset($token['p']) || !is_numeric($token['p'])) {
                    continue;
                }
                $tokText = (string) ($token['text'] ?? '');
                // Служебные токены [_BEG_], [_TT_123] и т.п. не участвуют в оценке
                if (str_starts_with($tokText, '[_') || trim($tokText) === '') {
                    continue;
                }
                $probSum += (float) $token['p'];
                $probCount++;
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, static fn (string $p) => $p !== ''))));
        $lang = $data['result']['language'] ?? null;

        return [
            'text' => $text,
            'language' => is_string($lang) && $lang !== '' ? $lang : null,
            'confidence' => $probCount > 0 ? $probSum / $probCount : null,
        ];
    }

    /**
     * Язык распознавания: ru|kk|en из подсказки клиента (ru-RU → ru), иначе язык по умолчанию.
     */
    public function resolveLanguage(?string $hint): string
    {
        $supported = (array) ($this->config['stt_supported_languages'] ?? ['ru', 'kk', 'en']);
        $default = (string) ($this->config['stt_default_language'] ?? 'ru');

        $code = strtolower(substr(trim((string) $hint), 0, 2));

        return in_array($code, $supported, true) ? $code : $default;
    }

    /**
     * Выполнение процесса без shell с ограничением времени.
     *
     * @param list<string> $cmd
     * @return int|null Код завершения или null при тайм-ауте
     */
    private function runProcess(array $cmd, int $timeoutSeconds): ?int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return -1;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $exitCode = -1;

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                proc_close($proc);
                return null;
            }
            usleep(20000);
        }

        proc_close($proc);

        return $exitCode;
    }

    /**
     * Перекодирование в WAV 16 кГц моно через ffmpeg (если настроен).
     */
    private function convertToWav(string $inputPath): ?string
    {
        $ffmpeg = (string) ($this->config['ffmpeg_path'] ?? '');
        if ($ffmpeg === '' || !is_file($ffmpeg) || !is_executable($ffmpeg)) {
            return null;
        }

        $out = $this->secureTempFile('.wav');
        if ($out === null) {
            return null;
        }
        $exit = $this->runProcess([
            $ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $inputPath,
            '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le',
            $out,
        ], 20);

        if ($exit !== 0 || !is_file($out) || filesize($out) <= 44) {
            if (is_file($out)) {
                @unlink($out);
            }
            return null;
        }

        return $out;
    }

    /**
     * Семафор на N одновременных процессов whisper (flock на файлах-слотах).
     *
     * @return resource|null
     */
    private function acquireSlot()
    {
        $max = max(1, (int) ($this->config['whisper_max_concurrent'] ?? 2));
        $dir = sys_get_temp_dir();

        for ($i = 0; $i < $max; $i++) {
            $fh = @fopen($dir . '/zk_whisper_slot_' . $i . '.lock', 'c');
            if ($fh === false) {
                continue;
            }
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            fclose($fh);
        }

        return null;
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/zk_stt_' . bin2hex(random_bytes(8)) . $suffix;
    }

    /**
     * Новый временный файл, доступный только владельцу процесса (0600).
     */
    private function secureTempFile(string $suffix): ?string
    {
        $path = $this->tempPath($suffix);

        return $this->createPrivateFile($path) ? $path : null;
    }

    private function createPrivateFile(string $path): bool
    {
        $fh = @fopen($path, 'x'); // 'x' — не перезаписывать существующий (защита от подмены)
        if ($fh === false) {
            return false;
        }
        fclose($fh);

        return @chmod($path, 0600);
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function decodeBase64Audio(string $data): ?string
    {
        if (preg_match('/^data:audio\/[a-zA-Z0-9.+-]+(?:;[a-zA-Z0-9=.+-]+)*;base64,(.+)$/s', $data, $matches)) {
            $data = $matches[1];
        }

        $decoded = base64_decode($data, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }
}
