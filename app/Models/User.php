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
            'permissions' => "TEXT DEFAULT NULL COMMENT 'JSON permissions for manager' AFTER role",
            'site_access' => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Early access while stub_mode' AFTER permissions",
            'referred_by' => 'INT UNSIGNED DEFAULT NULL AFTER site_access',
            'iin' => 'VARCHAR(12) DEFAULT NULL AFTER phone',
            'aml_status' => 'VARCHAR(20) DEFAULT NULL AFTER iin',
            'aml_checked_at' => 'DATETIME DEFAULT NULL AFTER aml_status',
        ];

        foreach ($needed as $col => $def) {
            $exists = $this->db->query("SHOW COLUMNS FROM users LIKE " . $this->db->quote($col))->fetch();
            if (!$exists) {
                $this->db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            }
        }

        // role: user | manager | admin
        try {
            $col = $this->db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            $type = strtolower((string) ($col['Type'] ?? ''));
            if ($col && strpos($type, 'manager') === false) {
                $this->db->exec(
                    "ALTER TABLE users MODIFY COLUMN role ENUM('user','manager','admin') NOT NULL DEFAULT 'user'"
                );
            }
        } catch (\Throwable $e) {
            // ignore
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

        try {
            $this->db->exec('CREATE INDEX users_referred_by_idx ON users (referred_by)');
        } catch (\Throwable $e) {
            // index may already exist
        }

        try {
            $this->db->exec('CREATE UNIQUE INDEX users_iin_unique ON users (iin)');
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

    /** Resolve referrer by login or numeric user id. */
    public function findByReferralCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if (ctype_digit($code)) {
            $user = $this->find((int) $code);
            return $user ?: null;
        }
        return $this->findByLogin($code);
    }

    public function referralCodeFor(array $user): string
    {
        $login = trim((string) ($user['login'] ?? ''));
        if ($login !== '') {
            return $login;
        }
        return (string) (int) ($user['id'] ?? 0);
    }

    public function referralUrlFor(array $user): string
    {
        $code = rawurlencode($this->referralCodeFor($user));
        $path = \App\Helpers\ProductHelper::url('/register?ref=' . $code);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . $path;
    }

    public function setReferredBy(int $userId, int $referrerId): bool
    {
        if ($userId <= 0 || $referrerId <= 0 || $userId === $referrerId) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE users SET referred_by = ? WHERE id = ? AND (referred_by IS NULL OR referred_by = 0)'
        );
        $stmt->execute([$referrerId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function countReferrals(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM users WHERE referred_by = ?');
        $stmt->execute([$userId]);
        return (int) ($stmt->fetch()['c'] ?? 0);
    }

    /** @return list<array<string, mixed>> */
    public function referrals(int $userId, int $limit = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, login, avatar, avatar_file, created_at
             FROM users
             WHERE referred_by = ?
             ORDER BY id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(100, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
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

    /** Users registered on or after the given datetime (Y-m-d H:i:s or relative via SQL). */
    public function countRegisteredSince(string $sinceSql = 'CURDATE()'): int
    {
        // Allow only safe built-in SQL expressions for the boundary
        $allowed = [
            'CURDATE()' => true,
            '(CURDATE() - INTERVAL 7 DAY)' => true,
            '(NOW() - INTERVAL 24 HOUR)' => true,
            '(NOW() - INTERVAL 7 DAY)' => true,
        ];
        if (!isset($allowed[$sinceSql])) {
            $sinceSql = 'CURDATE()';
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM users WHERE created_at >= {$sinceSql}"
        )->fetchColumn();
    }

    /** @return array{total:int, today:int, week:int, site_access:int} */
    public function registrationStats(): array
    {
        return [
            'total' => $this->countAll(),
            'today' => $this->countRegisteredSince('CURDATE()'),
            'week' => $this->countRegisteredSince('(CURDATE() - INTERVAL 7 DAY)'),
            'site_access' => $this->countWithSiteAccess(),
        ];
    }

    public static function normalizeRole(string $role): string
    {
        return in_array($role, ['user', 'manager', 'admin'], true) ? $role : 'user';
    }

    /** @param list<string>|mixed $permissions */
    public static function encodePermissions(mixed $permissions, string $role): ?string
    {
        if ($role !== 'manager') {
            return null;
        }
        $list = \App\Core\Auth::normalizePermissions($permissions, 'manager');
        return json_encode($list, JSON_UNESCAPED_UNICODE);
    }

    /** @return list<array<string, mixed>> */
    public function listForAdmin(?string $role = null, ?string $q = null, ?bool $siteAccess = null): array
    {
        $sql = 'SELECT id, name, first_name, last_name, login, email, phone, iin, aml_status, role, permissions, site_access,
                       avatar, avatar_file, created_at, two_factor_enabled, google_id
                FROM users WHERE 1=1';
        $params = [];

        if (in_array((string) $role, ['admin', 'manager', 'user'], true)) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }

        if ($siteAccess === true) {
            $sql .= ' AND site_access = 1 AND role != \'admin\'';
        } elseif ($siteAccess === false) {
            $sql .= ' AND (site_access = 0 OR site_access IS NULL) AND role != \'admin\'';
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

    public function setSiteAccess(int $userId, bool $allowed): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET site_access = ? WHERE id = ?');
        return $stmt->execute([$allowed ? 1 : 0, $userId]);
    }

    public function setAmlStatus(int $userId, string $status, ?string $iin = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET aml_status = ?, iin = COALESCE(?, iin), aml_checked_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([$status, $iin !== null && $iin !== '' ? $iin : null, $userId]);
    }

    public function saveAmlClear(int $userId, string $iin): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET iin = ?, aml_status = ?, aml_checked_at = NOW() WHERE id = ?'
        );
        return $stmt->execute([$iin, 'clear', $userId]);
    }

    public function countWithSiteAccess(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM users WHERE site_access = 1 AND role != 'admin'"
        )->fetchColumn();
    }

    public function updateRole(int $userId, string $role, ?array $permissions = null): bool
    {
        $role = self::normalizeRole($role);
        $permsJson = self::encodePermissions($permissions ?? [], $role);
        $stmt = $this->db->prepare('UPDATE users SET role = ?, permissions = ? WHERE id = ?');
        return $stmt->execute([$role, $permsJson, $userId]);
    }

    public function updatePermissions(int $userId, array $permissions): bool
    {
        $user = $this->find($userId);
        if (!$user || ($user['role'] ?? '') !== 'manager') {
            return false;
        }
        $permsJson = self::encodePermissions($permissions, 'manager');
        $stmt = $this->db->prepare('UPDATE users SET permissions = ? WHERE id = ?');
        return $stmt->execute([$permsJson, $userId]);
    }

    /** Создание пользователя админом (можно задать роль и права менеджера). */
    public function createByAdmin(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = self::normalizeRole((string) ($data['role'] ?? 'user'));
        $phone = trim((string) ($data['phone'] ?? ''));
        $login = trim((string) ($data['login'] ?? ''));
        $permsJson = self::encodePermissions($data['permissions'] ?? [], $role);

        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $first = $parts[0] ?? $name;
        $last = $parts[1] ?? '';
        if ($login === '') {
            $login = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $first . rand(100, 999)) ?: ('user' . rand(1000, 9999)));
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (name, first_name, last_name, login, email, password, role, permissions, avatar, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $first,
            $last,
            $login,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $permsJson,
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
