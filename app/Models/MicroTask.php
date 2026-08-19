<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDOException;

class MicroTask extends Model
{
    protected string $table = 'micro_tasks';
    private static bool $ensured = false;

    public function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `micro_categories` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `is_unskilled_only` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `micro_tasks` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `customer_id` INT UNSIGNED NOT NULL,
                `executor_id` INT UNSIGNED NULL DEFAULT NULL,
                `category_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `description` TEXT NOT NULL,
                `address` VARCHAR(255) NOT NULL,
                `initial_price` INT UNSIGNED NOT NULL,
                `final_price` INT UNSIGNED NULL DEFAULT NULL,
                `platform_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
                `completion_pin` VARCHAR(4) NOT NULL,
                `acquiring_rrn` VARCHAR(64) NULL DEFAULT NULL,
                `image` VARCHAR(255) NULL DEFAULT NULL,
                `images` TEXT NULL DEFAULT NULL,
                `status` ENUM('open', 'locked', 'in_progress', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'open',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                INDEX `idx_customer` (`customer_id`),
                INDEX `idx_executor` (`executor_id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `micro_task_offers` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `task_id` INT UNSIGNED NOT NULL,
                `executor_id` INT UNSIGNED NOT NULL,
                `offer_type` ENUM('accept', 'discount_20', 'raise_20', 'custom') NOT NULL,
                `proposed_price` INT UNSIGNED NOT NULL,
                `response_fee_status` ENUM('held', 'charged', 'refunded') NOT NULL DEFAULT 'held',
                `status` ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_task_executor` (`task_id`, `executor_id`),
                INDEX `idx_executor_status` (`executor_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $count = (int) $this->db->query('SELECT COUNT(*) FROM `micro_categories`')->fetchColumn();
        if ($count === 0) {
            $this->db->exec(
                "INSERT INTO `micro_categories` (`id`, `name`, `is_unskilled_only`) VALUES
                (1, 'Разгрузка и погрузка', 1),
                (2, 'Вынос мусора и мебели', 1),
                (3, 'Раздача листовок и расклейка', 1),
                (4, 'Курьерские поручения', 1),
                (5, 'Уборка и клининг', 1),
                (6, 'Помощь в саду и на участке', 1)"
            );
        }

        $this->ensureColumn('micro_tasks', 'image', 'VARCHAR(255) NULL DEFAULT NULL');
        $this->ensureColumn('micro_tasks', 'images', 'TEXT NULL DEFAULT NULL');

        self::$ensured = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCategoriesWithCounts(): array
    {
        $this->ensureSchema();
        $rows = $this->db->query(
            'SELECT c.`id`, c.`name`, c.`is_unskilled_only`, c.`created_at`,
                    (SELECT COUNT(*) FROM `micro_tasks` t WHERE t.`category_id` = c.`id`) AS `task_count`
             FROM `micro_categories` c
             ORDER BY c.`id`'
        )->fetchAll();

        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['is_unskilled_only'] = (int) $row['is_unskilled_only'];
            $row['task_count'] = (int) $row['task_count'];
            return $row;
        }, $rows);
    }

    public function findCategory(int $id): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT * FROM `micro_categories` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function categoryNameExists(string $name, ?int $exceptId = null): bool
    {
        $this->ensureSchema();
        $sql = 'SELECT `id` FROM `micro_categories` WHERE LOWER(`name`) = LOWER(:name)';
        $params = ['name' => $name];
        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :id';
            $params['id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public function createCategory(string $name, bool $unskilledOnly = true): int
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'INSERT INTO `micro_categories` (`name`, `is_unskilled_only`) VALUES (:name, :flag)'
        );
        $stmt->execute([
            'name' => $name,
            'flag' => $unskilledOnly ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateCategory(int $id, string $name, bool $unskilledOnly): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'UPDATE `micro_categories` SET `name` = :name, `is_unskilled_only` = :flag WHERE `id` = :id'
        );
        return $stmt->execute([
            'id' => $id,
            'name' => $name,
            'flag' => $unskilledOnly ? 1 : 0,
        ]);
    }

    public function categoryTaskCount(int $id): int
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `micro_tasks` WHERE `category_id` = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteCategory(int $id): bool
    {
        $this->ensureSchema();
        if ($this->categoryTaskCount($id) > 0) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM `micro_categories` WHERE `id` = :id');
        return $stmt->execute(['id' => $id]);
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $this->db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException) {
        }
    }
}
