<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;
use PDOException;

class MicroTask extends Model
{
    protected string $table = 'micro_tasks';
    private static bool $ensured = false;
    private static bool $ensuring = false;

    public function ensureSchema(): void
    {
        if (self::$ensured || self::$ensuring) {
            return;
        }
        self::$ensuring = true;

        try {
            $this->ensureSchemaInner();
            self::$ensured = true;
        } finally {
            self::$ensuring = false;
        }
    }

    private function ensureSchemaInner(): void
    {

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS `micro_categories` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `parent_id` INT UNSIGNED NULL DEFAULT NULL,
                `code` VARCHAR(40) NULL DEFAULT NULL,
                `name` VARCHAR(180) NOT NULL,
                `is_unskilled_only` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_micro_cat_code` (`code`),
                INDEX `idx_micro_cat_parent` (`parent_id`)
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

        $this->ensureColumn('micro_categories', 'parent_id', 'INT UNSIGNED NULL DEFAULT NULL');
        $this->ensureColumn('micro_categories', 'code', 'VARCHAR(40) NULL DEFAULT NULL');
        try {
            $this->db->exec('ALTER TABLE `micro_categories` MODIFY `name` VARCHAR(180) NOT NULL');
        } catch (PDOException) {
        }
        try {
            $this->db->exec('ALTER TABLE `micro_categories` ADD UNIQUE KEY `uniq_micro_cat_code` (`code`)');
        } catch (PDOException) {
        }
        try {
            $this->db->exec('ALTER TABLE `micro_categories` ADD INDEX `idx_micro_cat_parent` (`parent_id`)');
        } catch (PDOException) {
        }

        $this->ensureColumn('micro_tasks', 'image', 'VARCHAR(255) NULL DEFAULT NULL');
        $this->ensureColumn('micro_tasks', 'images', 'TEXT NULL DEFAULT NULL');

        try {
            $this->seedCatalog();
        } catch (PDOException) {
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCategoriesWithCounts(): array
    {
        $this->ensureSchema();
        $rows = $this->db->query(
            'SELECT c.`id`, c.`parent_id`, c.`code`, c.`name`, c.`is_unskilled_only`, c.`created_at`,
                    p.`name` AS `parent_name`,
                    (SELECT COUNT(*) FROM `micro_tasks` t WHERE t.`category_id` = c.`id`) AS `task_count`,
                    (SELECT COUNT(*) FROM `micro_categories` ch WHERE ch.`parent_id` = c.`id`) AS `child_count`
             FROM `micro_categories` c
             LEFT JOIN `micro_categories` p ON p.`id` = c.`parent_id`
             ORDER BY c.`id`'
        )->fetchAll();

        $byParent = [];
        foreach ($rows as $row) {
            $pid = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $byParent[$pid][] = $row;
        }

        $flat = [];
        $seen = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$flat, &$seen, $byParent): void {
            if ($depth > 8) {
                return;
            }
            foreach ($byParent[$parentId] ?? [] as $row) {
                $id = (int) $row['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row['id'] = $id;
                $row['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
                $row['is_unskilled_only'] = (int) $row['is_unskilled_only'];
                $row['task_count'] = (int) $row['task_count'];
                $row['child_count'] = (int) $row['child_count'];
                $row['depth'] = $depth;
                $flat[] = $row;
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $flat;
    }

    /**
     * @return list<array{id: int, name: string, parent_id: int|null, children: list<array<string, mixed>>}>
     */
    public function categoryTree(): array
    {
        $this->ensureSchema();
        $rows = $this->db->query(
            'SELECT `id`, `parent_id`, `name` FROM `micro_categories` ORDER BY `id`'
        )->fetchAll();

        $nodes = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $nodes[$id] = [
                'id' => $id,
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'name' => (string) $row['name'],
                'children' => [],
            ];
        }

        $childrenOf = [];
        $roots = [];
        foreach ($nodes as $id => $node) {
            $pid = $node['parent_id'];
            if ($pid !== null && isset($nodes[$pid])) {
                $childrenOf[$pid][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $build = function (int $id, int $depth = 0) use (&$build, $nodes, $childrenOf): array {
            $kids = [];
            if ($depth < 8) {
                foreach ($childrenOf[$id] ?? [] as $childId) {
                    $kids[] = $build($childId, $depth + 1);
                }
            }
            return [
                'id' => $nodes[$id]['id'],
                'parent_id' => $nodes[$id]['parent_id'],
                'name' => $nodes[$id]['name'],
                'children' => $kids,
            ];
        };

        $tree = [];
        foreach ($roots as $id) {
            $tree[] = $build($id);
        }
        return $tree;
    }

    /**
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $this->ensureSchema();
        $ids = [$id];
        $frontier = [$id];
        while ($frontier !== []) {
            $placeholders = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->db->prepare(
                "SELECT `id` FROM `micro_categories` WHERE `parent_id` IN ({$placeholders})"
            );
            $stmt->execute($frontier);
            $frontier = [];
            while ($row = $stmt->fetch()) {
                $childId = (int) $row['id'];
                $ids[] = $childId;
                $frontier[] = $childId;
            }
        }

        return $ids;
    }

    public function categoryHasChildren(int $id): bool
    {
        $this->ensureSchema();
        return $this->countChildren($id) > 0;
    }

    public function categoryTaskCount(int $id): int
    {
        $this->ensureSchema();
        return $this->countTasks($id);
    }

    private function countChildren(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `micro_categories` WHERE `parent_id` = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    private function countTasks(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `micro_tasks` WHERE `category_id` = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function categoryDepth(int $id): int
    {
        $depth = 0;
        $current = $this->findCategory($id);
        while ($current && $current['parent_id'] !== null && $current['parent_id'] !== '') {
            $depth++;
            $parentId = (int) $current['parent_id'];
            if ($parentId === $id || $depth > 8) {
                break;
            }
            $current = $this->findCategory($parentId);
        }
        return $depth;
    }

    public function findCategory(int $id): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT * FROM `micro_categories` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function categoryNameExists(string $name, ?int $exceptId = null, ?int $parentId = null): bool
    {
        $this->ensureSchema();
        $sql = 'SELECT `id` FROM `micro_categories` WHERE LOWER(`name`) = LOWER(:name)';
        $params = ['name' => $name];
        if ($parentId === null) {
            $sql .= ' AND `parent_id` IS NULL';
        } else {
            $sql .= ' AND `parent_id` = :parent_id';
            $params['parent_id'] = $parentId;
        }
        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :id';
            $params['id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public function createCategory(string $name, bool $unskilledOnly = true, ?int $parentId = null): int
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'INSERT INTO `micro_categories` (`parent_id`, `name`, `is_unskilled_only`) VALUES (:parent_id, :name, :flag)'
        );
        $stmt->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'flag' => $unskilledOnly ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateCategory(int $id, string $name, bool $unskilledOnly, ?int $parentId = null): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'UPDATE `micro_categories` SET `parent_id` = :parent_id, `name` = :name, `is_unskilled_only` = :flag WHERE `id` = :id'
        );
        return $stmt->execute([
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
            'flag' => $unskilledOnly ? 1 : 0,
        ]);
    }

    public function deleteCategory(int $id): bool
    {
        $this->ensureSchema();
        if ($this->categoryTaskCount($id) > 0 || $this->categoryHasChildren($id)) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM `micro_categories` WHERE `id` = :id');
        return $stmt->execute(['id' => $id]);
    }

    private function seedCatalog(): void
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'micro_gig_catalog.php';
        if (!is_file($path)) {
            $this->seedLegacyDefaults();
            return;
        }

        $tree = require $path;
        if (!is_array($tree) || $tree === []) {
            $this->seedLegacyDefaults();
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO `micro_categories` (`parent_id`, `code`, `name`, `is_unskilled_only`)
             VALUES (:parent_id, :code, :name, 1)'
        );
        $exists = $this->db->prepare('SELECT `id` FROM `micro_categories` WHERE `code` = :code LIMIT 1');
        $allowed = [];

        $walk = function ($nodes, ?int $parentId, string $prefix) use (&$walk, &$allowed, $insert, $exists): void {
            if (!is_array($nodes)) {
                return;
            }
            $i = 0;
            foreach ($nodes as $node) {
                $i++;
                if (is_string($node)) {
                    $code = $prefix . '.' . $i;
                    $allowed[$code] = true;
                    $exists->execute(['code' => $code]);
                    if ($exists->fetchColumn()) {
                        continue;
                    }
                    $insert->execute([
                        'parent_id' => $parentId,
                        'code' => $code,
                        'name' => $node,
                    ]);
                    continue;
                }
                if (!is_array($node) || !isset($node[0])) {
                    continue;
                }
                $name = (string) $node[0];
                $children = $node[1] ?? [];
                $code = $prefix === '' ? ('g:' . $i) : ($prefix . '.' . $i);
                $allowed[$code] = true;
                $exists->execute(['code' => $code]);
                $id = (int) $exists->fetchColumn();
                if ($id <= 0) {
                    $insert->execute([
                        'parent_id' => $parentId,
                        'code' => $code,
                        'name' => $name,
                    ]);
                    $id = (int) $this->db->lastInsertId();
                }
                if (is_array($children) && $children !== []) {
                    $walk($children, $id, $code);
                }
            }
        };

        $walk($tree, null, '');
        $this->pruneUnusedCatalogCodes($allowed);
    }

    /**
     * @param array<string, true> $allowed
     */
    private function pruneUnusedCatalogCodes(array $allowed): void
    {
        for ($i = 0; $i < 20; $i++) {
            $rows = $this->db->query(
                "SELECT `id`, `code` FROM `micro_categories`
                 WHERE `code` IS NOT NULL AND (`code` LIKE 'c:%' OR `code` LIKE 'g:%')"
            )->fetchAll();
            $deleted = 0;
            foreach ($rows as $row) {
                $code = (string) $row['code'];
                if (isset($allowed[$code])) {
                    continue;
                }
                $id = (int) $row['id'];
                if ($this->countChildren($id) > 0 || $this->countTasks($id) > 0) {
                    continue;
                }
                $stmt = $this->db->prepare('DELETE FROM `micro_categories` WHERE `id` = :id');
                $stmt->execute(['id' => $id]);
                $deleted++;
            }
            if ($deleted === 0) {
                break;
            }
        }

        $legacy = [
            'разгрузка и погрузка',
            'вынос мусора и мебели',
            'раздача листовок и расклейка',
            'курьерские поручения',
            'уборка и клининг',
            'помощь в саду и на участке',
        ];
        $rows = $this->db->query(
            'SELECT `id`, `name` FROM `micro_categories` WHERE `parent_id` IS NULL AND (`code` IS NULL OR `code` = \'\')'
        )->fetchAll();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($this->countChildren($id) > 0 || $this->countTasks($id) > 0) {
                continue;
            }
            if (!in_array(mb_strtolower(trim((string) $row['name']), 'UTF-8'), $legacy, true)) {
                continue;
            }
            $stmt = $this->db->prepare('DELETE FROM `micro_categories` WHERE `id` = :id');
            $stmt->execute(['id' => $id]);
        }
    }

    private function seedLegacyDefaults(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM `micro_categories`')->fetchColumn();
        if ($count > 0) {
            return;
        }
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

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $this->db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (PDOException) {
        }
    }
}
