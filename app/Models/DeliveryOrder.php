<?php

namespace App\Models;

use App\Core\Model;

class DeliveryOrder extends Model
{
    protected string $table = 'delivery_orders';
    private static bool $ensured = false;

    public const STATUS_DATA_COLLECTION = 'DELIVERY_DATA_COLLECTION';
    public const STATUS_DATA_COMPLETE = 'DELIVERY_DATA_COMPLETE';
    public const STATUS_QUOTE_REQUESTED = 'DELIVERY_QUOTE_REQUESTED';
    public const STATUS_QUOTE_RECEIVED = 'DELIVERY_QUOTE_RECEIVED';
    public const STATUS_READY_FOR_PAYMENT = 'DELIVERY_ORDER_READY_FOR_PAYMENT';
    public const STATUS_PAYMENT_PENDING = 'DELIVERY_PAYMENT_PENDING';
    public const STATUS_PAID = 'DELIVERY_PAID';
    public const STATUS_ORDER_CREATED = 'DELIVERY_ORDER_CREATED';
    public const STATUS_ACCEPTED = 'DELIVERY_ACCEPTED';
    public const STATUS_SHIPMENT_RECEIVED = 'SHIPMENT_RECEIVED';
    public const STATUS_IN_TRANSIT = 'IN_TRANSIT';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXCEPTION = 'EXCEPTION';

    public function __construct()
    {
        parent::__construct();
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS logistics_providers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(120) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                api_config TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_logistics_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_orders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(32) NOT NULL,
                order_id INT UNSIGNED NOT NULL,
                buyer_user_id INT UNSIGNED NOT NULL,
                seller_user_id INT UNSIGNED NOT NULL,
                logistics_provider_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED DEFAULT NULL,
                sender_id INT UNSIGNED DEFAULT NULL,
                recipient_id INT UNSIGNED DEFAULT NULL,
                origin_address_id INT UNSIGNED DEFAULT NULL,
                destination_address_id INT UNSIGNED DEFAULT NULL,
                quote_id INT UNSIGNED DEFAULT NULL,
                logistics_order_id VARCHAR(64) DEFAULT NULL,
                status VARCHAR(48) NOT NULL DEFAULT 'DELIVERY_DATA_COLLECTION',
                currency CHAR(3) NOT NULL DEFAULT 'KZT',
                total_amount INT UNSIGNED DEFAULT NULL,
                paid_amount INT UNSIGNED NOT NULL DEFAULT 0,
                payment_status VARCHAR(32) NOT NULL DEFAULT 'unpaid',
                data_completeness_status VARCHAR(32) NOT NULL DEFAULT 'pending',
                version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                paid_at DATETIME DEFAULT NULL,
                accepted_at DATETIME DEFAULT NULL,
                delivered_at DATETIME DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                cancel_reason VARCHAR(64) DEFAULT NULL,
                UNIQUE KEY uq_order_number (order_number),
                UNIQUE KEY uq_p2p_order (order_id),
                INDEX idx_buyer (buyer_user_id),
                INDEX idx_seller (seller_user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_senders (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                name VARCHAR(160) NOT NULL,
                phone VARCHAR(32) NOT NULL,
                email VARCHAR(120) DEFAULT NULL,
                country VARCHAR(64) NOT NULL DEFAULT 'KZ',
                region VARCHAR(120) DEFAULT NULL,
                city VARCHAR(120) NOT NULL,
                street VARCHAR(200) DEFAULT NULL,
                building VARCHAR(40) DEFAULT NULL,
                apartment VARCHAR(40) DEFAULT NULL,
                postal_code VARCHAR(20) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_delivery_sender (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_recipients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                name VARCHAR(160) NOT NULL,
                phone VARCHAR(32) NOT NULL,
                email VARCHAR(120) DEFAULT NULL,
                delivery_mode VARCHAR(24) NOT NULL DEFAULT 'courier',
                country VARCHAR(64) NOT NULL DEFAULT 'KZ',
                region VARCHAR(120) DEFAULT NULL,
                city VARCHAR(120) NOT NULL,
                street VARCHAR(200) DEFAULT NULL,
                building VARCHAR(40) DEFAULT NULL,
                apartment VARCHAR(40) DEFAULT NULL,
                postal_code VARCHAR(20) DEFAULT NULL,
                pvz_code VARCHAR(64) DEFAULT NULL,
                pvz_name VARCHAR(200) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_delivery_recipient (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_shipments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                product_title VARCHAR(255) DEFAULT NULL,
                product_qty INT UNSIGNED NOT NULL DEFAULT 1,
                package_count INT UNSIGNED NOT NULL DEFAULT 1,
                weight_value DECIMAL(10,3) DEFAULT NULL,
                weight_unit VARCHAR(8) NOT NULL DEFAULT 'kg',
                length_value DECIMAL(10,2) DEFAULT NULL,
                width_value DECIMAL(10,2) DEFAULT NULL,
                height_value DECIMAL(10,2) DEFAULT NULL,
                dimension_unit VARCHAR(8) NOT NULL DEFAULT 'cm',
                weight_source VARCHAR(16) NOT NULL DEFAULT 'seller',
                dimension_source VARCHAR(16) NOT NULL DEFAULT 'seller',
                measurement_status VARCHAR(16) NOT NULL DEFAULT 'preliminary',
                packaging_id INT UNSIGNED DEFAULT NULL,
                packaging_name_snapshot VARCHAR(120) DEFAULT NULL,
                dimensions_unknown TINYINT(1) NOT NULL DEFAULT 0,
                is_fragile TINYINT(1) NOT NULL DEFAULT 0,
                special_handling TEXT DEFAULT NULL,
                actual_weight DECIMAL(10,3) DEFAULT NULL,
                actual_length DECIMAL(10,2) DEFAULT NULL,
                actual_width DECIMAL(10,2) DEFAULT NULL,
                actual_height DECIMAL(10,2) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_delivery_shipment (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_packagings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                logistics_provider_id INT UNSIGNED NOT NULL,
                code VARCHAR(32) NOT NULL,
                name VARCHAR(120) NOT NULL,
                max_weight_kg DECIMAL(10,2) DEFAULT NULL,
                length_cm DECIMAL(10,2) DEFAULT NULL,
                width_cm DECIMAL(10,2) DEFAULT NULL,
                height_cm DECIMAL(10,2) DEFAULT NULL,
                price_amount INT UNSIGNED NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'KZT',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_packaging (logistics_provider_id, code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_services (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                logistics_provider_id INT UNSIGNED NOT NULL,
                service_code VARCHAR(32) NOT NULL,
                service_name VARCHAR(120) NOT NULL,
                delivery_mode VARCHAR(24) NOT NULL DEFAULT 'courier',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                meta TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_service (logistics_provider_id, service_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_quotes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                logistics_provider_id INT UNSIGNED NOT NULL,
                request_id VARCHAR(64) NOT NULL,
                tariff_id INT UNSIGNED DEFAULT NULL,
                tariff_version VARCHAR(16) DEFAULT NULL,
                service_code VARCHAR(32) NOT NULL,
                service_name VARCHAR(120) NOT NULL,
                base_amount INT UNSIGNED NOT NULL DEFAULT 0,
                packaging_amount INT UNSIGNED NOT NULL DEFAULT 0,
                extra_services_amount INT UNSIGNED NOT NULL DEFAULT 0,
                discount_amount INT UNSIGNED NOT NULL DEFAULT 0,
                total_amount INT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'KZT',
                billable_weight DECIMAL(10,3) DEFAULT NULL,
                billable_weight_method VARCHAR(64) DEFAULT NULL,
                eta_days_min INT UNSIGNED DEFAULT NULL,
                eta_days_max INT UNSIGNED DEFAULT NULL,
                valid_until DATETIME NOT NULL,
                request_payload_hash VARCHAR(64) DEFAULT NULL,
                response_hash VARCHAR(64) DEFAULT NULL,
                is_selected TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_delivery_quote_order (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(32) NOT NULL DEFAULT 'avr_data',
                payload_json LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_del_doc (delivery_order_id, document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_tracking (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                tracking_number VARCHAR(120) DEFAULT NULL,
                carrier_status VARCHAR(64) DEFAULT NULL,
                carrier_message VARCHAR(255) DEFAULT NULL,
                location VARCHAR(200) DEFAULT NULL,
                event_at DATETIME NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'logistics',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_del_track_order (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_api_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED DEFAULT NULL,
                logistics_provider_id INT UNSIGNED DEFAULT NULL,
                direction VARCHAR(8) NOT NULL DEFAULT 'out',
                endpoint VARCHAR(200) NOT NULL,
                request_hash VARCHAR(64) DEFAULT NULL,
                response_code INT DEFAULT NULL,
                response_hash VARCHAR(64) DEFAULT NULL,
                error_message VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_del_api_order (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS delivery_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_order_id INT UNSIGNED NOT NULL,
                actor_id INT UNSIGNED DEFAULT NULL,
                actor_role VARCHAR(20) NOT NULL DEFAULT 'system',
                event_type VARCHAR(48) NOT NULL,
                from_status VARCHAR(48) DEFAULT NULL,
                to_status VARCHAR(48) DEFAULT NULL,
                payload TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_del_events_order (delivery_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $this->seedDefaults();
        $this->ensureUpgradeColumns();
        self::$ensured = true;
    }

    private function ensureUpgradeColumns(): void
    {
        $this->ensureTableColumns('delivery_packagings', [
            'packaging_type' => "VARCHAR(16) NOT NULL DEFAULT 'box'",
            'padding_cm' => 'DECIMAL(4,1) NOT NULL DEFAULT 2.0',
            'sort_order' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ]);
        $this->ensureTableColumns('delivery_shipments', [
            'item_weight' => 'DECIMAL(10,3) DEFAULT NULL',
            'packaging_weight' => 'DECIMAL(10,3) DEFAULT NULL',
            'gross_weight' => 'DECIMAL(10,3) DEFAULT NULL',
            'item_length' => 'DECIMAL(10,2) DEFAULT NULL',
            'item_width' => 'DECIMAL(10,2) DEFAULT NULL',
            'item_height' => 'DECIMAL(10,2) DEFAULT NULL',
            'package_length' => 'DECIMAL(10,2) DEFAULT NULL',
            'package_width' => 'DECIMAL(10,2) DEFAULT NULL',
            'package_height' => 'DECIMAL(10,2) DEFAULT NULL',
            'billed_length' => 'DECIMAL(10,2) DEFAULT NULL',
            'billed_width' => 'DECIMAL(10,2) DEFAULT NULL',
            'billed_height' => 'DECIMAL(10,2) DEFAULT NULL',
            'billed_gross_weight' => 'DECIMAL(10,3) DEFAULT NULL',
            'is_irregular' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'irregular_reason' => 'VARCHAR(64) DEFAULT NULL',
            'recommended_packaging_id' => 'INT UNSIGNED DEFAULT NULL',
        ]);
        $this->ensureTableColumns('delivery_quotes', [
            'quote_status' => "VARCHAR(16) NOT NULL DEFAULT 'active'",
            'handling_amount' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'snapshot_json' => 'LONGTEXT DEFAULT NULL',
            'calculation_method' => 'VARCHAR(64) DEFAULT NULL',
        ]);
    }

    /** @param array<string, string> $columns */
    private function ensureTableColumns(string $table, array $columns): void
    {
        $existing = [];
        try {
            $rows = $this->db->query("SHOW COLUMNS FROM {$table}")->fetchAll();
            foreach ($rows as $row) {
                $existing[strtolower((string) $row['Field'])] = true;
            }
        } catch (\Throwable $e) {
            return;
        }

        foreach ($columns as $name => $definition) {
            if (isset($existing[strtolower($name)])) {
                continue;
            }
            try {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    private function seedDefaults(): void
    {
        $stmt = $this->db->query("SELECT id FROM logistics_providers WHERE code = 'stub' LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            $providerId = (int) $row['id'];
        } else {
            $ins = $this->db->prepare(
                "INSERT INTO logistics_providers (code, name, is_active) VALUES ('stub', 'Тестовая логистика', 1)"
            );
            $ins->execute();
            $providerId = (int) $this->db->lastInsertId();
        }

        $packs = [
            ['S', 'Малая (S)', 3, 20, 20, 20, 0, 1],
            ['M', 'Средняя (M)', 10, 40, 30, 20, 0, 2],
            ['L', 'Большая (L)', 20, 60, 40, 40, 0, 3],
            ['XL', 'Очень большая (XL)', 30, 100, 60, 60, 0, 4],
        ];
        foreach ($packs as $p) {
            $check = $this->db->prepare(
                'SELECT id FROM delivery_packagings WHERE logistics_provider_id = ? AND code = ? LIMIT 1'
            );
            $check->execute([$providerId, $p[0]]);
            if ($check->fetch()) {
                continue;
            }
            $ins = $this->db->prepare(
                'INSERT INTO delivery_packagings
                 (logistics_provider_id, code, name, max_weight_kg, length_cm, width_cm, height_cm, price_amount, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$providerId, $p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]]);
        }

        $services = [
            ['courier_std', 'Курьер стандарт', 'courier'],
            ['pvz_std', 'ПВЗ стандарт', 'pvz'],
            ['express', 'Экспресс', 'courier'],
        ];
        foreach ($services as $s) {
            $check = $this->db->prepare(
                'SELECT id FROM delivery_services WHERE logistics_provider_id = ? AND service_code = ? LIMIT 1'
            );
            $check->execute([$providerId, $s[0]]);
            if ($check->fetch()) {
                continue;
            }
            $ins = $this->db->prepare(
                'INSERT INTO delivery_services (logistics_provider_id, service_code, service_name, delivery_mode)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$providerId, $s[0], $s[1], $s[2]]);
        }
    }

    public function defaultProviderId(): int
    {
        $stmt = $this->db->query("SELECT id FROM logistics_providers WHERE code = 'stub' AND is_active = 1 LIMIT 1");
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : 1;
    }

    public function findByP2pOrderId(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_orders WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, lp.name AS logistics_name, lp.code AS logistics_code,
                    p.title AS product_title, p.type AS product_type
             FROM delivery_orders d
             JOIN logistics_providers lp ON lp.id = d.logistics_provider_id
             JOIN orders o ON o.id = d.order_id
             JOIN products p ON p.id = o.product_id
             WHERE d.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['sender'] = $this->senderFor($id);
        $row['recipient'] = $this->recipientFor($id);
        $row['shipment'] = $this->shipmentFor($id);
        $row['quotes'] = $this->quotesFor($id);
        $row['selected_quote'] = $this->selectedQuoteFor($id);
        $row['tracking'] = $this->trackingFor($id);
        $row['events'] = $this->eventsFor($id);

        return $row;
    }

    public function senderFor(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_senders WHERE delivery_order_id = ? LIMIT 1');
        $stmt->execute([$deliveryOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recipientFor(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_recipients WHERE delivery_order_id = ? LIMIT 1');
        $stmt->execute([$deliveryOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function shipmentFor(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_shipments WHERE delivery_order_id = ? LIMIT 1');
        $stmt->execute([$deliveryOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function quotesFor(int $deliveryOrderId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM delivery_quotes
             WHERE delivery_order_id = ? AND COALESCE(quote_status, 'active') = 'active'
             ORDER BY total_amount ASC, id ASC"
        );
        $stmt->execute([$deliveryOrderId]);
        return $stmt->fetchAll() ?: [];
    }

    public function invalidateQuotes(int $deliveryOrderId, string $reason = 'parameters_changed'): void
    {
        $this->db->prepare(
            "UPDATE delivery_quotes
             SET quote_status = 'invalidated', is_selected = 0
             WHERE delivery_order_id = ? AND COALESCE(quote_status, 'active') = 'active'"
        )->execute([$deliveryOrderId]);

        $this->updateFields($deliveryOrderId, [
            'quote_id' => null,
            'total_amount' => null,
            'payment_status' => 'unpaid',
        ]);

        $this->logEvent($deliveryOrderId, null, 'system', 'quotes_invalidated', null, null, [
            'reason' => $reason,
        ]);
    }

    public function selectedQuoteFor(int $deliveryOrderId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM delivery_quotes
             WHERE delivery_order_id = ? AND is_selected = 1
               AND COALESCE(quote_status, 'active') IN ('active', 'paid_snapshot')
             LIMIT 1"
        );
        $stmt->execute([$deliveryOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function trackingFor(int $deliveryOrderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_tracking WHERE delivery_order_id = ? ORDER BY event_at DESC, id DESC'
        );
        $stmt->execute([$deliveryOrderId]);
        return $stmt->fetchAll() ?: [];
    }

    public function eventsFor(int $deliveryOrderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_events WHERE delivery_order_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$deliveryOrderId]);
        return $stmt->fetchAll() ?: [];
    }

    public function packagingsForProvider(int $providerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_packagings WHERE logistics_provider_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$providerId]);
        return $stmt->fetchAll() ?: [];
    }

    public function packagingById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM delivery_packagings WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createForP2pOrder(array $order, string $productTitle): int
    {
        $existing = $this->findByP2pOrderId((int) $order['id']);
        if ($existing) {
            return (int) $existing['id'];
        }

        $providerId = $this->defaultProviderId();
        $orderNumber = 'DO-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));

        $stmt = $this->db->prepare(
            'INSERT INTO delivery_orders (
                order_number, order_id, buyer_user_id, seller_user_id, logistics_provider_id,
                customer_id, status, data_completeness_status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderNumber,
            (int) $order['id'],
            (int) $order['buyer_id'],
            (int) $order['seller_id'],
            $providerId,
            (int) $order['buyer_id'],
            self::STATUS_DATA_COLLECTION,
            'pending',
        ]);
        $deliveryOrderId = (int) $this->db->lastInsertId();

        $ship = $this->db->prepare(
            'INSERT INTO delivery_shipments (delivery_order_id, product_title, product_qty, package_count)
             VALUES (?, ?, 1, 1)'
        );
        $ship->execute([$deliveryOrderId, $productTitle]);

        $this->logEvent($deliveryOrderId, null, 'system', 'created', null, self::STATUS_DATA_COLLECTION, [
            'order_id' => (int) $order['id'],
        ]);

        return $deliveryOrderId;
    }

    public function upsertSender(int $deliveryOrderId, array $data): int
    {
        $existing = $this->senderFor($deliveryOrderId);
        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE delivery_senders SET
                    name = ?, phone = ?, email = ?, country = ?, region = ?, city = ?,
                    street = ?, building = ?, apartment = ?, postal_code = ?, notes = ?
                 WHERE delivery_order_id = ?'
            );
            $stmt->execute([
                $data['name'], $data['phone'], $data['email'] ?? null,
                $data['country'] ?? 'KZ', $data['region'] ?? null, $data['city'],
                $data['street'] ?? null, $data['building'] ?? null, $data['apartment'] ?? null,
                $data['postal_code'] ?? null, $data['notes'] ?? null,
                $deliveryOrderId,
            ]);
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO delivery_senders (
                delivery_order_id, name, phone, email, country, region, city,
                street, building, apartment, postal_code, notes
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deliveryOrderId,
            $data['name'], $data['phone'], $data['email'] ?? null,
            $data['country'] ?? 'KZ', $data['region'] ?? null, $data['city'],
            $data['street'] ?? null, $data['building'] ?? null, $data['apartment'] ?? null,
            $data['postal_code'] ?? null, $data['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function upsertRecipient(int $deliveryOrderId, array $data): int
    {
        $existing = $this->recipientFor($deliveryOrderId);
        if ($existing) {
            $stmt = $this->db->prepare(
                'UPDATE delivery_recipients SET
                    name = ?, phone = ?, email = ?, delivery_mode = ?, country = ?, region = ?, city = ?,
                    street = ?, building = ?, apartment = ?, postal_code = ?, pvz_code = ?, pvz_name = ?, notes = ?
                 WHERE delivery_order_id = ?'
            );
            $stmt->execute([
                $data['name'], $data['phone'], $data['email'] ?? null,
                $data['delivery_mode'] ?? 'courier',
                $data['country'] ?? 'KZ', $data['region'] ?? null, $data['city'],
                $data['street'] ?? null, $data['building'] ?? null, $data['apartment'] ?? null,
                $data['postal_code'] ?? null, $data['pvz_code'] ?? null, $data['pvz_name'] ?? null,
                $data['notes'] ?? null,
                $deliveryOrderId,
            ]);
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO delivery_recipients (
                delivery_order_id, name, phone, email, delivery_mode, country, region, city,
                street, building, apartment, postal_code, pvz_code, pvz_name, notes
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deliveryOrderId,
            $data['name'], $data['phone'], $data['email'] ?? null,
            $data['delivery_mode'] ?? 'courier',
            $data['country'] ?? 'KZ', $data['region'] ?? null, $data['city'],
            $data['street'] ?? null, $data['building'] ?? null, $data['apartment'] ?? null,
            $data['postal_code'] ?? null, $data['pvz_code'] ?? null, $data['pvz_name'] ?? null,
            $data['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateShipment(int $deliveryOrderId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE delivery_shipments SET
                package_count = ?,
                item_weight = ?, packaging_weight = ?, gross_weight = ?,
                item_length = ?, item_width = ?, item_height = ?,
                package_length = ?, package_width = ?, package_height = ?,
                billed_length = ?, billed_width = ?, billed_height = ?, billed_gross_weight = ?,
                weight_value = ?, length_value = ?, width_value = ?, height_value = ?,
                weight_source = ?, dimension_source = ?, measurement_status = ?,
                packaging_id = ?, packaging_name_snapshot = ?, recommended_packaging_id = ?,
                dimensions_unknown = ?, is_fragile = ?, is_irregular = ?, irregular_reason = ?,
                special_handling = ?
             WHERE delivery_order_id = ?'
        );
        $stmt->execute([
            max(1, (int) ($data['package_count'] ?? 1)),
            $data['item_weight'] ?? null,
            $data['packaging_weight'] ?? null,
            $data['gross_weight'] ?? null,
            $data['item_length'] ?? null,
            $data['item_width'] ?? null,
            $data['item_height'] ?? null,
            $data['package_length'] ?? null,
            $data['package_width'] ?? null,
            $data['package_height'] ?? null,
            $data['billed_length'] ?? null,
            $data['billed_width'] ?? null,
            $data['billed_height'] ?? null,
            $data['billed_gross_weight'] ?? null,
            $data['weight_value'] ?? $data['gross_weight'] ?? null,
            $data['length_value'] ?? $data['package_length'] ?? null,
            $data['width_value'] ?? $data['package_width'] ?? null,
            $data['height_value'] ?? $data['package_height'] ?? null,
            $data['weight_source'] ?? 'seller',
            $data['dimension_source'] ?? 'seller',
            $data['measurement_status'] ?? 'preliminary',
            $data['packaging_id'] ?? null,
            $data['packaging_name_snapshot'] ?? null,
            $data['recommended_packaging_id'] ?? null,
            !empty($data['dimensions_unknown']) ? 1 : 0,
            !empty($data['is_fragile']) ? 1 : 0,
            !empty($data['is_irregular']) ? 1 : 0,
            $data['irregular_reason'] ?? null,
            $data['special_handling'] ?? null,
            $deliveryOrderId,
        ]);
    }

    public function saveQuotes(int $deliveryOrderId, int $providerId, string $requestId, array $quotes): void
    {
        $this->db->prepare(
            "UPDATE delivery_quotes SET quote_status = 'superseded', is_selected = 0
             WHERE delivery_order_id = ? AND COALESCE(quote_status, 'active') = 'active'"
        )->execute([$deliveryOrderId]);

        $stmt = $this->db->prepare(
            'INSERT INTO delivery_quotes (
                delivery_order_id, logistics_provider_id, request_id, tariff_id, tariff_version,
                service_code, service_name, base_amount, packaging_amount, handling_amount,
                extra_services_amount, discount_amount, total_amount, currency,
                billable_weight, billable_weight_method, calculation_method,
                eta_days_min, eta_days_max, valid_until,
                request_payload_hash, response_hash, snapshot_json, quote_status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($quotes as $q) {
            $stmt->execute([
                $deliveryOrderId,
                $providerId,
                $requestId,
                $q['tariff_id'] ?? null,
                $q['tariff_version'] ?? null,
                $q['service_code'],
                $q['service_name'],
                (int) ($q['base_amount'] ?? 0),
                (int) ($q['packaging_amount'] ?? 0),
                (int) ($q['handling_amount'] ?? 0),
                (int) ($q['extra_services_amount'] ?? 0),
                (int) ($q['discount_amount'] ?? 0),
                (int) $q['total_amount'],
                $q['currency'] ?? 'KZT',
                $q['billable_weight'] ?? null,
                $q['billable_weight_method'] ?? null,
                $q['calculation_method'] ?? null,
                $q['eta_days_min'] ?? null,
                $q['eta_days_max'] ?? null,
                $q['valid_until'],
                $q['request_payload_hash'] ?? null,
                $q['response_hash'] ?? null,
                !empty($q['snapshot_json']) ? (is_string($q['snapshot_json']) ? $q['snapshot_json'] : json_encode($q['snapshot_json'], JSON_UNESCAPED_UNICODE)) : null,
                'active',
            ]);
        }
    }

    public function selectQuote(int $deliveryOrderId, int $quoteId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM delivery_quotes WHERE id = ? AND delivery_order_id = ? LIMIT 1'
        );
        $stmt->execute([$quoteId, $deliveryOrderId]);
        $quote = $stmt->fetch();
        if (!$quote) {
            return null;
        }

        $this->db->prepare('UPDATE delivery_quotes SET is_selected = 0 WHERE delivery_order_id = ?')
            ->execute([$deliveryOrderId]);
        $this->db->prepare(
            "UPDATE delivery_quotes SET is_selected = 1, quote_status = 'active' WHERE id = ?"
        )->execute([$quoteId]);

        $this->db->prepare(
            'UPDATE delivery_orders SET quote_id = ?, total_amount = ?, currency = ?, version = version + 1 WHERE id = ?'
        )->execute([
            $quoteId,
            (int) $quote['total_amount'],
            $quote['currency'] ?? 'KZT',
            $deliveryOrderId,
        ]);

        return $quote;
    }

    public function markQuotePaidSnapshot(int $quoteId): void
    {
        $this->db->prepare(
            "UPDATE delivery_quotes SET quote_status = 'paid_snapshot' WHERE id = ?"
        )->execute([$quoteId]);
    }

    public function updateFields(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        $sql = 'UPDATE delivery_orders SET ' . implode(', ', $sets) . ', version = version + 1 WHERE id = ?';
        $this->db->prepare($sql)->execute($vals);
    }

    public function transitionStatus(
        int $deliveryOrderId,
        string $toStatus,
        ?int $actorId = null,
        string $actorRole = 'system',
        string $eventType = 'status_change',
        ?array $payload = null
    ): bool {
        $row = $this->find($deliveryOrderId);
        if (!$row) {
            return false;
        }
        $from = $row['status'] ?? '';
        if ($from === $toStatus) {
            return true;
        }

        $this->updateFields($deliveryOrderId, ['status' => $toStatus]);
        $this->logEvent($deliveryOrderId, $actorId, $actorRole, $eventType, $from, $toStatus, $payload);
        return true;
    }

    public function logEvent(
        int $deliveryOrderId,
        ?int $actorId,
        string $actorRole,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?array $payload = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO delivery_events (delivery_order_id, actor_id, actor_role, event_type, from_status, to_status, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deliveryOrderId,
            $actorId,
            $actorRole,
            $eventType,
            $fromStatus,
            $toStatus,
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function saveAvrDocument(int $deliveryOrderId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $existing = $this->db->prepare(
            'SELECT id FROM delivery_documents WHERE delivery_order_id = ? AND document_type = ? LIMIT 1'
        );
        $existing->execute([$deliveryOrderId, 'avr_data']);
        if ($existing->fetch()) {
            $this->db->prepare(
                'UPDATE delivery_documents SET payload_json = ?, updated_at = NOW() WHERE delivery_order_id = ? AND document_type = ?'
            )->execute([$json, $deliveryOrderId, 'avr_data']);
            return;
        }
        $this->db->prepare(
            'INSERT INTO delivery_documents (delivery_order_id, document_type, payload_json) VALUES (?, ?, ?)'
        )->execute([$deliveryOrderId, 'avr_data', $json]);
    }

    public function addTrackingEvent(int $deliveryOrderId, array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO delivery_tracking (delivery_order_id, tracking_number, carrier_status, carrier_message, location, event_at, source)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deliveryOrderId,
            $data['tracking_number'] ?? null,
            $data['carrier_status'] ?? null,
            $data['carrier_message'] ?? null,
            $data['location'] ?? null,
            $data['event_at'] ?? date('Y-m-d H:i:s'),
            $data['source'] ?? 'logistics',
        ]);
    }

    public static function statusLabel(string $status): string
    {
        $key = 'delivery.status_' . strtolower($status);
        $label = t($key);
        return $label !== $key ? $label : $status;
    }
}
