# CDEK API Client (Phase 3)

Backend-интеграционный слой CDEK для Zakopeyki.kz.  
Скрывает детали OpenAPI v2 от бизнес-логики delivery / checkout.

Источники:
- `openapi_api_v2_integration.json`
- `docs/cdek-integration-audit.md`
- `docs/cdek-data-mapping.md`
- `docs/cdek-state-machine.md`

На этом этапе **не** реализованы: seller form, checkout UI, quote UI, оплата, auto-create после оплаты, inbound webhook processing, полноценный frontend.

---

## Архитектура

```
Business services (DeliveryService, …)
        │
        ▼
   CdekApi  (typed facade: locations, PVZ, calculator, orders, print, webhooks)
        │
        ▼
   Client   (единый HTTP: auth header, timeout, safe retry, JSON, logging)
        │
   ┌────┴────┐
   ▼         ▼
CdekAuthService     CdekErrorMapper / CdekApiResponse / CdekRequestLogger
(OAuth + cache)     (202 async, errors, tech log без PII)
```

Правило: **все** HTTP-запросы к CDEK проходят только через `App\Services\Cdek\Client` (или фасад `CdekApi`).  
Прямые `curl`/Guzzle к CDEK из других сервисов запрещены.

Ключевые классы:

| Класс | Роль |
|-------|------|
| `CdekApi` | Типизированные методы endpoint'ов |
| `Client` | Единый HTTP-транспорт |
| `CdekAuthService` | OAuth2 client_credentials + кэш токена |
| `CdekApiResponse` | Нормализованный ответ (`successful` / `accepted` / `processing` / `invalid` / `error`) |
| `CdekErrorMapper` | Нормализация ошибок CDEK → внутренние коды |
| `CdekRequestLogger` | Техлог без секретов/PII |

---

## Конфигурация

Файл: `config/cdek.php` (в `.gitignore`). Шаблон: `config/cdek.php.example`.

| Ключ | Env | Описание |
|------|-----|----------|
| `account` | `CDEK_ACCOUNT` | OAuth `client_id` |
| `secure_password` | `CDEK_SECURE_PASSWORD` | OAuth `client_secret` |
| `api_url` | `CDEK_API_URL` | Base URL `/v2` |
| `environment` | (из `CDEK_TEST_MODE`) | `test` / `production` |
| `test_mode` | `CDEK_TEST_MODE` | `1` = test |
| `developer_key` | `CDEK_DEVELOPER_KEY` | Optional header `developer-key` |
| `timeout` | `CDEK_TIMEOUT` | HTTP timeout (сек) |
| `connect_timeout` | `CDEK_CONNECT_TIMEOUT` | Connect timeout |
| `retry_max` | `CDEK_RETRY_MAX` | Max attempts для **safe** retry |
| `retry_backoff_ms` | `CDEK_RETRY_BACKOFF_MS` | Backoff |
| `token_cache_path` | `CDEK_TOKEN_CACHE` | File cache path |
| `redis_token_key` | `CDEK_REDIS_TOKEN_KEY` | Redis key |
| `webhook_token` / `webhook_url` | `CDEK_WEBHOOK_*` | Подготовка webhook (processing — later) |
| `order_create_enabled` | `CDEK_ORDER_CREATE_ENABLED` | Gate реального POST `/orders` (default off) |
| `http_log_*` | `CDEK_HTTP_LOG_*` | Техлог |

### Environments

| Env | Base URL |
|-----|----------|
| test | `https://api.edu.cdek.ru/v2` |
| production | `https://api.cdek.ru/v2` |

Секреты: только env / локальный `config/cdek.php`.  
**Не** во frontend, **не** в Git, **не** в обычные логи.

---

## OAuth

Endpoint: `POST /v2/oauth/token`  
Параметры (form): `grant_type=client_credentials`, `client_id`, `client_secret`.

Flow:

1. `CdekAuthService::getAccessToken()` проверяет memory → Redis → file cache.
2. При miss / `force=true` — запрос нового токена.
3. TTL из `expires_in`, с запасом ~60 сек до истечения.
4. `Client` ставит `Authorization: Bearer <token>` на каждый API-вызов.
5. При HTTP 401 — один refresh + один повтор запроса (не считается unsafe create-retry).

Токен и `client_secret` **не логируются**.

---

## HTTP 202 (async)

CDEK может вернуть `202 ACCEPTED`: запрос принят, финальный результат ещё неизвестен.

`CdekApiResponse`:

| `internal_status` | Смысл |
|-------------------|--------|
| `accepted` | HTTP 202 / `requests[].state=ACCEPTED` |
| `processing` | `state=WAITING` |
| `successful` | Финальный успех (не 202) |
| `invalid` | `state=INVALID` или errors в requests |
| `error` | HTTP/transport failure |

Методы:

- `isAcceptedPending()` — accepted/processing
- `isFinalSuccess()` — только `successful`
- `isDuplicateOrder()` — duplicate IM / order number

Бизнес-слой **не** должен считать 202 финальным созданием заказа; нужен последующий GET `/orders/{uuid}` / webhook (следующие этапы).

---

## Retry strategy

| Операция | Auto-retry |
|----------|------------|
| GET / HEAD | Да (network/timeout/429/5xx) |
| POST `/calculator/*` | Да (идемпотентный расчёт) |
| POST `/orders` | **Нет** |
| PATCH `/orders` | **Нет** |
| DELETE | **Нет** |
| POST print / webhooks | **Нет** |

Если у Delivery Order уже есть `cdek_uuid`, повторный create на бизнес-уровне не выполнять (идемпотентность — Stage с Delivery Order).

401 → один token refresh + один retry (даже для create) — это обновление auth, не дублирование create-retry loop.

---

## Error handling

`CdekErrorMapper` нормализует:

| `error_type` | Примеры |
|--------------|---------|
| `network` | DNS, connection |
| `timeout` | curl timeout |
| `authentication` | 401, `invalid_client` |
| `authorization` | 403, `access_denied` |
| `validation` | HTTP 4xx без спец. кода |
| `business` | tariff/city/point/package |
| `duplicate_order` | `v2_similar_order_exists`, `v2_im_number_already_used`, … |
| `rate_limit` | 429 |
| `server` | 5xx |
| `unknown` | прочее |

Frontend **не** получает raw CDEK body.  
Пользователю — безопасный текст; техника — в `error_mapped` / логах.

---

## Logging

`CdekRequestLogger` → `storage/logs/cdek-http.log` (если включено):

- method, path (без query), duration_ms, http_status
- request_id, cdek_uuid, internal_status, error_type, retry

**Не логируется:** `client_secret`, `access_token`, адреса, телефоны, email, ФИО, полные body.

---

## Поддерживаемые endpoint'ы (через `CdekApi`)

### OAuth
- `POST /v2/oauth/token` — через `CdekAuthService` / `getAccessToken()`

### Location
- `GET /v2/location/suggest/cities`
- `GET /v2/location/regions`
- `GET /v2/location/postalcodes`
- `GET /v2/location/coordinates`
- `GET /v2/location/cities`

### Delivery points
- `GET /v2/deliverypoints`
- `GET /v2/deliverypoints/byPolygons` — **GAP**: нет в предоставленном OpenAPI; метод возвращает структурированную ошибку

### Calculator
- `POST /v2/calculator/tariff`
- `POST /v2/calculator/tariffAndService`
- `POST /v2/calculator/tarifflist`
- `GET /v2/calculator/alltariffs`

### Orders
- `GET /v2/orders`
- `POST /v2/orders` (без auto-retry; 202 → accepted)
- `PATCH /v2/orders`
- `GET /v2/orders/{uuid}`
- `DELETE /v2/orders/{uuid}`

### Printing
- `POST /v2/print/orders`
- `GET /v2/print/orders/{uuid}`
- `GET /v2/print/orders/{uuid}.pdf`

### Webhooks (registration only)
- `GET /v2/webhooks`
- `POST /v2/webhooks`
- `GET /v2/webhooks/{uuid}`
- `DELETE /v2/webhooks/{uuid}`

Inbound webhook processing — **следующий этап**.

---

## Тесты

```bash
php tools/run_cdek_client_tests.php
# или PHPUnit:
# vendor/bin/phpunit tests/Unit/Services/Cdek/CdekClientIntegrationLayerTest.php
```

Покрыто:
1. OAuth token
2. Cached token reuse
3. Force/expired refresh
4. Authorization header
5. Successful GET
6. HTTP 202 ≠ final success
7. HTTP 400
8. HTTP 401
9. HTTP 429
10. HTTP 500
11. Timeout
12. Network error
13. Duplicate IM/order-number
14. Запрет unsafe retry create order

Реальные production-запросы в тестах не выполняются (transport mocks).

---

## GAP / следующие этапы

| GAP | Комментарий |
|-----|-------------|
| `Интеграция.docx` | Файл не найден в репозитории |
| `/deliverypoints/byPolygons` | Нет в предоставленном OpenAPI |
| Inbound webhooks | Только registration API; processing later |
| Business create-after-pay | `order_create_enabled=false`; FSM + payment stage |
| Seller / checkout / quote UI | Отдельные этапы |
| Print PDF binary streaming | Метод есть; UX/attachment — later |

**Следующий этап (не начинать здесь):** бизнес-flow (seller Point A, checkout Point B, quote в UI, оплата доставки, создание CDEK-заказа только после confirmed payment, webhook processing).
