<?php

namespace App\Models;

use App\Core\Model;

class Favorite extends Model
{
    protected string $table = 'favorites';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS favorites (
                user_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, product_id),
                INDEX idx_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$ensured = true;
    }

    public function isFavorite(int $userId, int $productId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM favorites WHERE user_id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $productId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<int> */
    public function idsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT product_id FROM favorites WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, u.name AS seller_name, u.phone AS seller_phone
             FROM favorites f
             JOIN products p ON p.id = f.product_id
             JOIN users u ON u.id = p.user_id
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** @return array{ok: bool, favorited: bool, error?: string} */
    public function toggle(int $userId, int $productId): array
    {
        $product = (new Product())->find($productId);
        if (!$product) {
            return ['ok' => false, 'favorited' => false, 'error' => 'Товар не найден'];
        }

        if ($this->isFavorite($userId, $productId)) {
            $stmt = $this->db->prepare(
                'DELETE FROM favorites WHERE user_id = ? AND product_id = ?'
            );
            $stmt->execute([$userId, $productId]);
            return ['ok' => true, 'favorited' => false];
        }

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO favorites (user_id, product_id) VALUES (?, ?)'
        );
        $stmt->execute([$userId, $productId]);
        return ['ok' => true, 'favorited' => true];
    }
}
