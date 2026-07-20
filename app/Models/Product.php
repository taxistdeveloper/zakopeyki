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
        } catch (\Throwable $e) {
            // ignore on fresh/broken installs
        }
        self::$ensured = true;
    }

    public function allActive(?string $type = null, ?string $search = null, ?string $category = null): array
    {
        $sql = 'SELECT p.*, u.name AS seller_name, u.phone AS seller_phone
                FROM products p
                JOIN users u ON u.id = p.user_id
                WHERE p.status = ?';
        $params = ['active'];

        if ($type) {
            $sql .= ' AND p.type = ?';
            $params[] = $type;
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
            'SELECT p.*, u.name AS seller_name, u.phone AS seller_phone, u.email AS seller_email
             FROM products p
             JOIN users u ON u.id = p.user_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products (user_id, type, category, title, description, price, exchange_for, price_label, current_bid, bid_step, location, image, images, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $type = $data['type'];
        $price = in_array($type, ['free', 'exchange'], true)
            ? 0
            : (int) preg_replace('/\D/', '', (string) ($data['price'] ?? 0));
        $currentBid = $type === 'auction' ? $price : null;
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
            (int) ($data['bid_step'] ?? 1000),
            $data['location'] ?? 'Караганда',
            $cover,
            $images,
            'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateProduct(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET type=?, category=?, title=?, description=?, price=?, exchange_for=?, price_label=?, location=?, image=?, images=?, status=? WHERE id=?'
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

        return $stmt->execute([
            $type,
            $data['category'] ?? 'Разное',
            $data['title'],
            $data['description'],
            $price,
            $exchangeFor,
            $priceLabel,
            $data['location'] ?? 'Караганда',
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
        $stmt = $this->db->prepare('SELECT * FROM products WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function countActive(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM products WHERE status='active'"
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

    public function placeBid(int $productId, int $userId, int $amount): array
    {
        $product = $this->find($productId);
        if (!$product || $product['type'] !== 'auction') {
            return ['ok' => false, 'error' => 'Лот не найден или это не аукцион'];
        }

        $min = (int) ($product['current_bid'] ?: $product['price']) + (int) $product['bid_step'];
        if ($amount < $min) {
            return ['ok' => false, 'error' => "Минимальная ставка: {$min} ₸"];
        }

        $this->db->beginTransaction();
        try {
            $bid = $this->db->prepare('INSERT INTO bids (product_id, user_id, amount) VALUES (?, ?, ?)');
            $bid->execute([$productId, $userId, $amount]);

            $upd = $this->db->prepare('UPDATE products SET current_bid = ?, price = ? WHERE id = ?');
            $upd->execute([$amount, $amount, $productId]);

            $this->db->commit();
            return ['ok' => true, 'amount' => $amount];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'error' => 'Не удалось сделать ставку'];
        }
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
}
