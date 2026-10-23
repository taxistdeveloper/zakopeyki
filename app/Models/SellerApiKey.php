<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

class SellerApiKey extends Model
{
    protected string $table = 'seller_api_keys';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS seller_api_keys (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL DEFAULT 'default',
                key_prefix VARCHAR(16) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                last_used_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_sak_hash (key_hash),
                INDEX idx_sak_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    /**
     * @return array{ok: bool, id?: int, plain_key?: string, prefix?: string, error?: string}
     */
    public function createForUser(int $userId, string $name = 'default'): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'user'];
        }

        $raw = 'zk_' . bin2hex(random_bytes(24));
        $prefix = substr($raw, 0, 10);
        $hash = hash('sha256', $raw);

        $stmt = $this->db->prepare(
            'INSERT INTO seller_api_keys (user_id, name, key_prefix, key_hash)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, mb_substr($name, 0, 120), $prefix, $hash]);

        return [
            'ok' => true,
            'id' => (int) $this->db->lastInsertId(),
            'plain_key' => $raw,
            'prefix' => $prefix,
        ];
    }

    public function findActiveByPlainKey(string $plainKey): ?array
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '' || !str_starts_with($plainKey, 'zk_')) {
            return null;
        }
        $hash = hash('sha256', $plainKey);
        $stmt = $this->db->prepare(
            'SELECT * FROM seller_api_keys
             WHERE key_hash = ? AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE seller_api_keys SET last_used_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function revoke(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE seller_api_keys SET revoked_at = NOW()
             WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array> */
    public function listActiveForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, key_prefix, last_used_at, created_at
             FROM seller_api_keys
             WHERE user_id = ? AND revoked_at IS NULL
             ORDER BY id DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }
}
