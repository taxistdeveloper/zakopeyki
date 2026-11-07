<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Helpers\ProductHelper;
use App\Services\AI\Contracts\TextToSpeechInterface;
use App\Services\AI\DTO\TtsResult;
use App\Services\AI\Support\SpeechTextNormalizer;
use App\Services\AI\Support\TtsAudioCache;

/**
 * Общий конвейер серверного TTS (Azure, Edge):
 * нормализация текста → выбор голоса из белого списка → закрытый кеш TtsAudioCache →
 * подписанная ссылка /ai/tts/audio/{token} → при сбое честный браузерный fallback
 * (use_browser=true, warnings: server_tts_failed, fallback_browser, причина).
 *
 * Наследник реализует только сам синтез в файл (produce) и идентификатор движка для ключа кеша.
 */
abstract class AbstractCachedTtsProvider implements TextToSpeechInterface
{
    protected SpeechTextNormalizer $normalizer;
    protected BrowserTtsProvider $fallbackProvider;
    protected TtsAudioCache $cache;
    /** @var array<string, list<string>> */
    protected array $voices;
    /** Причина последнего сбоя синтеза (код без секретов) — попадает в warnings. */
    protected ?string $lastFailure = null;

    /**
     * @param array<string, list<string>> $voices
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $voices,
        ?SpeechTextNormalizer $normalizer = null,
        ?TtsAudioCache $cache = null,
        array $config = []
    ) {
        $this->normalizer = $normalizer ?? new SpeechTextNormalizer();
        $this->fallbackProvider = new BrowserTtsProvider($this->normalizer);
        $this->cache = $cache ?? new TtsAudioCache(
            null,
            null,
            isset($config['cache_ttl_seconds']) ? (int) $config['cache_ttl_seconds'] : null,
            isset($config['url_ttl_seconds']) ? (int) $config['url_ttl_seconds'] : null
        );
        $this->voices = $voices;
    }

    /** Идентификатор движка и формата: входит в ключ кеша, его смена инвалидирует кеш. */
    abstract protected function engineId(): string;

    /** Синтез $text голосом $voice в файл $outPath. true — файл записан. */
    abstract protected function produce(string $voice, string $language, string $text, string $outPath): bool;

    /** Готов ли провайдер к синтезу (ключи, бинарники). Если нет — сразу fallback. */
    abstract protected function isConfigured(): bool;

    public function isAvailable(): bool
    {
        return $this->isConfigured();
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
        if (!isset($this->voices[$lang]) || $this->voices[$lang] === []) {
            $lang = 'ru';
        }
        $cleanText = $this->normalizer->normalize($text, $lang);
        $requestId = (string) ($options['request_id'] ?? ('tts_' . $this->providerName() . '_' . bin2hex(random_bytes(6))));

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

        $this->lastFailure = null;
        if (!$this->isConfigured()) {
            $this->lastFailure = 'provider_not_configured';
        } else {
            $cacheId = $this->cache->cacheId([
                'provider' => $this->providerName(),
                'engine' => $this->engineId(),
                'voice' => $voice,
                'language' => $lang,
                'text' => $cleanText,
            ]);

            $ok = $this->cache->getOrCreate(
                $cacheId,
                fn (string $tmpPath): bool => $this->produce($voice, $lang, $cleanText, $tmpPath)
            );

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
            $this->lastFailure ??= 'synthesis_failed';
        }

        error_log("[tts] {$requestId} {$this->providerName()} failed ({$this->lastFailure}) for voice {$voice}, fallback to browser");
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
            warnings: array_values(array_unique(array_merge(
                $fallback->warnings,
                $warnings,
                ['server_tts_failed', 'fallback_browser', $this->providerName() . ':' . $this->lastFailure]
            ))),
            error: $fallback->error
        );
    }
}
