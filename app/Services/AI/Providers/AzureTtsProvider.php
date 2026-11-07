<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Support\SpeechTextNormalizer;
use App\Services\AI\Support\TtsAudioCache;

/**
 * Лицензируемый серверный TTS: Azure AI Speech (REST, prebuilt neural voices).
 *
 * Документация: https://learn.microsoft.com/azure/ai-services/speech-service/rest-text-to-speech
 * POST https://{region}.tts.speech.microsoft.com/cognitiveservices/v1, тело — SSML,
 * заголовки Ocp-Apim-Subscription-Key, Content-Type: application/ssml+xml,
 * X-Microsoft-OutputFormat, User-Agent. Аудио не длиннее 10 минут.
 *
 * Ключ берётся только из окружения (AZURE_SPEECH_KEY), в клиент и в логи не попадает.
 * Текст ответа уходит в Microsoft (обработчик данных) — см. docs/AI_SETUP.md.
 * Code of Conduct for Microsoft AI Services требует раскрывать синтетическую природу голоса:
 * клиент помечает кнопку озвучивания («Синтезированный голос»).
 */
final class AzureTtsProvider extends AbstractCachedTtsProvider
{
    /** @var array<string, list<string>> Голоса из официального списка (language-support, 2026-11-06) */
    public const DEFAULT_VOICES = [
        'ru' => ['ru-RU-DmitryNeural', 'ru-RU-SvetlanaNeural', 'ru-RU-DariyaNeural'],
        'kk' => ['kk-KZ-AigulNeural', 'kk-KZ-DauletNeural'],
        'en' => ['en-US-JennyNeural', 'en-US-GuyNeural'],
    ];
    public const DEFAULT_OUTPUT_FORMAT = 'audio-24khz-48kbitrate-mono-mp3';
    private const MP3_FORMATS = [
        'audio-16khz-32kbitrate-mono-mp3', 'audio-16khz-64kbitrate-mono-mp3', 'audio-16khz-128kbitrate-mono-mp3',
        'audio-24khz-48kbitrate-mono-mp3', 'audio-24khz-96kbitrate-mono-mp3', 'audio-24khz-160kbitrate-mono-mp3',
        'audio-48khz-96kbitrate-mono-mp3', 'audio-48khz-192kbitrate-mono-mp3',
    ];

    private string $key;
    private string $endpoint;
    private string $outputFormat;
    private int $timeoutSeconds;
    /** @var callable|null fn(string $url, array $headers, string $body, int $timeout): array{status:int, body:string} */
    private $transport;

    /**
     * @param array<string, mixed> $config Секция `tts` из config/ai.php (ключи azure_*)
     * @param callable|null $transport Подмена HTTP-клиента в тестах
     */
    public function __construct(
        array $config = [],
        ?SpeechTextNormalizer $normalizer = null,
        ?TtsAudioCache $cache = null,
        ?callable $transport = null
    ) {
        $voices = $config['azure_voices'] ?? self::DEFAULT_VOICES;
        parent::__construct(is_array($voices) && $voices !== [] ? $voices : self::DEFAULT_VOICES, $normalizer, $cache, $config);

        $this->key = trim((string) ($config['azure_key'] ?? ''));
        $region = strtolower(trim((string) ($config['azure_region'] ?? '')));
        $endpoint = trim((string) ($config['azure_endpoint'] ?? ''));
        if ($endpoint === '' && preg_match('/^[a-z0-9]+$/', $region)) {
            $endpoint = 'https://' . $region . '.tts.speech.microsoft.com/cognitiveservices/v1';
        }
        // Только https и только домены Microsoft — защита от утечки ключа на произвольный адрес
        $this->endpoint = preg_match('#^https://[a-z0-9.-]+\.(microsoft\.com|azure\.com|azure\.us)/#i', $endpoint) ? $endpoint : '';
        $format = (string) ($config['azure_output_format'] ?? self::DEFAULT_OUTPUT_FORMAT);
        $this->outputFormat = in_array($format, self::MP3_FORMATS, true) ? $format : self::DEFAULT_OUTPUT_FORMAT;
        $this->timeoutSeconds = max(3, (int) ($config['azure_timeout_seconds'] ?? 15));
        $this->transport = $transport;
    }

    public function providerName(): string
    {
        return 'azure_speech';
    }

    protected function engineId(): string
    {
        return 'azure-rest-v1/' . $this->outputFormat;
    }

    protected function isConfigured(): bool
    {
        return $this->key !== '' && $this->endpoint !== '';
    }

    /**
     * SSML: текст экранируется как XML, язык голоса берётся из его имени (kk-KZ-AigulNeural → kk-KZ).
     */
    public function buildSsml(string $voice, string $text): string
    {
        $locale = preg_match('/^([a-z]{2,3}-[A-Z]{2})-/', $voice, $m) ? $m[1] : 'ru-RU';
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='" . $esc($locale) . "'>"
            . "<voice name='" . $esc($voice) . "'>" . $esc($text) . '</voice></speak>';
    }

    protected function produce(string $voice, string $language, string $text, string $outPath): bool
    {
        $headers = [
            'Ocp-Apim-Subscription-Key: ' . $this->key,
            'Content-Type: application/ssml+xml',
            'X-Microsoft-OutputFormat: ' . $this->outputFormat,
            'User-Agent: zakopeyki-zak-tts',
        ];
        $res = $this->transport !== null
            ? ($this->transport)($this->endpoint, $headers, $this->buildSsml($voice, $text), $this->timeoutSeconds)
            : $this->httpPost($headers, $this->buildSsml($voice, $text));

        $status = (int) ($res['status'] ?? 0);
        $body = (string) ($res['body'] ?? '');
        if ($status !== 200) {
            // 401 — ключ/регион, 429 — квота, 400/415 — запрос, 5xx — сервис. Тело ответа не логируем.
            $this->lastFailure = $status === 0 ? 'network_error' : 'http_' . $status;
            return false;
        }
        if (strlen($body) < 500) {
            $this->lastFailure = 'empty_audio';
            return false;
        }

        return file_put_contents($outPath, $body) !== false;
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string}
     */
    private function httpPost(array $headers, string $ssml): array
    {
        $ch = curl_init($this->endpoint);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $ssml,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $body === false ? 0 : $status, 'body' => is_string($body) ? $body : ''];
    }
}
