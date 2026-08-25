-- Демо-отзывы по завершённым сделкам (идемпотентно)

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT UNSIGNED NOT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `role` ENUM('as_seller', 'as_buyer') NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `body` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_order_author` (`order_id`, `author_id`),
  INDEX `idx_subject` (`subject_id`),
  INDEX `idx_author` (`author_id`),
  INDEX `idx_rating` (`rating`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Доп. завершённые сделки для демо (если ещё нет)
INSERT INTO orders (id, product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold, confirmed_at, released_at, created_at, paid_at)
SELECT 10, 10, 3, 2, 36000, 'wallet', 'kazpost', 'completed', 'released', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM orders WHERE id = 10);

INSERT INTO orders (id, product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold, confirmed_at, released_at, created_at, paid_at)
SELECT 11, 5, 4, 2, 120000, 'wallet', 'kazpost', 'completed', 'released', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM orders WHERE id = 11);

INSERT INTO orders (id, product_id, buyer_id, seller_id, amount, payment_method, delivery_method, status, escrow_hold, confirmed_at, released_at, created_at, paid_at)
SELECT 13, 9, 1, 2, 5000, 'wallet', 'kazpost', 'completed', 'released', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM orders WHERE id = 13);

-- Сделка #1 уже completed: buyer 3 → seller 2
INSERT IGNORE INTO reviews (order_id, author_id, subject_id, role, rating, body, created_at) VALUES
(1, 3, 2, 'as_seller', 5, 'Всё пришло быстро, товар как в описании. Рекомендую продавца!', NOW() - INTERVAL 4 DAY),
(1, 2, 3, 'as_buyer', 5, 'Покупатель адекватный, быстро подтвердил получение. Спасибо!', NOW() - INTERVAL 4 DAY);

INSERT IGNORE INTO reviews (order_id, author_id, subject_id, role, rating, body, created_at) VALUES
(10, 3, 2, 'as_seller', 4, 'Хороший продавец, небольшая задержка с отправкой, но всё ок.', NOW() - INTERVAL 5 DAY),
(10, 2, 3, 'as_buyer', 5, 'Приятный покупатель, без лишних вопросов.', NOW() - INTERVAL 5 DAY);

INSERT IGNORE INTO reviews (order_id, author_id, subject_id, role, rating, body, created_at) VALUES
(11, 4, 2, 'as_seller', 5, 'Наушники новые, упаковка целая. Супер!', NOW() - INTERVAL 3 DAY),
(11, 2, 4, 'as_buyer', 4, 'Оплата прошла нормально, связь была.', NOW() - INTERVAL 3 DAY);

INSERT IGNORE INTO reviews (order_id, author_id, subject_id, role, rating, body, created_at) VALUES
(13, 1, 2, 'as_seller', 4, 'Ремонт сделали качественно, но ждал чуть дольше обещанного.', NOW() - INTERVAL 1 DAY),
(13, 2, 1, 'as_buyer', 5, 'Клиент вежливый, оплатил сразу. Буду рад ещё раз.', NOW() - INTERVAL 1 DAY);
