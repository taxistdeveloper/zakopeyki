<?php

namespace App\Core;

use App\Core\Database;
use PDO;

class Model
{
    protected PDO $db;
    protected string $table;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function all(string $order = 'id DESC'): array
    {
        return $this->db->query("SELECT * FROM {$this->table} ORDER BY {$order}")->fetchAll();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Таблица могла быть создана раньше без PRIMARY KEY / AUTO_INCREMENT.
     * CREATE TABLE IF NOT EXISTS такую схему не исправляет.
     */
    protected function ensurePrimaryAutoIncrement(string $table, string $type = 'INT UNSIGNED NOT NULL'): void
    {
        $table = str_replace('`', '', $table);
        $stmt = $this->db->prepare(
            'SELECT COLUMN_KEY, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, 'id']);
        $col = $stmt->fetch();
        if (!$col) {
            return;
        }
        if (($col['COLUMN_KEY'] ?? '') !== 'PRI') {
            $this->db->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
        }
        if (!str_contains(strtolower((string) ($col['EXTRA'] ?? '')), 'auto_increment')) {
            $this->db->exec("ALTER TABLE `{$table}` MODIFY `id` {$type} AUTO_INCREMENT");
        }
    }

    protected function ensureIndex(string $table, string $name, string $ddl): void
    {
        $table = str_replace('`', '', $table);
        $stmt = $this->db->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $name]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $this->db->exec("ALTER TABLE `{$table}` ADD {$ddl}");
    }
}
