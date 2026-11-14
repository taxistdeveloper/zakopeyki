<?php

namespace App\Models;

use App\Core\Model;

/**
 * Токены мобильного приложения.
 *
 * kind = 'access' — долгоживущий токен устройства (Bearer / cookie zk_mt).
 * kind = '2fa'    — короткий челлендж между вводом пароля и TOTP-кодом.
 * В БД хранится только sha256 от токена.
 */
class MobileToken extends Model
{
    protected string $table = 'mobile_tokens';
    private static bool $ensured = false;

    public const ACCESS_PREFIX = 'zkm_';
    private const CHALLENGE_PREFIX = 'zkc_';
    private const CHALLENGE_TTL = 300;
    private const CHALLENGE_MAX_ATTEMPTS = 5;

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
            "CREATE TABLE IF NOT EXISTS mobile_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'access',
                token_hash CHAR(64) NOT NULL,
                device VARCHAR(120) DEFAULT NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_mobile_token_hash (token_hash),
                INDEX idx_mobile_token_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$ensured = true;
    }

    public static function looksLikeAccessToken(string $token): bool
    {
        return (bool) preg_match('/^zkm_[a-f0-9]{48}$/', $token);
    }

    public function issueAccess(int $userId, ?string $device): string
    {
        $token = self::ACCESS_PREFIX . bin2hex(random_bytes(24));
        $stmt = $this->db->prepare(
            "INSERT INTO mobile_tokens (user_id, kind, token_hash, device) VALUES (?, 'access', ?, ?)"
        );
        $stmt->execute([$userId, hash('sha256', $token), $this->cleanDevice($device)]);

        return $token;
    }

    /** Пользователь по действующему access-токену (или null). */
    public function userForAccess(string $token): ?array
    {
        if (!self::looksLikeAccessToken($token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare(
            "SELECT u.*, t.id AS mobile_token_id, t.last_used_at AS mobile_token_last_used
             FROM mobile_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.kind = 'access' AND t.revoked_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Не пишем в БД на каждый запрос — раз в 10 минут достаточно.
        $last = $row['mobile_token_last_used'] ? strtotime((string) $row['mobile_token_last_used']) : 0;
        if ($last < time() - 600) {
            $this->db->prepare('UPDATE mobile_tokens SET last_used_at = NOW() WHERE id = ?')
                ->execute([(int) $row['mobile_token_id']]);
        }
        unset($row['mobile_token_id'], $row['mobile_token_last_used']);

        return $row;
    }

    public function revokeAccess(string $token): void
    {
        $stmt = $this->db->prepare(
            "UPDATE mobile_tokens SET revoked_at = NOW()
             WHERE token_hash = ? AND kind = 'access' AND revoked_at IS NULL"
        );
        $stmt->execute([hash('sha256', $token)]);
    }

    public function issueChallenge(int $userId, ?string $device): string
    {
        $token = self::CHALLENGE_PREFIX . bin2hex(random_bytes(24));
        $stmt = $this->db->prepare(
            "INSERT INTO mobile_tokens (user_id, kind, token_hash, device, expires_at)
             VALUES (?, '2fa', ?, ?, DATE_ADD(NOW(), INTERVAL " . self::CHALLENGE_TTL . ' SECOND))'
        );
        $stmt->execute([$userId, hash('sha256', $token), $this->cleanDevice($device)]);

        // Чистим протухшие челленджи
        $this->db->exec("DELETE FROM mobile_tokens WHERE kind = '2fa' AND expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

        return $token;
    }

    /**
     * Проверяет челлендж и засчитывает попытку.
     *
     * @return int|null user_id, если челлендж жив
     */
    public function challengeUser(string $challenge): ?int
    {
        if (!preg_match('/^zkc_[a-f0-9]{48}$/', $challenge)) {
            return null;
        }
        $hash = hash('sha256', $challenge);
        $stmt = $this->db->prepare(
            "SELECT id, user_id, attempts FROM mobile_tokens
             WHERE token_hash = ? AND kind = '2fa' AND revoked_at IS NULL AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS) {
            return null;
        }
        $this->db->prepare('UPDATE mobile_tokens SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $row['id']]);

        return (int) $row['user_id'];
    }

    public function consumeChallenge(string $challenge): void
    {
        $stmt = $this->db->prepare(
            "UPDATE mobile_tokens SET revoked_at = NOW() WHERE token_hash = ? AND kind = '2fa'"
        );
        $stmt->execute([hash('sha256', $challenge)]);
    }

    private function cleanDevice(?string $device): ?string
    {
        $device = trim((string) $device);
        if ($device === '') {
            return null;
        }

        return mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $device) ?? '', 0, 120);
    }
}
