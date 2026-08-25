<?php

declare(strict_types=1);

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=zakapeiku;charset=utf8mb4',
    'root',
    'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS reviews (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        author_id INT UNSIGNED NOT NULL,
        subject_id INT UNSIGNED NOT NULL,
        role ENUM('as_seller', 'as_buyer') NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        body TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_order_author (order_id, author_id),
        INDEX idx_subject (subject_id),
        INDEX idx_author (author_id),
        INDEX idx_rating (rating)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$orders = [
    [10, 10, 3, 2, 36000, 10],
    [11, 5, 4, 2, 120000, 8],
    [13, 9, 1, 2, 5000, 4],
];

$insOrder = $pdo->prepare(
    "INSERT IGNORE INTO orders
        (id, product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold, confirmed_at, released_at, created_at, paid_at)
     VALUES
        (?, ?, ?, ?, ?, 'wallet', 'kazpost', 'completed', 'released',
         DATE_SUB(NOW(), INTERVAL ? DAY),
         DATE_SUB(NOW(), INTERVAL ? DAY),
         DATE_SUB(NOW(), INTERVAL ? DAY),
         DATE_SUB(NOW(), INTERVAL ? DAY))"
);

foreach ($orders as [$id, $productId, $buyerId, $sellerId, $amount, $daysAgo]) {
    $insOrder->execute([
        $id,
        $productId,
        $buyerId,
        $sellerId,
        $amount,
        $daysAgo - 5,
        $daysAgo - 5,
        $daysAgo,
        $daysAgo,
    ]);
}

$pdo->exec('DELETE FROM reviews');

$rows = [
    [1, 3, 2, 'as_seller', 5, 'Всё пришло быстро, товар как в описании. Рекомендую продавца!', 4],
    [1, 2, 3, 'as_buyer', 5, 'Покупатель адекватный, быстро подтвердил получение. Спасибо!', 4],
    [10, 3, 2, 'as_seller', 4, 'Хороший продавец, небольшая задержка с отправкой, но всё ок.', 5],
    [10, 2, 3, 'as_buyer', 5, 'Приятный покупатель, без лишних вопросов.', 5],
    [11, 4, 2, 'as_seller', 5, 'Наушники новые, упаковка целая. Супер!', 3],
    [11, 2, 4, 'as_buyer', 4, 'Оплата прошла нормально, связь была.', 3],
    [13, 1, 2, 'as_seller', 4, 'Ремонт сделали качественно, но ждал чуть дольше обещанного.', 1],
    [13, 2, 1, 'as_buyer', 5, 'Клиент вежливый, оплатил сразу. Буду рад ещё раз.', 1],
];

$stmt = $pdo->prepare(
    'INSERT INTO reviews (order_id, author_id, subject_id, role, rating, body, created_at)
     VALUES (?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))'
);

foreach ($rows as $row) {
    $stmt->execute($row);
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
echo "Seeded {$count} reviews OK\n";
foreach ($pdo->query('SELECT id, rating, body FROM reviews ORDER BY id LIMIT 3') as $r) {
    echo "#{$r['id']} {$r['rating']}★ {$r['body']}\n";
}
