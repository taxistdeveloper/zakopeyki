# 02 — API-контракты

Префикс `/api/v1/try-on` (как `/api/v1/micro-tasks`, `/api/v1/business`). Контроллер наследует `BaseApiController`, конверт ответа — существующий:

```json
{ "success": true,  "data": { ... }, "error": null }
{ "success": false, "data": { "code": "quota_exceeded", "retry_after": 3600 }, "error": "Локализованный текст" }
```

`data.code` — стабильный машинный код (клиенты ветвятся по нему), `error` — текст на языке пользователя (`ru`/`kk`).

## Общие правила

| Аспект | Правило |
|---|---|
| Авторизация | сайт: сессия (`Auth::check()`), гости → 401 `auth_required`. Мобильное: Bearer-токен покупателя — PHASE 5 |
| CSRF | для изменяющих запросов с сессией: заголовок `X-CSRF-Token` (`Csrf::tokenFromRequest`) |
| Идентификаторы | `public_id` — 26 символов случайных (не автоинкремент), чужой/несуществующий → одинаково 404 |
| Идемпотентность | заголовок `Idempotency-Key` (8–64 символа `[A-Za-z0-9_-]`), уникален в паре с пользователем |
| Rate limit | `RateLimiter` с ключом `tryon:u{id}`: 6/мин, 30/ч на все эндпоинты записи; 429 + `Retry-After` |
| Кеш | ответы с фото: `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff` |
| Версия | `v1` в пути; несовместимые изменения → `v2` |

## GET /api/v1/try-on/capabilities?product_id={id}

Без `product_id` — общие возможности.

```json
{
  "enabled": true,
  "types": ["upper", "lower", "dress"],
  "product": {
    "id": 123,
    "tryon_type": "upper",
    "supported": true,
    "garment_images": [ { "index": 0, "url": "/public/uploads/products/p_....jpg" } ]
  },
  "limits": { "daily_left": 2, "monthly_left": 17, "active_jobs": 0, "max_active_jobs": 1 },
  "upload": { "max_bytes": 10485760, "formats": ["image/jpeg", "image/png", "image/webp"], "min_side_px": 512, "max_side_px": 6000 },
  "consent": { "version": "2026-11-v1", "text_key": "tryon.consent_text" },
  "retention_hours": 24
}
```

Гостю отдаётся `enabled` и `product.supported` (для показа кнопки), `limits: null`.

## POST /api/v1/try-on/jobs

`multipart/form-data`:

| Поле | Обяз. | Описание |
|---|---|---|
| `photo` | да | фото покупателя |
| `product_id` | да | ID активного объявления |
| `garment_image_index` | нет (0) | индекс фото товара из `garment_images` |
| `consent` | да | `1` — согласие на обработку и передачу провайдеру |
| `consent_version` | да | должна совпасть с текущей |

Заголовки: `X-CSRF-Token`, `Idempotency-Key`.

Порядок проверок (до любых платных действий): авторизация → CSRF → rate limit → модуль включён → товар активен и тип поддерживается → согласие → валидация файла → идемпотентность → лимит активных заданий → дневной/месячный лимит → бюджет (под `GET_LOCK('tryon_budget')`) → запись файла → `INSERT queued`.

`202 Accepted`:
```json
{ "job": { "id": "01JC...", "status": "queued", "product_id": 123, "created_at": "2026-11-09T12:00:00+05:00", "poll_after_ms": 3000 }, "idempotent": false }
```
Повтор с тем же `Idempotency-Key` → тот же `job`, `"idempotent": true`, лимит не списывается.
Тот же ключ, но другой `product_id` → 409 `idempotency_conflict`.

## GET /api/v1/try-on/jobs/{id}

```json
{
  "job": {
    "id": "01JC...", "status": "processing", "product_id": 123, "tryon_type": "upper",
    "created_at": "...", "finished_at": null, "expires_at": null,
    "error_code": null, "result_available": false, "is_test": false, "poll_after_ms": 3000
  }
}
```
`is_test: true` — результат тестового провайдера; UI обязан показывать плашку «Тестовый результат, не AI».

## GET /api/v1/try-on/jobs?product_id={id}&limit=10

Свои задания (для сравнения вариантов), без удалённых, новые первыми. Формат элемента — как выше.

## GET /api/v1/try-on/jobs/{id}/result и /photo

Поток `image/jpeg` (результат / исходное фото покупателя для сравнения). Только владелец. Не готово → 409 `not_ready`; удалено/истекло → 410 `gone`.

## DELETE /api/v1/try-on/jobs/{id}

Алиас для клиентов без DELETE: `POST /api/v1/try-on/jobs/{id}/delete`. Требует CSRF.
Удаляет файлы локально; `queued`/`processing` → `cancelled` (если провайдер уже принял задание — результат будет удалён при получении; расход учитывается). Ответ `200 {"deleted": true}`. Повторный вызов → тот же ответ (идемпотентно).

## Коды ошибок

| HTTP | code | Когда |
|---|---|---|
| 401 | `auth_required` | нет сессии/токена |
| 403 | `csrf_invalid` | неверный CSRF |
| 403 | `module_disabled` | модуль выключен админом / stub |
| 404 | `not_found` | нет задания/товара или чужое |
| 409 | `idempotency_conflict`, `not_ready` | см. выше |
| 410 | `gone` | файлы удалены |
| 413 | `file_too_large` | > max_bytes |
| 415 | `unsupported_format` | не JPEG/PNG/WebP или файл не изображение |
| 422 | `consent_required`, `consent_outdated`, `image_too_small`, `image_too_large_dimensions`, `product_not_supported`, `garment_image_missing` | валидация |
| 429 | `rate_limited`, `active_job_exists`, `daily_limit`, `monthly_limit` | лимиты; `retry_after` |
| 503 | `budget_exhausted`, `provider_unavailable` | бюджет/провайдеры |

Коды ошибок задания (`job.error_code`): `provider_rejected_image`, `content_moderation`, `generation_failed`, `provider_timeout`, `queue_timeout`, `result_fetch_failed`, `provider_error`. Текст провайдера пользователю не показывается.

## Веб-хуки провайдеров

В MVP нет. Зарезервировано: `POST /webhooks/try-on/{provider}` с проверкой подписи и дедупликацией (по образцу `DeliveryWebhookEvent`), добавляется в `stubAllowed` в `index.php`.
