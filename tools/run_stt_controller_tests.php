<?php

declare(strict_types=1);

/**
 * Controller-level integration tests for POST /ai/voice.
 *
 * php tools/run_stt_controller_tests.php
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Controllers\AiAssistantController;
use App\Services\AI\Core\AiConfig;
use App\Services\AI\DTO\SttResult;
use App\Services\AI\Providers\MockSttProvider;
use App\Services\AI\Support\SttFactory;

$passed = 0;
$failed = 0;

function assert_c(bool $cond, string $msg, string $extra = ''): void
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
echo "   AI ASSISTANT CONTROLLER STT INTEGRATION TESTS    \n";
echo "====================================================\n\n";

// Helper subclass of AiAssistantController to capture json responses without exit()
class TestableAiAssistantController extends AiAssistantController
{
    public ?array $lastJson = null;
    public ?int $lastStatus = null;

    protected function json($data, int $status = 200, array $headers = []): void
    {
        $this->lastJson = $data;
        $this->lastStatus = $status;
    }
}

$controller = new TestableAiAssistantController();

// 1. Transcribe-only mode via client transcript
$_POST = [
    'transcript' => 'Найди iPhone 15 до 300 тысяч',
    'language' => 'ru',
    'confidence' => 0.95,
    'transcribe_only' => true,
    'guest_token' => 'gt_test_controller',
];
$controller->voice();

assert_c($controller->lastStatus === 200, "Transcribe-only returns status 200");
assert_c(!empty($controller->lastJson['ok']), "Response ok is true");
assert_c($controller->lastJson['recognized_text'] === 'Найди iPhone 15 до 300 тысяч', "Recognized text matched");
assert_c($controller->lastJson['language'] === 'ru', "Language matched ru");
assert_c($controller->lastJson['provider'] === 'web_speech', "Provider is web_speech");
assert_c(isset($controller->lastJson['request_id']), "Request ID present");
assert_c(isset($controller->lastJson['stt']), "Full STT DTO structure present in response");

// 2. Empty speech error
$_POST = [
    'transcript' => '   ',
    'transcribe_only' => true,
    'guest_token' => 'gt_test_controller',
];
$controller->voice();
assert_c($controller->lastStatus === 422, "Empty transcript returns status 422");
assert_c($controller->lastJson['ok'] === false, "Response ok is false on silence");
assert_c(!empty($controller->lastJson['error']), "Error message returned for empty speech");

// 3. Audio upload with Mock backend
$mock = new MockSttProvider();
$mock->setCannedResponse('*', 'Покажи объявления в Караганде', 'ru', 0.98);
SttFactory::setMockInstance($mock);

$tmpAudio = tempnam(sys_get_temp_dir(), 'test_ctrl_audio_');
file_put_contents($tmpAudio, "RIFF" . pack('V', 300) . "WAVE" . str_repeat("\0", 250));

$_POST = [
    'transcribe_only' => true,
    'guest_token' => 'gt_test_controller',
];
// Emulate uploaded file
$_FILES = [
    'audio' => [
        'name' => 'voice.wav',
        'type' => 'audio/wav',
        'tmp_name' => $tmpAudio,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tmpAudio),
    ]
];

// Note: is_uploaded_file() returns false for tempnam in CLI, so we also test audio_base64 path
$base64Wav = 'data:audio/wav;base64,' . base64_encode(file_get_contents($tmpAudio));
$_POST = [
    'audio_base64' => $base64Wav,
    'transcribe_only' => true,
    'guest_token' => 'gt_test_controller',
];
$_FILES = [];
$controller->voice();

assert_c($controller->lastStatus === 200, "Base64 audio upload returns status 200");
assert_c(!empty($controller->lastJson['ok']), "Base64 audio transcription ok");
assert_c($controller->lastJson['recognized_text'] === 'Покажи объявления в Караганде', "Base64 recognized text matches mock");

@unlink($tmpAudio);
SttFactory::setMockInstance(null);

// 4. Feature flag disable check
// Temporarily mock disabled flag
$origAll = AiConfig::all();
$disabledConfig = $origAll;
$disabledConfig['voice']['voice_input_enabled'] = false;

// Reflection to set cache in AiConfig
$ref = new ReflectionClass(AiConfig::class);
$prop = $ref->getProperty('cache');
$prop->setValue(null, $disabledConfig);

$_POST = [
    'transcript' => 'Привет',
    'guest_token' => 'gt_test_controller',
];
$controller->voice();
assert_c($controller->lastStatus === 403, "Disabled voice flag returns 403 Forbidden");
assert_c($controller->lastJson['error'] === 'voice_disabled', "Error code is voice_disabled");

// Restore config
$prop->setValue(null, null);

// 6. Повторная отправка одного аудиофрагмента (client_request_id)
$reqId = 'v_test_' . bin2hex(random_bytes(8));
$_POST = [
    'transcript' => 'Покажи объявления',
    'transcribe_only' => true,
    'guest_token' => 'gt_test_dedupe',
    'client_request_id' => $reqId,
];
$controller->voice();
assert_c($controller->lastStatus === 200, "First request with client_request_id is processed");
$controller->voice();
assert_c($controller->lastStatus === 409 && ($controller->lastJson['error'] ?? '') === 'duplicate_request', "Same client_request_id again → 409 duplicate_request");

$_POST['guest_token'] = 'gt_test_dedupe_other';
$controller->voice();
assert_c($controller->lastStatus === 200, "Same id from a different owner is not blocked (keys are per owner)");

$_POST['client_request_id'] = '../../etc/passwd';
$controller->voice();
assert_c($controller->lastStatus === 400 && ($controller->lastJson['error'] ?? '') === 'invalid_request_id', "Malformed client_request_id → 400");

unset($_POST['client_request_id']);
$controller->voice();
assert_c($controller->lastStatus === 200, "Requests without client_request_id still work (backward compatible)");

echo "\n====================================================\n";
echo "SUMMARY: Passed: {$passed}, Failed: {$failed}\n";
echo "====================================================\n";

exit($failed > 0 ? 1 : 0);

