# AI Troubleshooting

| Симптом | Что проверить |
|---------|----------------|
| Чат молчит / 500 | `php bin/ai_healthcheck.php`, Ollama up, `config/ai.php` enabled |
| Пустой поиск | Нормально, если нет лотов; смотрите SQL в Product::searchAdvanced |
| Vision долго | `performance.vision_async=true` + `php bin/ai_worker.php` |
| Pending не закрывается | Worker не запущен или queue `vision` не читается |
| 429 Too many requests | Rate limit; подождите Retry-After или поднимите `rate_limit` |
| RAG «пусто» | `php bin/ai_migrate.php`, статьи в KB, опционально reindex embeddings |
| Candidate prompt не active | Нужен Approve в `/admin/ai` |
| Session warnings в CLI | Игнор для smoke; Auth трогает session в CLI |
| Embeddings slow | Кэш `storage/cache/ai` TTL `cache_embed_ttl` |

Логи: `ai_usage_logs`, `ai_audit_logs`, `ai_tool_calls`.
