-- Биржа услуг (Gig-Economy): отдельный тип объявлений
USE zakapeiku;

ALTER TABLE `products`
    MODIFY `type` ENUM('used','new','auction','free','exchange','service','course','gig') NOT NULL;
