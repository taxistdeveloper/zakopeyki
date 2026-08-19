-- Отзывы по поручениям биржи услуг (заказчик ↔ исполнитель)

ALTER TABLE `reviews` MODIFY `order_id` INT UNSIGNED NULL;
ALTER TABLE `reviews` ADD COLUMN `micro_task_id` INT UNSIGNED NULL AFTER `order_id`;
ALTER TABLE `reviews` MODIFY `role` ENUM('as_seller','as_buyer','as_executor','as_customer') NOT NULL;
ALTER TABLE `reviews` ADD UNIQUE KEY `uq_task_author` (`micro_task_id`, `author_id`);
