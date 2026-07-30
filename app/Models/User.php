<?php

namespace App\Models;

use App\Core\Model;

class User extends Model
{
    protected string $table = 'users';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureColumns();
    }

    private function ensureColumns(): void
    {
        if (self::$ensured) {
            return;
        }

        $needed = [
            'avatar_file' => 'VARCHAR(255) DEFAULT NULL AFTER avatar',
            'first_name' => 'VARCHAR(100) DEFAULT NULL AFTER name',
            'last_name' => 'VARCHAR(100) DEFAULT NULL AFTER first_name',
            'login' => 'VARCHAR(50) DEFAULT NULL AFTER last_name',
            'bio' => 'TEXT DEFAULT NULL AFTER phone',
            'phone_visible' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER bio',
            'google_id' => 'VARCHAR(64) DEFAULT NULL AFTER email',
            'two_factor_secret' => 'VARCHAR(64) DEFAULT NULL AFTER password',
            'two_factor_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_secret',
            'two_factor_recovery_codes' => 'TEXT DEFAULT NULL AFTER two_factor_enabled',
            'password_reset_token' => 'VARCHAR(64) DEFAULT NULL AFTER two_factor_recovery_codes',
            'password_reset_expires' => 'DATETIME DEFAULT NULL AFTER password_reset_token',
        ];

        foreach ($needed as $col => $def) {
            $exists = $this->db->query("SHOW COLUMNS FROM users LIKE " . $this->db->quote($col))->fetch();
            if (!$exists) {
                $this->db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            }
        }

        // OAuth-пользователи могут не иметь пароля
        try {
            $col = $this->db->query("SHOW COLUMNS FROM users LIKE 'password'")->fetch();
            if ($col && strtoupper((string) ($col['Null'] ?? '')) === 'NO') {
                $this->db->exec('ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NULL');
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // unique login if column exists and index missing — soft, ignore fail
        try {
            $this->db->exec('CREATE UNIQUE INDEX users_login_unique ON users (login)');
        } catch (\Throwable $e) {
            // index may already exist
        }

        try {
            $this->db->exec('CREATE UNIQUE INDEX users_google_id_unique ON users (google_id)');
        } catch (\Throwable $e) {
            // index may already exist
        }

        self::$ensured = true;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?)');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByGoogleId(string $googleId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE google_id = ?');
        $stmt->execute([$googleId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByLogin(string $login): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE login = ?');
        $stmt->execute([$login]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $name = $data['name'];
        $parts = preg_split('/\s+/', trim($name), 2);
        $first = $parts[0] ?? $name;
        $last = $parts[1] ?? '';
        $login = $data['login'] ?? strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $first . ($data['id'] ?? rand(100, 999))));
        $passwordHash = array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== ''
            ? password_hash($data['password'], PASSWORD_DEFAULT)
            : null;

        $stmt = $this->db->prepare(
            'INSERT INTO users (name, first_name, last_name, login, email, google_id, password, role, avatar, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $first,
            $last,
            $login,
            $data['email'],
            $data['google_id'] ?? null,
            $passwordHash,
            'user',
            mb_strtoupper(mb_substr($name, 0, 1)),
            $data['phone'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function linkGoogleId(int $userId, string $googleId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET google_id = ? WHERE id = ? AND (google_id IS NULL OR google_id = "")');
        return $stmt->execute([$googleId, $userId]);
    }

    public function updateProfile(int $userId, array $data): bool
    {
        $first = trim($data['first_name'] ?? '');
        $last = trim($data['last_name'] ?? '');
        $login = trim($data['login'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $name = trim($first . ' ' . $last) ?: ($data['name'] ?? 'User');
        $avatarLetter = mb_strtoupper(mb_substr($first !== '' ? $first : $name, 0, 1));

        $stmt = $this->db->prepare(
            'UPDATE users SET name=?, first_name=?, last_name=?, login=?, phone=?, avatar=? WHERE id=?'
        );
        return $stmt->execute([$name, $first, $last, $login, $phone ?: null, $avatarLetter, $userId]);
    }

    public function updateBio(int $userId, string $bio): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET bio = ? WHERE id = ?');
        return $stmt->execute([mb_substr($bio, 0, 2000), $userId]);
    }

    public function updateAvatar(int $userId, string $filename): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET avatar_file = ? WHERE id = ?');
        return $stmt->execute([$filename, $userId]);
    }

    public function updatePassword(int $userId, string $password): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        return $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    /** Создаёт одноразовый токен сброса (plain возвращается один раз). TTL в секундах. */
    public function createPasswordResetToken(int $userId, int $ttlSeconds = 3600): string
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $expires = date('Y-m-d H:i:s', time() + max(60, $ttlSeconds));
        $stmt = $this->db->prepare(
            'UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?'
        );
        $stmt->execute([$hash, $expires, $userId]);
        return $plain;
    }

    public function findByPasswordResetToken(string $plainToken): ?array
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || !preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            return null;
        }
        $hash = hash('sha256', $plainToken);
        $stmt = $this->db->prepare(
            'SELECT * FROM users WHERE password_reset_token = ? AND password_reset_expires IS NOT NULL AND password_reset_expires >= NOW()'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function clearPasswordResetToken(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?'
        );
        return $stmt->execute([$userId]);
    }

    public function resetPasswordWithToken(string $plainToken, string $password): bool
    {
        $user = $this->findByPasswordResetToken($plainToken);
        if (!$user) {
            return false;
        }
        $userId = (int) $user['id'];
        $ok = $this->updatePassword($userId, $password);
        if ($ok) {
            $this->clearPasswordResetToken($userId);
        }
        return $ok;
    }

    public function togglePhoneVisible(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET phone_visible = IF(phone_visible=1,0,1) WHERE id = ?');
        return $stmt->execute([$userId]);
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function countAdmins(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function listForAdmin(?string $role = null, ?string $q = null): array
    {
        $sql = 'SELECT id, name, first_name, last_name, login, email, phone, role, avatar, avatar_file, created_at,
                       two_factor_enabled, google_id
                FROM users WHERE 1=1';
        $params = [];

        if ($role === 'admin' || $role === 'user') {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }

        $q = $q !== null ? trim($q) : '';
        if ($q !== '') {
            $sql .= ' AND (
                name LIKE ? OR email LIKE ? OR login LIKE ?
                OR first_name LIKE ? OR last_name LIKE ? OR phone LIKE ?
                OR CAST(id AS CHAR) = ?
            )';
            $like = '%' . $q . '%';
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $q]);
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function updateRole(int $userId, string $role): bool
    {
        if (!in_array($role, ['user', 'admin'], true)) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE users SET role = ? WHERE id = ?');
        return $stmt->execute([$role, $userId]);
    }

    /** Создание пользователя админом (можно задать роль). */
    public function createByAdmin(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = ($data['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $phone = trim((string) ($data['phone'] ?? ''));
        $login = trim((string) ($data['login'] ?? ''));

        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $first = $parts[0] ?? $name;
        $last = $parts[1] ?? '';
        if ($login === '') {
            $login = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $first . rand(100, 999)) ?: ('user' . rand(1000, 9999)));
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (name, first_name, last_name, login, email, password, role, avatar, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $first,
            $last,
            $login,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            mb_strtoupper(mb_substr($name !== '' ? $name : 'U', 0, 1)),
            $phone !== '' ? $phone : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $user = $this->find($userId);
        if (!$user || empty($user['password'])) {
            return false;
        }
        return password_verify($password, $user['password']);
    }

    public function hasTwoFactor(array $user): bool
    {
        return !empty($user['two_factor_enabled']) && !empty($user['two_factor_secret']);
    }

    public function enableTwoFactor(int $userId, string $secret, array $plainRecoveryCodes): bool
    {
        $hashed = array_map(
            static fn (string $code) => \App\Helpers\Totp::hashRecoveryCode($code),
            $plainRecoveryCodes
        );
        $stmt = $this->db->prepare(
            'UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_recovery_codes = ? WHERE id = ?'
        );
        return $stmt->execute([$secret, json_encode($hashed, JSON_UNESCAPED_UNICODE), $userId]);
    }

    public function disableTwoFactor(int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_recovery_codes = NULL WHERE id = ?'
        );
        return $stmt->execute([$userId]);
    }

    public function consumeRecoveryCode(int $userId, string $code): bool
    {
        $user = $this->find($userId);
        if (!$user) {
            return false;
        }
        $codes = json_decode((string) ($user['two_factor_recovery_codes'] ?? '[]'), true);
        if (!is_array($codes) || $codes === []) {
            return false;
        }
        $index = \App\Helpers\Totp::verifyRecoveryCode($code, $codes);
        if ($index === null) {
            return false;
        }
        unset($codes[$index]);
        $codes = array_values($codes);
        $stmt = $this->db->prepare('UPDATE users SET two_factor_recovery_codes = ? WHERE id = ?');
        return $stmt->execute([json_encode($codes, JSON_UNESCAPED_UNICODE), $userId]);
    }

    public function deleteAccount(int $userId): bool
    {
        $user = $this->find($userId);
        if (!$user) {
            return false;
        }

        $root = dirname(__DIR__, 2);

        if (!empty($user['avatar_file'])) {
            $avatar = $root . '/public/uploads/avatars/' . basename($user['avatar_file']);
            if (is_file($avatar)) {
                @unlink($avatar);
            }
        }

        try {
            $stmt = $this->db->prepare('SELECT image FROM stories WHERE user_id = ? AND image IS NOT NULL AND image != ""');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $row) {
                $img = $root . '/public/uploads/stories/' . basename($row['image']);
                if (is_file($img)) {
                    @unlink($img);
                }
            }
        } catch (\Throwable $e) {
            // table may not exist on old installs
        }

        try {
            $stmt = $this->db->prepare('SELECT image, images FROM products WHERE user_id = ?');
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll() as $row) {
                $files = [];
                if (!empty($row['images'])) {
                    $decoded = json_decode((string) $row['images'], true);
                    if (is_array($decoded)) {
                        $files = $decoded;
                    }
                }
                if (!$files && !empty($row['image'])) {
                    $files = [$row['image']];
                }
                foreach ($files as $file) {
                    if (!is_string($file) || $file === '') {
                        continue;
                    }
                    $img = $root . '/public/uploads/products/' . basename($file);
                    if (is_file($img)) {
                        @unlink($img);
                    }
                }
            }
        } catch (\Throwable $e) {
            // column may not exist yet
        }

        $del = $this->db->prepare('DELETE FROM users WHERE id = ?');
        return $del->execute([$userId]);
    }
}
