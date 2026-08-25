<?php

namespace App\Models;

use App\Core\Model;

class Product extends Model
{
    protected string $table = 'products';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        (new User())->ensureBusinessSchema();
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM products LIKE 'image'")->fetchAll();
            if (!$cols) {
                $this->db->exec('ALTER TABLE products ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER location');
            }
            $colsImages = $this->db->query("SHOW COLUMNS FROM products LIKE 'images'")->fetchAll();
            if (!$colsImages) {
                $this->db->exec('ALTER TABLE products ADD COLUMN images TEXT DEFAULT NULL AFTER image');
            }
            $colsExchange = $this->db->query("SHOW COLUMNS FROM products LIKE 'exchange_for'")->fetchAll();
            if (!$colsExchange) {
                $this->db->exec('ALTER TABLE products ADD COLUMN exchange_for VARCHAR(255) DEFAULT NULL AFTER price');
            }
            $colsWhatsapp = $this->db->query("SHOW COLUMNS FROM products LIKE 'whatsapp'")->fetch();
            if (!$colsWhatsapp) {
                $this->db->exec('ALTER TABLE products ADD COLUMN whatsapp VARCHAR(20) DEFAULT NULL AFTER location');
            }

            $this->ensureColumn('auction_kind', "ALTER TABLE products ADD COLUMN auction_kind VARCHAR(20) NOT NULL DEFAULT 'english' AFTER bid_step");
            $this->ensureColumn('auction_reserve', 'ALTER TABLE products ADD COLUMN auction_reserve INT UNSIGNED DEFAULT NULL AFTER auction_kind');
            $this->ensureColumn('auction_buy_now', 'ALTER TABLE products ADD COLUMN auction_buy_now INT UNSIGNED DEFAULT NULL AFTER auction_reserve');
            $this->ensureColumn('auction_min_price', 'ALTER TABLE products ADD COLUMN auction_min_price INT UNSIGNED DEFAULT NULL AFTER auction_reserve');
            $this->ensureColumn('auction_step_interval', 'ALTER TABLE products ADD COLUMN auction_step_interval INT UNSIGNED DEFAULT NULL AFTER auction_min_price');
            $this->ensureColumn('auction_start_at', 'ALTER TABLE products ADD COLUMN auction_start_at DATETIME DEFAULT NULL AFTER auction_step_interval');
            $this->ensureColumn('auction_end_at', 'ALTER TABLE products ADD COLUMN auction_end_at DATETIME DEFAULT NULL AFTER auction_start_at');
            $this->ensureColumn('anti_snipe_seconds', 'ALTER TABLE products ADD COLUMN anti_snipe_seconds INT UNSIGNED NOT NULL DEFAULT 30 AFTER auction_end_at');
            $this->ensureColumn('auto_extend_seconds', 'ALTER TABLE products ADD COLUMN auto_extend_seconds INT UNSIGNED NOT NULL DEFAULT 120 AFTER anti_snipe_seconds');
            $this->ensureColumn('inactivity_timeout_seconds', 'ALTER TABLE products ADD COLUMN inactivity_timeout_seconds INT UNSIGNED DEFAULT NULL AFTER auto_extend_seconds');
            $this->ensureColumn('last_bid_at', 'ALTER TABLE products ADD COLUMN last_bid_at DATETIME DEFAULT NULL AFTER inactivity_timeout_seconds');
            $this->ensureColumn('winner_user_id', 'ALTER TABLE products ADD COLUMN winner_user_id INT UNSIGNED DEFAULT NULL AFTER last_bid_at');
            $this->ensureColumn('winning_bid_id', 'ALTER TABLE products ADD COLUMN winning_bid_id INT UNSIGNED DEFAULT NULL AFTER winner_user_id');
            $this->ensureColumn('view_count', 'ALTER TABLE products ADD COLUMN view_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER winning_bid_id');

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS product_views (
                    product_id INT UNSIGNED NOT NULL,
                    visitor_key CHAR(32) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (product_id, visitor_key),
                    INDEX idx_views_product (product_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $statusCol = $this->db->query("SHOW COLUMNS FROM products LIKE 'status'")->fetch();
            $type = strtolower((string) ($statusCol['Type'] ?? ''));
            if ($type !== '' && !str_contains($type, "'reserved'")) {
                $this->db->exec(
                    "ALTER TABLE products
                     MODIFY status ENUM('active','sold','reserved','archived')
                     NOT NULL DEFAULT 'active'"
                );
            }

            // Чиним лоты, зависшие в sold/reserved после отмены/возврата сделки
            $this->db->exec(
                "UPDATE products p
                 INNER JOIN orders o ON o.product_id = p.id
                 SET p.status = 'active'
                 WHERE p.status IN ('sold', 'reserved')
                   AND o.status IN ('cancelled', 'refunded')
                   AND NOT EXISTS (
                       SELECT 1 FROM orders o2
                       WHERE o2.product_id = p.id
                         AND o2.status IN (
                             'awaiting_payment', 'escrowed', 'shipped', 'delivered',
                             'dispute', 'return_approved', 'return_shipped',
                             'return_delivered', 'completed'
                         )
                   )"
            );
        } catch (\Throwable $e) {
            // ignore on fresh/broken installs
        }
        self::$ensured = true;
    }

    private function ensureColumn(string $name, string $alterSql): void
    {
        $col = $this->db->query('SHOW COLUMNS FROM products LIKE ' . $this->db->quote($name))->fetch();
        if (!$col) {
            $this->db->exec($alterSql);
        }
    }

    public function allActive(?string $type = null, ?string $search = null, ?string $category = null): array
    {
        $sql = 'SELECT p.*, u.name AS seller_name, u.phone AS seller_phone,
                       u.account_type AS seller_account_type, u.business_status AS seller_business_status,
                       u.business_name AS seller_business_name, u.business_entity_type AS seller_business_entity_type
                FROM products p
                JOIN users u ON u.id = p.user_id
                WHERE p.status = ?';
        $params = ['active'];

        if ($type) {
            $sql .= ' AND p.type = ?';
            $params[] = $type;
        } else {
            $sql .= ' AND p.type <> \'course\'';
        }

        if ($search) {
            $sql .= ' AND (p.title LIKE ? OR p.description LIKE ? OR p.category LIKE ? OR p.exchange_for LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($category) {
            // Exact match or parent prefix (e.g. "Дом и сад" → all its subcategories)
            $sql .= ' AND (p.category = ? OR p.category LIKE ?)';
            $params[] = $category;
            $params[] = $category . ' / %';
        }

        $sql .= ' ORDER BY p.created_at DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findWithSeller(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.name AS seller_name, u.phone AS seller_phone, u.email AS seller_email,
                    u.avatar AS seller_avatar, u.avatar_file AS seller_avatar_file,
                    u.created_at AS seller_created_at,
                    u.account_type AS seller_account_type, u.business_status AS seller_business_status,
                    u.business_name AS seller_business_name, u.business_entity_type AS seller_business_entity_type
             FROM products p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Похожие активные объявления: тот же тип, категория, близкая цена.
     *
     * @return list<array<string, mixed>>
     */
    public function similar(array $product, int $limit = 8): array
    {
        $id = (int) ($product['id'] ?? 0);
        $limit = max(1, min(24, $limit));
        if ($id < 1) {
            return [];
        }

        $type = (string) ($product['type'] ?? '');
        $category = trim((string) ($product['category'] ?? ''));
        $price = (int) ($product['price'] ?? 0);
        $location = trim((string) ($product['location'] ?? ''));

        $parentLike = null;
        if ($category !== '' && $category !== 'Разное' && str_contains($category, ' / ')) {
            $parent = trim(explode(' / ', $category, 2)[0]);
            if ($parent !== '') {
                $parentLike = $parent . ' / %';
            }
        }

        $filters = [];
        $filterParams = [];
        if ($type !== '') {
            $filters[] = 'p.type = ?';
            $filterParams[] = $type;
        }
        if ($category !== '' && $category !== 'Разное') {
            $filters[] = 'p.category = ?';
            $filterParams[] = $category;
            if ($parentLike) {
                $filters[] = 'p.category LIKE ?';
                $filterParams[] = $parentLike;
            }
        }
        if ($filters === []) {
            return [];
        }

        $sql = 'SELECT p.*, u.name AS seller_name, u.phone AS seller_phone,
                       u.account_type AS seller_account_type, u.business_status AS seller_business_status,
                       u.business_name AS seller_business_name, u.business_entity_type AS seller_business_entity_type
                FROM products p
                JOIN users u ON u.id = p.user_id
                WHERE p.status = ?
                  AND p.id != ?
                  AND p.type <> \'course\'
                  AND (' . implode(' OR ', $filters) . ')
                ORDER BY (p.type = ?) DESC';
        $params = array_merge(['active', $id], $filterParams, [$type]);

        if ($category !== '') {
            $sql .= ', (p.category = ?) DESC';
            $params[] = $category;
        }
        if ($parentLike) {
            $sql .= ', (p.category LIKE ?) DESC';
            $params[] = $parentLike;
        }
        if ($location !== '') {
            $sql .= ', (p.location = ?) DESC';
            $params[] = $location;
        }

        // UNSIGNED price: (p.price - ?) underflows when cheaper lots exist
        $sql .= ', ABS(CAST(p.price AS SIGNED) - CAST(? AS SIGNED)) ASC, p.created_at DESC LIMIT ' . $limit;
        $params[] = $price;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param list<int> $ids @return list<array> */
    public function findWithSellersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT p.*, u.name AS seller_name, u.phone AS seller_phone, u.email AS seller_email,
                    u.account_type AS seller_account_type, u.business_status AS seller_business_status,
                    u.business_name AS seller_business_name, u.business_entity_type AS seller_business_entity_type
             FROM products p
             JOIN users u ON u.id = p.user_id
             WHERE p.id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products (
                user_id, type, category, title, description, price, exchange_for, price_label,
                current_bid, bid_step, auction_kind, auction_reserve, auction_buy_now, auction_min_price, auction_step_interval,
                auction_start_at, auction_end_at, anti_snipe_seconds, auto_extend_seconds,
                inactivity_timeout_seconds, location, whatsapp, image, images, status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $type = $data['type'];
        $price = in_array($type, ['free', 'exchange'], true)
            ? 0
            : (int) preg_replace('/\D/', '', (string) ($data['price'] ?? 0));
        $isAuction = $type === 'auction';
        $currentBid = $isAuction ? $price : null;
        $exchangeFor = $type === 'exchange'
            ? (trim((string) ($data['exchange_for'] ?? '')) ?: null)
            : null;
        $priceLabel = match ($type) {
            'free' => $data['price_label'] ?? 'Бесплатно',
            'exchange' => $data['price_label'] ?? 'Обмен',
            default => null,
        };

        $images = $this->encodeImages($data['images'] ?? []);
        $cover = $data['image'] ?? null;
        if (!$cover && $images) {
            $list = json_decode($images, true) ?: [];
            $cover = $list[0] ?? null;
        }

        $kind = $isAuction ? (string) ($data['auction_kind'] ?? 'english') : 'english';
        if (!in_array($kind, ['english', 'dutch', 'continuous'], true)) {
            $kind = 'english';
        }
        $now = date('Y-m-d H:i:s');

        $stmt->execute([
            $data['user_id'],
            $type,
            $data['category'] ?? 'Разное',
            $data['title'],
            $data['description'],
            $price,
            $exchangeFor,
            $priceLabel,
            $currentBid,
            $isAuction ? max(1, (int) ($data['bid_step'] ?? 1000)) : (int) ($data['bid_step'] ?? 1000),
            $kind,
            $isAuction ? ($data['auction_reserve'] ?? null) : null,
            $isAuction ? ($data['auction_buy_now'] ?? null) : null,
            $isAuction ? ($data['auction_min_price'] ?? null) : null,
            $isAuction ? ($data['auction_step_interval'] ?? null) : null,
            $isAuction ? ($data['auction_start_at'] ?? $now) : null,
            $isAuction ? ($data['auction_end_at'] ?? null) : null,
            $isAuction ? (int) ($data['anti_snipe_seconds'] ?? 30) : 30,
            $isAuction ? (int) ($data['auto_extend_seconds'] ?? 120) : 120,
            $isAuction ? ($data['inactivity_timeout_seconds'] ?? null) : null,
            $data['location'] ?? 'Караганда',
            $data['whatsapp'] ?? null,
            $cover,
            $images,
            'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateProduct(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET type=?, category=?, title=?, description=?, price=?, exchange_for=?, price_label=?,
             bid_step=?, auction_kind=?, auction_reserve=?, auction_buy_now=?, auction_min_price=?, auction_step_interval=?,
             auction_end_at=?, anti_snipe_seconds=?, auto_extend_seconds=?, inactivity_timeout_seconds=?,
             location=?, whatsapp=?, image=?, images=?, status=? WHERE id=?'
        );
        $type = $data['type'];
        $price = in_array($type, ['free', 'exchange'], true)
            ? 0
            : (int) preg_replace('/\D/', '', (string) ($data['price'] ?? 0));
        $exchangeFor = $type === 'exchange'
            ? (trim((string) ($data['exchange_for'] ?? '')) ?: null)
            : null;
        $priceLabel = match ($type) {
            'free' => $data['price_label'] ?? 'Бесплатно',
            'exchange' => $data['price_label'] ?? 'Обмен',
            default => $data['price_label'] ?? null,
        };
        $images = $this->encodeImages($data['images'] ?? []);
        $cover = $data['image'] ?? null;
        if (!$cover && $images) {
            $list = json_decode($images, true) ?: [];
            $cover = $list[0] ?? null;
        }

        $isAuction = $type === 'auction';
        $kind = $isAuction ? (string) ($data['auction_kind'] ?? 'english') : 'english';
        if (!in_array($kind, ['english', 'dutch', 'continuous'], true)) {
            $kind = 'english';
        }

        return $stmt->execute([
            $type,
            $data['category'] ?? 'Разное',
            $data['title'],
            $data['description'],
            $price,
            $exchangeFor,
            $priceLabel,
            $isAuction ? max(1, (int) ($data['bid_step'] ?? 1000)) : (int) ($data['bid_step'] ?? 1000),
            $kind,
            $isAuction ? ($data['auction_reserve'] ?? null) : null,
            $isAuction ? ($data['auction_buy_now'] ?? null) : null,
            $isAuction ? ($data['auction_min_price'] ?? null) : null,
            $isAuction ? ($data['auction_step_interval'] ?? null) : null,
            $isAuction ? ($data['auction_end_at'] ?? null) : null,
            $isAuction ? (int) ($data['anti_snipe_seconds'] ?? 30) : 30,
            $isAuction ? (int) ($data['auto_extend_seconds'] ?? 120) : 120,
            $isAuction ? ($data['inactivity_timeout_seconds'] ?? null) : null,
            $data['location'] ?? 'Караганда',
            $data['whatsapp'] ?? null,
            $cover,
            $images,
            $data['status'] ?? 'active',
            $id,
        ]);
    }

    public function encodeImages(array $files): ?string
    {
        $clean = [];
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            $clean[] = basename($file);
            if (count($clean) >= 3) {
                break;
            }
        }
        return $clean ? json_encode(array_values($clean), JSON_UNESCAPED_UNICODE) : null;
    }

    public function deleteProductFiles($images): void
    {
        $files = [];
        if (is_string($images) && $images !== '') {
            $decoded = json_decode($images, true);
            if (is_array($decoded)) {
                $files = $decoded;
            } else {
                $files = [$images];
            }
        } elseif (is_array($images)) {
            $files = $images;
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/products/';
        foreach ($files as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }
            $path = $dir . basename($file);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function byUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE user_id = ? AND type <> \'course\' ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function countCreatedToday(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM products WHERE user_id = ? AND DATE(created_at) = CURDATE()'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Активные товары продавца для витрины эфира */
    public function activeShopByUser(int $userId, int $limit = 24): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM products
             WHERE user_id = ? AND status = 'active' AND type <> 'course'
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function activeShopByUserAndIds(int $userId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM products
             WHERE user_id = ? AND status = 'active' AND id IN ({$placeholders})
             ORDER BY FIELD(id, {$placeholders})"
        );
        $params = array_merge([$userId], $ids, $ids);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countActive(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM products WHERE status='active' AND type <> 'course'"
        )->fetchColumn();
    }

    public function countByType(): array
    {
        $rows = $this->db->query(
            "SELECT type, COUNT(*) AS cnt FROM products WHERE status='active' GROUP BY type"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['type']] = (int) $row['cnt'];
        }
        return $out;
    }

    public function findAndLock(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getHighestBid(int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM bids WHERE product_id = ? ORDER BY amount DESC, created_at ASC LIMIT 1'
        );
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createBid(int $productId, int $userId, int $amount): int
    {
        $stmt = $this->db->prepare('INSERT INTO bids (product_id, user_id, amount) VALUES (?, ?, ?)');
        $stmt->execute([$productId, $userId, $amount]);
        return (int) $this->db->lastInsertId();
    }

    public function saveAuctionState(array $auction): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET
                current_bid = ?, status = ?, auction_end_at = ?, last_bid_at = ?,
                winner_user_id = ?, winning_bid_id = ?
             WHERE id = ?'
        );
        return $stmt->execute([
            $auction['current_bid'] ?? null,
            $auction['status'] ?? 'active',
            $auction['auction_end_at'] ?? null,
            $auction['last_bid_at'] ?? null,
            $auction['winner_user_id'] ?? null,
            $auction['winning_bid_id'] ?? null,
            $auction['id'],
        ]);
    }

    /** @return list<array> */
    public function getExpiredAuctions(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM products
             WHERE type = 'auction' AND status = 'active' AND (
                (auction_end_at IS NOT NULL AND auction_end_at <= NOW())
                OR (
                    auction_kind = 'continuous'
                    AND last_bid_at IS NOT NULL
                    AND inactivity_timeout_seconds IS NOT NULL
                    AND DATE_ADD(last_bid_at, INTERVAL inactivity_timeout_seconds SECOND) <= NOW()
                )
             )"
        );
        return $stmt->fetchAll();
    }

    public function placeBid(int $productId, int $userId, int $amount): array
    {
        return (new \App\Services\AuctionService($this))->placeBid($productId, $userId, $amount);
    }

    public function recentBids(int $productId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, u.name AS bidder_name
             FROM bids b JOIN users u ON u.id = b.user_id
             WHERE b.product_id = ?
             ORDER BY b.created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $productId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countBids(int $productId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM bids WHERE product_id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public function countBidsForProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT product_id, COUNT(*) AS cnt FROM bids WHERE product_id IN ({$placeholders}) GROUP BY product_id"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['product_id']] = (int) $row['cnt'];
        }
        return $out;
    }

    public function viewCount(int $productId): int
    {
        $stmt = $this->db->prepare('SELECT view_count FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Уникальный просмотр лота (один посетитель = один счёт). Владелец лота не учитывается.
     */
    public function recordView(int $productId, int $ownerId): int
    {
        if (\App\Core\Auth::check() && (int) \App\Core\Auth::id() === $ownerId) {
            return $this->viewCount($productId);
        }

        try {
            $key = \App\Models\SiteVisit::currentVisitorKey();
            if ($key === '') {
                return $this->viewCount($productId);
            }
            $ins = $this->db->prepare(
                'INSERT IGNORE INTO product_views (product_id, visitor_key) VALUES (?, ?)'
            );
            $ins->execute([$productId, $key]);
            if ($ins->rowCount() > 0) {
                $upd = $this->db->prepare('UPDATE products SET view_count = view_count + 1 WHERE id = ?');
                $upd->execute([$productId]);
            }
        } catch (\Throwable $e) {
            // analytics must not break the page
        }

        return $this->viewCount($productId);
    }
}
