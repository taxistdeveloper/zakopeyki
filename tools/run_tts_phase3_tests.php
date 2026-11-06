<?php
/**
 * Stage 03: TTS (Text-to-Speech) Comprehensive Test Suite
 * Zakopeyki.kz AI Assistant Zak
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\AI\DTO\TtsResult;
use App\Services\AI\Support\SpeechTextNormalizer;
use App\Services\AI\Contracts\TextToSpeechInterface;
use App\Services\AI\Providers\BrowserTtsProvider;
use App\Services\AI\Providers\MockTtsProvider;
use App\Services\AI\Support\TtsFactory;
use App\Services\AI\Core\AiConfig;
use App\Controllers\AiAssistantController;
use App\Core\Router;

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$failures = [];

function it(string $description, callable $fn): void {
    global $totalTests, $passedTests, $failedTests, $failures;
    $totalTests++;
    try {
        $fn();
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } catch (\Throwable $e) {
        $failedTests++;
        $failures[] = "{$description}: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")";
        echo "  [FAIL] {$description}\n";
        echo "         -> " . $e->getMessage() . "\n";
    }
}

function assertTrue($val, string $msg = 'Expected true'): void {
    if ($val !== true) {
        throw new \Exception($msg . ' (got: ' . var_export($val, true) . ')');
    }
}

function assertEquals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \Exception(($msg ? $msg . ' ' : '') . 'Expected: ' . var_export($expected, true) . ', got: ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $msg = ''): void {
    if (mb_strpos($haystack, $needle) === false) {
        throw new \Exception(($msg ? $msg . ' ' : '') . "Expected '{$haystack}' to contain '{$needle}'");
    }
}

echo "====================================================================\n";
echo "STAGE 03: TEXT-TO-SPEECH (TTS) AUTOMATED TEST SUITE\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------
// Group 1: TtsResult DTO
// -----------------------------------------------------------------
echo "--- Group 1: TtsResult DTO ---\n";

it('TtsResult initializes with valid fields and getters', function () {
    $dto = new TtsResult(
        ok: true,
        cleanText: 'Привет мир',
        originalText: '**Привет мир**',
        language: 'ru',
        voice: 'ru-female',
        provider: 'browser',
        useBrowser: true,
        durationMs: 1200,
        requestId: 'req_123',
        warnings: ['trimmed']
    );

    assertTrue($dto->ok);
    assertEquals('Привет мир', $dto->cleanText);
    assertEquals('**Привет мир**', $dto->originalText);
    assertEquals('ru', $dto->language);
    assertEquals('ru-female', $dto->voice);
    assertEquals('browser', $dto->provider);
    assertTrue($dto->useBrowser);
    assertEquals(1200, $dto->durationMs);
    assertEquals('req_123', $dto->requestId);
    assertEquals(['trimmed'], $dto->warnings);
    assertEquals(null, $dto->error);
});

it('TtsResult implements JsonSerializable and ArrayAccess', function () {
    $dto = new TtsResult(
        ok: true,
        cleanText: 'Сәлем',
        originalText: 'Сәлем',
        language: 'kk',
        provider: 'mock',
        useBrowser: false,
        durationMs: 500
    );

    $arr = $dto->toArray();
    assertEquals('Сәлем', $arr['clean_text']);
    assertEquals('kk', $arr['language']);
    assertEquals('mock', $arr['provider']);
    assertEquals(false, $arr['use_browser']);
    assertEquals(500, $arr['duration_ms']);

    // ArrayAccess check
    assertEquals('Сәлем', $dto['clean_text']);
    assertEquals('mock', $dto['provider']);
    assertTrue(isset($dto['clean_text']));

    // JsonSerialize check
    $json = json_encode($dto);
    assertTrue(is_string($json));
    $decoded = json_decode($json, true);
    assertEquals('Сәлем', $decoded['clean_text']);
    assertEquals('mock', $decoded['provider']);
});

it('TtsResult calculates estimated duration from words', function () {
    $words = implode(' ', array_fill(0, 150, 'слово')); // 150 words = ~1 minute = 60,000ms
    $dur = TtsResult::estimateDurationMs($words);
    assertTrue($dur >= 55000 && $dur <= 65000, "Estimated duration for 150 words should be ~60000ms, got: {$dur}");
});

// -----------------------------------------------------------------
// Group 2: SpeechTextNormalizer
// -----------------------------------------------------------------
echo "\n--- Group 2: SpeechTextNormalizer ---\n";

it('removes markdown formatting (bold, italic, strikethrough, headers)', function () {
    $raw = "### Заголовок\n\nЭто **жирный** и *курсивный* текст, а также ~~зачёркнутый~~.";
    $clean = SpeechTextNormalizer::clean($raw, 'ru');
    assertContains('Заголовок.', $clean);
    assertContains('Это жирный и курсивный текст, а также зачёркнутый.', $clean);
    assertTrue(strpos($clean, '**') === false);
    assertTrue(strpos($clean, '###') === false);
    assertTrue(strpos($clean, '~~') === false);
});

it('removes markdown code blocks and inline code', function () {
    $raw = "Вот пример команды: `php bin/console test`.\n```bash\nrm -rf /tmp\n```\nИ дальше текст.";
    $clean = SpeechTextNormalizer::clean($raw, 'ru');
    assertContains('php bin/console test', $clean);
    assertTrue(strpos($clean, 'rm -rf') === false, "Code blocks should be stripped from speech");
    assertContains('И дальше текст.', $clean);
});

it('normalizes markdown links to link text', function () {
    $raw = "Посмотрите [смартфон Apple iPhone 13](https://zakopeyki.kz/item/99) в каталоге.";
    $clean = SpeechTextNormalizer::clean($raw, 'ru');
    assertContains('смартфон Apple iPhone 13', $clean);
    assertTrue(strpos($clean, 'https://zakopeyki.kz') === false);
});

it('removes HTML tags and scripts safely', function () {
    $raw = "<p>Привет <span class=\"bold\">мир</span>! <script>alert(1)</script></p>";
    $clean = SpeechTextNormalizer::clean($raw, 'ru');
    assertEquals('Привет мир!', $clean);
    assertTrue(strpos($clean, '<') === false);
});

it('expands currencies in Russian', function () {
    $raw = "Цена: 300 000 ₸, а доставка 1 500 тенге, или $100, или €50.";
    $clean = SpeechTextNormalizer::clean($raw, 'ru');
    assertContains('300 тысяч тенге', $clean);
    assertContains('1500 тенге', $clean);
    assertContains('100 долларов', $clean);
    assertContains('50 евро', $clean);
});

it('expands currencies in Kazakh', function () {
    $raw = "Бағасы 300 000 ₸, ал жеткізу 500 теңге.";
    $clean = SpeechTextNormalizer::clean($raw, 'kk');
    assertContains('300 мың теңге', $clean);
    assertContains('500 теңге', $clean);
});

it('expands currencies in English', function () {
    $raw = "The price is 300 000 ₸, shipping 500 KZT, total $100.";
    $clean = SpeechTextNormalizer::clean($raw, 'en');
    assertContains('300 thousand tenge', $clean);
    assertContains('500 tenge', $clean);
    assertContains('100 dollars', $clean);
});

it('preserves authentic Kazakh characters', function () {
    $raw = "Сәлеметсіз бе! Бұл заттың құны қанша? Жағдайы өте жақсы, ұсынысыңызға рақмет.";
    $clean = SpeechTextNormalizer::clean($raw, 'kk');
    assertContains('Сәлеметсіз бе!', $clean);
    assertContains('құны қанша?', $clean);
    assertContains('өте жақсы', $clean);
    assertContains('ұсынысыңызға рақмет.', $clean);
});

it('truncates at sentence or word boundary without breaking words', function () {
    $long = "Первое предложение о товаре. Второе предложение о доставке и условиях сервиса. Третье предложение о гарантии качества.";
    $truncated = SpeechTextNormalizer::truncate($long, 60);
    assertTrue(mb_strlen($truncated, 'UTF-8') <= 60);
    assertTrue(str_ends_with($truncated, '.') || str_ends_with($truncated, '…'));
    assertContains('Первое предложение о товаре.', $truncated);
});

it('handles empty and whitespace-only text safely', function () {
    assertEquals('', SpeechTextNormalizer::clean('', 'ru'));
    assertEquals('', SpeechTextNormalizer::clean("   \n\t  ", 'ru'));
    assertEquals('', SpeechTextNormalizer::clean(null ?? '', 'ru'));
});

// -----------------------------------------------------------------
// Group 3: BrowserTtsProvider
// -----------------------------------------------------------------
echo "\n--- Group 3: BrowserTtsProvider ---\n";

it('BrowserTtsProvider implements TextToSpeechInterface and returns providerName browser', function () {
    $provider = new BrowserTtsProvider();
    assertTrue($provider instanceof TextToSpeechInterface);
    assertEquals('browser', $provider->providerName());
    assertTrue($provider->isAvailable());
});

it('BrowserTtsProvider synthesizes text to TtsResult with useBrowser=true', function () {
    $provider = new BrowserTtsProvider();
    $result = $provider->synthesize("Товар стоит **15 000 ₸**", 'ru');

    assertTrue($result instanceof TtsResult);
    assertTrue($result->useBrowser);
    assertEquals('browser', $result->provider);
    assertEquals('ru', $result->language);
    assertContains('15 тысяч тенге', $result->cleanText);
    assertTrue($result->durationMs > 0);
    assertTrue(str_starts_with($result->requestId, 'tts_'));
});

it('BrowserTtsProvider adds warning when maxChars is exceeded', function () {
    $provider = new BrowserTtsProvider(maxChars: 30);
    $result = $provider->synthesize("Это очень длинное предложение, которое заведомо превысит ограничение в тридцать символов.", 'ru');

    assertTrue(mb_strlen($result->cleanText, 'UTF-8') <= 30);
    assertTrue(count($result->warnings) > 0);
    assertContains('text_truncated_to_max_chars', $result->warnings[0]);
});

// -----------------------------------------------------------------
// Group 4: MockTtsProvider
// -----------------------------------------------------------------
echo "\n--- Group 4: MockTtsProvider ---\n";

it('MockTtsProvider synthesizes canned audio or mock text', function () {
    $provider = new MockTtsProvider();
    assertTrue($provider instanceof TextToSpeechInterface);
    assertEquals('mock', $provider->providerName());
    assertTrue($provider->isAvailable());

    $result = $provider->synthesize("Тестовое сообщение", 'kk');
    assertTrue($result instanceof TtsResult);
    assertEquals('mock', $result->provider);
    assertEquals('kk', $result->language);
    assertEquals('Тестовое сообщение', $result->cleanText);
});

it('EdgeTtsProvider implements TextToSpeechInterface and handles network offline gracefully', function () {
    $provider = new \App\Services\AI\Providers\EdgeTtsProvider();
    assertTrue($provider instanceof TextToSpeechInterface);
    assertEquals('edge_neural', $provider->providerName());
    assertTrue($provider->isAvailable());

    $result = $provider->synthesize("Привет, как дела?", 'ru');
    assertTrue($result instanceof TtsResult);
    assertTrue($result->ok);
    assertContains('Привет, как дела?', $result->cleanText);
});

// -----------------------------------------------------------------
// Group 5: TtsFactory
// -----------------------------------------------------------------
echo "\n--- Group 5: TtsFactory ---\n";

it('TtsFactory instantiates providers correctly', function () {
    $browser = TtsFactory::create('browser');
    assertTrue($browser instanceof BrowserTtsProvider);

    $mock = TtsFactory::create('mock');
    assertTrue($mock instanceof MockTtsProvider);

    // Default or unknown fallback
    $fallback = TtsFactory::create('unknown_provider');
    assertTrue($fallback instanceof BrowserTtsProvider);
});

// -----------------------------------------------------------------
// Group 6: Controller & Configuration Integration
// -----------------------------------------------------------------
echo "\n--- Group 6: Controller & Configuration Integration ---\n";

it('AiConfig contains valid tts configuration block', function () {
    $cfg = AiConfig::get('tts');
    assertTrue(is_array($cfg), 'tts config block must be an array');
    assertTrue(array_key_exists('enabled', $cfg), 'tts.enabled must exist');
    assertTrue(array_key_exists('provider', $cfg), 'tts.provider must exist');
    assertTrue(array_key_exists('max_text_chars', $cfg), 'tts.max_text_chars must exist');
    assertEquals('browser', $cfg['provider']);
});

it('POST /ai/tts route is registered in Router', function () {
    $router = require dirname(__DIR__) . '/config/routes.php';
    assertTrue($router instanceof Router);

    $ref = new \ReflectionClass($router);
    $prop = $ref->getProperty('routes');
    $prop->setAccessible(true);
    $routes = $prop->getValue($router);

    assertTrue(isset($routes['POST']['/ai/tts']), 'POST /ai/tts route must be registered');
    assertEquals([AiAssistantController::class, 'tts'], $routes['POST']['/ai/tts']);
});

it('TtsResult payload matches client contract', function () {
    $provider = TtsFactory::create();
    $res = $provider->synthesize("Здравствуйте! Чем я могу помочь?", 'ru');
    $arr = $res->toArray();

    $expectedKeys = [
        'clean_text', 'original_text', 'language', 'voice',
        'provider', 'use_browser', 'audio_url', 'audio_base64',
        'duration_ms', 'request_id', 'warnings'
    ];
    foreach ($expectedKeys as $key) {
        assertTrue(array_key_exists($key, $arr), "TtsResult must contain key '{$key}'");
    }
});

// -----------------------------------------------------------------
// Group 7: Localization Verification
// -----------------------------------------------------------------
echo "\n--- Group 7: Localization Verification ---\n";

it('RU and KK translation files have all required TTS keys in ai namespace', function () {
    $ru = require dirname(__DIR__) . '/lang/ru.php';
    $kk = require dirname(__DIR__) . '/lang/kk.php';

    $keys = [
        'tts_play',
        'tts_stop',
        'tts_pause',
        'tts_resume',
        'tts_unsupported',
        'tts_error'
    ];

    foreach ($keys as $k) {
        assertTrue(isset($ru['ai'][$k]), "RU missing key ai.{$k}");
        assertTrue(isset($kk['ai'][$k]), "KK missing key ai.{$k}");
        assertTrue(mb_strlen($ru['ai'][$k]) > 0, "RU ai.{$k} cannot be empty");
        assertTrue(mb_strlen($kk['ai'][$k]) > 0, "KK ai.{$k} cannot be empty");
    }
});

// -----------------------------------------------------------------
// Group 8: Controller Action Direct Simulation
// -----------------------------------------------------------------
echo "\n--- Group 8: Controller Action Direct Simulation ---\n";

class TestableAiAssistantController extends AiAssistantController
{
    public ?array $lastJson = null;
    public ?int $lastStatus = null;
    public array $mockConfig = [];

    protected function json(array $data, int $status = 200): void
    {
        $this->lastJson = $data;
        $this->lastStatus = $status;
    }

    protected function aiConfig(): array
    {
        if (!empty($this->mockConfig)) {
            return $this->mockConfig;
        }
        return parent::aiConfig();
    }
}

it('Controller tts action rejects disabled feature flag with 403', function () {
    $ctrl = new TestableAiAssistantController();
    $ctrl->mockConfig = [
        'tts' => ['enabled' => false]
    ];
    $_POST = ['text' => 'Тест'];

    $ctrl->tts();
    assertEquals(403, $ctrl->lastStatus);
    assertEquals(false, $ctrl->lastJson['ok']);
    assertEquals('tts_disabled', $ctrl->lastJson['error']);
});

it('Controller tts action rejects empty text with 422', function () {
    $ctrl = new TestableAiAssistantController();
    $ctrl->mockConfig = [
        'tts' => ['enabled' => true, 'provider' => 'browser']
    ];
    $_POST = ['text' => '   '];

    $ctrl->tts();
    assertEquals(422, $ctrl->lastStatus);
    assertEquals(false, $ctrl->lastJson['ok']);
    assertEquals('empty_text', $ctrl->lastJson['error']);
});

it('Controller tts action processes valid speech synthesis with 200 and TtsResult', function () {
    $ctrl = new TestableAiAssistantController();
    $ctrl->mockConfig = [
        'tts' => ['enabled' => true, 'provider' => 'browser', 'max_text_chars' => 500]
    ];
    $_POST = [
        'text' => 'Купите **ноутбук** за 250 000 ₸',
        'language' => 'ru'
    ];

    $ctrl->tts();
    assertEquals(200, $ctrl->lastStatus);
    assertEquals(true, $ctrl->lastJson['ok']);
    assertEquals('browser', $ctrl->lastJson['provider']);
    assertEquals(true, $ctrl->lastJson['use_browser']);
    assertContains('250 тысяч тенге', $ctrl->lastJson['clean_text']);
});

echo "\n====================================================================\n";
echo "STAGE 03 TEST RESULTS: {$passedTests}/{$totalTests} PASSED";
if ($failedTests > 0) {
    echo " ({$failedTests} FAILED)\n";
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo " - {$f}\n";
    }
    echo "====================================================================\n";
    exit(1);
} else {
    echo " (ALL TESTS PASSED 100%)\n";
    echo "====================================================================\n";
    exit(0);
}
