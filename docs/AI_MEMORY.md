# AI Memory

Компоненты: `MemoryManager`, `MemoryRepository`, `MemoryRetriever`, `MemoryWriter`, `MemoryScorer`, `MemorySummarizer`, `MemoryCleaner`.

## Правила

- Не пишем каждый текст пользователя.
- Пишем: явные «запомни…», стабильные search-предпочтения (бюджет, город, бренд).
- Dedup через `similar_text`.
- Лимит на пользователя: `config/ai.php` → `memory.max_user_memories`.
- Episodic TTL: `memory.ttl_days_episodic`.

Таблица: `ai_memories` (+ опционально `ai_memory_embeddings`).
