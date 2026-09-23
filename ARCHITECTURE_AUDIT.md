# ARCHITECTURE AUDIT — zakopeyki.kz

Дата: 2026-09-23  
Стек: чистый PHP MVC, MySQL 8, Redis (опционально), Ollama  
Правило аудита: код AI до завершения этого документа не писался.

---

## 1. Архитектура проекта

| Слой | Реализация |
|------|------------|
| Entry | `index.php` — autoload `App\`, Auth, Lang, stub_mode, Router |
| Routing | `app/Core/Router.php` + `config/routes.php` |
| Controllers | `app/Controllers/*` — HTTP → сервисы/модели |
| Models | `app/Models/*` — PDO SQL (без ORM) |
| Services | `app/Services/*` — бизнес-логика (Escrow, Delivery, CDEK, AI, Return…) |
| Views | `app/Views/*` — PHP-шаблоны |
| Config | `config/*.php` |
| Jobs/CLI | `app/Jobs/*`, `bin/*` |
| API | частично через те же routes (`/ai/chat`, `/api/v1/micro-tasks/*`) |

**Нет:** Laravel/Symfony/Yii, Composer, Doctrine/Eloquent, отдельного DI-контейнера.  
**Есть:** PSR-4-подобный autoload через `spl_autoload_register`, constructor injection вручную, prepared statements, CSRF, session auth.

---

## 2. Структура директорий

```
zakopeyki/
├── index.php
├── config/          app, database, ai, routes, aml, cdek.example…
├── app/
│   ├── Core/        Auth, Router, Database, Csrf, Lang, Model, View, Controller
│   ├── Controllers/ ~30 контроллеров
│   ├── Models/      ~35 моделей
│   ├── Services/    бизнес + AI/, Cdek/, Delivery/, Listing/, Digital/, Auction/
│   ├── Jobs/        ProcessAiMessageJob
│   ├── Helpers/
│   ├── Views/
│   └── ApiRouter.php (micro-tasks)
├── database/        schema.sql + ~45 migrate_*.sql
├── public/assets/   js/app.js, img, icons
├── lang/            ru.php, kk.php
├── bin/             ai_worker, ai_migrate, ai_healthcheck, cdek_*, auction_cron…
├── tests/           Unit (Cdek, AML, MicroTask…), Integration
└── scripts/         cron_escrow, cron_business
```

Composer.json **отсутствует**. PHPUnit через `tools/phpunit.phar` (в .gitignore).

---

## 3. Существующие модули

| Модуль | Статус | Ключевые классы |
|--------|--------|-----------------|
| Auth / 2FA / Google OAuth | ✅ | AuthController, Auth, Totp |
| Users / profile / business | ✅ | User, ProfileController, Business* |
| Catalog / products / listings | ✅ | Product, ProductController, CatalogController |
| Search | ⚠️ базовый | `Product::allActive` LIKE + FULLTEXT в schema |
| Cart / checkout | ✅ | Cart, CheckoutController |
| Escrow orders | ✅ | Order, EscrowService, OrderController |
| Returns / disputes | ✅ | ReturnService, OrderReturnEvent |
| Delivery / CDEK | ✅ | DeliveryService, Cdek*, DeliveryOrder |
| Payments / FreedomPay | ✅ | Payment, FreedomPay, AcquiringService |
| Wallet / bonuses | ✅ | WalletService, Bonus |
| Chat (P2P) | ✅ | Chat, ChatController |
| Support tickets | ✅ | SupportTicket, SupportController |
| AI support assistant | ⚠️ частичный | см. §17 |
| Auctions | ✅ | AuctionService, strategies |
| Live streams | ✅ | Stream, StreamController |
| Digital / courses | ✅ | DigitalProduct, DigitalController |
| Micro-tasks / gigs | ✅ | MicroTaskService, ApiRouter |
| AML | ✅ | AMLService + Redis set |
| Admin / library / ops tasks | ✅ | AdminController, Library, OpsTask |
| Notifications | ✅ | Notification model |
| i18n | ⚠️ | ru + kk (en нет в UI) |
| Stub mode | ✅ | закрытие сайта до открытия |

---

## 4. База данных

- MySQL, `utf8mb4`, InnoDB  
- Имя БД в schema: `zakapeiku`; в `config/database.php`: `zakopeyki` — **возможный drift**  
- Миграции: ручные SQL + `bin/ai_migrate.php`; модели часто делают `ensureTable()` / `ensureColumn()`

### AI-таблицы (уже есть — `migrate_ai_support.sql`)

- `ai_conversations`, `ai_messages`, `ai_knowledge_base` (FULLTEXT)
- `ai_queue_jobs` (MySQL-очередь)
- `ai_intent_logs`, `ai_feedback`, `ai_few_shots`, `ai_training_datasets`

### Marketplace (фрагмент)

- `users` (role: user|manager|admin; account_type personal|business)
- `products` (types: used|new|auction|free|exchange|service|course|gig)
- `orders` (escrowed → shipped → delivered → completed / dispute / return / cancelled…)
- `delivery_orders` + tracking/quotes/payments
- `payments`, `wallets`, `favorites`, `product_views`, `reviews`, `chat_*`, `support_*`

---

## 5. API

| Endpoint | Назначение |
|----------|------------|
| `POST /ai/chat` | сообщение AI |
| `GET /ai/chat/messages` | история диалога |
| `POST /ai/chat/feedback` | оценка 1–5 |
| `/api/v1/micro-tasks/*` | микрозадачи |
| Admin `/admin/ai-chats*` | эскалация оператору |

Остальное — page routes + form POST (не REST).  
**Нет:** `/api/ai/voice`, `/api/ai/image`, `/api/ai/action/confirm`, streaming SSE.

---

## 6. Authentication

- Session cookie (httponly, samesite Lax, secure при HTTPS)
- Password + Google OAuth + TOTP 2FA
- CSRF: `App\Core\Csrf`
- Роли: `user`, `manager` (permissions JSON), `admin`
- `Auth::hasSiteAccess` для stub_mode
- Guest AI: `guest_token` в conversation

---

## 7. User system

- Personal / business accounts, AML (IIN blacklist), personal limits
- Seller stats через orders/reviews
- Favorites, follows, stories

---

## 8. Marketplace

Типы лотов: б/у, новые, аукцион, даром, обмен, услуги, курсы, гиги.  
Эскроу-сделки, прямые сделки (`deal_mode`), корзина, live-shop.

---

## 9. Search

- `Product::allActive($type, $search, $category)` — LIKE по title/description/category/exchange_for
- Schema имеет FULLTEXT `ft_search`, но AI-поиск его **не использует**
- Нет фильтров max_price / location в `allActive` (location есть в колонке, фильтр не прокинут)
- `CatalogAiAssistant` — эвристики + stop-words, без LLM-парсинга параметров

---

## 10. Listings

- Создание: `ProfileController::store` + `Product::create`
- Shipping: `ListingShippingService`, `ProductListingShipping`
- Нет отдельного ListingDraftService для AI

---

## 11. Orders

Статусы (факт): `awaiting_payment`, `escrowed`, `shipped`, `delivered`, `completed`, `cancelled`, `refunded`, dispute/return ветки через `ReturnService`.  
Логика в `EscrowService` + `Order` + `OrderController`.

---

## 12. Delivery

- `DeliveryService` + CDEK provider + Stub provider  
- Статусы: `DELIVERY_DATA_COLLECTION` … `IN_TRANSIT` … `DELIVERED`  
- Redis: кэш токена CDEK, lock create order  
- Webhook: `/webhooks/delivery/status`

---

## 13. Payments

- FreedomPay + simulated payments (`allow_simulated_payments`)
- Wallet deposit/withdraw
- Escrow hold/release

---

## 14. Messaging

- P2P: `chat_conversations` / `chat_messages`
- Support tickets: отдельно
- AI chats: `ai_*` — **не смешивать** с P2P chat

---

## 15. Notifications

- Таблица `notifications`, модель `Notification`
- Нет event-bus; точечные insert из контроллеров/сервисов

---

## 16. Statistics

- `product_views`, `SiteVisit`, seller completed order counts
- Admin dashboard частично
- Нет единого AnalyticsService для продавца под AI tools

---

## 17. Existing AI functionality

### Уже реализовано

| Компонент | Файл | Что делает |
|-----------|------|------------|
| Floating UI «Улы» | `layouts/main.php` + `app.js` | чат, suggestions, polling, CSAT |
| Controller | `AiAssistantController` | chat / messages / feedback |
| Orchestrator (support) | `SupportAiService` | Intent → Catalog / FAQ RAG / Escalate |
| Intent | `IntentClassifier` | 5 intents: GREETING, FAQ, ACTION, CATALOG, ESCALATE |
| LLM | `OllamaClient` | chat, embeddings, listModels (без stream) |
| RAG | `RagEngine` + `AiKnowledge` | MySQL FULLTEXT knowledge |
| Learning | `SelfLearningService` | few-shots, feedback→dataset, operator learn |
| Queue | `AiQueue` + `ProcessAiMessageJob` + `bin/ai_worker.php` | MySQL jobs |
| Catalog helper | `CatalogAiAssistant` | поиск без LLM (fallback) |
| Admin | ai-chats reply/close, export dataset | human escalation |
| Config | `config/ai.php` | Ollama URL, model, sync/async |

### Не реализовано / слабо

- Tool Manager / permissions на tools  
- Structured NL → search params (цена, город, бренд)  
- Memory (user preferences, semantic, episodic)  
- Vector RAG / embeddings storage  
- Vision / image analysis  
- Voice STT/TTS  
- Streaming responses  
- Action confirmation tokens  
- Price recommendation service  
- Listing draft via AI  
- Seller analytics tools  
- Model router / prompt versioning  
- Prompt-injection hardening  
- Rate limiting AI endpoints  
- Redis streams для learning  
- English locale  
- Proactive assistant  
- Hallucination guard поверх tool results  

**Критично:** `handleActionQuery` читает заказ по ID **без проверки buyer/seller** — security gap.

---

## 18. Existing Redis functionality

Redis **опционален** (`ext-redis`), fallback на файл/MySQL:

| Use | Где |
|-----|-----|
| CDEK OAuth token cache | CdekAuthService |
| CDEK order create lock | CdekOrderService |
| AML IIN set | AMLService / AmlListSyncService |
| Micro-task locks | MicroTaskService / MicroTaskLock |

AI queue = **MySQL**, не Redis. Streams для AI **нет**.

---

## 19. External integrations

- Ollama (local LLM)  
- CDEK OpenAPI  
- FreedomPay  
- Google OAuth  
- Cloudflare Stream (webhooks)  
- SMTP mail  

---

## 20. Что уже реализовано (для AI-платформы)

1. Точка входа UI + API чата  
2. Диалоги, сообщения, feedback, intent logs  
3. Knowledge base + few-shot learning loop  
4. Ollama client  
5. Catalog search tool (эвристический)  
6. Async worker path  
7. Admin human takeover  
8. Реальные сервисы marketplace (orders, delivery, returns, products)

---

## 21. Что необходимо добавить

1. **AI Core 2.0** поверх существующего: Orchestrator, Context, Memory, ToolManager, ModelRouter, LLMProviderInterface  
2. Расширенная Intent-система (SEARCH_PRODUCT, CREATE_LISTING, ORDER_STATUS…)  
3. Реальные Tools → Product/Order/Delivery/Return/Escrow/Profile services  
4. PermissionChecker + ownership (исправить order leak)  
5. Structured search (price, location, condition) — расширить Product search  
6. Memory tables + MemoryManager  
7. VectorStore (MySQL-backed) + chunk embeddings  
8. Vision via Ollama multimodal  
9. Voice: Web Speech + backend STT/TTS interfaces  
10. Streaming (SSE)  
11. ActionConfirmationService  
12. Learning pipeline workers (evaluation, prompt versions)  
13. Admin AI dashboard (models, prompts, KB, metrics)  
14. Rate limit, audit log, prompt injection filter  
15. Tests (unit + acceptance scenarios)  
16. Docs (AI_*.md)

---

## 22. Какие места необходимо изменить

| Место | Изменение |
|-------|-----------|
| `SupportAiService` | Эволюция в тонкую обёртку или делегирование в `AIOrchestrator` |
| `IntentClassifier` | Расширить intents + parameter extraction |
| `OllamaClient` | stream, vision, retries, circuit breaker |
| `CatalogAiAssistant` | Вынести в tool `search_products`; добавить price/location |
| `Product` | Метод `searchAdvanced(filters)` |
| `AiAssistantController` | voice, image, confirm, stream; CSRF/rate limit |
| `config/ai.php` | models, limits, retention, redis, prompts |
| `migrate_ai_*.sql` | memory, embeddings, tools, prompts, confirmations, audit |
| `layouts/main.php` + `app.js` | voice, image upload, structured cards, confirm buttons |
| `AdminController` | dashboard AI metrics |
| `bin/ai_worker.php` | новые job types (embed, learn, summarize) |
| Order action path | **обязательно** Auth ownership |

---

## 23. Возможные архитектурные конфликты

| Конфликт | Решение под текущий стек |
|----------|--------------------------|
| Master-prompt требует Redis Streams | `QueueInterface`: MySQL (default) + Redis Streams (optional) |
| Нет Composer/DI | Factory `AiServiceFactory` + constructor injection (как сейчас) |
| Models содержат SQL | Tools вызывают Models/Services; SQL не в Controller/AI |
| Два «AI» (Catalog vs Support) | Единый Orchestrator; Catalog = tool |
| RAG FULLTEXT vs embeddings | Hybrid: FULLTEXT + VectorStoreInterface (MySQL vectors) |
| stub_mode закрывает сайт | AI routes добавить в stubAllowed **или** только для авторизованных с site_access |
| DB name zakapeiku vs zakopeyki | Документировать; не ломать текущий config |
| Prompt «нельзя mock» vs offline Ollama | Fallback: tools + knowledge без LLM-текста; **не** выдуманные товары |

---

## 24. Security risks

1. **Order status без ownership** в SupportAiService — HIGH  
2. Нет rate limit на `/ai/chat` — DoS / Ollama overload  
3. Guest token в localStorage — predictable если слабый RNG (сейчас `random_bytes` — OK)  
4. Prompt injection не фильтруется  
5. Feedback без CSRF (JSON) — session cookie может быть достаточна, но проверить  
6. `allow_simulated_payments=true` в config — не AI, но production risk  
7. AI не должен обходить escrow/return permissions  

---

## 25. Performance risks

1. Sync Ollama в HTTP (timeout 60s) — блокирует PHP-FPM  
2. Нет streaming — UX деградация  
3. Catalog LIKE без индексов по price/location filters  
4. Embedding всех KB docs без batch worker  
5. N+1 при сериализации многих products  
6. Worker single-queue без priority  

---

## Вердикт аудита

Платформа — зрелый marketplace на чистом PHP с **рабочим AI-support слоем (Ollama + RAG + catalog)**.  
Целевой «интеллектуальный интерфейс всей платформы» **ещё не достигнут**, но фундамент правильный: расширять `App\Services\AI\*`, а не строить параллельный фреймворк.

**Следующий артефакт:** `AI_IMPLEMENTATION_PLAN.md`  
**Стратегия:** extend-in-place, reuse services, no Laravel, no mock products.
