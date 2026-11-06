<?php

declare(strict_types=1);

/**
 * Тесты серверного STT на whisper.cpp (ServerAudioSttProvider).
 *
 *   php tools/run_stt_whisper_tests.php
 *
 * Часть A — детерминированные тесты с поддельным whisper-cli (выполняются всегда).
 * Часть B — реальное распознавание аудио: выполняется, только если whisper-cli и модель
 *           установлены (config/ai.php → voice.whisper_*) и есть python edge_tts + afconvert/ffmpeg
 *           для генерации синтетических фикстур. Иначе помечается как SKIPPED (не PASS).
 *
 * Фикстуры — синтетическая речь (edge-tts) во временной папке; удаляются после теста.
 * Пользовательские записи не используются.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';

use App\Services\AI\Providers\ServerAudioSttProvider;

$passed = 0;
$failed = 0;
$skipped = 0;

function t_assert(bool $cond, string $msg, string $extra = ''): void
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

function t_skip(string $msg): void
{
    global $skipped;
    $skipped++;
    echo "  [SKIPPED] {$msg}\n";
}

function make_wav(string $path, float $seconds, int $amplitude, int $rate = 16000): void
{
    $n = (int) ($seconds * $rate);
    $pcm = '';
    for ($i = 0; $i < $n; $i++) {
        $pcm .= pack('v', $amplitude === 0 ? 0 : (random_int(-$amplitude, $amplitude) & 0xFFFF));
    }
    $header = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', strlen($pcm));
    file_put_contents($path, $header . $pcm);
}

function tmp_leftovers(): int
{
    return count(glob(sys_get_temp_dir() . '/zk_stt_*') ?: []);
}

$work = sys_get_temp_dir() . '/zkt_whisper_test_' . bin2hex(random_bytes(4));
mkdir($work, 0700, true);

echo "====================================================\n";
echo "STT whisper.cpp tests\n";
echo "====================================================\n";

// ---------------------------------------------------------------------------
// A. Детерминированные тесты (поддельный whisper-cli)
// ---------------------------------------------------------------------------
echo "\nA1. parseWhisperJson / confidence\n";

$p = new ServerAudioSttProvider([]);
$parsed = $p->parseWhisperJson([
    'result' => ['language' => 'ru'],
    'transcription' => [
        ['text' => ' Зак, найди', 'tokens' => [
            ['text' => '[_BEG_]', 'p' => 0.1],
            ['text' => ' Зак', 'p' => 0.8],
            ['text' => ',', 'p' => 0.6],
            ['text' => '[_TT_50]', 'p' => 0.05],
        ]],
        ['text' => ' айфон ', 'tokens' => [['text' => ' айфон', 'p' => 0.7]]],
    ],
]);
t_assert($parsed['text'] === 'Зак, найди айфон', 'Segments joined and trimmed', $parsed['text']);
t_assert($parsed['language'] === 'ru', 'Language taken from whisper result');
t_assert($parsed['confidence'] !== null && abs($parsed['confidence'] - 0.7) < 1e-9, 'Confidence = mean p of text tokens, special tokens excluded', (string) $parsed['confidence']);

$empty = $p->parseWhisperJson(['transcription' => []]);
t_assert($empty['text'] === '' && $empty['confidence'] === null, 'No segments → empty text, confidence null (not invented)');

echo "\nA2. resolveLanguage (whisper auto-detect is never used)\n";
$lp = new ServerAudioSttProvider(['stt_supported_languages' => ['ru', 'kk', 'en'], 'stt_default_language' => 'ru']);
t_assert($lp->resolveLanguage('ru-RU') === 'ru', 'ru-RU → ru');
t_assert($lp->resolveLanguage('KK') === 'kk', 'KK → kk');
t_assert($lp->resolveLanguage('en-US') === 'en', 'en-US → en');
t_assert($lp->resolveLanguage('auto') === 'ru', "'auto' → default ru");
t_assert($lp->resolveLanguage('de') === 'ru', 'unsupported de → default ru');
t_assert($lp->resolveLanguage(null) === 'ru', 'null → default ru');

// Поддельный whisper-cli: пишет JSON в <-of>.json, логирует аргументы
$fakeModel = $work . '/fake-model.bin';
file_put_contents($fakeModel, 'x');
$argsLog = $work . '/args.log';
$fakeOk = $work . '/fake-whisper-ok.sh';
file_put_contents($fakeOk, <<<SH
#!/bin/sh
echo "\$@" > '{$argsLog}'
OUT=""
while [ \$# -gt 0 ]; do
  if [ "\$1" = "-of" ]; then OUT="\$2"; fi
  shift
done
ls -l "\$OUT.json" | cut -c1-10 >> '{$argsLog}'
printf '%s' '{"result":{"language":"kk"},"transcription":[{"text":" Сәлем","tokens":[{"text":" Сәлем","p":0.9}]}]}' > "\$OUT.json"
exit 0
SH);
chmod($fakeOk, 0700);

$fakeFail = $work . '/fake-whisper-fail.sh';
file_put_contents($fakeFail, "#!/bin/sh\nexit 3\n");
chmod($fakeFail, 0700);

$fakeSlow = $work . '/fake-whisper-slow.sh';
file_put_contents($fakeSlow, "#!/bin/sh\nsleep 30\n");
chmod($fakeSlow, 0700);

$fakeSilent = $work . '/fake-whisper-silent.sh';
file_put_contents($fakeSilent, <<<SH
#!/bin/sh
OUT=""
while [ \$# -gt 0 ]; do
  if [ "\$1" = "-of" ]; then OUT="\$2"; fi
  shift
done
printf '%s' '{"result":{"language":"ru"},"transcription":[]}' > "\$OUT.json"
SH);
chmod($fakeSilent, 0700);

$speechWav = $work . '/tone.wav';
make_wav($speechWav, 1.0, 3000);

$baseCfg = [
    'whisper_model_path' => $fakeModel,
    'whisper_vad_model_path' => $fakeModel,
    'whisper_threads' => 2,
    'whisper_timeout_seconds' => 5,
    'whisper_max_concurrent' => 2,
    'stt_supported_languages' => ['ru', 'kk', 'en'],
    'stt_default_language' => 'ru',
];

echo "\nA3. Process invocation and result mapping\n";
$ok = new ServerAudioSttProvider($baseCfg + ['whisper_cli_path' => $fakeOk]);
t_assert($ok->isAvailable(), 'isAvailable() true when cli + model exist');
$r = $ok->transcribe($speechWav, 'audio/wav', ['language_hint' => 'kk-KZ', 'request_id' => 'stt_test1']);
$args = (string) @file_get_contents($argsLog);
t_assert($r->ok && $r->recognizedText === 'Сәлем', 'Success result from whisper JSON', $r->recognizedText);
t_assert($r->provider === 'whisper_cpp' && $r->language === 'kk', 'provider=whisper_cpp, language=kk');
t_assert($r->confidence === 0.9, 'Confidence from token probabilities', (string) $r->confidence);
t_assert(str_contains($args, '-l kk') && str_contains($args, '--vad') && str_contains($args, '-ojf') && str_contains($args, '-np'), 'CLI args: explicit language, VAD, full JSON, no prints', trim($args));
t_assert(!str_contains($args, 'auto'), "Never passes '-l auto'");
t_assert(str_contains($args, '-rw-------'), 'Transcript JSON file is private (0600) while whisper runs');
t_assert($r->requestId === 'stt_test1', 'request_id propagated');

$noVad = new ServerAudioSttProvider(['whisper_vad_model_path' => $work . '/missing.bin'] + $baseCfg + ['whisper_cli_path' => $fakeOk]);
$r = $noVad->transcribe($speechWav, 'audio/wav');
t_assert($r->ok && in_array('vad_model_missing', $r->warnings, true), 'Missing VAD model is reported in warnings');

$silent = new ServerAudioSttProvider($baseCfg + ['whisper_cli_path' => $fakeSilent]);
$r = $silent->transcribe($speechWav, 'audio/wav');
t_assert(!$r->ok && in_array('no_speech_detected', $r->warnings, true), 'Empty transcription → no_speech_detected');

$fail = new ServerAudioSttProvider($baseCfg + ['whisper_cli_path' => $fakeFail]);
$r = $fail->transcribe($speechWav, 'audio/wav');
t_assert(!$r->ok && in_array('whisper_exit_code_3', $r->warnings, true), 'Non-zero exit → failure with exit code', implode(',', $r->warnings));

echo "\nA4. Timeout kills the process\n";
$slow = new ServerAudioSttProvider($baseCfg + ['whisper_cli_path' => $fakeSlow]);
$t0 = microtime(true);
$r = $slow->transcribe($speechWav, 'audio/wav');
$elapsed = microtime(true) - $t0;
t_assert(!$r->ok && in_array('stt_timeout', $r->warnings, true), 'Timeout → stt_timeout');
t_assert($elapsed < 7.0, 'Returned near the 5 s limit', round($elapsed, 2) . 's');
usleep(200000);
$orphans = trim((string) shell_exec('pgrep -f ' . escapeshellarg($fakeSlow) . ' 2>/dev/null'));
t_assert($orphans === '', 'No orphan process after timeout');

echo "\nA5. Concurrency limit\n";
$held = [];
for ($i = 0; $i < 2; $i++) {
    $fh = fopen(sys_get_temp_dir() . '/zk_whisper_slot_' . $i . '.lock', 'c');
    flock($fh, LOCK_EX | LOCK_NB);
    $held[] = $fh;
}
// flock в одном процессе не конфликтует сам с собой на macOS/Linux для разных fd? Проверяем в дочернем процессе.
$child = PHP_BINARY . ' -r ' . escapeshellarg(
    'require ' . var_export(dirname(__DIR__) . '/tests/bootstrap.php', true) . ';'
    . '$p=new App\\Services\\AI\\Providers\\ServerAudioSttProvider(' . var_export($baseCfg + ['whisper_cli_path' => $fakeOk], true) . ');'
    . '$r=$p->transcribe(' . var_export($speechWav, true) . ',"audio/wav");'
    . 'echo implode(",",$r->warnings);'
);
$childOut = trim((string) shell_exec($child . ' 2>/dev/null'));
t_assert(str_contains($childOut, 'stt_busy'), 'All slots busy → stt_busy', $childOut);
foreach ($held as $fh) {
    flock($fh, LOCK_UN);
    fclose($fh);
}

echo "\nA6. Configuration / formats\n";
$nc = new ServerAudioSttProvider(['whisper_cli_path' => '', 'whisper_model_path' => $fakeModel]);
$r = $nc->transcribe($speechWav, 'audio/wav');
t_assert(!$r->ok && in_array('server_stt_not_configured', $r->warnings, true), 'No cli → server_stt_not_configured');
t_assert(!$nc->isAvailable(), 'isAvailable() false without cli');

$nm = new ServerAudioSttProvider(['whisper_cli_path' => $fakeOk, 'whisper_model_path' => $work . '/nope.bin']);
t_assert(!$nm->isAvailable(), 'isAvailable() false without model');

$webm = $work . '/rec.webm';
file_put_contents($webm, "\x1A\x45\xDF\xA3" . str_repeat("\x00", 400));
$r = $ok->transcribe($webm, 'audio/webm');
t_assert(!$r->ok && in_array('wav_required', $r->warnings, true) && in_array('unsupported_format', $r->warnings, true), 'webm without ffmpeg → unsupported_format + wav_required');

$fakeMp3AsWav = $work . '/fake.wav';
file_put_contents($fakeMp3AsWav, 'ID3' . str_repeat("\x00", 400));
$r = $ok->transcribe($fakeMp3AsWav, 'audio/wav');
t_assert(!$r->ok && in_array('unsupported_format', $r->warnings, true), 'Client MIME is not trusted (magic bytes checked)');

$tooLong = $work . '/long.wav';
make_wav($tooLong, 61.0, 0);
$r = $ok->transcribe($tooLong, 'audio/wav');
t_assert(!$r->ok && in_array('exceeded_duration', $r->warnings, true), 'WAV > 60 s rejected before whisper');

$r = $ok->transcribe('data:audio/wav;base64,' . base64_encode((string) file_get_contents($speechWav)), null);
t_assert($r->ok, 'base64 data URI accepted');
$r = $ok->transcribe(str_repeat('@', 300), null);
t_assert(!$r->ok && in_array('corrupted_base64', $r->warnings, true), 'Corrupted base64 rejected');
t_assert(tmp_leftovers() === 0, 'No temporary audio/json files left in temp dir', (string) tmp_leftovers());

echo "\nA7. Env flag parsing in config/ai.php\n";
$cases = ['' => [true, false], '0' => [false, false], 'false' => [false, false], 'off' => [false, false], '1' => [true, true], 'true' => [true, true]];
foreach ($cases as $val => [$expFallback, $expDebug]) {
    $val = (string) $val;
    $cmd = 'AI_SERVER_STT_FALLBACK=' . escapeshellarg($val) . ' AI_STT_DEBUG=' . escapeshellarg($val) . ' '
        . PHP_BINARY . ' -r ' . escapeshellarg('$c=require ' . var_export(dirname(__DIR__) . '/config/ai.php', true) . '; echo json_encode([$c["voice"]["server_stt_fallback_enabled"],$c["voice"]["stt_debug_enabled"]]);');
    $got = json_decode((string) shell_exec($cmd), true);
    t_assert($got === [$expFallback, $expDebug], "env '{$val}' → fallback=" . var_export($expFallback, true) . ', debug=' . var_export($expDebug, true), json_encode($got));
}

// ---------------------------------------------------------------------------
// B. Реальное распознавание (whisper.cpp + модель)
// ---------------------------------------------------------------------------
echo "\nB. Real audio through whisper.cpp\n";

$voiceCfg = (array) ((require dirname(__DIR__) . '/config/ai.php')['voice'] ?? []);
$real = new ServerAudioSttProvider($voiceCfg);

$haveEdge = trim((string) shell_exec('python3 -c "import edge_tts" 2>&1')) === '';
$afconvert = is_executable('/usr/bin/afconvert') ? '/usr/bin/afconvert' : '';
$ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

if (!$real->isWhisperConfigured()) {
    t_skip('whisper-cli or model not installed (see docs/AI_SETUP.md, раздел «Серверный STT»)');
} elseif (!$haveEdge || ($afconvert === '' && $ffmpeg === '')) {
    t_skip('fixture generator unavailable (needs python edge_tts and afconvert or ffmpeg)');
} else {
    $fixtures = [
        'ru' => ['voice' => 'ru-RU-DmitryNeural', 'text' => 'Зак, найди мне айфон пятнадцать до трёхсот тысяч тенге'],
        'kk' => ['voice' => 'kk-KZ-AigulNeural', 'text' => 'Зак, маған үш жүз мың теңгеге дейін телефон тауып бер'],
        'en' => ['voice' => 'en-US-JennyNeural', 'text' => 'Zak, find me an iPhone fifteen under three hundred thousand tenge'],
    ];
    $made = [];
    foreach ($fixtures as $lang => $f) {
        $txt = "{$work}/{$lang}.txt";
        $mp3 = "{$work}/{$lang}.mp3";
        $wav = "{$work}/{$lang}.wav";
        file_put_contents($txt, $f['text']);
        shell_exec('python3 -m edge_tts --voice ' . escapeshellarg($f['voice']) . ' -f ' . escapeshellarg($txt) . ' --write-media ' . escapeshellarg($mp3) . ' 2>/dev/null');
        if (!is_file($mp3) || filesize($mp3) < 1000) {
            continue;
        }
        if ($afconvert !== '') {
            shell_exec("{$afconvert} -f WAVE -d LEI16@16000 -c 1 " . escapeshellarg($mp3) . ' ' . escapeshellarg($wav) . ' 2>/dev/null');
        } else {
            shell_exec(escapeshellarg($ffmpeg) . ' -nostdin -loglevel error -y -i ' . escapeshellarg($mp3) . ' -ar 16000 -ac 1 ' . escapeshellarg($wav));
        }
        if (is_file($wav)) {
            $made[$lang] = $wav;
        }
    }

    if ($made === []) {
        t_skip('fixture generation failed (network/edge-tts unavailable)');
    } else {
        $norm = static fn (string $s): string => mb_strtolower((string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $s));
        if (isset($made['ru'])) {
            $r = $real->transcribe($made['ru'], 'audio/wav', ['language_hint' => 'ru']);
            $n = $norm($r->recognizedText);
            t_assert($r->ok && str_contains($n, 'найди') && (str_contains($n, 'iphone') || str_contains($n, 'айфон')) && str_contains($n, '300'),
                'RU command recognized (найди + iPhone + 300)', "{$r->durationMs}ms conf={$r->confidence} «{$r->recognizedText}»");
            t_assert(str_starts_with($n, 'зак'), 'RU: beginning of phrase («Зак») preserved');
        }
        if (isset($made['en'])) {
            $r = $real->transcribe($made['en'], 'audio/wav', ['language_hint' => 'en']);
            $n = $norm($r->recognizedText);
            t_assert($r->ok && str_contains($n, 'find') && str_contains($n, 'iphone'), 'EN command recognized (find + iPhone)', "{$r->durationMs}ms conf={$r->confidence} «{$r->recognizedText}»");
        }
        if (isset($made['kk'])) {
            $r = $real->transcribe($made['kk'], 'audio/wav', ['language_hint' => 'kk']);
            // Качество казахского у small-модели низкое: проверяем только, что результат получен на kk.
            t_assert($r->ok && $r->language === 'kk', 'KK audio transcribed with language=kk (quality NOT asserted)', "conf={$r->confidence} «{$r->recognizedText}»");
        }

        $silence = $work . '/silence.wav';
        make_wav($silence, 3.0, 0);
        $r = $real->transcribe($silence, 'audio/wav', ['language_hint' => 'ru']);
        t_assert(!$r->ok && in_array('no_speech_detected', $r->warnings, true), 'Silence → no_speech_detected (no hallucinated text)', $r->recognizedText);

        $noise = $work . '/noise.wav';
        make_wav($noise, 3.0, 1500);
        $r = $real->transcribe($noise, 'audio/wav', ['language_hint' => 'ru']);
        t_assert(!$r->ok && in_array('no_speech_detected', $r->warnings, true), 'White noise → no_speech_detected', $r->recognizedText);

        t_assert(tmp_leftovers() === 0, 'No temporary files left after real runs');
    }
}

// Очистка
foreach (glob($work . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($work);

echo "\n====================================================\n";
echo "SUMMARY: Passed: {$passed}, Failed: {$failed}, Skipped: {$skipped}\n";
echo "====================================================\n";
exit($failed > 0 ? 1 : 0);
