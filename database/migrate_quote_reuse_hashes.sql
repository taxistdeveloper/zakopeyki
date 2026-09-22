-- Quote reuse: route_hash / package_hash на delivery_quotes.
-- Совместимо с MySQL 5.7 / MariaDB. Те же колонки — runtime ensureUpgradeColumns.

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
    'delivery_quotes',
    'route_hash',
    'CHAR(64) DEFAULT NULL AFTER request_payload_hash'
);

CALL zakapeiku_add_column_if_missing(
    'delivery_quotes',
    'package_hash',
    'CHAR(64) DEFAULT NULL AFTER route_hash'
);

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing;
