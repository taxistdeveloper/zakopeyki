-- Привязка ответа в чате к сторис
USE zakapeiku;

ALTER TABLE chat_messages
    ADD COLUMN story_id INT UNSIGNED NULL DEFAULT NULL AFTER body;
