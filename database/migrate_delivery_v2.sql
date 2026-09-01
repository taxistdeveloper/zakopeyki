-- Доставка v2: рекомендация упаковки, разделение весов/размеров, quote snapshot (ТЗ §22–40)
-- Совместимо с MySQL 5.7 / MariaDB (без ADD COLUMN IF NOT EXISTS).
-- Те же колонки добавляются из App\Models\DeliveryOrder::ensureUpgradeColumns() при открытии сайта.

USE zakapeiku;

DELIMITER $$

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing$$

CREATE PROCEDURE zakapeiku_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition
        );
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL zakapeiku_add_column_if_missing('delivery_packagings', 'packaging_type', "VARCHAR(16) NOT NULL DEFAULT 'box' AFTER name");
CALL zakapeiku_add_column_if_missing('delivery_packagings', 'padding_cm', 'DECIMAL(4,1) NOT NULL DEFAULT 2.0 AFTER height_cm');
CALL zakapeiku_add_column_if_missing('delivery_packagings', 'sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active');

CALL zakapeiku_add_column_if_missing('delivery_shipments', 'item_weight', 'DECIMAL(10,3) DEFAULT NULL AFTER product_qty');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'packaging_weight', 'DECIMAL(10,3) DEFAULT NULL AFTER item_weight');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'gross_weight', 'DECIMAL(10,3) DEFAULT NULL AFTER packaging_weight');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'item_length', 'DECIMAL(10,2) DEFAULT NULL AFTER gross_weight');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'item_width', 'DECIMAL(10,2) DEFAULT NULL AFTER item_length');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'item_height', 'DECIMAL(10,2) DEFAULT NULL AFTER item_width');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'package_length', 'DECIMAL(10,2) DEFAULT NULL AFTER item_height');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'package_width', 'DECIMAL(10,2) DEFAULT NULL AFTER package_length');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'package_height', 'DECIMAL(10,2) DEFAULT NULL AFTER package_width');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'billed_length', 'DECIMAL(10,2) DEFAULT NULL AFTER package_height');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'billed_width', 'DECIMAL(10,2) DEFAULT NULL AFTER billed_length');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'billed_height', 'DECIMAL(10,2) DEFAULT NULL AFTER billed_width');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'billed_gross_weight', 'DECIMAL(10,3) DEFAULT NULL AFTER billed_height');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'is_irregular', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_fragile');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'irregular_reason', 'VARCHAR(64) DEFAULT NULL AFTER is_irregular');
CALL zakapeiku_add_column_if_missing('delivery_shipments', 'recommended_packaging_id', 'INT UNSIGNED DEFAULT NULL AFTER packaging_name_snapshot');

CALL zakapeiku_add_column_if_missing('delivery_quotes', 'quote_status', "VARCHAR(16) NOT NULL DEFAULT 'active' AFTER is_selected");
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'handling_amount', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER packaging_amount');
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'snapshot_json', 'LONGTEXT DEFAULT NULL AFTER response_hash');
CALL zakapeiku_add_column_if_missing('delivery_quotes', 'calculation_method', 'VARCHAR(64) DEFAULT NULL AFTER billable_weight_method');

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing;
