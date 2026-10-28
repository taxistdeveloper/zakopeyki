<?php

declare(strict_types=1);

/**
 * Local presentation accounts. Re-running refreshes their passwords and demo access.
 * Usage: php bin/seed_investor_demo.php --apply
 */

if (PHP_SAPI !== 'cli' || !in_array('--apply', $argv ?? [], true)) {
    http_response_code(403);
    exit("Run from CLI with --apply.\n");
}

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Models\BusinessPackage;
use App\Models\BusinessSubscription;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AMLService;

$config = require dirname(__DIR__) . '/config/database.php';
if (!in_array((string) ($config['host'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Investor demo seeding is allowed only for a local database.\n");
    exit(1);
}

$db = Database::connect();
$users = new User();
$products = new Product();
$wallet = new Wallet();
$subscriptions = new BusinessSubscription();
$package = (new BusinessPackage())->findBySlug('business-month');
if (!$package) {
    fwrite(STDERR, "Business package is unavailable.\n");
    exit(1);
}

$path = dirname(__DIR__) . '/storage/investor-demo-credentials.txt';
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0700, true);
}
if (!is_writable(dirname($path))) {
    throw new RuntimeException('Credentials directory is not writable.');
}

$accounts = [
    [
        'email' => 'investor.demo1@zakopeyki.kz',
        'login' => 'investor_demo1',
        'name' => 'Инвестор Демо 1',
        'role' => 'user',
        'phone' => '77070000041',
        'iin' => '990101300041',
        'bin' => '990101300041',
        'product' => [
            'title' => 'Демо · Samsung Galaxy A55 256 ГБ',
            'description' => 'Пример карточки для презентации. Новый смартфон, полный комплект и гарантия.',
            'characteristics' => "Бренд: Samsung\nМодель: Galaxy A55\nПамять: 256 ГБ\nЦвет: синий",
            'price' => 189000,
            'image' => 'demo_galaxy-a55.png',
        ],
    ],
    [
        'email' => 'investor.demo2@zakopeyki.kz',
        'login' => 'investor_demo2',
        'name' => 'Инвестор Демо 2',
        'role' => 'admin',
        'phone' => '77070000042',
        'iin' => '990101300042',
        'bin' => '990101300042',
        'product' => [
            'title' => 'Демо · Ноутбук Lenovo IdeaPad',
            'description' => 'Пример второго товара для презентации. Готов к работе, зарядное устройство в комплекте.',
            'characteristics' => "Бренд: Lenovo\nПамять: 512 ГБ\nСостояние: отличное",
            'price' => 245000,
            'image' => 'demo_lenovo-i5.png',
        ],
    ],
];

$credentials = ["Zakopeyki investor demo — generated " . date('Y-m-d H:i:s'), ''];
foreach ($accounts as $account) {
    $existing = $users->findByEmail($account['email']);
    if ($existing && (string) ($existing['login'] ?? '') !== $account['login']) {
        throw new RuntimeException('Email belongs to another account: ' . $account['email']);
    }

    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    $id = $existing
        ? (int) $existing['id']
        : $users->create([
            'name' => $account['name'],
            'email' => $account['email'],
            'login' => $account['login'],
            'phone' => $account['phone'],
            'password' => $password,
        ]);
    if ($existing) {
        $users->updatePassword($id, $password);
    }

    $db->prepare(
        'UPDATE users SET name = ?, role = ?, phone = ?, site_access = 1,
         listing_rules_accepted_at = COALESCE(listing_rules_accepted_at, NOW()),
         two_factor_enabled = 0, two_factor_secret = NULL,
         ship_country = ?, ship_region = ?, ship_city = ?, ship_contact_name = ?, ship_phone = ?
         WHERE id = ?'
    )->execute([
        $account['name'], $account['role'], $account['phone'],
        'KZ', 'Карагандинская область', 'Караганда', $account['name'], $account['phone'], $id,
    ]);
    $users->promoteToBusiness($id, [
        'business_entity_type' => 'ip',
        'business_name' => 'Демо · Zakopeyki ' . ($account['role'] === 'admin' ? 'Admin' : 'Business'),
        'bin' => $account['bin'],
    ]);
    $users->setAmlStatus($id, AMLService::STATUS_CLEAR, $account['iin'], $account['bin']);
    $users->enableCourseAuthor($id);

    $active = $subscriptions->activeForUser($id);
    $start = date('Y-m-d H:i:s');
    $end = date('Y-m-d H:i:s', strtotime('+30 days'));
    if ($active) {
        $subscriptions->updateWindow((int) $active['id'], $start, $end);
    } else {
        $subscriptions->createSubscription([
            'user_id' => $id,
            'package_id' => (int) $package['id'],
            'starts_at' => $start,
            'ends_at' => $end,
            'price_paid_kzt' => 0,
            'payment_meta' => 'investor-demo',
        ]);
    }

    $balance = $wallet->balance($id);
    if ($balance < 500000) {
        $credit = $wallet->deposit($id, max(100, 500000 - $balance), 'demo', 'investor-demo');
        if (empty($credit['ok'])) {
            throw new RuntimeException('Could not seed demo balance for ' . $account['email']);
        }
    }

    $sample = $account['product'];
    $check = $db->prepare('SELECT id FROM products WHERE user_id = ? AND title = ? LIMIT 1');
    $check->execute([$id, $sample['title']]);
    if (!$check->fetchColumn()) {
        $image = is_file(dirname(__DIR__) . '/public/uploads/products/' . $sample['image'])
            ? $sample['image'] : null;
        $products->create([
            'user_id' => $id,
            'type' => 'new',
            'title' => $sample['title'],
            'description' => $sample['description'],
            'characteristics' => $sample['characteristics'],
            'price' => $sample['price'],
            'quantity' => 3,
            'location' => 'Караганда',
            'image' => $image,
            'images' => $image ? [$image] : [],
        ]);
    }

    $credentials[] = $account['email'] . ' | ' . $password . ' | ' . $account['role'];
}

if (file_put_contents($path, implode(PHP_EOL, $credentials) . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Could not save demo credentials.');
}
@chmod($path, 0600);
echo implode(PHP_EOL, $credentials), PHP_EOL;
echo 'Saved to storage/investor-demo-credentials.txt', PHP_EOL;
