<?php

namespace App\Models;

use App\Core\Model;

class ProductListingShipping extends Model
{
    protected string $table = 'product_listing_shipping';
    private static bool $ensured = false;

    public const FULFILLMENT_DELIVERY = 'delivery';
    public const FULFILLMENT_PICKUP = 'pickup';
    public const FULFILLMENT_BOTH = 'both';

    public const MODE_EXACT = 'exact';
    public const MODE_STANDARD = 'standard_packaging';
    public const MODE_UNKNOWN = 'unknown';

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
            "CREATE TABLE IF NOT EXISTS product_listing_shipping (
                product_id INT UNSIGNED NOT NULL PRIMARY KEY,
                fulfillment_mode VARCHAR(16) NOT NULL DEFAULT 'delivery',
                param_mode VARCHAR(24) NOT NULL DEFAULT 'exact',
                use_default_ship_from TINYINT(1) NOT NULL DEFAULT 1,
                ship_country VARCHAR(64) NOT NULL DEFAULT 'KZ',
                ship_region VARCHAR(120) DEFAULT NULL,
                ship_city VARCHAR(120) DEFAULT NULL,
                ship_street VARCHAR(200) DEFAULT NULL,
                ship_building VARCHAR(40) DEFAULT NULL,
                ship_apartment VARCHAR(40) DEFAULT NULL,
                ship_postal_code VARCHAR(20) DEFAULT NULL,
                ship_contact_name VARCHAR(160) DEFAULT NULL,
                ship_phone VARCHAR(32) DEFAULT NULL,
                packaging_id INT UNSIGNED DEFAULT NULL,
                recommended_packaging_id INT UNSIGNED DEFAULT NULL,
                packaging_name_snapshot VARCHAR(120) DEFAULT NULL,
                product_type_hint VARCHAR(32) DEFAULT NULL,
                item_weight DECIMAL(10,3) DEFAULT NULL,
                packaging_weight DECIMAL(10,3) DEFAULT NULL,
                gross_weight DECIMAL(10,3) DEFAULT NULL,
                item_length DECIMAL(10,2) DEFAULT NULL,
                item_width DECIMAL(10,2) DEFAULT NULL,
                item_height DECIMAL(10,2) DEFAULT NULL,
                package_length DECIMAL(10,2) DEFAULT NULL,
                package_width DECIMAL(10,2) DEFAULT NULL,
                package_height DECIMAL(10,2) DEFAULT NULL,
                is_irregular TINYINT(1) NOT NULL DEFAULT 0,
                irregular_reason VARCHAR(64) DEFAULT NULL,
                is_fragile TINYINT(1) NOT NULL DEFAULT 0,
                shipping_ready TINYINT(1) NOT NULL DEFAULT 0,
                shipping_version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_fulfillment (fulfillment_mode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->ensureColumn(
            'shipping_version',
            'INT UNSIGNED NOT NULL DEFAULT 1 AFTER shipping_ready'
        );
        $this->ensureColumn('origin_type', "VARCHAR(16) NOT NULL DEFAULT 'door'");
        $this->ensureColumn('shipment_point', 'VARCHAR(64) DEFAULT NULL');
        $this->ensureColumn('cdek_city_code', 'INT DEFAULT NULL');
        $this->ensureColumn('ship_latitude', 'DECIMAL(10,7) DEFAULT NULL');
        $this->ensureColumn('ship_longitude', 'DECIMAL(10,7) DEFAULT NULL');
        $this->ensureColumn('cdek_ready', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureColumn('declared_value', 'INT UNSIGNED DEFAULT NULL');
        $this->ensureColumn('declared_currency', "CHAR(3) NOT NULL DEFAULT 'KZT'");
        $this->ensureColumn('package_count', 'TINYINT UNSIGNED NOT NULL DEFAULT 1');
        $this->ensureColumn('shipment_description', 'VARCHAR(255) DEFAULT NULL');

        self::$ensured = true;
    }

    private function ensureColumn(string $name, string $definition): void
    {
        try {
            $rows = $this->db->query('SHOW COLUMNS FROM product_listing_shipping')->fetchAll();
            foreach ($rows as $row) {
                if (strtolower((string) $row['Field']) === strtolower($name)) {
                    return;
                }
            }
            $this->db->exec("ALTER TABLE product_listing_shipping ADD COLUMN {$name} {$definition}");
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function findByProductId(int $productId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM product_listing_shipping WHERE product_id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Upsert с инкрементом shipping_version при изменении shipping-полей.
     * Pickup-only / пустой shipping_ready=0 — версию всё равно можно хранить.
     */
    public function upsert(int $productId, array $data): void
    {
        $existing = $this->findByProductId($productId);
        if ($existing) {
            $bump = $this->shippingFingerprint($existing) !== $this->shippingFingerprint(array_merge($existing, $data));
            $version = (int) ($existing['shipping_version'] ?? 1);
            if ($bump) {
                $version++;
            }
            $vals = $this->rowValues($data);
            $vals[] = $version;
            $vals[] = $productId;
            $stmt = $this->db->prepare(
                'UPDATE product_listing_shipping SET
                    fulfillment_mode = ?, param_mode = ?, use_default_ship_from = ?,
                    ship_country = ?, ship_region = ?, ship_city = ?, ship_street = ?,
                    ship_building = ?, ship_apartment = ?, ship_postal_code = ?,
                    ship_contact_name = ?, ship_phone = ?,
                    origin_type = ?, shipment_point = ?, cdek_city_code = ?,
                    ship_latitude = ?, ship_longitude = ?,
                    packaging_id = ?, recommended_packaging_id = ?, packaging_name_snapshot = ?,
                    product_type_hint = ?,
                    item_weight = ?, packaging_weight = ?, gross_weight = ?,
                    item_length = ?, item_width = ?, item_height = ?,
                    package_length = ?, package_width = ?, package_height = ?,
                    package_count = ?, shipment_description = ?,
                    is_irregular = ?, irregular_reason = ?, is_fragile = ?, shipping_ready = ?,
                    cdek_ready = ?, declared_value = ?, declared_currency = ?,
                    shipping_version = ?
                 WHERE product_id = ?'
            );
            $stmt->execute($vals);
            return;
        }

        $vals = [$productId];
        $vals = array_merge($vals, $this->rowValues($data));
        $vals[] = 1; // shipping_version
        $stmt = $this->db->prepare(
            'INSERT INTO product_listing_shipping (
                product_id, fulfillment_mode, param_mode, use_default_ship_from,
                ship_country, ship_region, ship_city, ship_street, ship_building, ship_apartment,
                ship_postal_code, ship_contact_name, ship_phone,
                origin_type, shipment_point, cdek_city_code, ship_latitude, ship_longitude,
                packaging_id, recommended_packaging_id, packaging_name_snapshot, product_type_hint,
                item_weight, packaging_weight, gross_weight,
                item_length, item_width, item_height,
                package_length, package_width, package_height,
                package_count, shipment_description,
                is_irregular, irregular_reason, is_fragile, shipping_ready,
                cdek_ready, declared_value, declared_currency, shipping_version
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute($vals);
    }

    /** @param array<string, mixed> $row */
    private function shippingFingerprint(array $row): string
    {
        $keys = [
            'fulfillment_mode', 'param_mode', 'use_default_ship_from',
            'ship_country', 'ship_region', 'ship_city', 'ship_street',
            'ship_building', 'ship_apartment', 'ship_postal_code',
            'ship_contact_name', 'ship_phone',
            'origin_type', 'shipment_point', 'cdek_city_code',
            'ship_latitude', 'ship_longitude',
            'packaging_id', 'gross_weight',
            'item_weight', 'packaging_weight',
            'item_length', 'item_width', 'item_height',
            'package_length', 'package_width', 'package_height',
            'package_count', 'shipment_description',
            'is_irregular', 'irregular_reason', 'is_fragile', 'shipping_ready',
            'cdek_ready', 'declared_value', 'declared_currency',
        ];
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = $k . '=' . (string) ($row[$k] ?? '');
        }
        return hash('sha256', implode('|', $parts));
    }

    /** @return array<int, mixed> */
    private function rowValues(array $data): array
    {
        return [
            $data['fulfillment_mode'] ?? self::FULFILLMENT_DELIVERY,
            $data['param_mode'] ?? self::MODE_EXACT,
            !empty($data['use_default_ship_from']) ? 1 : 0,
            $data['ship_country'] ?? 'KZ',
            $data['ship_region'] ?? null,
            $data['ship_city'] ?? null,
            $data['ship_street'] ?? null,
            $data['ship_building'] ?? null,
            $data['ship_apartment'] ?? null,
            $data['ship_postal_code'] ?? null,
            $data['ship_contact_name'] ?? null,
            $data['ship_phone'] ?? null,
            $data['origin_type'] ?? 'door',
            $data['shipment_point'] ?? null,
            $data['cdek_city_code'] ?? null,
            $data['ship_latitude'] ?? null,
            $data['ship_longitude'] ?? null,
            $data['packaging_id'] ?? null,
            $data['recommended_packaging_id'] ?? null,
            $data['packaging_name_snapshot'] ?? null,
            $data['product_type_hint'] ?? null,
            $data['item_weight'] ?? null,
            $data['packaging_weight'] ?? null,
            $data['gross_weight'] ?? null,
            $data['item_length'] ?? null,
            $data['item_width'] ?? null,
            $data['item_height'] ?? null,
            $data['package_length'] ?? null,
            $data['package_width'] ?? null,
            $data['package_height'] ?? null,
            max(1, (int) ($data['package_count'] ?? 1)),
            $data['shipment_description'] ?? null,
            !empty($data['is_irregular']) ? 1 : 0,
            $data['irregular_reason'] ?? null,
            !empty($data['is_fragile']) ? 1 : 0,
            !empty($data['shipping_ready']) ? 1 : 0,
            !empty($data['cdek_ready']) ? 1 : 0,
            $data['declared_value'] ?? null,
            $data['declared_currency'] ?? 'KZT',
        ];
    }
}
