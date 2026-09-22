<?php

namespace App\Models;

use App\Core\Model;

/**
 * Локальный справочник CDEK ПВЗ / постаматов (OfficeDto).
 * Frontend не должен слать произвольный код без проверки через этот каталог.
 *
 * @see openapi_api_v2_integration.json OfficeDto / GET /v2/deliverypoints
 */
class CdekDeliveryPoint extends Model
{
    protected string $table = 'cdek_delivery_points';
    private static bool $ensured = false;

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
            "CREATE TABLE IF NOT EXISTS cdek_delivery_points (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(64) NOT NULL,
                uuid VARCHAR(64) DEFAULT NULL,
                type VARCHAR(32) NOT NULL DEFAULT 'PVZ',
                name VARCHAR(255) DEFAULT NULL,
                address VARCHAR(255) DEFAULT NULL,
                address_full VARCHAR(255) DEFAULT NULL,
                city VARCHAR(120) DEFAULT NULL,
                city_code INT UNSIGNED DEFAULT NULL,
                region VARCHAR(120) DEFAULT NULL,
                region_code INT DEFAULT NULL,
                country_code CHAR(2) NOT NULL DEFAULT 'KZ',
                postal_code VARCHAR(32) DEFAULT NULL,
                longitude DECIMAL(12,8) DEFAULT NULL,
                latitude DECIMAL(12,8) DEFAULT NULL,
                work_time VARCHAR(255) DEFAULT NULL,
                is_handout TINYINT(1) NOT NULL DEFAULT 1,
                is_reception TINYINT(1) NOT NULL DEFAULT 0,
                take_only TINYINT(1) NOT NULL DEFAULT 0,
                have_cashless TINYINT(1) NOT NULL DEFAULT 0,
                have_cash TINYINT(1) NOT NULL DEFAULT 0,
                allowed_cod TINYINT(1) NOT NULL DEFAULT 0,
                weight_min DECIMAL(10,3) DEFAULT NULL,
                weight_max DECIMAL(10,3) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                raw_hash CHAR(64) DEFAULT NULL,
                synced_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_cdek_point_code (code),
                INDEX idx_cdek_point_city_code (city_code),
                INDEX idx_cdek_point_city (city),
                INDEX idx_cdek_point_country_active (country_code, is_active),
                INDEX idx_cdek_point_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    public function findByCode(string $code, bool $activeOnly = true): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $sql = 'SELECT * FROM cdek_delivery_points WHERE code = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @param array{
     *   city_code?: int|null,
     *   city?: string|null,
     *   country_code?: string|null,
     *   q?: string|null,
     *   type?: string|null,
     *   limit?: int,
     *   offset?: int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters = []): array
    {
        $where = ['is_active = 1', 'is_handout = 1'];
        $params = [];

        $country = strtoupper(trim((string) ($filters['country_code'] ?? 'KZ')));
        if ($country !== '') {
            $where[] = 'country_code = ?';
            $params[] = mb_substr($country, 0, 2);
        }

        if (!empty($filters['city_code'])) {
            $where[] = 'city_code = ?';
            $params[] = (int) $filters['city_code'];
        }

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $where[] = 'city LIKE ?';
            $params[] = '%' . $city . '%';
        }

        $type = strtoupper(trim((string) ($filters['type'] ?? '')));
        if (in_array($type, ['PVZ', 'POSTAMAT'], true)) {
            $where[] = 'type = ?';
            $params[] = $type;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(code LIKE ? OR address LIKE ? OR address_full LIKE ? OR name LIKE ? OR city LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $limit = max(1, min(100, (int) ($filters['limit'] ?? 30)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = 'SELECT code, uuid, type, name, address, address_full, city, city_code, region,
                       country_code, postal_code, longitude, latitude, work_time,
                       is_handout, is_reception, weight_min, weight_max
                FROM cdek_delivery_points
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY city ASC, address ASC
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function countActive(?string $countryCode = 'KZ'): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM cdek_delivery_points WHERE is_active = 1 AND country_code = ?'
        );
        $stmt->execute([strtoupper((string) $countryCode)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Upsert одной точки из OfficeDto.
     *
     * @param array<string, mixed> $row normalized row
     */
    public function upsertNormalized(array $row, string $syncedAt): void
    {
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            return;
        }

        $existing = $this->findByCode($code, false);
        $values = [
            $row['uuid'] ?? null,
            $row['type'] ?? 'PVZ',
            $row['name'] ?? null,
            $row['address'] ?? null,
            $row['address_full'] ?? null,
            $row['city'] ?? null,
            $row['city_code'] ?? null,
            $row['region'] ?? null,
            $row['region_code'] ?? null,
            $row['country_code'] ?? 'KZ',
            $row['postal_code'] ?? null,
            $row['longitude'] ?? null,
            $row['latitude'] ?? null,
            $row['work_time'] ?? null,
            !empty($row['is_handout']) ? 1 : 0,
            !empty($row['is_reception']) ? 1 : 0,
            !empty($row['take_only']) ? 1 : 0,
            !empty($row['have_cashless']) ? 1 : 0,
            !empty($row['have_cash']) ? 1 : 0,
            !empty($row['allowed_cod']) ? 1 : 0,
            $row['weight_min'] ?? null,
            $row['weight_max'] ?? null,
            1,
            $row['raw_hash'] ?? null,
            $syncedAt,
        ];

        if ($existing) {
            $values[] = $code;
            $this->db->prepare(
                'UPDATE cdek_delivery_points SET
                    uuid=?, type=?, name=?, address=?, address_full=?, city=?, city_code=?,
                    region=?, region_code=?, country_code=?, postal_code=?,
                    longitude=?, latitude=?, work_time=?,
                    is_handout=?, is_reception=?, take_only=?,
                    have_cashless=?, have_cash=?, allowed_cod=?,
                    weight_min=?, weight_max=?, is_active=?, raw_hash=?, synced_at=?
                 WHERE code=?'
            )->execute($values);
            return;
        }

        $this->db->prepare(
            'INSERT INTO cdek_delivery_points (
                code, uuid, type, name, address, address_full, city, city_code,
                region, region_code, country_code, postal_code,
                longitude, latitude, work_time,
                is_handout, is_reception, take_only,
                have_cashless, have_cash, allowed_cod,
                weight_min, weight_max, is_active, raw_hash, synced_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge([$code], $values));
    }

    /** Деактивировать точки страны, не попавшие в текущий sync batch. */
    public function deactivateStale(string $countryCode, string $syncedAt): int
    {
        $stmt = $this->db->prepare(
            'UPDATE cdek_delivery_points
             SET is_active = 0
             WHERE country_code = ?
               AND is_active = 1
               AND (synced_at IS NULL OR synced_at < ?)'
        );
        $stmt->execute([strtoupper($countryCode), $syncedAt]);
        return $stmt->rowCount();
    }

    /**
     * Публичное представление для API (без внутренних полей).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublic(array $row): array
    {
        return [
            'code' => (string) ($row['code'] ?? ''),
            'type' => (string) ($row['type'] ?? 'PVZ'),
            'name' => $row['name'] ?? null,
            'address' => $row['address'] ?? ($row['address_full'] ?? null),
            'city' => $row['city'] ?? null,
            'city_code' => isset($row['city_code']) ? (int) $row['city_code'] : null,
            'region' => $row['region'] ?? null,
            'country_code' => $row['country_code'] ?? null,
            'postal_code' => $row['postal_code'] ?? null,
            'longitude' => isset($row['longitude']) ? (float) $row['longitude'] : null,
            'latitude' => isset($row['latitude']) ? (float) $row['latitude'] : null,
            'work_time' => $row['work_time'] ?? null,
            'weight_min' => $row['weight_min'] ?? null,
            'weight_max' => $row['weight_max'] ?? null,
            'is_handout' => !empty($row['is_handout']),
            'is_reception' => !empty($row['is_reception']),
        ];
    }
}
