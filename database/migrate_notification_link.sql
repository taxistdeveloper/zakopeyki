-- Ссылка в уведомлениях (клик → чат / страница)
USE zakapeiku;

ALTER TABLE notifications
    ADD COLUMN link VARCHAR(255) NULL DEFAULT NULL AFTER message;
