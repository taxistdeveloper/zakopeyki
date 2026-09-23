# AI Setup

## Требования

- PHP 8.1+
- MySQL 8+
- Ollama (локально) — опционально для LLM; поиск через tools работает без Ollama
- Redis — опционально

## Установка

1. Применить AI-схему:
   ```bash
   php bin/ai_migrate.php
   ```
2. (Опционально) проиндексировать knowledge для vector RAG:
   ```bash
   ollama pull nomic-embed-text
   ollama pull llava   # для vision
   php bin/ai_index_knowledge.php
   ```
3. Проверить `config/ai.php` (OLLAMA_URL, model, vision_model, embedding_model).
4. Healthcheck:
   ```bash
   php bin/ai_healthcheck.php
   ```
5. Worker (если `process_mode=async`):
   ```bash
   php bin/ai_worker.php
   ```
6. Learning (опционально, cron):
   ```bash
   php bin/ai_learning_worker.php --once
   php bin/ai_maintenance.php
   ```
7. Acceptance smoke:
   ```bash
   php bin/ai_acceptance.php
   ```

Rate limit: `config/ai.php` → `rate_limit.per_minute` / `per_hour`.

## Проверка сценариев

Поиск:
`Найди iPhone 15 до 300000 в Алматы`

Объявление:
`Хочу продать iPhone 15, 256 ГБ, батарея 91%, цена 280 тысяч, Алматы`
→ черновик + кнопки Опубликовать / Отмена

Фото (кнопка 📷 в чате, нужен логин + Ollama llava):
загрузить фото + «Хочу продать этот товар»

Цена:
`Какую цену поставить на iPhone 15?`
