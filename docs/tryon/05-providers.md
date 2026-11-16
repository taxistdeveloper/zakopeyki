# 05 — Провайдеры AI

## 1. Контракт

```php
namespace App\Services\TryOn\Providers;

interface TryOnProviderInterface
{
    public function id(): string;                         // 'genlook' | 'fashn' | 'fake'
    public function isTest(): bool;                       // true только у Fake / mock-режима
    public function supports(string $tryonType): bool;
    public function estimateCostMicros(string $tryonType): int;

    /** Отправка. Бросает ProviderException с retryable=true|false. */
    public function submit(TryOnSubmission $s): ProviderJobState;   // state=pending + externalId

    public function status(string $externalId): ProviderJobState;   // pending|processing|completed|failed

    /** Удаление данных пользователя у провайдера, если поддерживается. */
    public function purgeUser(string $externalUserId): bool;

    public function cancel(string $externalId): bool;     // false, если не поддерживается
}
```

`TryOnSubmission`: путь к фото покупателя (JPEG), путь к фото товара, `tryonType`, `externalUserId` (HMAC-SHA256 от `user_id` с `TRYON_USER_HASH_SECRET`, обрезанный до 32 hex — без ПД), `jobPublicId`.

`ProviderJobState`: `state`, `externalId`, `resultUrl?`, `errorCode?` (нормализованный), `costMicros?`.

`ProviderException`: `code` (нормализованный), `retryable`, `httpStatus`. Тексты провайдера не уходят дальше лога (без ПД).

HTTP — `ProviderHttp` на curl с инжектируемым транспортом `callable(method, url, body, headers): [status, body, headers]` по образцу `app/Services/Cdek/Client.php:34`; таймауты connect 5 с / total 30 с.

## 2. GenlookProvider (основной)

Документация: https://genlook.app/docs/tryon-api/introduction (проверено 2026-11-09).

| Операция | Вызов |
|---|---|
| submit | `POST https://api.genlook.app/tryon/v1/try-on`, `x-api-key`, `multipart/form-data`: поле `data` (JSON) + файлы `person`, `garment` |
| status | `GET https://api.genlook.app/tryon/v1/generations/{id}` |
| purgeUser | `DELETE https://api.genlook.app/tryon/v1/customers/{externalUserId}` → 204 |
| cancel | не поддерживается (у одиночных try-on нет cancel) → `false` |

Тело `data`:
```json
{
  "products": [ { "title": "<название товара>", "images": [ { "source": { "fileKey": "garment" } } ] } ],
  "person":   { "image": { "source": { "fileKey": "person" } } },
  "externalUserId": "<hmac>",
  "mock": false,
  "output": { "resolution": "default", "keepForDays": 1, "watermark": false }
}
```
One-shot продукт (без `externalId`): у Genlook хранится 7 дней после последнего использования; фото товара публичное, ПД нет.

Маппинг статусов: `PENDING`→pending, `PROCESSING`→processing, `COMPLETED`→completed (`resultImageUrl`, временная ссылка — скачиваем сразу), `FAILED`→failed (`errorCode`).

Маппинг ошибок:
| Genlook | Наш код | retryable |
|---|---|---|
| HTTP 429 `RATE_LIMITED` | `provider_busy` | да |
| HTTP 5xx, сеть | `provider_error` | да |
| HTTP 402 `INSUFFICIENT_CREDITS` | `provider_unavailable` (+ алерт админу) | нет (→ fallback) |
| HTTP 400 `VALIDATION_FAILED`, `CUSTOMER_IMAGE_*`, `PRODUCT_IMAGES_REQUIRED` | `provider_rejected_image` | нет |
| async `GENERATION_TIMEOUT` | `provider_timeout` | нет |
| async `GENERATION_FAILED`, `PRODUCT_IMAGE_FETCH_FAILED`, прочие | `generation_failed` | нет |

Стоимость: 1 кредит за 1 товар при `resolution=default`; PAYG $0.04 → `estimateCostMicros = 40000` (из конфига, т.к. тарифы меняются). Неудачные генерации возвращаются.

Особенность: товар «не носимый на теле» → Genlook завершает генерацию **с исходным фото без изменений** и без списания. Поэтому для `succeeded` проверяем, что результат не идентичен входу (сравнение хеша/размера после перекодирования невозможно точно → сравниваем перцептивный хеш с исходным фото покупателя); при совпадении → `failed/product_not_supported`, а не «успех». Для заданий `mock: true` проверка не выполняется (mock по определению возвращает исходное фото, такие задания `is_test = 1`).

Mock-режим (`mock: true`): возвращает фото покупателя без изменений, бесплатно. Используется в PHASE 2 для проверки интеграции; такие задания помечаются `is_test = 1`.

Типы MVP: `upper`, `lower`, `dress`. Позже (после проверки качества): `shoes`, `glasses`, `hat`, `bag`, `jewelry`, `watch` — по документации поддерживаются [качество не подтверждено].

Не подтверждено и проверяется в PHASE 2: хосты CDN для `resultImageUrl` (allowlist), путь эндпоинта проверки кредитов, наличие вебхуков у Try-On API, фактическое время генерации.

## 3. FashnProvider (резерв, PHASE 4)

Документация: https://docs.fashn.ai/api-reference/tryon-v1-6, https://docs.fashn.ai/api-overview/api-fundamentals.

| Операция | Вызов |
|---|---|
| submit | `POST https://api.fashn.ai/v1/run`, `Authorization: Bearer`, JSON `{ "model_name": "tryon-v1.6", "inputs": { ... } }` с base64 data-URI |
| status | `GET https://api.fashn.ai/v1/status/{id}` |
| purgeUser | нет API (удаление по email) → `false` |

Маппинг типов: `upper`→`tops`, `lower`→`bottoms`, `dress`→`one-pieces`. Цена v1.6: 1 кредит = $0.075. Лимиты: 100 `/run` в минуту, 6 одновременных. Точные имена полей `inputs` (`model_image`, `garment_image`, `category`) [сверить с документацией при реализации].

## 4. FakeTryOnProvider (тесты и dev)

- `isTest() = true`, стоимость 0, без сети.
- Результат — исходное фото покупателя с крупной плашкой «ТЕСТ — НЕ AI» (GD), сохраняется как обычный результат с `is_test = 1`.
- Сценарии управляются конфигом/конструктором: успех, `failed/generation_failed`, retryable-ошибка N раз, таймаут, задержка K опросов.
- В проекте нет `APP_ENV`, поэтому доступен только при `getenv('TRYON_ALLOW_FAKE') === '1'` (через `config/tryon.php`); на проде переменная не задаётся. В админке отмечен «тестовый».

## 5. Router

`TryOnProviderRouter::pick(string $type, array $exclude = []): ?TryOnProviderInterface` — первый по `tryon_providers_order`, включённый, с ключом, `supports($type)`, не в `$exclude`, без «открытого предохранителя» (≥5 ошибок `provider_error` за 5 мин → пропуск на 5 мин, счётчик в `RateLimiter`-хранилище/Redis).
