<?php

namespace App\Models;

use App\Core\Model;

class BusinessPackage extends Model
{
    protected string $table = 'business_packages';
    private static bool $ensured = false;

    public const KIND_PLAN = 'plan';
    public const KIND_ADDON = 'addon';
    public const KIND_TRIAL = 'trial';

    public const BILLING_PERIOD = 'period';
    public const BILLING_ONE_TIME = 'one_time';

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
                limits_json TEXT DEFAULT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'plan',
                billing VARCHAR(20) NOT NULL DEFAULT 'period',
                max_photos TINYINT UNSIGNED NOT NULL DEFAULT 10,
                free_service_listing TINYINT(1) NOT NULL DEFAULT 1,
                priority_boost TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_bp_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureColumn('limits_json', 'TEXT DEFAULT NULL AFTER benefits_json');
        $this->ensureColumn('kind', "VARCHAR(20) NOT NULL DEFAULT 'plan' AFTER limits_json");
        $this->ensureColumn('billing', "VARCHAR(20) NOT NULL DEFAULT 'period' AFTER kind");

        foreach ($this->catalogSeed() as $row) {
            $this->upsert($row);
        }

        // Старый slug 9 990 ₸ скрываем, если остался
        $this->db->exec("UPDATE business_packages SET is_active = 0 WHERE slug = 'business-pro'");

        self::$ensured = true;
    }

    private function ensureColumn(string $column, string $definition): void
    {
        try {
            $this->db->exec("ALTER TABLE business_packages ADD COLUMN `{$column}` {$definition}");
        } catch (\PDOException) {
            // exists
        }
    }

    /** @param array<string, mixed> $row */
    private function upsert(array $row): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO business_packages
                (slug, name, description, price_kzt, duration_days, benefits_json, limits_json, kind, billing,
                 max_photos, free_service_listing, priority_boost, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                price_kzt = VALUES(price_kzt),
                duration_days = VALUES(duration_days),
                benefits_json = VALUES(benefits_json),
                limits_json = VALUES(limits_json),
                kind = VALUES(kind),
                billing = VALUES(billing),
                max_photos = VALUES(max_photos),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)'
        );
        $stmt->execute([
            $row['slug'],
            $row['name'],
            $row['description'],
            (int) $row['price_kzt'],
            (int) $row['duration_days'],
            json_encode($row['benefits'], JSON_UNESCAPED_UNICODE),
            json_encode($row['limits'], JSON_UNESCAPED_UNICODE),
            $row['kind'],
            $row['billing'],
            (int) ($row['max_photos'] ?? 10),
            (int) $row['is_active'],
            (int) $row['sort_order'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function catalogSeed(): array
    {
        $fullLimits = self::paidLimits();
        $trialLimits = self::trialLimits();
        $tagline = 'Загрузите каталог один раз — остальное Zakopeyki сделает автоматически.';

        return [
            [
                'slug' => 'business-month',
                'name' => 'Бизнес-пакет · 1 месяц',
                'description' => $tagline,
                'price_kzt' => 29900,
                'duration_days' => 30,
                'kind' => self::KIND_PLAN,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 1,
                'sort_order' => 10,
                'max_photos' => 10,
                'benefits' => self::planBenefits(),
                'limits' => $fullLimits,
            ],
            [
                'slug' => 'business-3m',
                'name' => 'Бизнес-пакет · 3 месяца',
                'description' => 'Выгоднее месяца: профессиональный тариф на квартал.',
                'price_kzt' => 79900,
                'duration_days' => 90,
                'kind' => self::KIND_PLAN,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 1,
                'sort_order' => 20,
                'max_photos' => 10,
                'benefits' => self::planBenefits(),
                'limits' => $fullLimits,
            ],
            [
                'slug' => 'business-6m',
                'name' => 'Бизнес-пакет · 6 месяцев',
                'description' => 'Полугодовая подписка для стабильной работы каталога.',
                'price_kzt' => 149900,
                'duration_days' => 180,
                'kind' => self::KIND_PLAN,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 1,
                'sort_order' => 30,
                'max_photos' => 10,
                'benefits' => self::planBenefits(),
                'limits' => $fullLimits,
            ],
            [
                'slug' => 'business-12m',
                'name' => 'Бизнес-пакет · 12 месяцев',
                'description' => 'Годовая подписка — максимальная экономия.',
                'price_kzt' => 279900,
                'duration_days' => 365,
                'kind' => self::KIND_PLAN,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 1,
                'sort_order' => 40,
                'max_photos' => 10,
                'benefits' => self::planBenefits(),
                'limits' => $fullLimits,
            ],
            [
                'slug' => 'business-trial',
                'name' => 'Демо Business · 7 дней',
                'description' => 'Тестовый доступ: до 100 товаров, ограниченные AI-лимиты.',
                'price_kzt' => 0,
                'duration_days' => 7,
                'kind' => self::KIND_TRIAL,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 0,
                'sort_order' => 1,
                'max_photos' => 10,
                'benefits' => ['7 дней Business бесплатно', 'До 100 товаров в каталоге', 'Ограниченные AI-лимиты'],
                'limits' => $trialLimits,
            ],
            [
                'slug' => 'addon-catalog-50k',
                'name' => '+50 000 товаров в каталоге',
                'description' => 'Расширение каталога на период подписки.',
                'price_kzt' => 9900,
                'duration_days' => 30,
                'kind' => self::KIND_ADDON,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 1,
                'sort_order' => 100,
                'max_photos' => 10,
                'benefits' => ['+50 000 позиций в каталоге'],
                'limits' => ['extra_catalog' => 50000],
            ],
            [
                'slug' => 'addon-ai-infographic-500',
                'name' => '+500 AI-инфографик',
                'description' => 'Разовый пакет генераций инфографики.',
                'price_kzt' => 4900,
                'duration_days' => 0,
                'kind' => self::KIND_ADDON,
                'billing' => self::BILLING_ONE_TIME,
                'is_active' => 1,
                'sort_order' => 110,
                'max_photos' => 10,
                'benefits' => ['+500 AI-инфографик'],
                'limits' => ['extra_ai_infographic' => 500],
            ],
            [
                'slug' => 'addon-ai-tryon-100',
                'name' => '+100 AI-примерок',
                'description' => 'Разовый пакет виртуальных примерок.',
                'price_kzt' => 7900,
                'duration_days' => 0,
                'kind' => self::KIND_ADDON,
                'billing' => self::BILLING_ONE_TIME,
                'is_active' => 1,
                'sort_order' => 120,
                'max_photos' => 10,
                'benefits' => ['+100 AI-примерок'],
                'limits' => ['extra_ai_tryon' => 100],
            ],
            [
                'slug' => 'addon-staff-5',
                'name' => '+5 сотрудников',
                'description' => 'Дополнительные места в команде на месяц.',
                'price_kzt' => 4900,
                'duration_days' => 30,
                'kind' => self::KIND_ADDON,
                'billing' => self::BILLING_PERIOD,
                'is_active' => 1,
                'sort_order' => 130,
                'max_photos' => 10,
                'benefits' => ['+5 сотрудников бизнес-аккаунта'],
                'limits' => ['extra_staff' => 5],
            ],
        ];
    }

    /** @return list<string> */
    public static function planBenefits(): array
    {
        return [
            'Безлимитные активные объявления',
            'Каталог до 50 000 товаров, до 5 000 новых объявлений в сутки',
            'Автозагрузка XML / CSV / XLSX / YML / API / URL, синхронизация до 1 раза в час',
            'AI-инфографика 1 000/мес, описания и SEO 5 000/мес, оптимизация 5 000/мес',
            'Виртуальная примерка 300/мес',
            '100 бесплатных поднятий объявлений в месяц',
            'Бизнес-витрина, аналитика 12 месяцев, API и вебхуки',
            'До 5 сотрудников и приоритетная поддержка',
        ];
    }

    /** @return array<string, int|bool> */
    public static function paidLimits(): array
    {
        return [
            'catalog' => 50000,
            'listings_per_day' => 5000,
            'sync_per_hour' => 1,
            'ai_infographic' => 1000,
            'ai_copy' => 5000,
            'ai_optimize' => 5000,
            'ai_tryon' => 300,
            'boosts' => 100,
            'staff' => 5,
            'analytics_months' => 12,
            'api' => 1,
        ];
    }

    /** @return array<string, int|bool> */
    public static function trialLimits(): array
    {
        return [
            'catalog' => 100,
            'listings_per_day' => 100,
            'sync_per_hour' => 1,
            'ai_infographic' => 20,
            'ai_copy' => 20,
            'ai_optimize' => 20,
            'ai_tryon' => 10,
            'boosts' => 10,
            'staff' => 1,
            'analytics_months' => 1,
            'api' => 0,
        ];
    }

    /** @return list<array> */
    public function activeAll(): array
    {
        return $this->db->query(
            "SELECT * FROM business_packages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
        )->fetchAll() ?: [];
    }

    /** @return list<array> */
    public function activePlans(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM business_packages WHERE is_active = 1 AND kind = ? ORDER BY sort_order ASC"
        );
        $stmt->execute([self::KIND_PLAN]);
        return $stmt->fetchAll() ?: [];
    }

    /** @return list<array> */
    public function activeAddons(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM business_packages WHERE is_active = 1 AND kind = ? ORDER BY sort_order ASC"
        );
        $stmt->execute([self::KIND_ADDON]);
        return $stmt->fetchAll() ?: [];
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM business_packages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
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

    /** @return array<string, int> */
    public static function decodeLimits(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = (int) $v;
        }
        return $out;
    }
}
