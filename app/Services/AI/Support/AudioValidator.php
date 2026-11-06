<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

/**
 * Валидатор входящих аудиоданных на бэкенде.
 * Не доверяет заголовкам клиента и проверяет magic bytes, лимиты размера и длительности.
 */
final class AudioValidator
{
    public const DEFAULT_MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 МБ
    public const DEFAULT_MIN_SIZE_BYTES = 120; // отсечение пустых файлов
    public const DEFAULT_MAX_DURATION_SECONDS = 60.0;
    public const DEFAULT_MIN_DURATION_SECONDS = 0.3;

    /**
     * @param array{
     *   max_size_bytes?: int,
     *   min_size_bytes?: int,
     *   max_duration_s?: float,
     *   min_duration_s?: float,
     *   allowed_formats?: list<string>
     * } $config
     */
    public function __construct(
        private readonly array $config = []
    ) {
    }

    /**
     * @return array{
     *   valid: bool,
     *   format: ?string,
     *   mime: ?string,
     *   size: int,
     *   duration_s: ?float,
     *   error: ?string,
     *   error_code: ?string
     * }
     */
    public function validateFile(string $filePath, ?string $clientMime = null): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return [
                'valid' => false,
                'format' => null,
                'mime' => null,
                'size' => 0,
                'duration_s' => null,
                'error' => 'Файл аудио не найден или недоступен для чтения.',
                'error_code' => 'file_not_found',
            ];
        }

        $size = (int) filesize($filePath);
        $maxSize = (int) ($this->config['max_size_bytes'] ?? self::DEFAULT_MAX_SIZE_BYTES);
        $minSize = (int) ($this->config['min_size_bytes'] ?? self::DEFAULT_MIN_SIZE_BYTES);

        if ($size < $minSize) {
            return [
                'valid' => false,
                'format' => null,
                'mime' => null,
                'size' => $size,
                'duration_s' => null,
                'error' => 'Аудиозапись слишком короткая или пустая.',
                'error_code' => 'recording_too_short',
            ];
        }

        if ($size > $maxSize) {
            $mb = round($maxSize / (1024 * 1024), 1);
            return [
                'valid' => false,
                'format' => null,
                'mime' => null,
                'size' => $size,
                'duration_s' => null,
                'error' => "Размер аудиофайла превышает лимит ({$mb} МБ).",
                'error_code' => 'exceeded_size',
            ];
        }

        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            return [
                'valid' => false,
                'format' => null,
                'mime' => null,
                'size' => $size,
                'duration_s' => null,
                'error' => 'Не удалось прочитать содержимое аудиофайла.',
                'error_code' => 'file_read_error',
            ];
        }

        $header = fread($handle, 64) ?: '';
        fclose($handle);

        $detected = $this->detectFormatFromHeader($header);
        if ($detected === null) {
            return [
                'valid' => false,
                'format' => null,
                'mime' => null,
                'size' => $size,
                'duration_s' => null,
                'error' => 'Неподдерживаемый или повреждённый формат аудиофайла.',
                'error_code' => 'unsupported_format',
            ];
        }

        $format = $detected['format'];
        $mime = $detected['mime'];

        $allowed = $this->config['allowed_formats'] ?? ['webm', 'ogg', 'wav', 'mp4', 'm4a'];
        if (!in_array($format, $allowed, true)) {
            return [
                'valid' => false,
                'format' => $format,
                'mime' => $mime,
                'size' => $size,
                'duration_s' => null,
                'error' => "Формат аудио '{$format}' запрещён политикой безопасности.",
                'error_code' => 'format_not_allowed',
            ];
        }

        $duration = $this->estimateDuration($filePath, $format, $size);
        $maxDuration = (float) ($this->config['max_duration_s'] ?? self::DEFAULT_MAX_DURATION_SECONDS);
        $minDuration = (float) ($this->config['min_duration_s'] ?? self::DEFAULT_MIN_DURATION_SECONDS);

        if ($duration !== null) {
            if ($duration > $maxDuration) {
                return [
                    'valid' => false,
                    'format' => $format,
                    'mime' => $mime,
                    'size' => $size,
                    'duration_s' => $duration,
                    'error' => "Длительность аудиозаписи ({$duration} с) превышает лимит ({$maxDuration} с).",
                    'error_code' => 'exceeded_duration',
                ];
            }
            if ($duration < $minDuration) {
                return [
                    'valid' => false,
                    'format' => $format,
                    'mime' => $mime,
                    'size' => $size,
                    'duration_s' => $duration,
                    'error' => 'Аудиозапись слишком короткая.',
                    'error_code' => 'recording_too_short',
                ];
            }
        }

        return [
            'valid' => true,
            'format' => $format,
            'mime' => $mime,
            'size' => $size,
            'duration_s' => $duration,
            'error' => null,
            'error_code' => null,
        ];
    }

    /**
     * Определение формата по magic bytes в заголовке файла.
     *
     * @return array{format: string, mime: string}|null
     */
    public function detectFormatFromHeader(string $header): ?array
    {
        if (strlen($header) < 12) {
            return null;
        }

        // RIFF .... WAVE
        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WAVE') {
            return ['format' => 'wav', 'mime' => 'audio/wav'];
        }

        // OggS
        if (str_starts_with($header, 'OggS')) {
            return ['format' => 'ogg', 'mime' => 'audio/ogg'];
        }

        // WebM / EBML: 0x1A 0x45 0xDF 0xA3
        if (str_starts_with($header, "\x1A\x45\xDF\xA3")) {
            return ['format' => 'webm', 'mime' => 'audio/webm'];
        }

        // ISO Base Media File Format (MP4 / M4A): 'ftyp' at offset 4
        if (substr($header, 4, 4) === 'ftyp') {
            return ['format' => 'mp4', 'mime' => 'audio/mp4'];
        }

        return null;
    }

    /**
     * Оценка длительности аудио в секундах.
     */
    private function estimateDuration(string $filePath, string $format, int $size): ?float
    {
        if ($format === 'wav') {
            $handle = @fopen($filePath, 'rb');
            if ($handle) {
                $head = fread($handle, 44);
                fclose($handle);
                if (is_string($head) && strlen($head) >= 36) {
                    $byteRate = unpack('V', substr($head, 28, 4))[1] ?? 0;
                    if ($byteRate > 0) {
                        $dataSize = max(0, $size - 44);
                        return round($dataSize / $byteRate, 2);
                    }
                }
            }
        }

        // Для сжатых форматов WebM/OGG/MP4 при среднем битрейте 64-128 kbps
        // 10 МБ = ~10-20 минут, грубая проверка если файл подозрительно огромный
        return null;
    }
}

