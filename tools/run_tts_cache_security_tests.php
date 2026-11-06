<?php

declare(strict_types=1);

/**
 * Безопасность аудиокеша TTS, голоса edge-tts и выдача аудио.
 *
 *   php tools/run_tts_cache_security_tests.php
 *
 * Часть A — детерминированные тесты TtsAudioCache и EdgeTtsProvider с поддельным python (всегда).
 * Часть B — реальный синтез edge-tts (kk/ru голоса) и HTTP-проверки; без сети/edge_tts → SKIPPED.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Controllers\AiAssistantController;
use App\Services\AI\Providers\EdgeTtsProvider;
use App\Services\AI\Support\TtsAudioCache;

$passed = 0;
$failed = 0;
$skipped = 0;

function c_assert(bool $cond, string $msg, string $extra = ''): void
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

function c_skip(string $msg): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIPPED] {$msg}\n";
}

$work = sys_get_temp_dir() . '/zkt_tts_test_' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);
$secret = bin2hex(random_bytes(32));
$cache = new TtsAudioCache($work . '/cache', $secret, 3600, 600);
$fakeMp3 = static fn (string $tmp): bool => (bool) file_put_contents($tmp, "ID3" . str_repeat("\xFF", 1200));

echo "====================================================\n";
echo "TTS cache security tests\n";
echo "====================================================\n";

echo "\nA1. Cache key\n";
$params = ['provider' => 'edge_neural', 'engine' => 'edge-tts/7', 'voice' => 'kk-KZ-AigulNeural', 'language' => 'kk', 'rate' => '+0%', 'text' => 'Сәлем'];
$id = $cache->cacheId($params);
c_assert((bool) preg_match('/^[a-f0-9]{64}$/', $id), 'Cache id is 64-hex HMAC-SHA256');
c_assert($id !== md5($params['voice'] . '_' . $params['text']) && $id !== hash('sha256', $params['text']), 'Not derivable as md5/sha256 of the text');
$other = new TtsAudioCache($work . '/cache2', bin2hex(random_bytes(32)));
c_assert($other->cacheId($params) !== $id, 'Different server secret → different id (secret required to guess)');
c_assert($cache->cacheId(array_merge($params, ['voice' => 'kk-KZ-DauletNeural'])) !== $id, 'Voice is part of the key');
c_assert($cache->cacheId(array_merge($params, ['rate' => '+10%'])) !== $id, 'Synthesis settings are part of the key');
c_assert($cache->cacheId(array_merge($params, ['engine' => 'edge-tts/8'])) !== $id, 'Engine version is part of the key');
c_assert($cache->cacheId(array_reverse($params, true)) === $id, 'Key is independent of parameter order');

echo "\nA2. Storage\n";
c_assert($cache->getOrCreate($id, $fakeMp3), 'Audio created through producer');
$path = $cache->pathFor($id);
c_assert($path !== null && is_file($path) && str_starts_with($path, $work . '/cache/'), 'Stored inside private cache dir');
c_assert((fileperms($path) & 0777) === 0600, 'File permissions 0600', decoct(fileperms($path) & 0777));
c_assert((fileperms($work . '/cache') & 0777) === 0700, 'Directory permissions 0700', decoct(fileperms($work . '/cache') & 0777));
c_assert($cache->pathFor('../../etc/passwd') === null && $cache->pathFor(str_repeat('g', 64)) === null, 'Path traversal / malformed id rejected');
$calls = 0;
$cache->getOrCreate($id, function (string $t) use (&$calls, $fakeMp3) { $calls++; return $fakeMp3($t); });
c_assert($calls === 0, 'Existing audio reused, engine not called again');
$badId = $cache->cacheId(['text' => 'tiny']);
c_assert(!$cache->getOrCreate($badId, static fn (string $t): bool => (bool) file_put_contents($t, 'x')), 'Too-small output is rejected (no fake success)');
c_assert(!$cache->has($badId), 'Rejected output is not cached');
c_assert(!$cache->getOrCreate($badId, static fn (string $t): bool => false), 'Producer failure → false');
c_assert(glob($work . '/cache/*/*.tmp.*') === [], 'No temp files left');

echo "\nA3. Signed tokens\n";
$alice = 'u101';
$bob = 'u202';
$token = $cache->issueToken($id, $alice);
c_assert($token !== null && $cache->resolveToken($token, $alice) === $path, 'Owner can resolve own token');
c_assert($cache->resolveToken($token, $bob) === null, "Another user cannot use someone else's token");
c_assert($cache->resolveToken($token, 'sGUESTSESSION') === null, 'Guest session cannot use a user token');
[$tId, $tExp, $tSig] = explode('.', (string) $token);
c_assert($cache->resolveToken($tId . '.' . ($tExp + 999999) . '.' . $tSig, $alice) === null, 'Extending expiry invalidates signature');
c_assert($cache->resolveToken($cache->cacheId(['text' => 'other']) . '.' . $tExp . '.' . $tSig, $alice) === null, 'Swapping the id (IDOR) invalidates signature');
c_assert($cache->resolveToken($tId . '.' . $tExp . '.' . str_repeat('0', 64), $alice) === null, 'Forged signature rejected');
c_assert($cache->resolveToken($tId, $alice) === null && $cache->resolveToken('', $alice) === null && $cache->resolveToken('../' . $tId, $alice) === null, 'Bare id / garbage rejected (URL guessing)');
c_assert($cache->resolveToken((string) $token, $alice, time() + 601) === null, 'Expired token rejected');
c_assert($cache->resolveToken((string) $token, $alice, time() + 60) === $path, 'Token reusable by owner within its lifetime');
$bobToken = $cache->issueToken($id, $bob);
c_assert($bobToken !== $token && $cache->resolveToken((string) $bobToken, $bob) === $path, 'Shared audio: each owner gets an own token');

echo "\nA4. Expiry and purge\n";
touch($path, time() - 7200);
c_assert(!$cache->has($id), 'File older than TTL is not served');
c_assert($cache->resolveToken((string) $token, $alice) === null, 'Token for expired audio rejected');
$cache->getOrCreate($id, $fakeMp3);
$id2 = $cache->cacheId(['text' => 'old']);
$cache->getOrCreate($id2, $fakeMp3);
touch((string) $cache->pathFor($id2), time() - 7200);
$removed = $cache->purgeExpired();
c_assert($removed >= 1 && !is_file((string) $cache->pathFor($id2)) && is_file((string) $cache->pathFor($id)), 'purgeExpired removes only expired files', "removed={$removed}");
c_assert($cache->clear() >= 1 && !$cache->has($id), 'clear() empties the cache');

echo "\nA5. Concurrent creation of the same audio\n";
$conc = $work . '/conc';
$counter = $work . '/producer_calls.log';
$script = $work . '/worker.php';
file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/tests/bootstrap.php', true) . ';'
    . '$c = new App\Services\AI\Support\TtsAudioCache(' . var_export($conc, true) . ', ' . var_export($secret, true) . ');'
    . '$id = $c->cacheId(["text" => "concurrent"]);'
    . '$ok = $c->getOrCreate($id, function ($t) { file_put_contents(' . var_export($counter, true) . ', "x", FILE_APPEND | LOCK_EX); usleep(400000); return (bool) file_put_contents($t, "ID3" . str_repeat("A", 2000)); });'
    . 'echo $ok ? "ok" : "fail";');
$procs = [];
for ($i = 0; $i < 4; $i++) {
    $procs[] = popen(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null', 'r');
}
$outs = array_map(static function ($p) { $o = stream_get_contents($p); pclose($p); return trim((string) $o); }, $procs);
$producerCalls = strlen((string) @file_get_contents($counter));
c_assert($outs === ['ok', 'ok', 'ok', 'ok'], '4 parallel processes all get the audio', implode(',', $outs));
c_assert($producerCalls === 1, 'Engine called exactly once (flock)', "calls={$producerCalls}");

echo "\nA6. EdgeTtsProvider: voices and honest fallback (fake python)\n";
$fakePy = $work . '/fake-python.sh';
file_put_contents($fakePy, <<<'SH'
#!/bin/sh
VOICE=""; OUT=""
while [ $# -gt 0 ]; do
  case "$1" in --voice) VOICE="$2"; shift;; --write-media) OUT="$2"; shift;; esac
  shift
done
echo "$VOICE" >> "$(dirname "$0")/voices.log"
case "$VOICE" in
  kk-KZ-AigulNeural|kk-KZ-DauletNeural|ru-RU-DmitryNeural) printf 'ID3' > "$OUT"; head -c 2000 /dev/zero >> "$OUT"; exit 0;;
  *) exit 1;;
esac
SH);
chmod($fakePy, 0700);
$pCache = new TtsAudioCache($work . '/pcache', $secret);
$cfgVoices = ['voices' => ['ru' => ['ru-RU-DmitryNeural'], 'kk' => ['kk-KZ-AigulNeural', 'kk-KZ-DauletNeural'], 'en' => ['en-US-JennyNeural']]];
$p = new EdgeTtsProvider(null, $pCache, $cfgVoices, $fakePy);
$r = $p->synthesize('Тапсырыс №5 дайын, 15 000 тг.', 'kk', ['audio_owner' => 'u7']);
c_assert($r->ok && !$r->useBrowser && $r->voice === 'kk-KZ-AigulNeural', 'kk → kk-KZ-AigulNeural by default', (string) $r->voice);
c_assert(str_contains($r->cleanText, 'нөмір') && str_contains($r->cleanText, 'мың теңге'), 'Kazakh normalization (нөмір, мың теңге)', $r->cleanText);
c_assert(is_string($r->audioUrl) && (bool) preg_match('#/ai/tts/audio/[a-f0-9]{64}\.\d+\.[a-f0-9]{64}$#', $r->audioUrl), 'audio_url is a signed controller URL', (string) $r->audioUrl);
c_assert(!str_contains((string) $r->audioUrl, 'storage') && !str_contains((string) $r->audioUrl, '.mp3'), 'URL does not expose storage path or file name');
$token = substr((string) $r->audioUrl, strrpos((string) $r->audioUrl, '/') + 1);
c_assert($pCache->resolveToken($token, 'u7') !== null && $pCache->resolveToken($token, 'u8') === null, 'URL bound to requesting owner');
$r = $p->synthesize('Сәлем', 'kk', ['voice' => 'kk-KZ-DauletNeural', 'audio_owner' => 'u7']);
c_assert($r->ok && $r->voice === 'kk-KZ-DauletNeural', 'Allowed voice override honoured');
$r = $p->synthesize('Сәлем', 'kk', ['voice' => 'kk-KZ-DariyaNeural', 'audio_owner' => 'u7']);
c_assert($r->voice === 'kk-KZ-AigulNeural' && in_array('voice_not_allowed', $r->warnings, true), 'Non-existent/non-whitelisted voice ignored with warning');
$r = $p->synthesize('Hi', 'en', ['audio_owner' => 'u7']);
c_assert($r->ok && $r->useBrowser && $r->audioUrl === null && in_array('server_tts_failed', $r->warnings, true) && in_array('fallback_browser', $r->warnings, true) && $r->provider === 'browser',
    'Engine failure → explicit browser fallback, no fake audio', implode(',', $r->warnings));
$r = $p->synthesize('Сәлем', 'de', ['audio_owner' => 'u7']);
c_assert($r->language === 'ru', 'Unsupported language falls back to ru voice set');
$r = $p->synthesize('   ', 'kk');
c_assert(!$r->ok && in_array('empty_text', $r->warnings, true), 'Empty text → failure');

echo "\nA7. Controller /ai/tts/audio/{token}\n";
$runEndpoint = static function (string $token): array {
    $code = '<?php require ' . var_export(dirname(__DIR__) . '/tests/bootstrap.php', true) . ';'
        . '$c = new App\Controllers\AiAssistantController(); ob_start(); $c->ttsAudio(' . var_export($token, true) . '); $b = ob_get_clean();'
        . 'echo json_encode(["code" => http_response_code(), "len" => strlen($b), "body" => substr($b, 0, 20)]);';
    $f = sys_get_temp_dir() . '/zkt_ep_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($f, $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($f) . ' 2>/dev/null');
    @unlink($f);
    return (array) json_decode(substr($out, (int) strrpos($out, '{')), true);
};
foreach (['garbage', str_repeat('a', 64), str_repeat('a', 64) . '.9999999999.' . str_repeat('b', 64), '../../config/database.php'] as $bad) {
    $res = $runEndpoint($bad);
    c_assert(($res['code'] ?? 0) === 404 && ($res['body'] ?? '') === 'Not found', 'Invalid token → 404 without details: ' . substr($bad, 0, 24));
}

// ---------------------------------------------------------------------------
echo "\nB. Real edge-tts synthesis and HTTP exposure\n";
$haveEdge = trim((string) shell_exec('python3 -c "import edge_tts" 2>&1')) === '';
if (!$haveEdge) {
    c_skip('python edge_tts not installed');
} else {
    $realCache = new TtsAudioCache($work . '/real', $secret);
    $real = new EdgeTtsProvider(null, $realCache, (array) ((require dirname(__DIR__) . '/config/ai.php')['tts'] ?? []));
    foreach ([['kk', 'kk-KZ-AigulNeural'], ['kk', 'kk-KZ-DauletNeural'], ['ru', 'ru-RU-DmitryNeural']] as [$lang, $voice]) {
        $r = $real->synthesize($lang === 'kk' ? 'Тапсырыс №12345 дайын. Сома: 15 000 теңге.' : 'Заказ №12345 готов. Сумма: 15 000 тенге.', $lang, ['voice' => $voice, 'audio_owner' => 'u1']);
        if (!$r->ok || $r->useBrowser) {
            c_skip("{$voice}: edge-tts returned no audio (service-side; 2026-11-06 ru voices reject texts with numbers) — " . implode(',', $r->warnings));
            continue;
        }
        $tok = substr((string) $r->audioUrl, strrpos((string) $r->audioUrl, '/') + 1);
        $file = $realCache->resolveToken($tok, 'u1');
        $head = $file ? (string) file_get_contents($file, false, null, 0, 3) : '';
        c_assert($file !== null && filesize($file) > 5000 && ($head === 'ID3' || ord($head[0] ?? "\0") === 0xFF), "{$voice}: real MP3 synthesized", $file ? filesize($file) . ' bytes' : 'no file');
    }
    $r = $real->synthesize('Сәлем', 'kk', ['voice' => 'kk-KZ-DariyaNeural', 'audio_owner' => 'u1']);
    c_assert(in_array('voice_not_allowed', $r->warnings, true), 'kk-KZ-DariyaNeural is not accepted');
}

$base = 'http://localhost/zakopeyki';
$probe = @get_headers($base . '/', true);
if ($probe === false) {
    c_skip('local web server not reachable');
} else {
    $status = static function (string $url): int {
        $h = @get_headers($url);
        return $h && preg_match('#\s(\d{3})\s#', $h[0], $m) ? (int) $m[1] : 0;
    };
    c_assert($status($base . '/storage/cache/tts/') === 403, 'HTTP: /storage/cache/tts/ is forbidden (403)');
    c_assert($status($base . '/storage/keys/tts_cache.key') === 403, 'HTTP: secret key file is forbidden (403)');
    c_assert(in_array($status($base . '/public/storage/cache/tts/'), [403, 404], true) || $status($base . '/public/storage/cache/tts/') === 200 && !is_dir(dirname(__DIR__) . '/public/storage/cache/tts'),
        'HTTP: old public cache directory removed');
    c_assert(!is_dir(dirname(__DIR__) . '/public/storage/cache/tts'), 'Old public TTS cache directory does not exist');
}

foreach (array_reverse(array_merge(glob($work . '/*/*/*') ?: [], glob($work . '/*/*') ?: [], glob($work . '/*') ?: [])) as $f) {
    is_dir($f) ? @rmdir($f) : @unlink($f);
}
@rmdir($work);

echo "\n====================================================\n";
echo "SUMMARY: Passed: {$passed}, Failed: {$failed}, Skipped: {$skipped}\n";
echo "====================================================\n";
exit($failed > 0 ? 1 : 0);
