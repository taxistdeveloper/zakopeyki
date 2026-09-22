-- shipping_version: инвалидация котировок при изменении параметров отправления.
-- Совместимо с MySQL 5.7 / MariaDB (без ADD COLUMN IF NOT EXISTS).
-- Те же колонки добавляются runtime из моделей.

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
    'shipping_version',
    'INT UNSIGNED NOT NULL DEFAULT 1 AFTER shipping_ready'
);

CALL zakapeiku_add_column_if_missing(
    'delivery_orders',
    'shipping_version',
    'INT UNSIGNED NOT NULL DEFAULT 1 AFTER version'
);

CALL zakapeiku_add_column_if_missing(
    'delivery_orders',
    'listing_shipping_version',
    'INT UNSIGNED NOT NULL DEFAULT 1 AFTER shipping_version'
);

CALL zakapeiku_add_column_if_missing(
    'delivery_quotes',
    'shipping_version',
    'INT UNSIGNED DEFAULT NULL AFTER calculation_method'
);

DROP PROCEDURE IF EXISTS zakapeiku_add_column_if_missing;
