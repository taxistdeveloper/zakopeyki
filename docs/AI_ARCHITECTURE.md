# AI Architecture — zakopeyki.kz

См. также: `ARCHITECTURE_AUDIT.md`, `AI_IMPLEMENTATION_PLAN.md`.

## Pipeline

```
USER → AiAssistantController → ProcessAiMessageJob → AIOrchestrator
  → LanguageDetector → IntentDetector → ContextManager
  → PermissionChecker → ToolManager → Platform Services (MySQL)
  → ResponseBuilder (AiResponse) → ai_messages / audit / usage
```

## Key classes

| Class | Role |
|-------|------|
| `AIOrchestrator` | Центральный pipeline |
| `IntentDetector` | NLU + параметры (цена, город, бренд) |
| `ToolManager` | Безопасный вызов tools + audit |
| `SearchProductsTool` | `Product::searchAdvanced` |
| `GetOrderStatusTool` | Order + ownership |
| `GetDeliveryStatusTool` | DeliveryOrder + ownership |
| `OllamaProvider` | LLMProviderInterface |
| `PromptInjectionGuard` | Фильтр недоверенного ввода |

## Source of truth

Товары, цены, заказы, доставка — **только MySQL / сервисы**. LLM не генерирует карточки товаров.

## Config

`config/ai.php` — `orchestrator_enabled`, models, rate limits, `performance.*`, redis optional.

## Performance

- Context: `max_history_messages`, `max_history_chars`, `max_memory_items`
- Cache: KB search TTL, category index, embedding vectors (`storage/cache/ai`)
- Vision async: `performance.vision_async` / `AI_VISION_ASYNC=1` + worker queue `vision`

## Migrate

```bash
php bin/ai_migrate.php
```
