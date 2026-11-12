<?php

declare(strict_types=1);

/**
 * ListingService (создание/правка/удаление объявлений) — локальная БД и public/uploads/products.
 *   php tools/run_listing_service_tests.php
 * Создаёт временного пользователя *@m02-test.local, тестовые PNG и удаляет всё в конце.
 */

require __DIR__ . '/../bin/bootstrap.php';

use App\Core\Database;
use App\Helpers\ProductHelper;
use App\Services\AMLService;
use App\Services\Auth\AccountService;
use App\Services\Listing\ListingService;

$passes = 0;
$total = 0;
function check(bool $cond, string $desc): void
{
    global $passes, $total;
    $total++;
    $passes += $cond ? 1 : 0;
    echo ($cond ? ' [PASS] ' : ' [FAIL] ') . $desc . "\n";
}

/** Временный PNG в формате $_FILES. @return array{name: string, tmp_name: string, error: int, size: int} */
function fakeUpload(string $label): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'zkimg');
    $img = imagecreatetruecolor(120, 120);
    imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 120, 200));
    imagestring($img, 3, 10, 50, $label, imagecolorallocate($img, 255, 255, 255));
    imagepng($img, $tmp);

    return ['name' => $label . '.png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)];
}

/** @param list<array<string, mixed>> $uploads */
function filesArray(array $uploads): array
{
    return [
        'name' => array_column($uploads, 'name'),
        'tmp_name' => array_column($uploads, 'tmp_name'),
        'error' => array_column($uploads, 'error'),
        'size' => array_column($uploads, 'size'),
    ];
}

function validTestIin(): string
{
    $aml = AMLService::make();
    for ($serial = 1000; $serial < 9999; $serial++) {
        for ($check = 0; $check <= 9; $check++) {
            $iin = '900101' . '3' . $serial . $check;
            if ($aml->validateIinFormat($iin) && !$aml->isBlacklisted($iin)) {
                return $iin;
            }
        }
    }
    throw new RuntimeException('no test IIN');
}

echo "=== ListingService tests ===\n";

// AML-проверки должны реально выполняться (локально они отключены через env=local)
$prevEnv = $GLOBALS['appConfig']['env'] ?? null;
$GLOBALS['appConfig']['env'] = 'production';

echo "\n-- AML skip is config-based, not Host-based\n";
$_SERVER['HTTP_HOST'] = 'localhost';
check(AMLService::skipChecks() === false, 'Host: localhost does NOT disable AML checks in production');
$GLOBALS['appConfig']['env'] = 'local';
check(AMLService::skipChecks() === true, 'env=local disables AML checks (local development only)');
$GLOBALS['appConfig']['env'] = 'production';
unset($_SERVER['HTTP_HOST']);
$db = Database::connect();
$tag = bin2hex(random_bytes(3));
$uid = 0;
$otherUid = 0;
$dir = dirname(__DIR__) . '/public/uploads/products/';
// В CLI is_uploaded_file() всегда false — проверяем формат так же (расширение + реальное изображение),
// без проверки происхождения файла. Строгая проверка HTTP-загрузки — в HTTP-смоуке API.
$svc = new ListingService(static function (string $tmp, string $name, array $allowed): bool {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $info = @getimagesize($tmp);

    return in_array($ext, $allowed, true) && $info !== false && in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true);
});

try {
    $uid = (int) (new AccountService())->register(['name' => 'Продавец Тест', 'email' => "lst{$tag}@m02-test.local", 'password' => 'Seller12345', 'accept_offer' => true])['user']['id'];
    $otherUid = (int) (new AccountService())->register(['name' => 'Чужой', 'email' => "oth{$tag}@m02-test.local", 'password' => 'Other12345', 'accept_offer' => true])['user']['id'];
    $iin = validTestIin();
    $base = [
        'type' => 'used', 'title' => 'Велосипед горный', 'description' => 'Почти новый, 21 скорость',
        'price' => '85000', 'category' => 'Спорт и отдых / Велосипеды', 'quantity' => '1',
        'location' => 'Алматы', 'fulfillment_mode' => 'pickup',
    ];

    echo "\n-- gates\n";
    $state = $svc->formState($uid);
    check($state['rules_accepted'] === false && $state['tax_id_on_file'] === false && $state['max_photos'] >= 1, 'form state reports rules and IIN still needed');
    $r = $svc->create($uid, $base, filesArray([fakeUpload('a')]));
    check(($r['code'] ?? '') === 'rules_required', 'listing rules must be accepted');
    $r = $svc->create($uid, $base + ['listing_rules_ack' => '1'], filesArray([fakeUpload('a')]));
    check(($r['code'] ?? '') === 'tax_id_invalid', 'IIN required (AML screening)');
    check($svc->formState($uid)['rules_accepted'] === true, 'rules acceptance persisted');

    echo "\n-- validation\n";
    $withIin = $base + ['iin' => $iin];
    check(($svc->create($uid, ['title' => ''] + $withIin, filesArray([fakeUpload('a')]))['code'] ?? '') === 'title_desc_required', 'title required');
    check(($svc->create($uid, $withIin, null)['code'] ?? '') === 'photo_error', 'at least one photo required');
    check(($svc->create($uid, ['type' => 'exchange'] + $withIin, filesArray([fakeUpload('a')]))['code'] ?? '') === 'exchange_for_required', 'barter needs "exchange for"');
    check(($svc->create($uid, ['type' => 'gig'] + $withIin, filesArray([fakeUpload('a')]))['code'] ?? '') === 'invalid_type', 'gig is not handled by ListingService');
    $bad = fakeUpload('x');
    file_put_contents($bad['tmp_name'], '<?php echo 1;');
    $bad['name'] = 'evil.png';
    check(($svc->create($uid, $withIin, filesArray([$bad]))['code'] ?? '') === 'photo_error', 'non-image disguised as .png rejected');

    echo "\n-- create\n";
    $created = $svc->create($uid, $withIin + ['cover' => '__new__1'], filesArray([fakeUpload('one'), fakeUpload('two')]));
    check(!empty($created['ok']) && $created['product_id'] > 0, 'listing created');
    $p = $db->query('SELECT * FROM products WHERE id = ' . (int) $created['product_id'])->fetch(PDO::FETCH_ASSOC);
    $files = ProductHelper::decodeImages($p);
    check($p['status'] === 'active' && (int) $p['price'] === 85000 && $p['location'] === 'Алматы', 'stored with price, location, active status');
    check(count($files) === 2 && is_file($dir . $files[0]) && is_file($dir . $files[1]), 'two photos saved to uploads');
    check(basename((string) $p['image']) === $files[1], 'cover = chosen new photo (__new__1)');
    check($svc->formState($uid)['tax_id_on_file'] === true, 'IIN saved to profile after successful screening');

    echo "\n-- update\n";
    $upd = $svc->update($uid, (int) $p['id'], [
        'type' => 'used', 'title' => 'Велосипед горный (торг)', 'description' => 'Почти новый',
        'price' => '80000', 'quantity' => '1', 'fulfillment_mode' => 'pickup',
        'keep_images' => [$files[0]],
    ], filesArray([fakeUpload('three')]));
    $p2 = $db->query('SELECT * FROM products WHERE id = ' . (int) $p['id'])->fetch(PDO::FETCH_ASSOC);
    $files2 = ProductHelper::decodeImages($p2);
    check(!empty($upd['ok']) && $p2['title'] === 'Велосипед горный (торг)' && (int) $p2['price'] === 80000, 'title and price updated');
    check(count($files2) === 2 && $files2[0] === $files[0] && !is_file($dir . $files[1]), 'kept photo stays, removed photo deleted from disk');
    check(($svc->update($otherUid, (int) $p['id'], $withIin, null)['code'] ?? '') === 'not_found', "can't edit someone else's listing");

    echo "\n-- delete\n";
    $buyer = $otherUid;
    $db->prepare("INSERT INTO orders (product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold) VALUES (?, ?, ?, 80000, 'wallet', 'pickup', 'escrowed', 'holding')")
        ->execute([(int) $p['id'], $buyer, $uid]);
    $orderId = (int) $db->lastInsertId();
    check(($svc->delete($uid, (int) $p['id'])['code'] ?? '') === 'has_active_orders', 'listing with active order cannot be deleted');
    $db->exec("DELETE FROM orders WHERE id = {$orderId}");
    check(($svc->delete($otherUid, (int) $p['id'])['code'] ?? '') === 'not_found', "can't delete someone else's listing");
    check(!empty($svc->delete($uid, (int) $p['id'])['ok']), 'own listing deleted');
    check(!is_file($dir . $files2[0]) && !is_file($dir . $files2[1]), 'photos removed with listing');
} finally {
    foreach ([$uid, $otherUid] as $id) {
        if ($id > 0) {
            foreach ($db->query("SELECT image, images FROM products WHERE user_id = {$id}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (ProductHelper::decodeImages($row) as $f) {
                    @unlink($dir . $f);
                }
            }
            $db->exec("DELETE FROM orders WHERE seller_id = {$id} OR buyer_id = {$id}");
            $db->exec("DELETE FROM products WHERE user_id = {$id}");
        }
    }
    $db->exec("DELETE FROM users WHERE email LIKE '%@m02-test.local'");
}

$GLOBALS['appConfig']['env'] = $prevEnv;
echo "\nSummary: {$passes} / {$total} tests passed.\n";
exit($passes === $total ? 0 : 1);
