<?php
/**
 * Проставляет демо-фото товарам без image или с крошечным placeholder (seed.sql).
 * Берёт уже скачанные файлы из public/uploads/products/demo_*.png
 *
 *   C:\MAMP\bin\php\php8.3.1\php.exe bin/fix_seed_product_images.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$c = require $root . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['dbname']),
    $c['username'],
    $c['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dir = $root . '/public/uploads/products';

/** @var array<int,string> product id => existing demo filename */
$byId = [
    56 => 'demo_iphone-13-used.png',
    57 => 'demo_snowboard-exchange.png',
    58 => 'demo_sony-wh-1000xm5.png',
    59 => 'demo_watch-lot.png',
    60 => 'demo_ipad-10.png',
    61 => 'demo_tools-lot.png',
    62 => 'demo_pc-repair.png',
    63 => 'demo_iphone-12-lot.png',
    64 => 'demo_galaxy-a55.png',
    65 => 'demo_jbl-flip6.png',
    66 => 'demo_canon-2000d.png',
    67 => 'demo_free-chair.png',
];

/** @var array<string,string> title keyword => demo file */
$byKeyword = [
    'iphone 13' => 'demo_iphone-13-used.png',
    'сноуборд' => 'demo_snowboard-exchange.png',
    'sony' => 'demo_sony-wh-1000xm5.png',
    'смарт-часы' => 'demo_watch-lot.png',
    'монитор' => 'demo_ipad-10.png',
    'инструмент' => 'demo_tools-lot.png',
    'ремонт пк' => 'demo_pc-repair.png',
    'x-6' => 'demo_iphone-12-lot.png',
    'x-7' => 'demo_galaxy-a55.png',
    'x-8' => 'demo_jbl-flip6.png',
    'x-9' => 'demo_canon-2000d.png',
    'стол' => 'demo_free-chair.png',
];

$fallbackByType = [
    'new' => 'demo_sony-wh-1000xm5.png',
    'used' => 'demo_iphone-13-used.png',
    'auction' => 'demo_tools-lot.png',
    'free' => 'demo_free-chair.png',
    'exchange' => 'demo_ipad-exchange.png',
    'service' => 'demo_pc-repair.png',
];

$rows = $pdo->query(
    "SELECT id, type, title, image FROM products
     WHERE image IS NULL OR image = '' OR image LIKE 'demo_seed_%'
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nothing to fix.\n";
    exit(0);
}

$upd = $pdo->prepare('UPDATE products SET image = ?, images = ? WHERE id = ?');
$fixed = 0;

foreach ($rows as $row) {
    $id = (int) $row['id'];
    $type = (string) $row['type'];
    $titleLower = mb_strtolower((string) $row['title']);

    $srcFile = $byId[$id] ?? null;
    if ($srcFile === null) {
        foreach ($byKeyword as $needle => $file) {
            if (mb_strpos($titleLower, $needle) !== false) {
                $srcFile = $file;
                break;
            }
        }
    }
    if ($srcFile === null) {
        $srcFile = $fallbackByType[$type] ?? 'demo_iphone-15-128.png';
    }

    $srcPath = $dir . '/' . $srcFile;
    if (!is_file($srcPath)) {
        echo "MISSING source $srcFile for #$id\n";
        continue;
    }

    $destFile = 'demo_seed_' . $id . '.png';
    $destPath = $dir . '/' . $destFile;
    if (!@copy($srcPath, $destPath)) {
        // fallback: reference shared file directly
        $destFile = $srcFile;
    }

    $upd->execute([$destFile, json_encode([$destFile], JSON_UNESCAPED_UNICODE), $id]);
    echo "OK #$id {$row['title']} -> $destFile (from $srcFile)\n";
    $fixed++;
}

echo "Done: $fixed product(s).\n";
