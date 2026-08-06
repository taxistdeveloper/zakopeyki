<?php

namespace App\Models;

use App\Core\Model;

class Review extends Model
{
    protected string $table = 'reviews';
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

        self::$ensured = true;
    }

    public function findByOrderAndAuthor(int $orderId, int $authorId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM reviews WHERE order_id = ? AND author_id = ? LIMIT 1'
        );
        $stmt->execute([$orderId, $authorId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array{ok: bool, error?: string, id?: int} */
    public function createForOrder(int $orderId, int $authorId, int $rating, string $body = ''): array
    {
        $order = (new Order())->find($orderId);
        if (!$order) {
            return ['ok' => false, 'error' => t('reviews.order_not_found')];
        }

        if (($order['status'] ?? '') !== 'completed') {
            return ['ok' => false, 'error' => t('reviews.only_completed')];
        }

        $buyerId = (int) $order['buyer_id'];
        $sellerId = (int) $order['seller_id'];
        $isBuyer = $authorId === $buyerId;
        $isSeller = $authorId === $sellerId;

        if (!$isBuyer && !$isSeller) {
            return ['ok' => false, 'error' => t('reviews.not_party')];
        }

        if ($this->findByOrderAndAuthor($orderId, $authorId)) {
            return ['ok' => false, 'error' => t('reviews.already_left')];
        }

        if ($rating < 1 || $rating > 5) {
            return ['ok' => false, 'error' => t('reviews.rating_invalid')];
        }

        $body = trim($body);
        if (mb_strlen($body) > 2000) {
            return ['ok' => false, 'error' => t('reviews.body_too_long')];
        }

        $subjectId = $isBuyer ? $sellerId : $buyerId;
        $role = $isBuyer ? 'as_seller' : 'as_buyer';

        $stmt = $this->db->prepare(
            'INSERT INTO reviews (order_id, author_id, subject_id, role, rating, body)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $authorId,
            $subjectId,
            $role,
            $rating,
            $body !== '' ? $body : null,
        ]);

        $id = (int) $this->db->lastInsertId();

        $author = (new User())->find($authorId);
        $authorName = $author['name'] ?? ('#' . $authorId);
        (new Notification())->createFor(
            $subjectId,
            t('reviews.notify_new', [
                'name' => $authorName,
                'rating' => (string) $rating,
                'id' => (string) $orderId,
            ])
        );

        return ['ok' => true, 'id' => $id];
    }

    /** @return list<array<string, mixed>> */
    public function forSubject(int $subjectId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*,
                    a.name AS author_name, a.avatar AS author_avatar, a.avatar_file AS author_avatar_file,
                    p.title AS product_title
             FROM reviews r
             JOIN users a ON a.id = r.author_id
             JOIN orders o ON o.id = r.order_id
             LEFT JOIN products p ON p.id = o.product_id
             WHERE r.subject_id = ?
             ORDER BY r.created_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $subjectId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array{avg: float, count: int} */
    public function statsFor(int $subjectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS cnt
             FROM reviews WHERE subject_id = ?'
        );
        $stmt->execute([$subjectId]);
        $row = $stmt->fetch() ?: ['avg_rating' => 0, 'cnt' => 0];

        return [
            'avg' => round((float) $row['avg_rating'], 1),
            'count' => (int) $row['cnt'],
        ];
    }

    /** @return array<int, array{avg: float, count: int}> */
    public function statsForMany(array $subjectIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $subjectIds))));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT subject_id,
                    COALESCE(AVG(rating), 0) AS avg_rating,
                    COUNT(*) AS cnt
             FROM reviews
             WHERE subject_id IN ($placeholders)
             GROUP BY subject_id"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['subject_id']] = [
                'avg' => round((float) $row['avg_rating'], 1),
                'count' => (int) $row['cnt'],
            ];
        }
        return $out;
    }
}
