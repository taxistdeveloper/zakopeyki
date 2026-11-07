<?php

declare(strict_types=1);

namespace App\Services\AI\Support;

use App\Services\AI\Contracts\TextToSpeechInterface;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Providers\AzureTtsProvider;
use App\Services\AI\Providers\BrowserTtsProvider;
use App\Services\AI\Providers\EdgeTtsProvider;
use App\Services\AI\Providers\MockTtsProvider;

/**
 * Фабрика TTS-провайдеров.
 */
final class TtsFactory
{
    private static ?TextToSpeechInterface $mockInstance = null;

    public static function create(?string $preferredProvider = null): TextToSpeechInterface
    {
        if (self::$mockInstance !== null) {
            return self::$mockInstance;
        }

        $ttsConfig = (array) AiConfig::get('tts', []);
        $provider = strtolower((string) ($preferredProvider ?? ($ttsConfig['provider'] ?? 'browser')));

        if ($provider === 'mock') {
            return new MockTtsProvider();
        }

        if ($provider === 'azure' || $provider === 'azure_speech') {
            return new AzureTtsProvider(config: $ttsConfig);
        }

        if ($provider === 'edge' || $provider === 'edge_neural' || $provider === 'server') {
            return new EdgeTtsProvider(config: $ttsConfig);
        }

        // По умолчанию: браузерный Web Speech Synthesis с выбором живых человеческих голосов (Milena, Yuri, Dmitry)
        return new BrowserTtsProvider();
    }

    public static function setMockInstance(?TextToSpeechInterface $mock): void
    {
        self::$mockInstance = $mock;
    }
}

