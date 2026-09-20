<?php

namespace App\Models;

use App\Core\Model;

class Library extends Model
{
    protected string $table = 'library_documents';
    private static bool $ensured = false;

    public const MAX_DEPTH = 1; // root + 1 nested level = 2 levels
    public const DEFAULT_CATEGORIES = [
        'Инструкции',
        'Техническая документация',
        'Юридические документы',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS library_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id INT UNSIGNED DEFAULT NULL,
                name VARCHAR(180) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_library_cat_parent (parent_id),
                INDEX idx_library_cat_sort (sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS library_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_id INT UNSIGNED NOT NULL,
                title VARCHAR(240) NOT NULL,
                body MEDIUMTEXT DEFAULT NULL,
                tags VARCHAR(500) DEFAULT NULL,
                created_by INT UNSIGNED NOT NULL,
                updated_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_library_doc_cat (category_id),
                INDEX idx_library_doc_created (created_at),
                INDEX idx_library_doc_title (title)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS library_files (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(120) NOT NULL,
                mime VARCHAR(120) DEFAULT NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_library_file_stored (stored_name),
                INDEX idx_library_file_doc (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $count = (int) $this->db->query('SELECT COUNT(*) FROM library_categories')->fetchColumn();
        if ($count === 0) {
            $stmt = $this->db->prepare(
                'INSERT INTO library_categories (parent_id, name, sort_order) VALUES (NULL, ?, ?)'
            );
            foreach (self::DEFAULT_CATEGORIES as $i => $name) {
                $stmt->execute([$name, $i]);
            }
        }

        self::$ensured = true;
    }

    public function countDocuments(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM library_documents')->fetchColumn();
    }

    public function findCategory(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM library_categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function categoryDepth(int $id): int
    {
        $depth = 0;
        $current = $id;
        $guard = 0;
        while ($current > 0 && $guard < 8) {
            $row = $this->findCategory($current);
            if (!$row) {
                break;
            }
            $parent = (int) ($row['parent_id'] ?? 0);
            if ($parent <= 0) {
                return $depth;
            }
            $depth++;
            $current = $parent;
            $guard++;
        }
        return $depth;
    }

    /** @return list<array<string, mixed>> */
    public function listCategories(): array
    {
        $rows = $this->db->query(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM library_documents d WHERE d.category_id = c.id) AS doc_count,
                    (SELECT COUNT(*) FROM library_categories ch WHERE ch.parent_id = c.id) AS child_count
             FROM library_categories c
             ORDER BY c.sort_order ASC, c.id ASC'
        )->fetchAll();

        $byParent = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['parent_id'] ?? 0);
            $byParent[$pid][] = $row;
        }

        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                $row['depth'] = $depth;
                $out[] = $row;
                $walk((int) $row['id'], $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** @return list<int> */
    public function categoryAndDescendantIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $stmt = $this->db->prepare('SELECT id FROM library_categories WHERE parent_id = ?');
        $queue = [$categoryId];
        while ($queue !== []) {
            $parent = array_shift($queue);
            $stmt->execute([$parent]);
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['id'];
                $ids[] = $id;
                $queue[] = $id;
            }
        }
        return $ids;
    }

    public function createCategory(string $name, ?int $parentId, int $sortOrder = 0): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO library_categories (parent_id, name, sort_order) VALUES (?, ?, ?)'
        );
        $stmt->execute([$parentId, $name, $sortOrder]);
        return (int) $this->db->lastInsertId();
    }

    public function updateCategory(int $id, string $name, ?int $parentId, int $sortOrder): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE library_categories SET name = ?, parent_id = ?, sort_order = ? WHERE id = ?'
        );
        return $stmt->execute([$name, $parentId, $sortOrder, $id]);
    }

    public function deleteCategory(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM library_categories WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function categoryNameExists(string $name, ?int $exceptId, ?int $parentId): bool
    {
        $sql = 'SELECT id FROM library_categories WHERE name = ? AND ';
        $params = [$name];
        if ($parentId === null) {
            $sql .= 'parent_id IS NULL';
        } else {
            $sql .= 'parent_id = ?';
            $params[] = $parentId;
        }
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    /**
     * @param array{q?:string,category_id?:int,date_from?:string,date_to?:string} $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function searchDocuments(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where = ['1=1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(d.title LIKE ? OR IFNULL(d.tags, \'\') LIKE ? OR EXISTS (
                SELECT 1 FROM library_files f WHERE f.document_id = d.id AND f.original_name LIKE ?
            ))';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $ids = $this->categoryAndDescendantIds($categoryId);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $where[] = "d.category_id IN ({$in})";
            foreach ($ids as $id) {
                $params[] = $id;
            }
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = 'DATE(d.created_at) >= ?';
            $params[] = $dateFrom;
        }
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = 'DATE(d.created_at) <= ?';
            $params[] = $dateTo;
        }

        $sqlWhere = implode(' AND ', $where);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM library_documents d WHERE {$sqlWhere}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $pages = max(1, (int) ceil(max($total, 1) / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        $listSql = "SELECT d.*, c.name AS category_name, u.name AS author_name,
                    (SELECT COUNT(*) FROM library_files lf WHERE lf.document_id = d.id) AS file_count
                    FROM library_documents d
                    LEFT JOIN library_categories c ON c.id = d.category_id
                    LEFT JOIN users u ON u.id = d.created_by
                    WHERE {$sqlWhere}
                    ORDER BY d.created_at DESC, d.id DESC
                    LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($listSql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    public function findDocument(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, c.name AS category_name, u.name AS author_name, uu.name AS editor_name
             FROM library_documents d
             LEFT JOIN library_categories c ON c.id = d.category_id
             LEFT JOIN users u ON u.id = d.created_by
             LEFT JOIN users uu ON uu.id = d.updated_by
             WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createDocument(int $categoryId, string $title, string $body, string $tags, int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO library_documents (category_id, title, body, tags, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$categoryId, $title, $body, $tags !== '' ? $tags : null, $userId, $userId]);
        return (int) $this->db->lastInsertId();
    }

    public function updateDocument(int $id, int $categoryId, string $title, string $body, string $tags, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE library_documents
             SET category_id = ?, title = ?, body = ?, tags = ?, updated_by = ?
             WHERE id = ?'
        );
        return $stmt->execute([$categoryId, $title, $body, $tags !== '' ? $tags : null, $userId, $id]);
    }

    public function deleteDocument(int $id): bool
    {
        $files = $this->filesForDocument($id);
        $stmt = $this->db->prepare('DELETE FROM library_files WHERE document_id = ?');
        $stmt->execute([$id]);
        $stmt = $this->db->prepare('DELETE FROM library_documents WHERE id = ?');
        $ok = $stmt->execute([$id]);
        foreach ($files as $file) {
            $this->unlinkStored((string) $file['stored_name']);
        }
        return $ok;
    }

    /** @return list<array<string, mixed>> */
    public function filesForDocument(int $documentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM library_files WHERE document_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    public function findFile(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM library_files WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function addFile(int $documentId, string $original, string $stored, ?string $mime, int $size): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO library_files (document_id, original_name, stored_name, mime, size_bytes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$documentId, $original, $stored, $mime, $size]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteFile(int $id): bool
    {
        $file = $this->findFile($id);
        if (!$file) {
            return false;
        }
        $stmt = $this->db->prepare('DELETE FROM library_files WHERE id = ?');
        $ok = $stmt->execute([$id]);
        $this->unlinkStored((string) $file['stored_name']);
        return $ok;
    }

    public function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/library';
    }

    public function storedPath(string $storedName): ?string
    {
        $safe = basename($storedName);
        if ($safe === '' || $safe !== $storedName || !preg_match('/^f_\d{14}_[a-f0-9]+\.[a-z0-9]+$/i', $safe)) {
            return null;
        }
        $path = $this->storageDir() . DIRECTORY_SEPARATOR . $safe;
        return is_file($path) ? $path : null;
    }

    private function unlinkStored(string $storedName): void
    {
        $path = $this->storedPath($storedName);
        if ($path !== null) {
            @unlink($path);
        }
    }
}
