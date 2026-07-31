<?php

namespace App\Models;

use App\Core\Model;

class Setting extends Model
{
    protected string $table = 'settings';
    private static bool $ensured = false;

    /** @var array<string, string|null> */
    private static array $cache = [];

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
            "CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(64) NOT NULL PRIMARY KEY,
                `value` TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            $cached = self::$cache[$key];
            return $cached === null ? $default : $cached;
        }

        $stmt = $this->db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            self::$cache[$key] = null;
            return $default;
        }

        $value = (string) $row['value'];
        self::$cache[$key] = $value;
        return $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([$key, $value]);
        self::$cache[$key] = $value;
    }

    public function getBool(string $key, ?bool $default = null): ?bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}
