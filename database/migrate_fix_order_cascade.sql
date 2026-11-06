-- Migration: Fix order cascade deletion risk (QA-0003)
-- Change foreign key on orders.product_id from ON DELETE CASCADE to ON DELETE RESTRICT

SET @fk_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'product_id'
      AND REFERENCED_TABLE_NAME = 'products'
    LIMIT 1
);

SET @drop_fk_sql = IF(@fk_name IS NOT NULL,
    CONCAT('ALTER TABLE orders DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT "FK not found"'
);
PREPARE stmt FROM @drop_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE orders
  ADD CONSTRAINT fk_orders_product
  FOREIGN KEY (product_id) REFERENCES products(id)
  ON DELETE RESTRICT;

