-- Остаток товара и статус «нет в наличии»
USE zakapeiku;

-- ALTER TABLE products
--   ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER price;

-- ALTER TABLE products
--   MODIFY status ENUM('active','sold','reserved','archived','out_of_stock')
--   NOT NULL DEFAULT 'active';

-- ALTER TABLE orders
--   ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER amount,
--   ADD COLUMN stock_held TINYINT(1) NOT NULL DEFAULT 0 AFTER quantity,
--   ADD COLUMN stock_restored TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_held;
