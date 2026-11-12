<?php

declare(strict_types=1);

/**
 * Тесты AI Virtual Try-On (docs/tryon/06-testing.md). Без сети и платных запросов.
 *   php tools/run_tryon_tests.php          # unit + интеграция на локальной БД
 *   php tools/run_tryon_tests.php --unit   # только unit
 * Тестовые данные (пользователи, товары, задания, файлы) удаляются в конце.
 */

require dirname(__DIR__) . '/tests/bootstrap.php';
date_default_timezone_set('Asia/Almaty');

use App\Services\TryOn\Providers\FakeTryOnProvider;
use App\Services\TryOn\Providers\GenlookProvider;
use App\Services\TryOn\Providers\ProviderException;
use App\Services\TryOn\Providers\ProviderHttp;
use App\Services\TryOn\Providers\ProviderJobState;
use App\Services\TryOn\Providers\TryOnProviderInterface;
use App\Services\TryOn\Providers\TryOnProviderRouter;
use App\Services\TryOn\Providers\TryOnSubmission;
use App\Services\TryOn\TryOnException;
use App\Services\TryOn\TryOnImageValidator;
use App\Services\TryOn\TryOnJobRunner;
use App\Services\TryOn\TryOnJobStatus;
use App\Services\TryOn\TryOnResultFetcher;
use App\Services\TryOn\TryOnService;
use App\Services\TryOn\TryOnSettings;
use App\Services\TryOn\TryOnStorage;

$pass = 0;
$fail = 0;
function check(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK   {$msg}\n";
    } else {
        $fail++;
        echo "  FAIL {$msg}\n";
    }
}

/** @return string|null код TryOnException или null */
function code_of(callable $fn): ?string
{
    try {
        $fn();
    } catch (TryOnException $e) {
        return $e->errorCode;
    }

    return null;
}

/** JPEG w×h: градиент + цветной прямоугольник (разные seed → разные изображения). */
function make_jpeg(int $w, int $h, int $seed = 1): string
{
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 4) {
        $c = imagecolorallocate($img, ($y * 3 + $seed * 40) % 256, ($y + $seed * 90) % 256, ((255 - $y + $seed * 10) % 256 + 256) % 256);
        imagefilledrectangle($img, 0, $y, $w, $y + 3, $c);
    }
    imagefilledrectangle($img, (int) ($w * 0.3), (int) ($h * 0.3), (int) ($w * 0.7), (int) ($h * 0.7), imagecolorallocate($img, ($seed * 70) % 256, 30, 200));
    ob_start();
    imagejpeg($img, null, 92);
    imagedestroy($img);

    return (string) ob_get_clean();
}

function tmp_file(string $bytes, string $ext = 'jpg'): string
{
    $p = sys_get_temp_dir() . '/tryon_t_' . bin2hex(random_bytes(6)) . '.' . $ext;
    file_put_contents($p, $bytes);

    return $p;
}

$limits = ['max_bytes' => 10485760, 'min_side_px' => 512, 'max_side_px' => 6000, 'max_pixels' => 36000000, 'store_max_side_px' => 2048, 'jpeg_quality' => 90];
$v = new TryOnImageValidator($limits);

echo "== Unit: статусы и типы ==\n";
check(TryOnJobStatus::canTransition('queued', 'processing'), 'queued → processing');
check(!TryOnJobStatus::canTransition('succeeded', 'processing'), 'succeeded ↛ processing');
check(!TryOnJobStatus::canTransition('cancelled', 'expired'), 'cancelled — финальный');
check(TryOnJobStatus::canTransitionAny(['queued', 'processing'], 'cancelled'), 'queued|processing → cancelled');
check(\App\Services\TryOn\TryOnTypes::parseList('upper, bogus,dress,upper') === ['upper', 'dress'], 'parseList фильтрует и убирает дубли');

echo "== Unit: валидация изображений ==\n";
$jpeg = make_jpeg(800, 1000);
// Вставляем APP1/EXIF с «GPS»-маркером сразу после SOI.
$exifPayload = "Exif\0\0GPSLatitude-TEST-MARKER";
$withExif = "\xFF\xD8\xFF\xE1" . pack('n', strlen($exifPayload) + 2) . $exifPayload . substr($jpeg, 2);
$p = tmp_file($withExif);
$r = $v->processUpload($p, filesize($p));
check($r['width'] === 800 && $r['height'] === 1000, 'JPEG принят, размеры сохранены');
check(!str_contains($r['bytes'], 'GPSLatitude-TEST-MARKER') && !str_contains($r['bytes'], "Exif\0\0"), 'EXIF/GPS удалён перекодированием');
check(strlen($r['sha256']) === 64, 'sha256 вычислен');
@unlink($p);

$big = make_jpeg(3000, 4000);
$p = tmp_file($big);
$r = $v->processUpload($p, filesize($p));
check(max($r['width'], $r['height']) === 2048, 'ресайз до 2048 по длинной стороне');
@unlink($p);

$img = imagecreatetruecolor(600, 700);
ob_start(); imagepng($img); $png = (string) ob_get_clean();
$p = tmp_file($png, 'png');
check(code_of(fn () => $v->processUpload($p, filesize($p))) === null, 'PNG принят');
@unlink($p);

ob_start(); imagegif($img); $gif = (string) ob_get_clean();
$p = tmp_file($gif, 'gif');
check(code_of(fn () => $v->processUpload($p, filesize($p))) === 'unsupported_format', 'GIF → unsupported_format');
@unlink($p);

$p = tmp_file("<?php echo 'x'; ?>\nnot an image", 'jpg');
check(code_of(fn () => $v->processUpload($p, filesize($p))) === 'unsupported_format', 'текст с расширением .jpg → unsupported_format');
@unlink($p);

$p = tmp_file(make_jpeg(300, 400));
check(code_of(fn () => $v->processUpload($p, filesize($p))) === 'image_too_small', '300×400 → image_too_small');
@unlink($p);

// «Pixel bomb»: валидный заголовок PNG 50000×50000 без данных — отклоняется до декодирования.
$ihdr = pack('NN', 50000, 50000) . "\x08\x02\x00\x00\x00";
$bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
$p = tmp_file($bomb, 'png');
check(code_of(fn () => $v->processUpload($p, filesize($p))) === 'image_too_large_dimensions', 'pixel bomb 50000×50000 → image_too_large_dimensions');
@unlink($p);

$small = new TryOnImageValidator(['max_bytes' => 1000] + $limits);
$p = tmp_file(make_jpeg(800, 1000));
check(code_of(fn () => $small->processUpload($p, filesize($p))) === 'file_too_large', 'превышение max_bytes → file_too_large');
@unlink($p);

echo "== Unit: эвристика «результат = исходное фото» ==\n";
$person = $v->processBytes(make_jpeg(800, 1000, 1))['bytes'];
$recompressed = $v->processBytes((function (string $b): string {
    $i = imagecreatefromstring($b);
    $c = imagecreatetruecolor(640, 800);
    imagecopyresampled($c, $i, 0, 0, 0, 0, 640, 800, 800, 1000);
    ob_start(); imagejpeg($c, null, 75);
    return (string) ob_get_clean();
})($person))['bytes'];
check(TryOnImageValidator::looksUnchanged($person, $recompressed), 'пережатое/уменьшенное исходное фото распознано как «без изменений»');
$changed = FakeTryOnProvider::stamp($person);
$garment = (function (string $b): string {
    $i = imagecreatefromstring($b);
    imagefilledrectangle($i, 200, 250, 600, 750, imagecolorallocate($i, 250, 240, 10));
    ob_start(); imagejpeg($i, null, 90);
    return (string) ob_get_clean();
})($person);
check(!TryOnImageValidator::looksUnchanged($person, $garment), 'заметное изменение (одежда) — не «без изменений»');
check(!TryOnImageValidator::looksUnchanged($person, $changed), 'тестовая плашка — не «без изменений»');

echo "== Unit: Genlook-адаптер (фиктивный транспорт) ==\n";
$captured = [];
$responses = [];
$transport = function (string $method, string $url, mixed $body, array $headers) use (&$captured, &$responses): array {
    $captured[] = compact('method', 'url', 'body', 'headers');
    return array_shift($responses) ?? [500, ''];
};
$gcfg = ['api_key' => 'gk_test_123', 'base_url' => 'https://api.genlook.app/tryon/v1', 'mock' => false, 'cost_micros' => 40000, 'keep_for_days' => 1, 'types' => ['upper', 'lower', 'dress'], 'result_hosts' => ['genlook.app']];
$g = new GenlookProvider($gcfg, new ProviderHttp($transport));
$personPath = tmp_file($person);
$garmentPath = tmp_file(make_jpeg(600, 800, 5));
$sub = new TryOnSubmission('PUB123', 'upper', $personPath, $garmentPath, 'Футболка тест', 'zkabc');
$responses[] = [201, json_encode(['generationId' => 'gen_1', 'status' => 'PENDING'])];
$st = $g->submit($sub);
$req = $captured[0];
$data = json_decode((string) ($req['body']['data'] ?? ''), true);
check($req['method'] === 'POST' && $req['url'] === 'https://api.genlook.app/tryon/v1/try-on', 'submit → POST /try-on');
check(in_array('x-api-key: gk_test_123', $req['headers'], true), 'заголовок x-api-key');
check(($req['body']['person'] ?? null) instanceof \CURLFile && ($req['body']['garment'] ?? null) instanceof \CURLFile, 'фото передаются файлами (multipart), не URL');
check(($data['person']['image']['source']['fileKey'] ?? '') === 'person' && ($data['products'][0]['images'][0]['source']['fileKey'] ?? '') === 'garment', 'fileKey person/garment');
check(($data['output']['keepForDays'] ?? null) === 1 && ($data['mock'] ?? null) === false && ($data['externalUserId'] ?? '') === 'zkabc', 'keepForDays=1, mock=false, externalUserId');
check($st->state === ProviderJobState::PENDING && $st->externalId === 'gen_1', 'ответ 201 → pending gen_1');
check(!str_contains(json_encode($data, JSON_UNESCAPED_UNICODE), (string) 42), 'в теле нет внутренних ID пользователя');

$responses[] = [200, json_encode(['generationId' => 'gen_1', 'status' => 'PROCESSING'])];
check($g->status('gen_1')->state === ProviderJobState::PROCESSING, 'PROCESSING → processing');
$responses[] = [200, json_encode(['generationId' => 'gen_1', 'status' => 'COMPLETED', 'resultImageUrl' => 'https://cdn.genlook.app/r.jpg'])];
$s2 = $g->status('gen_1');
check($s2->state === ProviderJobState::COMPLETED && $s2->resultUrl === 'https://cdn.genlook.app/r.jpg' && $s2->costMicros === 40000, 'COMPLETED → completed + url + стоимость');
$responses[] = [200, json_encode(['generationId' => 'gen_1', 'status' => 'FAILED', 'errorCode' => 'GENERATION_TIMEOUT'])];
$s3 = $g->status('gen_1');
check($s3->state === ProviderJobState::FAILED && $s3->errorCode === 'provider_timeout' && $s3->costMicros === 0, 'FAILED/GENERATION_TIMEOUT → provider_timeout, без списания');

foreach ([[402, 'INSUFFICIENT_CREDITS', 'provider_out_of_credits', false], [429, 'RATE_LIMITED', 'provider_busy', true], [400, 'VALIDATION_FAILED', 'provider_rejected_image', false], [503, '', 'provider_error', true], [0, '', 'provider_error', true], [401, '', 'provider_auth_failed', false]] as [$http, $code, $want, $retry]) {
    $responses[] = [$http, json_encode(['code' => $code, 'message' => 'secret text with PII'])];
    try {
        $g->submit($sub);
        check(false, "HTTP {$http} должен бросить исключение");
    } catch (ProviderException $e) {
        check($e->errorCode === $want && $e->retryable === $retry && !str_contains($e->getMessage(), 'PII'), "HTTP {$http} {$code} → {$want}, retryable=" . var_export($retry, true));
    }
}
$responses[] = [204, ''];
check($g->purgeUser('zkabc') && end($captured)['method'] === 'DELETE' && str_ends_with(end($captured)['url'], '/customers/zkabc'), 'purgeUser → DELETE /customers/{externalUserId}');
check(!$g->supports('shoes') && $g->supports('dress'), 'типы MVP: upper/lower/dress');
check((new GenlookProvider(['api_key' => ''] + $gcfg))->isConfigured() === false, 'без ключа — не настроен');
$responses[] = [201, json_encode(['generationId' => 'gen_m', 'status' => 'PENDING'])];
$gm = new GenlookProvider(['mock' => true] + $gcfg, new ProviderHttp($transport));
$gm->submit($sub);
$mockData = json_decode((string) (end($captured)['body']['data'] ?? ''), true);
check(($mockData['mock'] ?? null) === true, 'mock-режим: в запрос уходит mock=true (кредиты не тратятся)');
check((new GenlookProvider(['mock' => true] + $gcfg))->isTest() && (new GenlookProvider(['mock' => true] + $gcfg))->estimateCostMicros('upper') === 0, 'mock-режим: is_test, стоимость 0');

echo "== Unit: защита от SSRF при скачивании результата ==\n";
$okTransport = fn () => [200, $person];
$pubResolver = fn (string $h) => ['34.120.1.1'];
$f = new TryOnResultFetcher(15728640, 5, $pubResolver, $okTransport);
check($f->fetch('https://cdn.genlook.app/r.jpg', ['genlook.app']) === $person, 'https + разрешённый хост + публичный IP → OK');
check(code_of(fn () => $f->fetch('http://cdn.genlook.app/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'http → отказ');
check(code_of(fn () => $f->fetch('https://evil.example/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'чужой хост → отказ');
check(code_of(fn () => $f->fetch('https://genlook.app.evil.example/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'суффикс-подделка хоста → отказ');
check(code_of(fn () => $f->fetch('https://cdn.genlook.app:8443/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'нестандартный порт → отказ');
check(code_of(fn () => $f->fetch('https://user:pw@cdn.genlook.app/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'userinfo в URL → отказ');
foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '192.168.1.1', '::1'] as $ip) {
    $fp = new TryOnResultFetcher(15728640, 5, fn () => [$ip], $okTransport);
    check(code_of(fn () => $fp->fetch('https://cdn.genlook.app/r.jpg', ['genlook.app'])) === 'result_fetch_failed', "DNS → {$ip} → отказ");
}
$fr = new TryOnResultFetcher(15728640, 5, $pubResolver, fn () => [302, '']);
check(code_of(fn () => $fr->fetch('https://cdn.genlook.app/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'редирект не следуется → отказ');
$fb = new TryOnResultFetcher(100, 5, $pubResolver, $okTransport);
check(code_of(fn () => $fb->fetch('https://cdn.genlook.app/r.jpg', ['genlook.app'])) === 'result_fetch_failed', 'превышение размера → отказ');
check(code_of(fn () => $v->processBytes('<html>not image</html>')) === 'result_fetch_failed', 'не изображение → отказ');

echo "== Unit: настройки ==\n";
$cfgBase = require dirname(__DIR__) . '/config/tryon.php';
$cfgT = array_replace_recursive($cfgBase, ['user_hash_secret' => 'unit-secret', 'allow_fake' => false]);
$s = new TryOnSettings(['tryon_enabled' => '0', 'tryon_beta_users' => '5, 7'], $cfgT);
check(!$s->isEnabledFor(1) && $s->isEnabledFor(7) && !$s->isEnabledFor(null), 'выключено: доступ только бета-пользователям');
check(!in_array('fake', $s->providersOrder(), true) && !$s->isProviderEnabled('fake'), 'Fake недоступен без TRYON_ALLOW_FAKE');
$eu = $s->externalUserId(42);
check($eu === $s->externalUserId(42) && $eu !== $s->externalUserId(43) && !str_contains($eu, '42') && strlen($eu) === 32, 'externalUserId: стабильный HMAC без исходного ID');
check(code_of(fn () => null) === null && (function () use ($cfgBase) {
    try { (new TryOnSettings([], ['user_hash_secret' => ''] + $cfgBase))->externalUserId(1); return false; } catch (\RuntimeException) { return true; }
})(), 'без TRYON_USER_HASH_SECRET — явная ошибка конфигурации');
@unlink($personPath);
@unlink($garmentPath);

echo "== Unit: тип примерки в объявлении ==\n";
$nl = [\App\Services\TryOn\TryOnTypes::class, 'normalizeForListing'];
$clothes = 'Одежда, обувь и аксессуары / Женская одежда и обувь';
check($nl('upper', 'new', $clothes, null) === 'upper', 'одежда + upper → upper');
check($nl('bogus', 'used', $clothes, 'upper') === null, 'неизвестный тип → NULL');
check($nl('', 'new', $clothes, 'upper') === null, '«Не поддерживается» → NULL');
check($nl(null, 'new', $clothes, 'dress') === 'dress', 'поле не пришло (скрыто) → прежнее значение');
check($nl('upper', 'new', 'Электроника и бытовая техника / ТВ и видео', 'upper') === null, 'не одежда → NULL даже если прислали');
check($nl('upper', 'auction', $clothes, null) === null, 'аукцион → NULL');
check($nl('lower', 'used', 'Детские товары / Детская одежда и обувь', null) === 'lower', 'детские товары → допускается');

echo "== Unit: Router DELETE и CSRF ==\n";
$routerCode = <<<'PHPC'
require 'tests/bootstrap.php';
$GLOBALS['appConfig'] = ['url' => '', 'locale' => 'ru', 'locales' => ['ru','kk']];
class TryOnRouterProbe { public function del(string $id): void { echo "HANDLER:$id"; } }
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$r = new App\Core\Router();
$r->delete('/api/v1/try-on/jobs/{id}', [TryOnRouterProbe::class, 'del']);
if (($argv[1] ?? '') === 'valid') { $_SERVER['HTTP_X_CSRF_TOKEN'] = App\Core\Csrf::token(); }
$r->dispatch('DELETE', '/api/v1/try-on/jobs/abc');
PHPC;
$probe = sys_get_temp_dir() . '/tryon_router_probe_' . getmypid() . '.php';
file_put_contents($probe, "<?php\nchdir(" . var_export(dirname(__DIR__), true) . ");\n" . $routerCode);
$noToken = (string) shell_exec('php ' . escapeshellarg($probe) . ' none 2>&1');
$withToken = (string) shell_exec('php ' . escapeshellarg($probe) . ' valid 2>&1');
@unlink($probe);
check(!str_contains($noToken, 'HANDLER') && str_contains($noToken, '"ok":false'), 'DELETE без CSRF-токена → отказ (419), обработчик не вызван');
check(str_contains($withToken, 'HANDLER:abc'), 'DELETE с CSRF-токеном → обработчик вызван');

if (in_array('--unit', $argv, true)) {
    echo "\nSummary: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "== Интеграция (локальная БД, Fake-провайдер, без сети) ==\n";
try {
    $db = \App\Core\Database::connect();
} catch (\Throwable $e) {
    echo "  БД недоступна — интеграция пропущена\n";
    echo "\nSummary: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

$tag = 'zkto' . bin2hex(random_bytes(3));
$tmpRoot = sys_get_temp_dir() . '/' . $tag;
$productsDir = $tmpRoot . '/products';
@mkdir($productsDir, 0700, true);
$cfgI = array_replace_recursive($cfgBase, [
    'storage_dir' => $tmpRoot . '/storage',
    'user_hash_secret' => 'int-secret',
    'allow_fake' => true,
]);
$userIds = [];
$productIds = [];
$now = strtotime('2026-11-09 12:00:00');
$clock = function () use (&$now): int { return $now; };

try {
    $users = new \App\Models\User();
    $mkUser = function (string $n) use ($users, $tag, &$userIds): int {
        $id = $users->create(['name' => $n . ' Test', 'email' => "{$tag}_{$n}@example.invalid", 'password' => bin2hex(random_bytes(8)), 'login' => $tag . $n]);
        $userIds[] = (int) $id;
        return (int) $id;
    };
    $u1 = $mkUser('alice');
    $u2 = $mkUser('bob');

    $mkProduct = function (?string $tryonType, string $status = 'active') use ($db, $u2, $productsDir, &$productIds): int {
        $file = 'p_tryon_' . bin2hex(random_bytes(5)) . '.jpg';
        file_put_contents($productsDir . '/' . $file, make_jpeg(600, 800, 9));
        $db->prepare("INSERT INTO products (user_id, type, category, title, description, price, image, images, status, tryon_type)
                      VALUES (?, 'new', 'Одежда, обувь и аксессуары / Женская одежда и обувь', 'Тестовая футболка', 'desc', 1000, ?, ?, ?, ?)")
            ->execute([$u2, $file, json_encode([$file]), $status, $tryonType]);
        $id = (int) $db->lastInsertId();
        $productIds[] = $id;
        return $id;
    };
    (new \App\Models\TryOnJob())->ensureSchema();
    $pUpper = $mkProduct('upper');
    $pNone = $mkProduct(null);
    $pShoes = $mkProduct('shoes');
    $pArchived = $mkProduct('upper', 'archived');

    $baseSettings = [
        'tryon_enabled' => '1',
        'tryon_providers_order' => 'fake',
        'tryon_provider_fake_enabled' => '1',
        'tryon_user_daily_limit' => '100',
        'tryon_user_monthly_limit' => '100',
        'tryon_user_max_active' => '1',
        'tryon_max_concurrent' => '5',
        'tryon_monthly_budget_usd' => '50',
        'tryon_retention_hours' => '24',
        'tryon_consent_version' => 'v-test',
    ];
    $fakeStateDir = $tmpRoot . '/fake';
    $build = function (array $over = [], ?array $providers = null) use ($baseSettings, $cfgI, $clock, $productsDir, $fakeStateDir): array {
        $settings = new TryOnSettings(array_merge($baseSettings, $over), $cfgI);
        $router = new TryOnProviderRouter($settings, $providers ?? ['fake' => new FakeTryOnProvider('success', ['upper', 'lower', 'dress'], $fakeStateDir)]);
        $storage = new TryOnStorage($cfgI['storage_dir']);
        $svc = new TryOnService($settings, $router, $storage, $clock, null, $productsDir);
        $runner = new TryOnJobRunner($settings, $router, $storage, new TryOnResultFetcher(15728640, 5, fn () => ['34.1.1.1'], fn () => [500, '']), $clock, null, $productsDir);
        return [$svc, $runner, $storage];
    };
    $photo = function (int $seed) use (&$tmpFiles): string {
        $p = tmp_file(make_jpeg(800, 1000, $seed));
        $tmpFiles[] = $p;
        return $p;
    };
    $tmpFiles = [];
    $create = function (TryOnService $svc, int $user, int $product, string $key, string $photoPath, bool $consent = true, string $ver = 'v-test', int $idx = 0): array {
        return $svc->create($user, $product, $idx, $consent, $ver, $key, $photoPath, filesize($photoPath));
    };
    $jobRow = fn (string $pub) => (new \App\Models\TryOnJob())->findByPublicId($pub);

    [$svc, $runner, $storage] = $build();

    // --- проверки до платных действий
    $ph1 = $photo(1);
    check(code_of(fn () => $create($svc, $u1, $pUpper, 'key-consent-1', $ph1, false)) === 'consent_required', 'без согласия → consent_required');
    check(code_of(fn () => $create($svc, $u1, $pUpper, 'key-consent-2', $ph1, true, 'old')) === 'consent_outdated', 'старая версия согласия → consent_outdated');
    check(code_of(fn () => $create($svc, $u1, $pNone, 'key-none-0001', $ph1)) === 'product_not_supported', 'товар без tryon_type → product_not_supported');
    check(code_of(fn () => $create($svc, $u1, $pShoes, 'key-shoes-001', $ph1)) === 'product_not_supported', 'тип shoes выключен → product_not_supported');
    check(code_of(fn () => $create($svc, $u1, $pArchived, 'key-arch-0001', $ph1)) === 'not_found', 'архивный товар → not_found');
    check(code_of(fn () => $create($svc, $u1, $pUpper, 'bad key!', $ph1)) === 'idempotency_key_invalid', 'неверный Idempotency-Key → 422');
    check(code_of(fn () => $create($svc, $u1, $pUpper, 'key-idx-00001', $ph1, true, 'v-test', 5)) === 'garment_image_missing', 'несуществующий индекс фото товара → garment_image_missing');
    [$svcOff] = $build(['tryon_enabled' => '0']);
    check(code_of(fn () => $create($svcOff, $u1, $pUpper, 'key-off-00001', $ph1)) === 'module_disabled', 'модуль выключен → module_disabled');
    [$svcBeta] = $build(['tryon_enabled' => '0', 'tryon_beta_users' => (string) $u1]);
    check($svcBeta->capabilities($u1, $pUpper)['product']['supported'] === true && $svcBeta->capabilities($u2, $pUpper)['product']['supported'] === false, 'бета-доступ: только указанным пользователям');
    check((int) $db->query("SELECT COUNT(*) FROM tryon_jobs WHERE user_id = {$u1}")->fetchColumn() === 0, 'отказы не создают заданий');

    // --- создание и идемпотентность
    $r1 = $create($svc, $u1, $pUpper, 'key-main-0001', $ph1);
    $job1 = $r1['job'];
    check($job1['status'] === 'queued' && !$r1['idempotent'] && (int) $job1['is_test'] === 1, 'создано задание queued, is_test=1 сразу (Fake)');
    $asset = (new \App\Models\TryOnAsset())->findForJob((int) $job1['id'], 'person');
    check($asset && is_file($cfgI['storage_dir'] . '/' . $asset['storage_path']) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.jpg$#', (string) $asset['storage_path']) === 1, 'фото сохранено в storage, путь YYYY/MM/<random>.jpg без user_id');
    check(is_file($cfgI['storage_dir'] . '/.htaccess'), 'storage/tryon/.htaccess создан');
    $r1b = $create($svc, $u1, $pUpper, 'key-main-0001', $ph1);
    check($r1b['idempotent'] && $r1b['job']['id'] === $job1['id'], 'тот же Idempotency-Key → то же задание');
    check(code_of(fn () => $create($svc, $u1, $pUpper, 'key-main-0001', $ph1, true, 'v-test', 1)) === 'idempotency_conflict', 'тот же ключ, другие параметры → idempotency_conflict');
    check(code_of(fn () => $create($svc, $u1, $pUpper, 'key-second-01', $photo(2))) === 'active_job_exists', 'второе активное задание → active_job_exists');

    // --- доступ
    check(code_of(fn () => $svc->getOwned($u2, $job1['public_id'])) === 'not_found', 'чужое задание → not_found');
    check(code_of(fn () => $svc->getOwned($u1, 'zzzzzzzzzzzzzzzzzzzzzzzzzz')) === 'not_found', 'несуществующее → not_found');
    check(code_of(fn () => $svc->imageFor($u1, $job1['public_id'], 'result')) === 'not_ready', 'результат до готовности → not_ready');
    check(strlen($svc->imageFor($u1, $job1['public_id'], 'person')) > 1000, 'владелец получает своё фото');

    // --- воркер: queued → processing → succeeded
    $st = $runner->runCycle('t1');
    check($st['submitted'] === 1 && $jobRow($job1['public_id'])['status'] === 'processing', 'цикл 1: отправлено провайдеру → processing');
    check((int) $jobRow($job1['public_id'])['is_test'] === 1, 'Fake → is_test = 1');
    $runner->runCycle('t1');
    check($jobRow($job1['public_id'])['status'] === 'processing', 'до next_poll_at опрос не выполняется');
    $now += 5;
    $runner->runCycle('t1');
    $j = $jobRow($job1['public_id']);
    check($j['status'] === 'succeeded' && $j['finished_at'] !== null && $j['expires_at'] !== null, 'цикл: completed → succeeded, срок хранения установлен');
    $res = $svc->imageFor($u1, $job1['public_id'], 'result');
    check(strlen($res) > 1000 && !TryOnImageValidator::looksUnchanged($svc->imageFor($u1, $job1['public_id'], 'person'), $res), 'результат доступен владельцу, помечен плашкой');
    check(TryOnService::present($j)['is_test'] === true && TryOnService::present($j)['result_available'] === true, 'API-представление: is_test=true, result_available=true');
    check(code_of(fn () => $svc->imageFor($u2, $job1['public_id'], 'result')) === 'not_found', 'чужой результат → not_found');

    // --- повторная оплата: то же фото и товар → reuse
    $rr = $create($svc, $u1, $pUpper, 'key-reuse-001', $ph1);
    check($rr['reused'] && $rr['job']['id'] === $job1['id'], 'то же фото+товар → существующий результат, без новой генерации');

    // --- лимиты
    [$svcLim] = $build(['tryon_user_daily_limit' => '1']);
    check(code_of(fn () => $create($svcLim, $u1, $pUpper, 'key-limit-001', $photo(3))) === 'daily_limit', 'дневной лимит → daily_limit');
    $caps = $svc->capabilities($u1, $pUpper);
    check($caps['limits']['daily_left'] === 99 && $caps['product']['supported'] && count($caps['product']['garment_images']) === 1, 'capabilities: остаток лимита и фото товара');

    // --- удаление succeeded
    $resultAsset = (new \App\Models\TryOnAsset())->findForJob((int) $job1['id'], 'result');
    $svc->delete($u1, $job1['public_id']);
    $j = $jobRow($job1['public_id']);
    check($j['status'] === 'expired' && $j['deleted_at'] !== null, 'удаление: succeeded → expired, deleted_at');
    check(!is_file($cfgI['storage_dir'] . '/' . $resultAsset['storage_path']) && !is_file($cfgI['storage_dir'] . '/' . $asset['storage_path']), 'удаление: файлы стёрты');
    check(code_of(fn () => $svc->imageFor($u1, $job1['public_id'], 'result')) === 'gone', 'после удаления → gone');
    $svc->delete($u1, $job1['public_id']);
    check(true, 'повторное удаление идемпотентно');
    check(code_of(fn () => $svc->delete($u2, $job1['public_id'])) === 'not_found', 'чужое удаление → not_found');

    // --- ошибка генерации не списывает лимит
    $fakeFail = new FakeTryOnProvider('fail', ['upper', 'lower', 'dress'], $fakeStateDir);
    [$svcF, $runF] = $build([], ['fake' => $fakeFail]);
    $before = $svcF->capabilities($u1, null)['limits']['daily_left'];
    $rf = $create($svcF, $u1, $pUpper, 'key-fail-0001', $photo(4));
    $runF->runCycle('t2'); $now += 5; $runF->runCycle('t2');
    $jf = $jobRow($rf['job']['public_id']);
    check($jf['status'] === 'failed' && $jf['error_code'] === 'generation_failed' && (int) $jf['cost_actual_micros'] === 0, 'FAILED → failed/generation_failed, стоимость 0');
    check($svcF->capabilities($u1, null)['limits']['daily_left'] === $before, 'неудачная генерация не списывает лимит');

    // --- retryable ошибка отправки
    [$svcR, $runR] = $build([], ['fake' => new FakeTryOnProvider('retryable:1', ['upper', 'lower', 'dress'], $fakeStateDir)]);
    $rRetry = $create($svcR, $u1, $pUpper, 'key-retry-001', $photo(5));
    $runR->runCycle('t3');
    $jr = $jobRow($rRetry['job']['public_id']);
    check($jr['status'] === 'queued' && (int) $jr['attempts'] === 1 && $jr['next_poll_at'] !== null, 'retryable ошибка: остаётся queued, attempts=1, отложено');
    $now += 6;
    $runR->runCycle('t3');
    check($jobRow($rRetry['job']['public_id'])['status'] === 'processing', 'повторная отправка после паузы → processing');
    $svcR->delete($u1, $rRetry['job']['public_id']);
    check($jobRow($rRetry['job']['public_id'])['status'] === 'cancelled', 'удаление processing → cancelled');

    // --- fallback: основной не принял задание (нет кредитов) → резервный
    $broke = new class implements TryOnProviderInterface {
        public int $calls = 0;
        public function id(): string { return 'broke'; }
        public function isTest(): bool { return false; }
        public function isConfigured(): bool { return true; }
        public function supports(string $t): bool { return true; }
        public function estimateCostMicros(string $t): int { return 40000; }
        public function submit(TryOnSubmission $s): ProviderJobState { $this->calls++; throw new ProviderException('provider_out_of_credits', false, 402); }
        public function status(string $id): ProviderJobState { throw new \LogicException('not expected'); }
        public function purgeUser(string $e): bool { return true; }
        public function cancel(string $id): bool { return false; }
    };
    [$svcFb, $runFb] = $build(['tryon_providers_order' => 'broke,fake', 'tryon_provider_broke_enabled' => '1'], ['broke' => $broke, 'fake' => new FakeTryOnProvider('success', ['upper', 'lower', 'dress'], $fakeStateDir)]);
    $rFb = $create($svcFb, $u1, $pUpper, 'key-fallbk-01', $photo(6));
    $runFb->runCycle('t4');
    $jfb = $jobRow($rFb['job']['public_id']);
    check($broke->calls === 1 && $jfb['status'] === 'processing' && $jfb['provider'] === 'fake', 'основной отказал до принятия → задание у резервного');
    $svcFb->delete($u1, $rFb['job']['public_id']);

    // --- таймаут processing
    [$svcT, $runT] = $build([], ['fake' => new FakeTryOnProvider('never', ['upper', 'lower', 'dress'], $fakeStateDir)]);
    $rT = $create($svcT, $u1, $pUpper, 'key-timeout-1', $photo(7));
    $runT->runCycle('t5');
    $now += 301;
    $runT->runCycle('t5');
    check($jobRow($rT['job']['public_id'])['error_code'] === 'provider_timeout', 'processing > 5 мин → failed/provider_timeout');

    // --- результат без изменений от «настоящего» провайдера → не успех
    $same = new class implements TryOnProviderInterface {
        private array $paths = [];
        public function id(): string { return 'same'; }
        public function isTest(): bool { return false; }
        public function isConfigured(): bool { return true; }
        public function supports(string $t): bool { return true; }
        public function estimateCostMicros(string $t): int { return 40000; }
        public function submit(TryOnSubmission $s): ProviderJobState { $this->paths['x1'] = $s->personJpegPath; return new ProviderJobState('pending', 'x1'); }
        public function status(string $id): ProviderJobState { return new ProviderJobState('completed', $id, null, null, 40000, (string) file_get_contents($this->paths[$id])); }
        public function purgeUser(string $e): bool { return true; }
        public function cancel(string $id): bool { return false; }
    };
    [$svcS, $runS] = $build(['tryon_providers_order' => 'same', 'tryon_provider_same_enabled' => '1'], ['same' => $same]);
    $rS = $create($svcS, $u1, $pUpper, 'key-same-0001', $photo(8));
    $runS->runCycle('t6'); $now += 5; $runS->runCycle('t6');
    $js = $jobRow($rS['job']['public_id']);
    check($js['status'] === 'failed' && $js['error_code'] === 'product_not_supported' && (int) $js['cost_actual_micros'] === 0, 'провайдер вернул исходное фото → failed/product_not_supported, не «успех»');

    // --- бюджет (платный тестовый провайдер, $0.04 за генерацию, бюджет $0.05)
    $db->exec("UPDATE tryon_jobs SET created_at = created_at - INTERVAL 1 YEAR WHERE user_id = {$u1}"); // изолируем месяц
    $paid = new class implements TryOnProviderInterface {
        public function id(): string { return 'paid'; }
        public function isTest(): bool { return false; }
        public function isConfigured(): bool { return true; }
        public function supports(string $t): bool { return true; }
        public function estimateCostMicros(string $t): int { return 40000; }
        public function submit(TryOnSubmission $s): ProviderJobState { return new ProviderJobState('pending', 'p'); }
        public function status(string $id): ProviderJobState { return new ProviderJobState('processing', $id); }
        public function purgeUser(string $e): bool { return true; }
        public function cancel(string $id): bool { return false; }
    };
    $spentOther = (new \App\Models\TryOnJob())->sumSpendSince(date('Y-m-01 00:00:00', $now));
    $budgetUsd = (string) (($spentOther + 50000) / 1_000_000);
    [$svcB] = $build(['tryon_providers_order' => 'paid', 'tryon_provider_paid_enabled' => '1', 'tryon_monthly_budget_usd' => $budgetUsd, 'tryon_user_max_active' => '5'], ['paid' => $paid]);
    $b1 = $create($svcB, $u1, $pUpper, 'key-budget-01', $photo(10));
    check($b1['job']['status'] === 'queued' && (int) $b1['job']['cost_estimate_micros'] === 40000, 'в пределах бюджета: задание создано с резервом $0.04');
    check(code_of(fn () => $create($svcB, $u1, $pUpper, 'key-budget-02', $photo(11))) === 'budget_exhausted', 'резерв превышает месячный бюджет → budget_exhausted');
    $svcB->delete($u1, $b1['job']['public_id']);

    // --- очистка по сроку хранения
    [$svcC, $runC] = $build();
    $rc = $create($svcC, $u1, $pUpper, 'key-clean-001', $photo(12));
    $runC->runCycle('t7'); $now += 5; $runC->runCycle('t7');
    $resC = (new \App\Models\TryOnAsset())->findForJob((int) $rc['job']['id'], 'result');
    $now += 25 * 3600;
    $stC = $runC->runCycle('t7', true);
    $jc = $jobRow($rc['job']['public_id']);
    check($jc['status'] === 'expired' && $stC['cleaned'] >= 1 && !is_file($cfgI['storage_dir'] . '/' . $resC['storage_path']), 'через 24 ч: expired, файлы удалены');

    // --- products.tryon_type сохраняется моделью
    (new \App\Models\Product())->setTryOnType($pNone, 'dress');
    check((string) $db->query("SELECT tryon_type FROM products WHERE id = {$pNone}")->fetchColumn() === 'dress', 'Product::setTryOnType сохраняет тип');
    (new \App\Models\Product())->setTryOnType($pNone, null);

    // --- полное удаление данных пользователя
    $rp = $create($svcC, $u1, $pUpper, 'key-purge-001', $photo(13));
    $pAsset = (new \App\Models\TryOnAsset())->findForJob((int) $rp['job']['id'], 'person');
    $svcC->purgeUser($u1);
    check($jobRow($rp['job']['public_id'])['status'] === 'cancelled' && !is_file($cfgI['storage_dir'] . '/' . $pAsset['storage_path']), 'purgeUser: задания отменены, файлы удалены');
    $left = (int) $db->query("SELECT COUNT(*) FROM tryon_assets WHERE owner_user_id = {$u1} AND storage_path IS NOT NULL")->fetchColumn();
    check($left === 0, 'purgeUser: не осталось ссылок на файлы');

    // --- удаление аккаунта удаляет файлы примерки (хук User::deleteAccount → TryOnService::purgeUser)
    $u3 = $mkUser('carol');
    $defaultStorage = $cfgBase['storage_dir']; // каталог, который использует хук с настройками по умолчанию
    $cfgDefault = array_replace_recursive($cfgI, ['storage_dir' => $defaultStorage]);
    $setA = new TryOnSettings($baseSettings, $cfgDefault);
    $svcA = new TryOnService($setA, new TryOnProviderRouter($setA, ['fake' => new FakeTryOnProvider('success', ['upper'], $fakeStateDir)]), new TryOnStorage($defaultStorage), $clock, null, $productsDir);
    $ra = $create($svcA, $u3, $pUpper, 'key-account-1', $photo(14));
    $aAsset = (new \App\Models\TryOnAsset())->findForJob((int) $ra['job']['id'], 'person');
    $aFile = $defaultStorage . '/' . $aAsset['storage_path'];
    $existedBefore = is_file($aFile);
    (new \App\Models\User())->deleteAccount($u3);
    $userIds = array_values(array_diff($userIds, [$u3]));
    check($existedBefore && !is_file($aFile) && (int) $db->query("SELECT COUNT(*) FROM tryon_jobs WHERE user_id = {$u3}")->fetchColumn() === 0, 'удаление аккаунта: хук стёр файл в storage/tryon, задания удалены каскадом');
} catch (\Throwable $e) {
    check(false, 'исключение: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        $db->exec("DELETE FROM tryon_jobs WHERE user_id IN ({$in})");
        if ($productIds !== []) {
            $db->exec('DELETE FROM products WHERE id IN (' . implode(',', array_map('intval', $productIds)) . ')');
        }
        $db->exec("DELETE FROM activity_logs WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM users WHERE id IN ({$in})");
    }
    foreach ($tmpFiles ?? [] as $f) {
        @unlink($f);
    }
    if (is_dir($tmpRoot)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($tmpRoot);
    }
    $leftUsers = (int) $db->query("SELECT COUNT(*) FROM users WHERE email LIKE '{$tag}%'")->fetchColumn();
    check($leftUsers === 0 && !is_dir($tmpRoot), 'тестовые данные и файлы удалены');
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
