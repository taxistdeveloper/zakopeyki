# AI Testing & Acceptance

## Unit

```bash
# MAMP PHP example
/Applications/MAMP/bin/php/php8.3.30/bin/php vendor/bin/phpunit tests/Unit/Services/AI
```

Покрыто: IntentDetector, LanguageDetector, PermissionChecker, PromptInjectionGuard, ListingParamsExtractor, MemoryScorer, RateLimiter, Learning evaluate.

## Smoke acceptance

```bash
php bin/ai_acceptance.php
```

Проверяет (без обязательного Ollama):

1. Search intent + `Product::searchAdvanced` (реальный массив, без галлюцинаций)
2. Listing extract / CREATE_LISTING
3. Order ownership (свой / чужой)
4. Язык kk
5. Feedback → learning event → pipeline
6. Prompt injection
7. Sales recommendation vs create listing
8. Rate limit
9. Memory scorer

Exit code `0` = критичные проверки зелёные.

## Ручной checklist

| # | Сценарий | Ожидание |
|---|----------|----------|
| 1 | «Найди iPhone 15 до 300000 в Алматы» | search tool, реальные карточки или честный «не найдено» |
| 2 | Фото + «хочу продать» | vision draft, без auto-publish |
| 3 | «Где моя посылка?» | delivery своего заказа |
| 4 | Казахский запрос | ответ на kk |
| 5 | 👎 feedback | `ai_feedback` + `ai_learning_events` |
| 6 | Чужой order id | permission deny |
