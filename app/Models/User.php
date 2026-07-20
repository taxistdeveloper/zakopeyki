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
        ];

        foreach ($needed as $col => $def) {
            $exists = $this->db->query("SHOW COLUMNS FROM users LIKE " . $this->db->quote($col))->fetch();
            if (!$exists) {
                $this->db->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            }
        }

        // unique login if column exists and index missing — soft, ignore fail
        try {
            $this->db->exec('CREATE UNIQUE INDEX users_login_unique ON users (login)');
        } catch (\Throwable $e) {
            // index may already exist
        }

        self::$ensured = true;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
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

        $stmt = $this->db->prepare(
            'INSERT INTO users (name, first_name, last_name, login, email, password, role, avatar, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $first,
            $last,
            $login,
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['role'] ?? 'user',
            mb_strtoupper(mb_substr($name, 0, 1)),
            $data['phone'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
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

    public function togglePhoneVisible(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET phone_visible = IF(phone_visible=1,0,1) WHERE id = ?');
        return $stmt->execute([$userId]);
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $user = $this->find($userId);
        if (!$user || empty($user['password'])) {
            return false;
        }
        return password_verify($password, $user['password']);
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
