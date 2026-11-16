#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Проверка интеграции с Genlook ТОЛЬКО в бесплатном mock-режиме (docs/tryon/07-phase2-report.md §4).
 * Mock принудительно включён в коде скрипта: кредиты не расходуются, AI не вызывается.
 * Используются синтетические изображения (без фото людей). БД не используется.
 *
 *   read -s TRYON_GENLOOK_API_KEY && export TRYON_GENLOOK_API_KEY
 *   php bin/tryon_genlook_mock_check.php
 *
 * Ключ и URL результата не выводятся (печатается только хост результата).
 */

$root = require __DIR__ . '/bootstrap.php';

use App\Services\TryOn\Providers\GenlookProvider;
use App\Services\TryOn\Providers\ProviderException;
use App\Services\TryOn\Providers\ProviderJobState;
use App\Services\TryOn\Providers\TryOnSubmission;
use App\Services\TryOn\TryOnException;
use App\Services\TryOn\TryOnImageValidator;
use App\Services\TryOn\TryOnResultFetcher;

$cfgAll = require $root . '/config/tryon.php';
$cfg = $cfgAll['providers']['genlook'];
$cfg['mock'] = true; // жёстко: только бесплатный режим

if (trim((string) $cfg['api_key']) === '' && function_exists('posix_isatty') && posix_isatty(STDIN)) {
    // Скрытый ввод ключа: не попадает в историю shell и не отображается на экране.
    fwrite(STDOUT, 'Вставьте ключ Genlook и нажмите Enter (ввод скрыт): ');
    shell_exec('stty -echo');
    $cfg['api_key'] = trim((string) fgets(STDIN));
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}

if (trim((string) $cfg['api_key']) === '') {
    fwrite(STDERR, "TRYON_GENLOOK_API_KEY не задан. См. заголовок скрипта.\n");
    exit(2);
}

$provider = new GenlookProvider($cfg);
if (!$provider->isTest() || $provider->estimateCostMicros('upper') !== 0) {
    fwrite(STDERR, "Защита: mock-режим не активен — остановлено.\n");
    exit(3);
}

$tmp = sys_get_temp_dir() . '/tryon_mock_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$mk = static function (string $path, int $w, int $h, array $rgb): void {
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 8) {
        imagefilledrectangle($img, 0, $y, $w, $y + 7, imagecolorallocate($img, ($rgb[0] + $y) % 256, $rgb[1], ($rgb[2] + intdiv($y, 2)) % 256));
    }
    imagejpeg($img, $path, 90);
    imagedestroy($img);
};
$person = $tmp . '/person.jpg';
$garment = $tmp . '/garment.jpg';
$mk($person, 800, 1000, [30, 120, 200]);
$mk($garment, 600, 800, [200, 40, 60]);

$extUser = 'zkmockcheck' . bin2hex(random_bytes(6));
$sub = new TryOnSubmission('MOCKCHECK', 'upper', $person, $garment, 'Mock check t-shirt', $extUser);
$out = static fn (string $s) => print('[' . date('H:i:s') . "] {$s}\n");
$exit = 0;

try {
    $t0 = microtime(true);
    $state = $provider->submit($sub);
    $out(sprintf('submit OK: state=%s, id=%s…, %.2fs', $state->state, substr($state->externalId, 0, 6), microtime(true) - $t0));

    $deadline = time() + 90;
    $polls = 0;
    do {
        sleep($polls === 0 ? 1 : 2);
        $polls++;
        $state = $provider->status($state->externalId);
        $out("poll #{$polls}: {$state->state}");
    } while (in_array($state->state, [ProviderJobState::PENDING, ProviderJobState::PROCESSING], true) && time() < $deadline);
    $out(sprintf('итог: %s за %.1fs, опросов %d', $state->state, microtime(true) - $t0, $polls));

    if ($state->state === ProviderJobState::COMPLETED && $state->resultUrl !== null) {
        $host = (string) parse_url($state->resultUrl, PHP_URL_HOST);
        $scheme = (string) parse_url($state->resultUrl, PHP_URL_SCHEME);
        $allowed = TryOnResultFetcher::hostAllowed(strtolower($host), $provider->resultHosts());
        $out("хост результата: {$scheme}://{$host} — " . ($allowed ? 'в allowlist' : 'НЕ в allowlist (добавить в TRYON_GENLOOK_RESULT_HOSTS)'));
        if ($allowed) {
            $bytes = (new TryOnResultFetcher((int) $cfgAll['result_fetch']['max_bytes'], (int) $cfgAll['result_fetch']['timeout']))
                ->fetch($state->resultUrl, $provider->resultHosts());
            $img = (new TryOnImageValidator($cfgAll['upload']))->processBytes($bytes);
            $out(sprintf('результат скачан: %d байт, %dx%d (mock = исходное фото, ожидаемо)', strlen($bytes), $img['width'], $img['height']));
        } else {
            $exit = 1;
        }
    } else {
        $out('ошибка генерации: ' . ($state->errorCode ?? 'unknown'));
        $exit = 1;
    }
} catch (ProviderException $e) {
    $out('ProviderException: ' . $e->getMessage() . ($e->retryable ? ' (retryable)' : ''));
    $exit = 1;
} catch (TryOnException $e) {
    $out('TryOnException: ' . $e->errorCode . ' ' . json_encode($e->extra));
    $exit = 1;
} finally {
    try {
        $out('удаление тестовых данных у Genlook (DELETE /customers/…): ' . ($provider->purgeUser($extUser) ? 'OK' : 'не подтверждено'));
    } catch (\Throwable $e) {
        $out('удаление: ошибка ' . get_class($e));
    }
    @unlink($person);
    @unlink($garment);
    @rmdir($tmp);
}

exit($exit);
