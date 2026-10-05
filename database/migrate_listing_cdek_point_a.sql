-- Phase 4: listing Point A / shipment extras for CDEK publish gate.
-- Совместимо с MySQL 5.7 / MariaDB (без ADD COLUMN IF NOT EXISTS — это синтаксис MariaDB).
-- Runtime ensureColumn also applies these; SQL for explicit migrations.

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

CALL zakapeiku_add_column_if_missing(
    'product_listing_shipping',
    'package_count',
    'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER package_height'
);

CALL zakapeiku_add_column_if_missing(
    'product_listing_shipping',
    'shipment_description',
    'VARCHAR(255) DEFAULT NULL AFTER package_count'
);

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing;
