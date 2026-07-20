-- Обмен / Отдам даром: поле условий обмена
USE zakapeiku;

ALTER TABLE `products`
    ADD COLUMN `exchange_for` VARCHAR(255) DEFAULT NULL AFTER `price`;
