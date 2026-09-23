# AI Security

## Rate limit

`RateLimiter`: Redis (если `AI_REDIS`) → MySQL `ai_rate_limits` → file `storage/cache/ai_rate`.

Лимиты в `config/ai.php`:

```php
'rate_limit' => [
    'per_minute' => 20,
    'per_hour' => 200,
],
```

Применяется к `/ai/chat`, `/ai/chat/stream`, `/ai/voice`, `/ai/image`. Ответ `429` + `Retry-After`.

Ключ: `user_id` → guest_token hash → IP hash.

## Ownership

Order/product tools проверяют buyer/seller через `PermissionChecker`. Чужой ID → отказ, без данных.

## Prompt injection

`PromptInjectionGuard` фильтрует типичные jailbreak-фразы перед LLM.

## Critical actions

Публикация объявления / деструктивные действия — только через `ActionConfirmationService` + confirm token.

## Learning

Candidate prompts не становятся active без admin approve.
