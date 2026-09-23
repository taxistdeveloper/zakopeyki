# AI Final Audit — zakopeyki.kz

Дата: 2026-09-23  
Стек: PHP MVC + MySQL + optional Redis + Ollama  
Принцип: AI — слой над платформой, не отдельный чатбот.

## Verdict

**Платформенный AI-слой готов к локальному/staging использованию.**  
Критические acceptance-сценарии покрыты `bin/ai_acceptance.php`. Авто-deploy промптов отсутствует.

## Что реализовано

| Область | Статус | Где |
|---------|--------|-----|
| Orchestrator pipeline | ✅ | `AIOrchestrator` |
| Intent + params | ✅ | `IntentDetector`, `ListingParamsExtractor` |
| Tools → MySQL | ✅ | `Tools/*` → Product/Order/Delivery/Favorites |
| Ownership IDOR fix | ✅ | `PermissionChecker` + order/delivery tools |
| Memory | ✅ | `Memory/*` |
| Hybrid RAG | ✅ | `RagEngine` + `MysqlVectorStore` |
| Vision + listing draft | ✅ | `VisionService`, `ListingDraftService`, confirm |
| Voice (browser STT/TTS) | ✅ | Web Speech + `BrowserTtsProvider` |
| Seller analytics | ✅ | sales / demand / recommendations tools |
| SSE streaming | ✅ | `POST /ai/chat/stream` |
| Admin dashboard | ✅ | `/admin/ai` |
| Learning (candidate only) | ✅ | `Learning/*` |
| Rate limit | ✅ | `RateLimiter` |
| Performance cache/limits | ✅ | `AiCache`, `ContextManager`, embed/KB TTL |
| Async vision queue | ✅ | `performance.vision_async` + queue `vision` |

## Acceptance (DoD)

| # | Сценарий | Результат |
|---|----------|-----------|
| 1 | «Найди iPhone 15 до 300000 в Алматы» | SEARCH_PRODUCT → `searchAdvanced` (реальные строки или пусто) |
| 2 | Фото + «хочу продать» | draft + confirm, без auto-publish |
| 3 | Статус своего заказа / доставки | tool + ownership |
| 4 | Казахский | `LanguageDetector` → kk |
| 5 | Feedback 👎 | `ai_feedback` + `ai_learning_events` |
| 6 | Чужой order | deny |

Smoke: `php bin/ai_acceptance.php` → все PASS.

## Безопасность

- Rate limit на chat/stream/voice/image  
- Prompt injection guard  
- Confirm token на publish listing  
- Нет auto-activate prompt candidates  
- Tools не отдают чужие заказы  

## Ограничения (известные)

1. Без Ollama: search/tools работают; LLM-ответы support деградируют / fallback.  
2. Vector RAG требует `nomic-embed-text` + reindex.  
3. Vision async по умолчанию **выключен** (`AI_VISION_ASYNC` / `performance.vision_async`); при включении нужен `php bin/ai_worker.php`.  
4. PHPUnit в vendor может отсутствовать — используйте `bin/ai_acceptance.php`.  
5. Redis опционален; без него — MySQL/file для rate limit и cache.

## Ops checklist

```bash
php bin/ai_migrate.php
php bin/ai_index_knowledge.php   # если Ollama + embed
php bin/ai_acceptance.php
php bin/ai_maintenance.php       # cron daily
# optional:
AI_VISION_ASYNC=1 + php bin/ai_worker.php
php bin/ai_learning_worker.php --once
```

## Документация

- `docs/AI_ARCHITECTURE.md`
- `docs/AI_SETUP.md`
- `docs/AI_TOOLS.md` / `AI_MEMORY.md` / `AI_RAG.md`
- `docs/AI_LEARNING.md` / `AI_SECURITY.md` / `AI_TESTING.md`
- `docs/AI_TROUBLESHOOTING.md`
- `docs/AI_API.md`

## Следующие улучшения (не блокеры)

- RecommendationEngine персонализации (views/favorites)  
- Больше tools: return/dispute drafts  
- OpenAI/Anthropic providers за тем же `LLMProviderInterface`  
- E2E HTTP suite с CSRF/session  
