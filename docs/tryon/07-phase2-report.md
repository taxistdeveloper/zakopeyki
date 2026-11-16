# PHASE 2 — AI prototype: отчёт

Дата: 2026-11-09. Платных запросов не было. Реальных AI-генераций не было (нет API-ключа Genlook).

## 1. Что реализовано

| Компонент | Файл |
|---|---|
| Конфигурация (секреты только из env) | `config/tryon.php` |
| DDL | `database/migrate_tryon.sql` (+ `TryOnJob::ensureSchema()`) |
| Модели | `app/Models/TryOnJob.php`, `app/Models/TryOnAsset.php` |
| Статусы, типы, ошибки | `app/Services/TryOn/TryOnJobStatus.php`, `TryOnTypes.php`, `TryOnException.php` |
| Настройки (settings + config) | `app/Services/TryOn/TryOnSettings.php` |
| Валидация и нормализация фото | `app/Services/TryOn/TryOnImageValidator.php` |
| Приватное хранилище | `app/Services/TryOn/TryOnStorage.php` → `storage/tryon/YYYY/MM/<hex>.jpg` |
| Лимиты и бюджет | `app/Services/TryOn/TryOnUsageService.php` |
| Бизнес-логика | `app/Services/TryOn/TryOnService.php` |
| Шаг воркера | `app/Services/TryOn/TryOnJobRunner.php` |
| Скачивание результата (SSRF) | `app/Services/TryOn/TryOnResultFetcher.php` |
| Провайдеры | `app/Services/TryOn/Providers/*` — `GenlookProvider`, `FakeTryOnProvider`, `TryOnProviderRouter`, контракт |
| Воркер | `bin/tryon_worker.php` |
| API | `app/Controllers/TryOnController.php`, маршруты в `config/routes.php` |
| Тексты ошибок и согласия ru/kk | `lang/ru.php`, `lang/kk.php` → `tryon.*` |
| Тесты | `tools/run_tryon_tests.php` |

UI (кнопка, модалка), админка и `FashnProvider` — PHASE 3–4.

## 2. Проверки

| Проверка | Результат |
|---|---|
| `php tools/run_tryon_tests.php` (unit + интеграция на локальной БД, Fake, без сети) | **105 passed, 0 failed** |
| E2E через HTTP (`php -S`, вход тестовым пользователем, бета-доступ, реальный `bin/tryon_worker.php`) | capabilities → 200; POST без CSRF → 419; создание → 202 queued; повтор ключа → 200 `idempotent`; не-изображение → 415; воркер: queued → processing → succeeded; `/result` → 200 `image/jpeg`, `Cache-Control: private, no-store`; `/photo` → 200; удаление → 200; `/result` после удаления → 410; файлов в `storage/tryon` не осталось |
| Гость при `stub_mode` | получает HTML-заглушку сайта (фото не отдаются) |
| Регрессия: `run_router_csrf_exempt_tests`, `run_contests_tests`, `run_cdek_client_tests` | пройдены (91 / 17 / OK) |
| `php -l` всех новых и изменённых файлов | без ошибок |

Тестовые пользователи, товары, задания, файлы и настройки после прогонов удалены/восстановлены.

**Не проверено:** реальное качество, скорость и стоимость Genlook — нужен ключ (см. §4). `looksUnchanged()` (защита от «результата = исходное фото») проверена только на синтетических изображениях; порог подтвердить на реальных ответах.

## 3. Отличия от документов PHASE 1

| Было | Стало | Почему |
|---|---|---|
| Тесты в `tests/Unit` (PHPUnit) | `tools/run_tryon_tests.php` | `tools/phpunit.phar` в репозитории нет; проект использует такие раннеры (`tools/run_*_tests.php`) |
| Идемпотентность после валидации файла | сразу после проверки доступа | повтор запроса не перекодирует фото и не тратит лимит |
| Проверка лимитов один раз | до записи файла и повторно под `GET_LOCK('tryon_budget')` | исключает гонку параллельных запросов |
| `DELETE /jobs/{id}` | `POST /jobs/{id}/delete` | `Router` поддерживает только GET/POST; `Router::delete()` — в PHASE 3 (D6) |
| Сравнение перцептивным хешем | `TryOnImageValidator::looksUnchanged()` — 95-й перцентиль разницы яркости на сетке 32 px после центр-кропа | aHash устойчив к изменению одежды и даёт ложные «без изменений» |
| — | `is_test` ставится при создании задания | UI сразу знает, что результат тестовый |
| — | Fake хранит состояние в `storage/cache/tryon_fake` | cron-воркер — отдельные процессы |

Дополнительные коды: `photo_required`, `idempotency_key_invalid` (API); `config_error`, `product_not_supported` (задание). Ошибки провайдера нормализуются (`provider_out_of_credits`, `provider_auth_failed`, `provider_busy` …), текст провайдера не сохраняется.

**Подтверждено в коде:** на фото товаров накладывается водяной знак `zakopeyki.kz` (`app/Controllers/ProfileController.php:1074`). Он попадёт в примерку как часть одежды → проверить на реальных генерациях; вариант — хранить копию без водяного знака для AI (решение PHASE 3).

## 4. Проверка Genlook — решение: только mock (2026-11-09)

Платные генерации (бесплатные 10 кредитов тоже) **не выполняются** до отдельного разрешения.

Скрипт `bin/tryon_genlook_mock_check.php`:
- mock включён принудительно в коде; при неактивном mock скрипт останавливается до запроса;
- синтетические изображения, без фото людей и без БД;
- submit → опрос статуса → проверка хоста `resultImageUrl` по allowlist → скачивание через `TryOnResultFetcher` → `DELETE /customers/{externalUserId}`;
- ключ и URL результата не выводятся (только хост).

Запуск (ключ не попадает в историю shell и в чат):

```bash
read -s TRYON_GENLOOK_API_KEY && export TRYON_GENLOOK_API_KEY
php bin/tryon_genlook_mock_check.php
```

Без ключа скрипт завершается с кодом 2 (проверено).

| Что проверяет mock | Что mock НЕ проверяет |
|---|---|
| ключ, формат multipart-запроса, коды ответов, поллинг, хост и скачивание результата, удаление данных пользователя | качество примерки, реальное время генерации, стоимость, водяной знак на фото товара, порог `looksUnchanged()` |

Результаты прогона 2026-11-09 21:54 (mock, синтетические изображения, локально):

| Шаг | Результат |
|---|---|
| `POST /try-on` (multipart, `mock: true`) | 201, `PENDING`, 0.95 с — ключ и формат запроса приняты |
| `GET /generations/{id}` | `COMPLETED` с 1-го опроса, всего 2.2 с (mock; реальное время не измерялось) |
| Хост `resultImageUrl` | `https://storage.googleapis.com` — входит в allowlist |
| Скачивание через `TryOnResultFetcher` | 17 170 байт, 800×1000, нормализовано |
| `DELETE /customers/{externalUserId}` | 204/OK |

Выводы и доработки:
- Интеграция `GenlookProvider` подтверждена на реальном API в бесплатном режиме.
- Результаты отдаются с общего хоста Google Cloud Storage: allowlist по хосту допускает любой публичный bucket. SSRF-риск низкий (публичный GCS, внутренняя сеть недоступна), но в PHASE 3 стоит сузить проверку до bucket'а Genlook (первый сегмент пути) после того, как он будет зафиксирован.
- Ключ, использованный для проверки, засвечен в переписке и истории shell → перевыпустить в кабинете Genlook; на сервере хранить только в переменной окружения.
