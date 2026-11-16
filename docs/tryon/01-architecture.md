# 01 — Архитектура

## 1. Схема

```
Сайт (products/show.php + tryon.js)      Мобильное приложение (PHASE 5)
                \                          /
                 ▼                        ▼
        /api/v1/try-on/*  ── TryOnController (BaseApiController)
                 │   auth (сессия + CSRF; позже Bearer), rate limit
                 ▼
            TryOnService ── TryOnImageValidator ── TryOnStorage (storage/tryon)
                 │      └── TryOnUsageService (лимиты, бюджет)
                 ▼
        tryon_jobs (MySQL, status=queued)   ← очередь = таблица
                 ▲
   cron * * * * *  php bin/tryon_worker.php --max-runtime=55
                 │
                 ▼
          TryOnProviderRouter ──► GenlookProvider (основной)
                 │              ├► FashnProvider  (резерв, PHASE 4)
                 │              └► FakeTryOnProvider (тесты/dev)
                 ▼
     результат скачивается → storage/tryon → succeeded
                 ▼
     клиент опрашивает GET /jobs/{id} → GET /jobs/{id}/result
```

Новых сервисов, очередей и БД нет: всё — в существующем PHP-приложении и MySQL.

## 2. Структура модуля (по соглашениям проекта)

```
app/Controllers/TryOnController.php          JSON API, extends BaseApiController
app/Controllers/AdminController.php          + методы tryOn*, view admin/tryon.php
app/Models/TryOnJob.php                      ensureTable + запросы
app/Models/TryOnAsset.php
app/Services/TryOn/TryOnService.php          создание/чтение/удаление заданий
app/Services/TryOn/TryOnJobRunner.php        шаг воркера: submit / poll / fetch / timeouts
app/Services/TryOn/TryOnJobStatus.php        константы статусов и разрешённые переходы
app/Services/TryOn/TryOnImageValidator.php   MIME, размеры, pixel-bomb, перекодирование
app/Services/TryOn/TryOnStorage.php          запись/чтение/удаление файлов вне webroot
app/Services/TryOn/TryOnUsageService.php     лимиты пользователя/системы, бюджет
app/Services/TryOn/TryOnSettings.php         обёртка над Setting + config/tryon.php
app/Services/TryOn/TryOnTypes.php            upper|lower|dress|shoes|glasses|hat|bag|jewelry|watch
app/Services/TryOn/Providers/TryOnProviderInterface.php
app/Services/TryOn/Providers/TryOnProviderRouter.php
app/Services/TryOn/Providers/GenlookProvider.php
app/Services/TryOn/Providers/FashnProvider.php        (PHASE 4)
app/Services/TryOn/Providers/FakeTryOnProvider.php
app/Services/TryOn/Providers/ProviderHttp.php         curl + инжектируемый транспорт (как Cdek/Client.php)
app/Services/TryOn/DTO/TryOnSubmission.php, ProviderJobState.php
app/Views/tryon/_modal.php                   партиал для products/show.php
app/Views/admin/tryon.php
public/assets/js/tryon.js                    подключается только на странице товара
bin/tryon_worker.php                         воркер + очистка (--cleanup)
config/tryon.php                             параметры; ключи только через getenv()
database/migrate_tryon.sql                   DDL (дублирует ensureTable моделей)
lang/ru.php, lang/kk.php                     ключи tryon.*
tests/Unit/Services/TryOn/*
```

`TryOnJobManager` из ТЗ разделён на `TryOnJobStatus` (машина состояний) и `TryOnJobRunner` (исполнение) — без отдельного менеджера-прослойки.

## 3. Жизненный цикл задания

```
            create (валидация, лимиты, бюджет OK)
                     │
                     ▼
   ┌──────────── queued ───────────┐
   │ worker claim+submit           │ DELETE пользователем / queue timeout
   ▼                               ▼
processing ──DELETE──► cancelled   cancelled | failed(queue_timeout)
   │  poll
   ├── COMPLETED → скачать результат → succeeded ──TTL──► expired
   └── FAILED / timeout ───────────► failed     ──TTL──► expired
```

| Статус | Значение | Переходы |
|---|---|---|
| `queued` | принято, ждёт воркера | processing, cancelled, failed |
| `processing` | отправлено провайдеру, есть `external_id` | succeeded, failed, cancelled |
| `succeeded` | результат сохранён у нас | expired |
| `failed` | ошибка, `error_code` заполнен | expired |
| `cancelled` | удалено пользователем до завершения | — |
| `expired` | файлы удалены по сроку хранения, строка — для статистики | — |

Удаление пользователем `succeeded` задания: файлы удаляются сразу, `deleted_at` проставляется, статус → `expired` (строка без путей остаётся для учёта расходов).

Захват задания воркером — атомарный `UPDATE ... SET locked_until = NOW()+INTERVAL 60 SECOND, locked_by = ? WHERE id = ? AND (locked_until IS NULL OR locked_until < NOW())`, без отдельного статуса.

## 4. Воркер

`php bin/tryon_worker.php --max-runtime=55` из cron каждую минуту (работает на любом хостинге с cron; можно запускать как демон без `--max-runtime`).

Цикл (каждые ~2 с, `flock` на `storage/cache/tryon_worker.lock` против двойного запуска):
1. Взять ≤N `queued` (N = `tryon_max_concurrent` минус уже `processing`) → `provider->submit()`.
2. Взять `processing` с `next_poll_at <= NOW()` → `provider->status()`; бэкофф 3 → 5 → 10 с.
3. `COMPLETED` → скачать результат (см. 04 §SSRF) → `succeeded`.
4. Таймауты: `queued` > 10 мин → `failed/queue_timeout`; `processing` > 5 мин → `failed/provider_timeout`.
5. Раз в 10 минут: очистка просроченных файлов (`expires_at < NOW()`).

Ретраи submit: только для сетевых ошибок/5xx/429 провайдера, до 3 попыток с бэкоффом; 4xx валидации → `failed` сразу.

**Fallback**: если основной провайдер недоступен *до* принятия задания (сеть/5xx после ретраев, выключен админом, не поддерживает тип) — задание отдаётся резервному. После того как провайдер вернул `external_id`, переключение запрещено (исключает двойное списание).

## 5. Сценарий покупателя → вызовы

| Шаг UI | Вызов |
|---|---|
| Открыл товар | сервер рендерит кнопку, если `tryon_enabled` и `products.tryon_type` поддерживается |
| «Примерить на себе» | `GET /api/v1/try-on/capabilities?product_id=` (лимиты, форматы, текст согласия) |
| Выбор/съёмка фото | только локальная проверка и превью в браузере; на сервер ничего не уходит |
| Выбор фото товара | индекс из галереи товара |
| Согласие + «Запустить» | `POST /api/v1/try-on/jobs` (multipart, `Idempotency-Key`) |
| Ожидание | `GET /api/v1/try-on/jobs/{id}` с `poll_after_ms` |
| Результат / сравнение | `GET .../result`, `GET .../photo` |
| Повтор с другим фото товара | новый `POST` (новый ключ идемпотентности, списывает лимит) |
| Удалить | `DELETE /api/v1/try-on/jobs/{id}` |
| Вернуться к покупке | закрытие модалки; кнопка «Купить» на месте |

## 6. Интеграция с существующим кодом

| Место | Изменение |
|---|---|
| `config/routes.php` | маршруты `/api/v1/try-on/*`, `/admin/try-on*` |
| `app/Core/Router.php` | + `delete()` (D6), обратно совместимо |
| `app/Views/products/show.php` | кнопка рядом с «Купить» (строки ~25–27) + include `_modal.php` |
| форма объявления (`ProfileController::store/update`, views профиля) | селект «Тип примерки» для категорий одежды/обуви/аксессуаров |
| `app/Helpers/AdminNav.php` | пункт «Виртуальная примерка» |
| `app/Services/Business/BusinessAiOperationService` | **не трогаем** в MVP; позже executor может вызывать `TryOnService` (D2) |

## 7. Будущая собственная модель (PHASE 6)

Отдельный Python/FastAPI GPU-сервис с тем же HTTP-контрактом (`POST /jobs`, `GET /jobs/{id}`), адаптер `SelfHostedProvider` реализует тот же `TryOnProviderInterface`. Версия модели пишется в `tryon_jobs.provider_model`. Откат — переключение порядка провайдеров в админке. Экономически оправдано от ~20–30k генераций/мес и только для коммерчески лицензированной модели.
