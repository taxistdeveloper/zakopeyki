<?php
/**
 * Создаёт/обновляет демо-сторис с фото.
 * Картинки: public/uploads/stories/demo_story_*.png
 * (если нет — копирует из public/uploads/products/demo_*.png)
 *
 *   php bin/fix_seed_story_images.php
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

$storiesDir = $root . '/public/uploads/stories';
$productsDir = $root . '/public/uploads/products';
if (!is_dir($storiesDir)) {
    mkdir($storiesDir, 0775, true);
}

$loginToId = [];
$st = $pdo->query(
    "SELECT id, login FROM users WHERE login LIKE 'demo_%'"
);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $key = preg_replace('/^demo_/', '', (string) $u['login']);
    $loginToId[$key] = (int) $u['id'];
}

if ($loginToId === []) {
    // fallback по email
    $st = $pdo->query(
        "SELECT id, email FROM users WHERE email LIKE 'demo.%@zakopeyki.kz'"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (preg_match('/^demo\.([a-z]+)@/i', (string) $u['email'], $m)) {
            $loginToId[$m[1]] = (int) $u['id'];
        }
    }
}

if ($loginToId === []) {
    fwrite(STDERR, "Demo users not found. Run: php bin/seed_demo.php\n");
    exit(1);
}

$packs = [
    'shop' => [
        ['🔥 Сегодня −15% на наушники Sony', '#ef4444', '🔥', 'sony-wh-1000xm5'],
        ['Новый iPhone 15 уже в TechMarket', '#2563eb', '📱', 'iphone-15-128'],
        ['PS5 Slim — самовывоз сегодня', '#7c3aed', '🎮', 'ps5-slim'],
    ],
    'fashion' => [
        ['Летняя капсула Style Qazaq', '#db2777', '👗', 'plate-summer'],
        ['Кроссовки Nike Dunk — последние размеры', '#111827', '👟', 'nike-dunk'],
        ['Кожаная сумка — новинка недели', '#be185d', '👜', 'bag-leather'],
    ],
    'seller' => [
        ['Отдам велик Trek — торг уместен', '#f59e0b', '🚲', 'trek-marlin'],
        ['Гитара Yamaha в идеале', '#7c3aed', '🎸', 'yamaha-f310'],
    ],
    'master' => [
        ['Чиню ПК и ноутбуки на выезд', '#059669', '🛠️', 'pc-repair'],
        ['Химчистка диванов на дому', '#0d9488', '✨', 'sofa-clean'],
    ],
    'neighbor' => [
        ['Книги даром — забирайте сегодня', '#0ea5e9', '📚', 'free-books'],
        ['Угловой диван б/у — самовывоз', '#ea580c', '🛋️', 'sofa-corner'],
    ],
    'teacher' => [
        ['Новый поток Excel стартует в понедельник', '#8b5cf6', '🎓', 'excel-course'],
    ],
    'buyer' => [
        ['Ищу хороший ноутбук в обмен на iPad', '#6366f1', '🔄', 'ipad-exchange'],
    ],
];

$find = $pdo->prepare(
    'SELECT id FROM stories WHERE user_id = ? AND caption = ? ORDER BY id DESC LIMIT 1'
);
$update = $pdo->prepare(
    "UPDATE stories
     SET image = ?, bg_color = ?, emoji = ?, audience = 'all', comments_enabled = 1,
         visibility = 'public', attach_product = 1,
         expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
     WHERE id = ?"
);
$insert = $pdo->prepare(
    "INSERT INTO stories (user_id, caption, image, bg_color, emoji, audience, comments_enabled, visibility, attach_product, expires_at)
     VALUES (?, ?, ?, ?, ?, 'all', 1, 'public', 1, DATE_ADD(NOW(), INTERVAL 24 HOUR))"
);

$done = 0;
foreach ($packs as $owner => $list) {
    $uid = $loginToId[$owner] ?? 0;
    if ($uid < 1) {
        echo "SKIP owner=$owner (no user)\n";
        continue;
    }
    foreach ($list as $story) {
        [$caption, $color, $emoji, $slug] = $story;
        $image = ensureStoryImage($storiesDir, $productsDir, $slug);
        if ($image === null) {
            echo "MISSING image for slug=$slug ($caption)\n";
            continue;
        }

        $find->execute([$uid, $caption]);
        $id = (int) $find->fetchColumn();
        if ($id > 0) {
            $update->execute([$image, $color, $emoji, $id]);
            echo "REFRESH #$id $caption -> $image\n";
        } else {
            $insert->execute([$uid, $caption, $image, $color, $emoji]);
            $id = (int) $pdo->lastInsertId();
            echo "CREATE #$id $caption -> $image\n";
        }
        $done++;
    }
}

// Подтянуть фото у любых сторис без image
$orphan = $pdo->query(
    "SELECT id, caption, image FROM stories
     WHERE image IS NULL OR image = '' OR expires_at > NOW()
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$fixOrphan = $pdo->prepare('UPDATE stories SET image = ? WHERE id = ? AND (image IS NULL OR image = \'\')');
foreach ($orphan as $row) {
    if (!empty($row['image'])) {
        $path = $storiesDir . '/' . basename((string) $row['image']);
        if (is_file($path) && filesize($path) > 10_000) {
            continue;
        }
    }
    if (!empty($row['image'])) {
        continue; // есть имя файла — не трогаем чужие сторис
    }
    // пустой image: поставить нейтральное демо-фото
    $fallback = ensureStoryImage($storiesDir, $productsDir, 'iphone-15-128')
        ?? ensureStoryImage($storiesDir, $productsDir, 'sony-wh-1000xm5');
    if ($fallback) {
        $fixOrphan->execute([$fallback, (int) $row['id']]);
        echo "FIX orphan #" . $row['id'] . " -> $fallback\n";
        $done++;
    }
}

echo "Done: $done story action(s).\n";

function ensureStoryImage(string $storiesDir, string $productsDir, string $slug): ?string
{
    $file = 'demo_story_' . preg_replace('/[^a-z0-9_-]+/i', '-', $slug) . '.png';
    $dest = $storiesDir . '/' . $file;
    if (is_file($dest) && filesize($dest) > 10_000) {
        return $file;
    }

    $productFile = 'demo_' . preg_replace('/[^a-z0-9_-]+/i', '-', $slug) . '.png';
    $src = $productsDir . '/' . $productFile;
    if (is_file($src) && filesize($src) > 10_000) {
        if (@copy($src, $dest)) {
            return $file;
        }
        // если copy не вышел — можно ссылаться на product path? Story helper expects uploads/stories/
        return null;
    }

    return is_file($dest) ? $file : null;
}
