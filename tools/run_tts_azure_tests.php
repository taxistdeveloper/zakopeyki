<?php

declare(strict_types=1);

/**
 * Тесты AzureTtsProvider (Azure AI Speech REST).
 *
 *   php tools/run_tts_azure_tests.php
 *
 * Часть A — с подменённым HTTP-транспортом (всегда): запрос, SSML, кеш, ошибки, fallback, безопасность ключа.
 * Часть B — реальный синтез, только если заданы AZURE_SPEECH_KEY и AZURE_SPEECH_REGION; иначе SKIPPED.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\AI\Providers\AzureTtsProvider;
use App\Services\AI\Support\TtsAudioCache;
use App\Services\AI\Support\TtsFactory;

$passed = 0;
$failed = 0;
$skipped = 0;

function z_assert(bool $cond, string $msg, string $extra = ''): void
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

$work = sys_get_temp_dir() . '/zkt_azure_test_' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
$cache = new TtsAudioCache($work . '/cache', bin2hex(random_bytes(32)));
$fakeKey = 'test-key-' . bin2hex(random_bytes(6));
$calls = [];
$next = ['status' => 200, 'body' => "ID3" . str_repeat("\xFF", 4000)];
$transport = function (string $url, array $headers, string $body, int $timeout) use (&$calls, &$next): array {
    $calls[] = compact('url', 'headers', 'body', 'timeout');
    return $next;
};
$cfg = ['azure_key' => $fakeKey, 'azure_region' => 'westeurope'];
$p = new AzureTtsProvider($cfg, null, $cache, $transport);

echo "====================================================\nAzure TTS provider tests\n====================================================\n";

echo "\nA1. Request\n";
$r = $p->synthesize('Тапсырыс №12345 дайын. Сома: 15 000 тг.', 'kk', ['audio_owner' => 'u5']);
$c = $calls[0] ?? [];
z_assert($r->ok && !$r->useBrowser && $r->provider === 'azure_speech' && $r->voice === 'kk-KZ-AigulNeural', 'kk synthesized by azure_speech with kk-KZ-AigulNeural');
z_assert(($c['url'] ?? '') === 'https://westeurope.tts.speech.microsoft.com/cognitiveservices/v1', 'Regional endpoint built from AZURE_SPEECH_REGION', (string) ($c['url'] ?? ''));
$h = implode("\n", $c['headers'] ?? []);
z_assert(str_contains($h, 'Ocp-Apim-Subscription-Key: ' . $fakeKey) && str_contains($h, 'Content-Type: application/ssml+xml')
    && str_contains($h, 'X-Microsoft-OutputFormat: audio-24khz-48kbitrate-mono-mp3') && str_contains($h, 'User-Agent: '), 'Required headers present');
z_assert(str_contains($c['body'] ?? '', "xml:lang='kk-KZ'") && str_contains($c['body'] ?? '', "name='kk-KZ-AigulNeural'"), 'SSML voice and xml:lang');
z_assert(str_contains($c['body'] ?? '', 'нөмір 12345') && str_contains($c['body'] ?? '', '15 мың теңге'), 'Kazakh-normalized text sent', (string) ($c['body'] ?? ''));
z_assert(is_string($r->audioUrl) && (bool) preg_match('#/ai/tts/audio/[a-f0-9]{64}\.\d+\.[a-f0-9]{64}$#', $r->audioUrl), 'Signed audio URL returned');
$json = json_encode($r->toArray(), JSON_UNESCAPED_UNICODE);
z_assert(!str_contains((string) $json, $fakeKey) && !str_contains((string) $json, 'microsoft.com'), 'Key and endpoint never exposed in the result');

echo "\nA2. Cache\n";
$n = count($calls);
$r2 = $p->synthesize('Тапсырыс №12345 дайын. Сома: 15 000 тг.', 'kk', ['audio_owner' => 'u6']);
z_assert(count($calls) === $n && $r2->ok && !$r2->useBrowser, 'Same text served from private cache without a new Azure call');
z_assert($r2->audioUrl !== $r->audioUrl, 'Another owner gets a different signed URL');

echo "\nA3. SSML injection\n";
$p->synthesize("Цена <b>5</b> & \"скидка\" '1' </voice><voice name='x'>hack", 'ru', ['audio_owner' => 'u5']);
$body = end($calls)['body'];
z_assert(!str_contains($body, "<voice name='x'>") && substr_count($body, '<voice ') === 1, 'Text cannot break out of <voice> element', $body);

echo "\nA4. Voices\n";
$r = $p->synthesize('Привет', 'ru', ['voice' => 'ru-RU-DariyaNeural', 'audio_owner' => 'u5']);
z_assert($r->voice === 'ru-RU-DariyaNeural', 'ru-RU-DariyaNeural is a valid Azure voice');
$r = $p->synthesize('Сәлем', 'kk', ['voice' => 'kk-KZ-DariyaNeural', 'audio_owner' => 'u5']);
z_assert($r->voice === 'kk-KZ-AigulNeural' && in_array('voice_not_allowed', $r->warnings, true), 'Non-existent kk-KZ-DariyaNeural rejected');
$r = $p->synthesize('Hello there', 'en', ['audio_owner' => 'u5']);
z_assert($r->voice === 'en-US-JennyNeural' && str_contains(end($calls)['body'], "xml:lang='en-US'"), 'en → en-US-JennyNeural');

echo "\nA5. Errors → honest browser fallback, nothing cached\n";
foreach ([[401, 'http_401'], [429, 'http_429'], [400, 'http_400'], [503, 'http_503'], [0, 'network_error']] as [$st, $code]) {
    $next = ['status' => $st, 'body' => $st === 0 ? '' : '{"error":"x"}'];
    $text = 'Ошибка ' . $st . ' ' . bin2hex(random_bytes(3));
    $r = $p->synthesize($text, 'ru', ['audio_owner' => 'u5']);
    z_assert($r->useBrowser && $r->audioUrl === null && $r->provider === 'browser'
        && in_array('server_tts_failed', $r->warnings, true) && in_array('azure_speech:' . $code, $r->warnings, true),
        "HTTP {$st} → fallback with azure_speech:{$code}", implode(',', $r->warnings));
}
$next = ['status' => 200, 'body' => 'tiny'];
$r = $p->synthesize('Пустой ответ ' . bin2hex(random_bytes(3)), 'ru', ['audio_owner' => 'u5']);
z_assert($r->useBrowser && in_array('azure_speech:empty_audio', $r->warnings, true), '200 with empty body → fallback (no fake audio)');
$next = ['status' => 200, 'body' => "ID3" . str_repeat("\xFF", 4000)];

echo "\nA6. Configuration safety\n";
$nk = new AzureTtsProvider(['azure_region' => 'westeurope'], null, $cache, $transport);
$n = count($calls);
$r = $nk->synthesize('Без ключа', 'ru');
z_assert(count($calls) === $n && $r->useBrowser && in_array('azure_speech:provider_not_configured', $r->warnings, true) && !$nk->isAvailable(), 'No key → no request, fallback, isAvailable() false');
$evil = new AzureTtsProvider(['azure_key' => $fakeKey, 'azure_endpoint' => 'https://attacker.example/cognitiveservices/v1'], null, $cache, $transport);
$n = count($calls);
$evil->synthesize('Ключ не должен утечь', 'ru');
z_assert(count($calls) === $n && !$evil->isAvailable(), 'Non-Microsoft endpoint refused (key never sent there)');
$http = new AzureTtsProvider(['azure_key' => $fakeKey, 'azure_endpoint' => 'http://westeurope.tts.speech.microsoft.com/cognitiveservices/v1'], null, $cache, $transport);
z_assert(!$http->isAvailable(), 'Plain http endpoint refused');
$badRegion = new AzureTtsProvider(['azure_key' => $fakeKey, 'azure_region' => 'west europe/../x'], null, $cache, $transport);
z_assert(!$badRegion->isAvailable(), 'Malformed region refused');
$custom = new AzureTtsProvider(['azure_key' => $fakeKey, 'azure_endpoint' => 'https://zak-speech.cognitiveservices.azure.com/cognitiveservices/v1'], null, $cache, $transport);
z_assert($custom->isAvailable(), 'Custom-domain Azure endpoint accepted');
$fmt = new AzureTtsProvider($cfg + ['azure_output_format' => 'riff-24khz-16bit-mono-pcm'], null, $cache, $transport);
$fmt->synthesize('Формат ' . bin2hex(random_bytes(3)), 'ru', ['audio_owner' => 'u5']);
z_assert(str_contains(implode("\n", end($calls)['headers']), 'X-Microsoft-OutputFormat: audio-24khz-48kbitrate-mono-mp3'), 'Non-MP3 output format coerced to MP3 (served as audio/mpeg)');

echo "\nA7. Factory and config\n";
$cfgSrc = dirname(__DIR__) . '/config/ai.php';
$run = static fn (string $env): string => trim((string) shell_exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$c=require ' . var_export($cfgSrc, true) . '; echo $c["tts"]["provider"];')));
$edgeInstalled = is_file('/usr/local/bin/edge-tts') || is_file('/opt/homebrew/bin/edge-tts') || is_file('/usr/bin/edge-tts');
$expectedNoKey = $edgeInstalled ? 'edge_neural' : 'browser';
z_assert($run('AZURE_SPEECH_KEY= AI_TTS_PROVIDER=') === $expectedNoKey, "No key → default provider {$expectedNoKey}");
z_assert($run('AZURE_SPEECH_KEY=abc') === 'azure', 'Key present → default provider azure');
z_assert($run('AZURE_SPEECH_KEY=abc AI_TTS_PROVIDER=browser') === 'browser', 'Explicit AI_TTS_PROVIDER wins');
z_assert(TtsFactory::create('azure') instanceof AzureTtsProvider, "TtsFactory::create('azure') → AzureTtsProvider");

echo "\nB. Real Azure synthesis\n";
$realKey = getenv('AZURE_SPEECH_KEY') ?: '';
$realRegion = getenv('AZURE_SPEECH_REGION') ?: '';
if ($realKey === '' || ($realRegion === '' && (getenv('AZURE_SPEECH_ENDPOINT') ?: '') === '')) {
    $skipped++;
    echo "  [SKIPPED] AZURE_SPEECH_KEY / AZURE_SPEECH_REGION not set — real synthesis not verified\n";
} else {
    $real = new AzureTtsProvider(['azure_key' => $realKey, 'azure_region' => $realRegion, 'azure_endpoint' => getenv('AZURE_SPEECH_ENDPOINT') ?: ''], null, new TtsAudioCache($work . '/real', bin2hex(random_bytes(32))));
    foreach ([['ru', 'Заказ №12345 готов. Сумма: 15 000 тенге.'], ['kk', 'Тапсырыс №12345 дайын. Сома: 15 000 теңге.'], ['en', 'Your order is ready.']] as [$lang, $text]) {
        $r = $real->synthesize($text, $lang, ['audio_owner' => 'u1']);
        z_assert($r->ok && !$r->useBrowser, "Real Azure {$lang} ({$r->voice})", implode(',', $r->warnings));
    }
}

foreach (array_reverse(array_merge(glob($work . '/*/*/*') ?: [], glob($work . '/*/*') ?: [], glob($work . '/*') ?: [])) as $f) {
    is_dir($f) ? @rmdir($f) : @unlink($f);
}
@rmdir($work);

echo "\n====================================================\nSUMMARY: Passed: {$passed}, Failed: {$failed}, Skipped: {$skipped}\n====================================================\n";
exit($failed > 0 ? 1 : 0);
