# AI IMPLEMENTATION PLAN — zakopeyki.kz

База: `ARCHITECTURE_AUDIT.md`  
Стек: чистый PHP MVC + MySQL + optional Redis + Ollama  
Принцип: **расширять** `App\Services\AI\*`, не дублировать Escrow/Delivery/Product.

---

## 1. Текущая архитектура (as-is)

```
UI (floating Улы)
  → POST /ai/chat
  → AiAssistantController
  → ProcessAiMessageJob
  → SupportAiService
       ├─ IntentClassifier (5 intents)
       ├─ CatalogAiAssistant (LIKE search)
       ├─ RagEngine (FULLTEXT KB)
       ├─ OllamaClient
       └─ SelfLearningService
  → MySQL ai_* + AiQueue
```

---

## 2. Целевая архитектура (to-be)

```
USER (text | voice | image | page context)
  → AI UI (existing widget, extended)
  → AI API (/ai/chat, /voice, /image, /action/confirm, /stream)
  → AIOrchestrator
       ├─ LanguageDetector
       ├─ IntentDetector (+ params)
       ├─ ContextManager (user, page, product, order, dialog)
       ├─ MemoryManager
       ├─ PermissionChecker
       ├─ ToolManager → Platform Services (Product, Order, Escrow, Delivery, Return…)
       ├─ RagService (FULLTEXT + VectorStore)
       ├─ ModelRouter → LLMProviderInterface (OllamaProvider)
       ├─ VisionService / VoiceService
       ├─ ActionConfirmationService
       ├─ ResponseBuilder (structured)
       └─ Feedback / Learning pipeline
  → MySQL (source of truth) + Redis optional (cache/streams/rate-limit)
```

`SupportAiService` остаётся facade/compat или тонким адаптером → `AIOrchestrator`.

---

## 3. Адаптация master-prompt под текущий стек

| Требование prompt | Решение в zakopeyki |
|-------------------|---------------------|
| Redis Streams | `AiQueueInterface`: MySQL (prod-default) + RedisStreamsQueue (если ext-redis) |
| Composer DI | `AiBootstrap` / factories, без контейнера |
| Vector DB | `MysqlVectorStore` (JSON embedding + cosine) |
| Voice | Frontend Web Speech API + `SpeechToTextInterface` (Ollama whisper / shell) |
| English | AI language detection; UI locales остаётся ru/kk, AI может отвечать en |
| Streaming | SSE endpoint + Ollama stream |
| No mock | Tools всегда ходят в реальные Models/Services |

---

## 4. Этапы реализации

### ЭТАП 1 — Audit ✅
- `ARCHITECTURE_AUDIT.md`

### ЭТАП 2 — Architecture docs
- Этот файл + `docs/AI_ARCHITECTURE.md` (краткая схема)

### ЭТАП 3 — Database migrations
Файл: `database/migrate_ai_platform.sql`

Новые таблицы (только нужные):
- `ai_memories`, `ai_memory_embeddings`
- `ai_knowledge_chunks`, `ai_knowledge_embeddings`
- `ai_tool_calls`, `ai_audit_logs`, `ai_usage_logs`
- `ai_action_confirmations`
- `ai_prompts`, `ai_prompt_versions`
- `ai_model_configs`
- `ai_user_preferences`
- `ai_learning_events`, `ai_evaluations`
- `ai_events` (опционально, если не хватает Redis)

Расширения существующих:
- `ai_messages`: `response_type`, `request_id`, `language`
- `ai_feedback`: reason enum / tags
- `ai_conversations`: `context_json` (page/product/order)

### ЭТАП 4 — AI Core
Классы (namespace `App\Services\AI`):

```
Contracts/
  LLMProviderInterface.php
  SpeechToTextInterface.php
  TextToSpeechInterface.php
  VectorStoreInterface.php
  AiQueueInterface.php
  ToolInterface.php

Providers/
  OllamaProvider.php          # wraps/evolves OllamaClient
  WebSpeechSttPassthrough.php # accepts client-side transcript
  NullTtsProvider.php         # TTS via browser; server optional later

Core/
  AIOrchestrator.php
  ModelRouter.php
  ContextManager.php
  LanguageDetector.php
  PermissionChecker.php
  ResponseBuilder.php
  PromptRegistry.php
  AiConfig.php
  RetryPolicy.php
  RateLimiter.php             # Redis or file/MySQL

DTO/
  AiRequest.php
  AiResponse.php
  IntentResult.php
  ToolResult.php
  PageContext.php
```

### ЭТАП 5 — Intent engine
- `IntentDetector` + `Intent` enum (PHP 8.1 backed enum)
- Parameter extraction (price, city, brand, model) via LLM JSON + heuristics
- Intents: SEARCH_PRODUCT, CREATE_LISTING, ANALYZE_LISTING, PRICE_RECOMMENDATION,
  IMAGE_ANALYSIS, ORDER_STATUS, DEAL_STATUS, DELIVERY_STATUS, PAYMENT_STATUS,
  RETURN_REQUEST, DISPUTE_REQUEST, SELLER_ANALYTICS, FAVORITES, RECOMMENDATIONS,
  SUPPORT, GENERAL_QUESTION, UNKNOWN, HUMAN_ESCALATE, GREETING…

### ЭТАП 6 — Tool system
```
Tools/
  ToolManager.php
  AbstractTool.php
  SearchProductsTool.php
  SearchCategoriesTool.php
  GetProductTool.php
  CompareProductsTool.php
  GetMarketPricesTool.php
  GetRecommendationsTool.php
  CreateListingDraftTool.php
  UpdateListingTool.php
  AnalyzeListingTool.php
  SuggestPriceTool.php
  AnalyzeListingImagesTool.php
  GetOrderStatusTool.php
  GetDeliveryStatusTool.php
  GetPaymentStatusTool.php
  CreateReturnDraftTool.php
  CreateDisputeDraftTool.php
  GetUserProfileTool.php
  GetFavoritesTool.php
  GetSalesStatisticsTool.php
  AnalyzeDemandTool.php
```

Каждый tool: name, description, schema, permission, execute, audit.

### ЭТАП 7 — Marketplace integration
- `Product::searchAdvanced(array $filters)` — q, type, category, min/max price, location, limit
- Tools → Order (с ownership), DeliveryService, ReturnService (draft only + confirm), EscrowService (read)
- `PriceRecommendationService` на основе similar products
- `ListingDraftService` — черновик в session/DB, публикация только после confirm

### ЭТАП 8 — Memory
- MemoryManager, MemoryRepository, MemoryRetriever, MemoryWriter, MemorySummarizer, MemoryCleaner, MemoryScorer
- Types: short_term (Redis/session), conversation, user, semantic, episodic
- «Стоит ли запоминать?» — scorer + явные предпочтения

### ЭТАП 9 — RAG
- Document → chunk → embed (Ollama) → MysqlVectorStore
- Hybrid retrieve: FULLTEXT + vector re-rank
- Admin reindex CLI: `bin/ai_index_knowledge.php`

### ЭТАП 10 — Vision
- VisionService + Ollama vision model (configurable)
- AnalyzeListingImagesTool
- Не утверждать неопределённые факты

### ЭТАП 11 — Voice
- UI: MediaRecorder / Web Speech → transcript → тот же chat pipeline
- Backend: accept audio upload → STT provider when available
- TTS: browser speechSynthesis (default)

### ЭТАП 12 — Personalization
- RecommendationEngine на product_views / favorites / purchases (без LLM-генерации товаров)
- LLM только объясняет выбор

### ЭТАП 13 — Learning ✅
- Расширить SelfLearningService
- LearningQueue job types, EvaluationService, PromptOptimizer (candidate only)
- Никакого auto-deploy prompt без admin validation

### ЭТАП 14 — Admin ✅
- Dashboard metrics, prompts versions, KB indexing, learning queue, audit logs
- Routes под `/admin/ai-*`

### ЭТАП 15 — Security ✅
- Fix order ownership
- RateLimiter, PromptInjectionGuard, ActionConfirmationService
- Tool permission + CSRF on mutations
- Audit logs

### ЭТАП 16 — Testing ✅
- Unit: IntentDetector, PermissionChecker, ToolManager, PriceRecommendation, MemoryScorer
- Integration: search tool against DB, chat endpoint
- E2E scripts / acceptance checklist in `AI_TESTING.md` + `bin/ai_acceptance.php`

### ЭТАП 17 — Performance ✅
- Async default for heavy vision/embed
- Cache categories / KB chunks
- Context size limits

### ЭТАП 18 — Final audit ✅
- `docs/AI_FINAL_AUDIT.md` + acceptance scenarios

---

## 5. Список ключевых файлов (новые)

```
ARCHITECTURE_AUDIT.md
AI_IMPLEMENTATION_PLAN.md
docs/AI_ARCHITECTURE.md
docs/AI_SETUP.md
docs/AI_API.md
docs/AI_TOOLS.md
docs/AI_MEMORY.md
docs/AI_RAG.md
docs/AI_LEARNING.md
docs/AI_SECURITY.md
docs/AI_TESTING.md
docs/AI_TROUBLESHOOTING.md

database/migrate_ai_platform.sql

config/ai.php                          # расширить

app/Services/AI/...                    # см. этапы 4–13
app/Services/PriceRecommendationService.php
app/Services/Listing/ListingDraftService.php
app/Services/RecommendationEngine.php

app/Controllers/AiAssistantController.php  # расширить
app/Models/AiMemory.php, AiPrompt.php, AiAuditLog.php, AiActionConfirmation.php…

bin/ai_index_knowledge.php
bin/ai_worker.php                      # расширить job types

public/assets/js/app.js                # voice/image/confirm/stream
app/Views/layouts/main.php             # UI controls
app/Views/admin/ai-*.php

tests/Unit/Services/AI/*
```

---

## 6. API (согласовано с routes.php)

| Method | Path | Назначение |
|--------|------|------------|
| POST | `/ai/chat` | текст (+ context_json) |
| GET | `/ai/chat/messages` | история |
| POST | `/ai/chat/feedback` | 👍/👎 + reason |
| POST | `/ai/chat/stream` | SSE streaming |
| POST | `/ai/voice` | audio или transcript |
| POST | `/ai/image` | upload + analyze |
| POST | `/ai/action/confirm` | confirm token |
| GET | `/ai/conversations` | список (auth) |

---

## 7. Redis / Queues

| Канал | Backend |
|--------|---------|
| AI message jobs | MySQL `ai_queue_jobs` (существующий) |
| Learning events | MySQL `ai_learning_events` + optional Redis Stream `ai:learning` |
| Rate limit | Redis INCR или file/APCu fallback |
| Session short-term memory | Redis HASH `ai:ctx:{session}` TTL или MySQL |
| Embeddings cache | Redis STRING optional |

---

## 8. Providers

| Role | Default | Future |
|------|---------|--------|
| Chat LLM | OllamaProvider (`qwen2.5:7b-instruct`) | OpenAI, Anthropic |
| Vision | Ollama vision model (config) | same interface |
| Embedding | Ollama embed model | — |
| STT | Client Web Speech → passthrough | Whisper/Ollama |
| TTS | Browser speechSynthesis | server TTS |

---

## 9. Tools → Platform Services (source of truth)

| Tool | Service/Model |
|------|---------------|
| search_products | Product::searchAdvanced |
| get_product | Product::findWithSeller |
| get_order_status | Order + Auth ownership |
| get_delivery_status | DeliveryOrder / DeliveryService |
| get_payment_status | Payment |
| create_return_draft | ReturnService (confirm → openCase) |
| create_dispute_draft | OrderController flow via service |
| create_listing_draft | ListingDraftService → Product::create on confirm |
| get_favorites | Favorite |
| get_sales_statistics | Order aggregates |
| suggest_price | PriceRecommendationService |
| knowledge | RagService / AiKnowledge |

---

## 10. Memory / RAG / Learning (кратко)

- Memory: только после scorer ≥ threshold или явного «запомни»  
- RAG: правила платформы, FAQ, delivery/payment/return docs  
- Learning: feedback → events → dataset → evaluation → **candidate** prompt → admin approve  

---

## 11. Tests (минимальный acceptance)

1. «Найди iPhone 15 до 300000 в Алматы» → search_products → реальные карточки  
2. Фото + «хочу продать» → vision draft (без auto-publish)  
3. «Где моя посылка?» → delivery status своего заказа  
4. Казахский запрос → ответ на kk  
5. Feedback → строка в ai_feedback + learning event  
6. Чужой order id → отказ permission  

---

## 12. Порядок работ после этого документа

```
AUDIT → IMPLEMENT → TEST → FIX → DOCUMENT → CONTINUE
```

**Старт реализации:** ЭТАП 3 (migrations) → ЭТАП 4 (core contracts + Orchestrator) → ЭТАП 5–7 (intent + tools + Product search) → security fix order ownership → UI → …  

Критические блокеры не переносятся на следующие этапы.

---

## 13. Definition of Done (платформенный слой)

Пользователь без изучения UI может сказать/написать:

> «Найди мне iPhone 15 до 300 тысяч в Алматы.»

и получить **реальные** результаты из MySQL через tool, с объяснением и без галлюцинаций товаров.
