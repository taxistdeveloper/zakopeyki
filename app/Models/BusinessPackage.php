<?php

namespace App\Models;

use App\Core\Model;

class BusinessPackage extends Model
{
    protected string $table = 'business_packages';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS business_packages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description TEXT DEFAULT NULL,
                price_kzt INT UNSIGNED NOT NULL,
                duration_days INT UNSIGNED NOT NULL DEFAULT 30,
                benefits_json TEXT DEFAULT NULL,
                max_photos TINYINT UNSIGNED NOT NULL DEFAULT 10,
                free_service_listing TINYINT(1) NOT NULL DEFAULT 1,
                priority_boost TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_bp_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $exists = $this->db->query(
            "SELECT id FROM business_packages WHERE slug = 'business-pro' LIMIT 1"
        )->fetch();
        if (!$exists) {
            $benefits = json_encode([
                'Бейдж «Проверенный бизнес»',
                'Приоритет в каталоге и поиске',
                'До 10 фото в объявлении',
                'Бесплатная публикация услуг',
                'Расширенная витрина продавца',
            ], JSON_UNESCAPED_UNICODE);
            $stmt = $this->db->prepare(
                'INSERT INTO business_packages
                    (slug, name, description, price_kzt, duration_days, benefits_json, max_photos, free_service_listing, priority_boost, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1)'
            );
            $stmt->execute([
                'business-pro',
                'Бизнес-пакет',
                'Премиум-возможности для ИП и ТОО на Zakopeyki: приоритет в выдаче, больше фото, бесплатная публикация услуг и бейдж проверенного бизнеса.',
                9990,
                30,
                $benefits,
                10,
            ]);
        }

        self::$ensured = true;
    }

    /** @return list<array> */
    public function activeAll(): array
    {
        return $this->db->query(
            'SELECT * FROM business_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        )->fetchAll() ?: [];
    }

    /** @return list<string> */
    public static function decodeBenefits(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
