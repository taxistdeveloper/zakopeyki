<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

use App\Services\AI\Contracts\SpeechToTextInterface;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Providers\ClientTranscriptSttProvider;
use App\Services\AI\Providers\MockSttProvider;
use App\Services\AI\Providers\ServerAudioSttProvider;

/**
 * Фабрика STT-провайдеров в соответствии с конфигурацией.
 */
final class SttFactory
{
    private static ?SpeechToTextInterface $mockInstance = null;

    public static function create(?string $preferredProvider = null): SpeechToTextInterface
    {
        if (self::$mockInstance !== null) {
            return self::$mockInstance;
        }

        $voiceConfig = (array) AiConfig::get('voice', []);
        $provider = $preferredProvider ?? (string) ($voiceConfig['stt_provider'] ?? 'web_speech');

        if ($provider === 'mock') {
            return new MockSttProvider();
        }

        if ($provider === 'server_stt' || $provider === 'whisper') {
            return new ServerAudioSttProvider($voiceConfig);
        }

        // По умолчанию: клиентский провайдер с серверной нормализацией
        return new ClientTranscriptSttProvider();
    }

    public static function createForAudioFile(): ServerAudioSttProvider
    {
        $voiceConfig = (array) AiConfig::get('voice', []);
        $mock = self::$mockInstance instanceof MockSttProvider ? self::$mockInstance : null;

        return new ServerAudioSttProvider(
            config: $voiceConfig,
            mockProvider: $mock
        );
    }

    public static function setMockInstance(?SpeechToTextInterface $mock): void
    {
        self::$mockInstance = $mock;
    }
}

