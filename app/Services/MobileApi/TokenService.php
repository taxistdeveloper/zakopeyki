<?php

declare(strict_types=1);

namespace App\Services\MobileApi;

use App\Core\Database;
use PDO;

/**
 * Токены мобильного API (M-02).
 *
 * - Access-токен: подписанный HMAC-SHA256, короткий (15 мин), не хранится в БД.
 *   Формат: "v1.<base64url(json payload)>.<base64url(hmac)>".
 * - Refresh-токен: случайные 32 байта, в БД хранится только sha256; ротация при каждом
 *   использовании; повторное использование старого токена отзывает всё «семейство» (устройство).
 * - Отзыв семейства (logout, logout-all, блокировка) сразу инвалидирует и access-токены,
 *   т.к. каждый запрос проверяет, что семейство активно.
 */
final class TokenService
{
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 60 * 86400;
    public const CHALLENGE_TTL = 300;

    private PDO $db;
    private static bool $ensured = false;

    public function __construct(?PDO $db = null, private ?string $secret = null)
    {
        $this->db = $db ?? Database::connect();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS api_refresh_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                family_id CHAR(32) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                device_id VARCHAR(64) NOT NULL DEFAULT \'\',
                device_name VARCHAR(120) NOT NULL DEFAULT \'\',
                platform VARCHAR(16) NOT NULL DEFAULT \'\',
                app_version VARCHAR(32) NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                expires_at DATETIME NOT NULL,
                rotated_at DATETIME NULL,
                revoked_at DATETIME NULL,
                revoke_reason VARCHAR(32) NULL,
                UNIQUE KEY uq_token_hash (token_hash),
                KEY idx_family (family_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$ensured = true;
    }

    // ---------------------------------------------------------------- issue / refresh / revoke

    /**
     * @param array{device_id?: string, device_name?: string, platform?: string, app_version?: string} $device
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public function issue(int $userId, array $device): array
    {
        $familyId = bin2hex(random_bytes(16));

        return $this->createPair($userId, $familyId, $device);
    }

    /**
     * @param array{device_id?: string, device_name?: string, platform?: string, app_version?: string} $device
     * @return array{ok: bool, tokens?: array<string, mixed>, user_id?: int, error?: string}
     */
    public function refresh(string $refreshToken, array $device = []): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $refreshToken)) {
            return ['ok' => false, 'error' => 'invalid_refresh_token'];
        }
        $hash = hash('sha256', $refreshToken);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM api_refresh_tokens WHERE token_hash = ? FOR UPDATE');
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->rollBack();

                return ['ok' => false, 'error' => 'invalid_refresh_token'];
            }
            if ($row['revoked_at'] !== null || $row['rotated_at'] !== null) {
                // Повторное использование уже ротированного токена — возможна кража: отзываем семейство.
                if ($row['rotated_at'] !== null && $row['revoked_at'] === null) {
                    $this->revokeFamilyInternal((string) $row['family_id'], 'reuse_detected');
                }
                $this->db->commit();

                return ['ok' => false, 'error' => 'invalid_refresh_token'];
            }
            if (strtotime((string) $row['expires_at']) < time()) {
                $this->db->rollBack();

                return ['ok' => false, 'error' => 'refresh_token_expired'];
            }
            $deviceId = (string) ($device['device_id'] ?? '');
            if ($deviceId !== '' && $row['device_id'] !== '' && !hash_equals((string) $row['device_id'], $deviceId)) {
                $this->revokeFamilyInternal((string) $row['family_id'], 'device_mismatch');
                $this->db->commit();

                return ['ok' => false, 'error' => 'invalid_refresh_token'];
            }

            $this->db->prepare('UPDATE api_refresh_tokens SET rotated_at = NOW(), last_used_at = NOW() WHERE id = ?')
                ->execute([(int) $row['id']]);
            $tokens = $this->createPair((int) $row['user_id'], (string) $row['family_id'], [
                'device_id' => (string) $row['device_id'],
                'device_name' => (string) ($device['device_name'] ?? $row['device_name']),
                'platform' => (string) ($device['platform'] ?? $row['platform']),
                'app_version' => (string) ($device['app_version'] ?? $row['app_version']),
            ]);
            $this->db->commit();

            return ['ok' => true, 'tokens' => $tokens, 'user_id' => (int) $row['user_id']];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function revokeByRefreshToken(string $refreshToken, string $reason = 'logout'): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $refreshToken)) {
            return;
        }
        $stmt = $this->db->prepare('SELECT family_id FROM api_refresh_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $refreshToken)]);
        $family = $stmt->fetchColumn();
        if (is_string($family)) {
            $this->revokeFamilyInternal($family, $reason);
        }
    }

    public function revokeFamily(string $familyId, string $reason = 'logout'): void
    {
        $this->revokeFamilyInternal($familyId, $reason);
    }

    public function revokeAllForUser(int $userId, string $reason = 'logout_all'): void
    {
        $this->db->prepare(
            'UPDATE api_refresh_tokens SET revoked_at = NOW(), revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL'
        )->execute([$reason, $userId]);
    }

    /** Сколько секунд назад был выполнен вход, создавший это семейство токенов (устройство). */
    public function familyAgeSeconds(string $familyId): ?int
    {
        $stmt = $this->db->prepare('SELECT MIN(created_at) FROM api_refresh_tokens WHERE family_id = ?');
        $stmt->execute([$familyId]);
        $created = $stmt->fetchColumn();

        return is_string($created) ? max(0, time() - (int) strtotime($created)) : null;
    }

    /** @return list<array<string, mixed>> */
    public function activeSessions(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT family_id, device_name, platform, app_version, MIN(created_at) AS created_at, MAX(COALESCE(last_used_at, created_at)) AS last_used_at
             FROM api_refresh_tokens
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW()
             GROUP BY family_id, device_name, platform, app_version
             ORDER BY last_used_at DESC'
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------- access token

    /**
     * @return array{ok: bool, user_id?: int, family_id?: string, error?: string}
     */
    public function verifyAccess(string $token): array
    {
        $payload = $this->decodeSigned($token, 'access');
        if ($payload === null) {
            return ['ok' => false, 'error' => 'invalid_token'];
        }
        if ((int) ($payload['exp'] ?? 0) < time()) {
            return ['ok' => false, 'error' => 'token_expired'];
        }
        $familyId = (string) ($payload['fid'] ?? '');
        $userId = (int) ($payload['uid'] ?? 0);
        $stmt = $this->db->prepare(
            'SELECT 1 FROM api_refresh_tokens
             WHERE family_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$familyId, $userId]);
        if (!$stmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'session_revoked'];
        }

        return ['ok' => true, 'user_id' => $userId, 'family_id' => $familyId];
    }

    // ---------------------------------------------------------------- 2FA challenge

    public function issueChallenge(int $userId, string $deviceId): string
    {
        return $this->sign([
            'typ' => '2fa',
            'uid' => $userId,
            'dev' => $deviceId,
            'exp' => time() + self::CHALLENGE_TTL,
            'n' => bin2hex(random_bytes(8)),
        ]);
    }

    /** @return int|null user id */
    public function verifyChallenge(string $token, string $deviceId): ?int
    {
        $payload = $this->decodeSigned($token, '2fa');
        if ($payload === null || (int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        if ((string) ($payload['dev'] ?? '') !== $deviceId) {
            return null;
        }

        return (int) ($payload['uid'] ?? 0) ?: null;
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param array<string, string> $device
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    private function createPair(int $userId, string $familyId, array $device): array
    {
        $refresh = bin2hex(random_bytes(32));
        $this->db->prepare(
            'INSERT INTO api_refresh_tokens (user_id, family_id, token_hash, device_id, device_name, platform, app_version, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $familyId,
            hash('sha256', $refresh),
            mb_substr((string) ($device['device_id'] ?? ''), 0, 64),
            mb_substr((string) ($device['device_name'] ?? ''), 0, 120),
            mb_substr((string) ($device['platform'] ?? ''), 0, 16),
            mb_substr((string) ($device['app_version'] ?? ''), 0, 32),
            date('Y-m-d H:i:s', time() + self::REFRESH_TTL),
        ]);

        $access = $this->sign([
            'typ' => 'access',
            'uid' => $userId,
            'fid' => $familyId,
            'iat' => time(),
            'exp' => time() + self::ACCESS_TTL,
        ]);

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
        ];
    }

    private function revokeFamilyInternal(string $familyId, string $reason): void
    {
        $this->db->prepare(
            'UPDATE api_refresh_tokens SET revoked_at = NOW(), revoke_reason = ? WHERE family_id = ? AND revoked_at IS NULL'
        )->execute([$reason, $familyId]);
    }

    /** @param array<string, mixed> $payload */
    private function sign(array $payload): string
    {
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64(hash_hmac('sha256', 'v1.' . $body, $this->secret(), true));

        return 'v1.' . $body . '.' . $sig;
    }

    /** @return array<string, mixed>|null */
    private function decodeSigned(string $token, string $type): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', 'v1.' . $parts[1], $this->secret(), true));
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $json = json_decode((string) self::unb64($parts[1]), true);
        if (!is_array($json) || ($json['typ'] ?? '') !== $type) {
            return null;
        }

        return $json;
    }

    private function secret(): string
    {
        if ($this->secret !== null && $this->secret !== '') {
            return $this->secret;
        }
        $env = (string) (getenv('MOBILE_API_SECRET') ?: '');
        if (strlen($env) >= 32) {
            return $this->secret = $env;
        }
        // Секрет хранится вне web root (storage/ закрыт .htaccess и .gitignore)
        $dir = dirname(__DIR__, 3) . '/storage/keys';
        $path = $dir . '/mobile_api.key';
        if (!is_file($path)) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $fh = @fopen($path, 'x');
            if ($fh !== false) {
                fwrite($fh, bin2hex(random_bytes(32)));
                fclose($fh);
                @chmod($path, 0600);
            }
        }
        $key = trim((string) @file_get_contents($path));
        if (strlen($key) < 32) {
            throw new \RuntimeException('mobile_api_secret_unavailable');
        }

        return $this->secret = $key;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
