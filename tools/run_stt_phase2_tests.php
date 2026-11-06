<?php

declare(strict_types=1);

/**
 * Комплексный набор тестов STT (Speech-to-Text) для этапа №02 Zakopeyki.kz.
 *
 * php tools/run_stt_phase2_tests.php
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Controllers\AiAssistantController;
use App\Core\Auth;
use App\Core\Csrf;
use App\Models\Product;
use App\Services\AI\Contracts\SpeechToTextInterface;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\Core\IntentDetector;
use App\Services\AI\Core\LanguageDetector;
use App\Services\AI\Core\PermissionChecker;
use App\Services\AI\Core\RateLimiter;
use App\Services\AI\DTO\SttResult;
use App\Services\AI\Enum\Intent;
use App\Services\AI\Providers\ClientTranscriptSttProvider;
use App\Services\AI\Providers\MockSttProvider;
use App\Services\AI\Providers\ServerAudioSttProvider;
use App\Services\AI\Support\AudioValidator;
use App\Services\AI\Support\SttFactory;

$passed = 0;
$failed = 0;

function assert_test(bool $cond, string $msg, string $extra = ''): void
{
    global $failed, $passed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$msg}" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    } else {
        $failed++;
        echo "  [FAIL] {$msg}" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    }
}

echo "====================================================\n";
echo "   MASTERING-02: STT FULL TEST SUITE (Zakopeyki.kz) \n";
echo "====================================================\n\n";

// ---------------------------------------------------------
// 1. DTO & Normalization
// ---------------------------------------------------------
echo "1. DTO & Normalization (SttResult)\n";

$dtoSuccess = SttResult::success(
    text: "Найди iPhone 15 до 300 тысяч",
    language: "ru",
    confidence: 0.94567,
    provider: "web_speech",
    durationMs: 340,
    requestId: "stt_test_123",
    warnings: ["sample_warning"]
);

assert_test($dtoSuccess->ok === true, "SttResult::success ok is true");
assert_test($dtoSuccess->recognizedText === "Найди iPhone 15 до 300 тысяч", "recognizedText preserved");
assert_test($dtoSuccess->language === "ru", "language preserved");
assert_test($dtoSuccess->confidence === 0.9457, "confidence rounded properly");
assert_test($dtoSuccess->provider === "web_speech", "provider preserved");
assert_test($dtoSuccess->durationMs === 340, "durationMs preserved");
assert_test($dtoSuccess->requestId === "stt_test_123", "requestId preserved");
assert_test($dtoSuccess['text'] === "Найди iPhone 15 до 300 тысяч", "ArrayAccess backward compat for 'text'");
assert_test($dtoSuccess['recognized_text'] === "Найди iPhone 15 до 300 тысяч", "ArrayAccess for 'recognized_text'");
assert_test(in_array("sample_warning", $dtoSuccess->warnings, true), "warnings array preserved");

$dtoFail = SttResult::failure(
    error: "silence_detected",
    provider: "web_speech",
    requestId: "stt_test_fail",
    warnings: ["too_quiet"]
);
assert_test($dtoFail->ok === false, "SttResult::failure ok is false");
assert_test($dtoFail->recognizedText === '', "SttResult::failure text is empty");
assert_test($dtoFail->error === "silence_detected", "error message preserved");
assert_test($dtoFail->confidence === null, "failure confidence is null (not invented)");

// ---------------------------------------------------------
// 2. AudioValidator (Magic bytes, formats, size, duration)
// ---------------------------------------------------------
echo "\n2. AudioValidator & Format Security\n";

$validator = new AudioValidator([
    'max_size_bytes' => 1024 * 1024, // 1 MB for test
    'min_size_bytes' => 50,
    'max_duration_s' => 60.0,
]);

// 2a. Valid WAV
$tmpWav = tempnam(sys_get_temp_dir(), 'test_wav_');
// Construct minimal valid WAV header (44 bytes) + 200 bytes PCM data
$sampleRate = 16000;
$byteRate = 32000; // 16kHz, 16bit, mono
$dataSize = 64000; // ~2 seconds of audio
$wavHeader = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE' .
    'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $sampleRate) . pack('V', $byteRate) . pack('v', 2) . pack('v', 16) .
    'data' . pack('V', $dataSize);
file_put_contents($tmpWav, $wavHeader . str_repeat("\0", $dataSize));

$vWav = $validator->validateFile($tmpWav, 'audio/wav');
assert_test($vWav['valid'] === true, "Valid WAV recognized", "duration={$vWav['duration_s']}s");
assert_test($vWav['format'] === 'wav', "WAV format detected");
assert_test($vWav['duration_s'] === 2.0, "WAV duration calculated correctly", "duration={$vWav['duration_s']}s");
@unlink($tmpWav);

// 2b. Valid WebM header
$tmpWebm = tempnam(sys_get_temp_dir(), 'test_webm_');
file_put_contents($tmpWebm, "\x1A\x45\xDF\xA3" . str_repeat("\x00", 300));
$vWebm = $validator->validateFile($tmpWebm, 'audio/webm');
assert_test($vWebm['valid'] === true, "WebM EBML magic bytes recognized");
assert_test($vWebm['format'] === 'webm', "WebM format detected");
@unlink($tmpWebm);

// 2c. Valid OGG header
$tmpOgg = tempnam(sys_get_temp_dir(), 'test_ogg_');
file_put_contents($tmpOgg, "OggS" . str_repeat("\x00", 250));
$vOgg = $validator->validateFile($tmpOgg, 'audio/ogg');
assert_test($vOgg['valid'] === true, "OGG magic bytes recognized");
assert_test($vOgg['format'] === 'ogg', "OGG format detected");
@unlink($tmpOgg);

// 2d. Fake audio (executable or text disguised as audio)
$tmpFake = tempnam(sys_get_temp_dir(), 'test_fake_');
file_put_contents($tmpFake, "<?php echo 'malicious'; ?>" . str_repeat("A", 300));
$vFake = $validator->validateFile($tmpFake, 'audio/wav');
assert_test($vFake['valid'] === false, "Malicious / fake audio rejected");
assert_test($vFake['error_code'] === 'unsupported_format', "Error code is unsupported_format");
@unlink($tmpFake);

// 2e. Too short / empty file
$tmpShort = tempnam(sys_get_temp_dir(), 'test_short_');
file_put_contents($tmpShort, "RIFF1234");
$vShort = $validator->validateFile($tmpShort);
assert_test($vShort['valid'] === false, "Too short file (< 50 bytes) rejected");
assert_test($vShort['error_code'] === 'recording_too_short', "Error code is recording_too_short");
@unlink($tmpShort);

// 2f. Exceeded size
$tmpBig = tempnam(sys_get_temp_dir(), 'test_big_');
// create 1.5MB file while max is 1MB
file_put_contents($tmpBig, "RIFF" . pack('V', 0) . "WAVE" . str_repeat("\0", 1200000));
$vBig = $validator->validateFile($tmpBig);
assert_test($vBig['valid'] === false, "Exceeded size rejected");
assert_test($vBig['error_code'] === 'exceeded_size', "Error code is exceeded_size");
@unlink($tmpBig);

// ---------------------------------------------------------
// 3. ClientTranscriptSttProvider
// ---------------------------------------------------------
echo "\n3. ClientTranscriptSttProvider Unit Tests\n";

$clientProvider = new ClientTranscriptSttProvider();
assert_test($clientProvider->providerName() === 'web_speech', "Provider name is web_speech");
assert_test($clientProvider->isAvailable() === true, "Provider is available");

// 3a. Normal speech transcript
$t1 = $clientProvider->transcribe("  Найди iPhone 15   до 300 тысяч тенге  ", null, [
    'confidence' => 0.96,
    'language_hint' => 'ru'
]);
assert_test($t1->ok === true, "Transcription succeeds");
assert_test($t1->recognizedText === "Найди iPhone 15 до 300 тысяч тенге", "Whitespace normalized");
assert_test($t1->language === 'ru', "Language detected as ru");
assert_test($t1->confidence === 0.96, "Confidence preserved");
assert_test($t1->provider === 'web_speech', "Provider is web_speech");

// 3b. Empty / silence
$tEmpty = $clientProvider->transcribe("   ", null);
assert_test($tEmpty->ok === false, "Empty transcript rejected");
assert_test(in_array('silence_detected', $tEmpty->warnings, true), "Silence warning flagged");

// 3c. Raw audio / base64 error handling
$tRaw = $clientProvider->transcribe("data:audio/webm;base64,GkXfo59ChoEBQveBAULygQ8=", null);
assert_test($tRaw->ok === false, "Raw dataURI safely rejected with error message");

// 3d. Low confidence warning
$tLow = $clientProvider->transcribe("телефон", null, ['confidence' => 0.25]);
assert_test(in_array('low_confidence_recognition', $tLow->warnings, true), "Low confidence flagged in warnings");

// ---------------------------------------------------------
// 4. ServerAudioSttProvider & MockSttProvider
// ---------------------------------------------------------
echo "\n4. ServerAudioSttProvider & Fallback Handling\n";

$mock = new MockSttProvider();
$mock->setCannedResponse('*', "Покажи объявления в Караганде", "ru", 0.95);

$serverProvider = new ServerAudioSttProvider(
    config: ['max_audio_size_bytes' => 10 * 1024 * 1024],
    mockProvider: $mock
);
assert_test($serverProvider->providerName() === 'server_stt', "Provider name is server_stt");

// Test with valid dummy WAV
$tmpValidWav = tempnam(sys_get_temp_dir(), 'test_v_');
file_put_contents($tmpValidWav, 'RIFF' . pack('V', 400) . 'WAVE' . str_repeat("\0", 350));
$resServer = $serverProvider->transcribe($tmpValidWav, 'audio/wav');
assert_test($resServer->ok === true, "ServerAudioSttProvider succeeds with mock backend");
assert_test($resServer->recognizedText === "Покажи объявления в Караганде", "Recognized text returned");
@unlink($tmpValidWav);

// Provider unavailable scenario
$mockOffline = new MockSttProvider();
$mockOffline->setAvailable(false);
$offlineServerProvider = new ServerAudioSttProvider(
    config: [],
    mockProvider: $mockOffline
);
$resOffline = $offlineServerProvider->transcribe($tmpWebm = tempnam(sys_get_temp_dir(), 't_'), 'audio/webm');
assert_test($resOffline->ok === false, "Offline server provider handles unavailability gracefully");
@unlink($tmpWebm);

// ---------------------------------------------------------
// 5. Language Support (RU, KK, EN) & Test Phrases from Section 8
// ---------------------------------------------------------
echo "\n5. Language Tests (Russian, Kazakh, English)\n";

$langDetector = new LanguageDetector();
$phrases = [
    // Russian
    'Найди iPhone 15 до трёхсот тысяч тенге' => ['expected_lang' => 'ru', 'expected_brand' => 'iPhone'],
    'Найди товары дешевле 300 тысяч' => ['expected_lang' => 'ru', 'expected_brand' => null],
    'Покажи объявления в Караганде' => ['expected_lang' => 'ru', 'expected_brand' => null],
    // Kazakh
    'Маған үш жүз мың теңгеге дейін iPhone тауып бер' => ['expected_lang' => 'kk', 'expected_brand' => 'iPhone'],
    'Үш жүз мың теңгеден арзан тауарларды көрсет' => ['expected_lang' => 'kk', 'expected_brand' => null],
    // English
    'Find an iPhone 15 under 300,000 tenge' => ['expected_lang' => 'en', 'expected_brand' => 'iPhone'],
    'Show me products in Karaganda' => ['expected_lang' => 'en', 'expected_brand' => null],
];

foreach ($phrases as $phrase => $meta) {
    $stt = $clientProvider->transcribe($phrase, null);
    assert_test($stt->ok === true, "STT transcribed: '{$phrase}'");
    $detected = $stt->language ?? $langDetector->detect($phrase);
    assert_test(
        $detected === $meta['expected_lang'],
        "Language detection for: '{$phrase}'",
        "detected={$detected}, expected={$meta['expected_lang']}"
    );
}

// ---------------------------------------------------------
// 6. Feature Flags
// ---------------------------------------------------------
echo "\n6. Feature Flags Configuration\n";

$voiceCfg = AiConfig::get('voice');
assert_test(is_array($voiceCfg), "config/ai.php contains 'voice' block");
assert_test(array_key_exists('voice_input_enabled', $voiceCfg), "voice_input_enabled flag present");
assert_test(array_key_exists('stt_enabled', $voiceCfg), "stt_enabled flag present");
assert_test(array_key_exists('server_stt_fallback_enabled', $voiceCfg), "server_stt_fallback_enabled flag present");
assert_test(array_key_exists('stt_debug_enabled', $voiceCfg), "stt_debug_enabled flag present");
assert_test(($voiceCfg['max_audio_duration_seconds'] ?? 0) === 60, "max_audio_duration_seconds is 60s");
assert_test(($voiceCfg['max_audio_size_bytes'] ?? 0) === 10 * 1024 * 1024, "max_audio_size_bytes is 10MB");

// ---------------------------------------------------------
// 7. Security: Zero-retention & Clean-up Verification
// ---------------------------------------------------------
echo "\n7. Security & Audio Data Retention\n";

// Ensure no permanent audio files are stored in public/uploads
$uploadDir = dirname(__DIR__) . '/public/uploads/ai';
$audioFiles = [];
if (is_dir($uploadDir)) {
    $files = glob($uploadDir . '/*.{webm,wav,ogg,mp4}', GLOB_BRACE);
    $audioFiles = is_array($files) ? $files : [];
}
assert_test(count($audioFiles) === 0, "Zero persistent audio files in uploads directory (Zero Retention)");

// Test RateLimiter works for voice key
$limiter = new RateLimiter(['per_minute' => 2, 'per_hour' => 10]);
$testKey = 'voice_test_' . bin2hex(random_bytes(4));
$r1 = $limiter->attempt($testKey);
$r2 = $limiter->attempt($testKey);
$r3 = $limiter->attempt($testKey);
assert_test(!empty($r1['allowed']) && !empty($r2['allowed']), "Rate limiter allows initial requests");
assert_test(empty($r3['allowed']), "Rate limiter blocks 3rd request when limit is 2/min");

// ---------------------------------------------------------
// 8. Regression Verification (Existing AI Text Chat & Tools)
// ---------------------------------------------------------
echo "\n8. Regression: Text Chat, NLU & Tools Integrity\n";

$offlineLlm = new class implements \App\Services\AI\Contracts\LLMProviderInterface {
    public function chat(array $m, ?float $t = null, bool $j = false, ?string $mo = null): array { throw new RuntimeException('offline'); }
    public function chatStream(array $m, callable $o, ?float $t = null, ?string $mo = null): array { throw new RuntimeException('offline'); }
    public function embed(string $t, ?string $mo = null): array { return []; }
    public function vision(string $p, string $i, ?string $mo = null): array { throw new RuntimeException('offline'); }
    public function isAvailable(): bool { return false; }
    public function providerName(): string { return 'stub'; }
};

$intentDetector = new IntentDetector($offlineLlm);
$detectRes = $intentDetector->detect("Найди iPhone 15 до 300000 тенге в Алматы");
assert_test($detectRes->intent === Intent::SearchProduct, "Text search intent detection works");
assert_test((int) ($detectRes->parameters['max_price'] ?? 0) === 300000, "Price parameter extracted");

$perm = new PermissionChecker();
$ownOrder = ['buyer_id' => 10, 'seller_id' => 20];
assert_test($perm->ownsOrder($ownOrder, 10) === true, "Order ownership allowed for owner");
assert_test($perm->ownsOrder($ownOrder, 99) === false, "Order ownership denied for stranger (IDOR protection)");

echo "\n====================================================\n";
echo "SUMMARY: Passed: {$passed}, Failed: {$failed}\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);
