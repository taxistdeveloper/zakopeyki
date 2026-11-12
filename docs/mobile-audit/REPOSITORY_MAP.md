# REPOSITORY_MAP — карта репозитория Zakopeyki.kz

> MASTERING-00 · аудит от 2026-11-09 · ветка `feature/contests-module` · только чтение.
> Обозначения: **[Ф]** — подтверждённый факт (со ссылкой), **[П]** — обоснованное предположение, **[?]** — неизвестно.

## 1. Стек и runtime

| Параметр | Значение | Источник |
|---|---|---|
| Язык | PHP, минимум 8.1 (readonly, enum, `never`, constructor promotion); локально CLI 8.3.30 | [Ф] код `app/**`, `docs/AI_SETUP.md:5`, `php -v` |
| Фреймворк | **Собственный MVC без фреймворка и без Composer** (нет `composer.json`, `package.json`) | [Ф] корень репозитория |
| Автозагрузка | PSR-4-подобный `spl_autoload_register` для `App\` → `app/` | [Ф] `index.php:18-28` |
| Точка входа | `index.php` (front controller), `.htaccess` → `RewriteRule ^ index.php` | [Ф] `index.php`, `.htaccess` |
| Маршрутизация | `App\Core\Router` (GET/POST/DELETE, regex `{param}`), таблица в `config/routes.php` (396 маршрутов: 142 GET, 253 POST, 1 DELETE; ~105 — `/admin`) | [Ф] `app/Core/Router.php`, `config/routes.php` |
| БД | MySQL 8, utf8mb4, InnoDB, PDO singleton (`ATTR_EMULATE_PREPARES=false`) | [Ф] `app/Core/Database.php`, `config/database.php`, `ARCHITECTURE_AUDIT.md` |
| Кэш/локи | Redis — опционально (AML, CDEK, локи micro-tasks, AI rate limit); APCu/файл как fallback в AI | [Ф] `app/Services/AI/Support/AiCache.php`, `app/Services/MicroTaskService.php:61` |
| Веб-сервер | Apache + mod_rewrite (+ mod_headers) | [Ф] `.htaccess`; [П] PHP-FPM/mod_php — не установлено |
| Шаблоны | PHP-views `app/Views/**` (110 файлов), layout `layouts/main` | [Ф] `app/Core/View.php` |
| Frontend | Vanilla JS, один бандл `public/assets/js/app.js` (~8 000 строк) + `tryon.js`; без сборки | [Ф] `public/assets/js` |
| AI | Ollama (`qwen2.5:7b-instruct`, `llava`, `nomic-embed-text`), whisper.cpp (STT), Azure TTS / python `edge-tts` | [Ф] `config/ai.php:63-108`, `app/Services/AI/**` |
| Тесты | PHPUnit 10.5 конфиг (`phpunit.xml`, только Unit) — **phpunit не установлен**; 24 собственных раннера `tools/run_*_tests.php` | [Ф] `phpunit.xml`, `.gitignore`, `tools/` |

## 2. Объём кода (строки, *.php/*.js/*.sql)

| Каталог | Файлов | Строк |
|---|---|---|
| `app/Services` | 210 | ~50 500 |
| `app/Views` | 110 | ~25 300 |
| `app/Models` | 59 | ~18 800 |
| `app/Controllers` | 42 | ~14 800 |
| `app/Helpers` | 18 | ~3 700 |
| `app/Core` | 8 | ~820 |
| `public/assets/js` | 2 | ~8 100 |
| `lang` | 6 | ~9 300 |
| `tests` | 66 | ~10 600 |
| `database` | 65 | ~3 100 |

Крупнейшие контроллеры: `AdminController` (2190), `ProfileController` (1298), `PaymentController` (1186), `AiAssistantController` (1069), `DeliveryController` (740), `AuthController` (732).

## 3. Структура каталогов

```
index.php                 front controller: config → autoload → Auth::start → Lang::boot → stub-mode gate → Router
config/                   app.php, routes.php, database.php, ai.php, halyk.php, tryon.php, aml.php, *.example
app/Core/                 Auth, Router, Csrf, Database, Lang, Model, View, Controller
app/Controllers/          42 контроллера (HTML-страницы + часть JSON)
app/Models/               59 Active-Record-подобных моделей; многие создают/дополняют таблицы в рантайме
app/Services/             доменные сервисы (см. §4)
app/Helpers/              Upload, Product, Mail, Totp, GoogleOAuth, ActivityLogger, ReferralCapture …
app/Jobs/                 ProcessAiMessageJob (очередь AI в MySQL)
app/Views/                серверные шаблоны
lang/                     ru.php, kk.php (+ modules/{ru,kk}/{contests,security}.php). Английского нет
database/                 schema.sql, seed*.sql, 60+ migrate_*.sql (без раннера и учёта версий)
bin/                      воркеры/cron/утилиты: ai_worker, tryon_worker, auction_cron, cdek_*, deploy.sh …
scripts/                  cron_*.php (escrow, vip, boost/highlight/top_fixed, ads, push, contests, business)
tools/                    собственные тест-раннеры, wakeword_bench (R&D)
tests/                    PHPUnit Unit/Services/**, Integration/ApiRouterTest.php
public/assets, public/uploads   статика и ПОЛЬЗОВАТЕЛЬСКИЕ ЗАГРУЗКИ (локальный диск)
docs/                     AI_*.md, cdek-*.md, contests/, tryon/ ; теперь mobile-audit/
storage/                  cache, keys, models, tryon (gitignored)
```

## 4. Ключевые доменные модули

| Домен | Основные файлы |
|---|---|
| Аутентификация | `app/Core/Auth.php`, `app/Controllers/AuthController.php`, `app/Helpers/Totp.php`, `app/Helpers/GoogleOAuth.php`, `app/Services/Security/AuthThrottle.php` |
| Каталог/товары | `app/Models/Product.php`, `app/Helpers/ProductHelper.php` (типы, дерево категорий, URL), `CatalogController`, `ProductController`, `HomeController` |
| Объявления | `ProfileController::store/updateLot/deleteLot/storeGig`, `StockService`, `app/Services/Listing/*`, `UploadHelper` |
| Корзина/заказы/escrow | `app/Services/Cart.php` (сессия), `CheckoutController`, `app/Models/Order.php`, `EscrowService`, `ReturnService`, `OrderController` |
| Платежи | `app/Services/HalykEpay/*`, `app/Services/Payment/*` (Interface/Factory/Halyk/FreedomPay), `PaymentController`, `app/Models/Payment.php`, `Wallet.php`, `WalletService`, `AMLService`, `app/Services/Finance/*` |
| Доставка | `app/Services/Delivery/*` (LogisticsProviderInterface, Cdek/Stub провайдеры), `app/Services/Cdek/*` (25 классов), `DeliveryController` |
| Соцфункции | `Chat`, `Follow`, `Review`, `Notification`, `Story`, `Stream` (+`StreamController`), `ContestController` + `app/Services/Contest/*`, `Bonus`, `ReferralCapture` |
| Платные услуги | `app/Services/{Boost,Highlight,TopFixed,TopFixedXl,Vip,Ads,TargetPush,MassPush}/*`, `PaidServices/PaidServiceCatalog.php`, `BusinessPackageService` |
| Аукционы | `AuctionService`, `app/Services/Auction/*` (English/Dutch/Continuous), `bin/auction_cron.php` |
| Цифровые курсы | `DigitalController`, `app/Services/Digital/*`, `Streaming/CloudflareStreamClient.php` |
| Micro-tasks | `/api/v1/micro-tasks/*`, `MicroTaskService`, `BaseApiController` |
| Business/Seller API | `BusinessController`, `BusinessApiController`, `SellerApiKey` |
| AI «Зак» | `AiAssistantController`, `app/Services/AI/{Core,Providers,Tools,Memory,Learning,Support,DTO}`, `RagEngine`, `bin/ai_worker.php` |
| Virtual Try-On | `TryOnController`, `app/Services/TryOn/*`, `bin/tryon_worker.php` (незакоммичено, Phase 3) |

## 5. Фоновые процессы (cron/воркеры)

[Ф] Скрипты есть, **но crontab/Supervisor-конфигов в репозитории нет** [?] фактическое расписание на сервере неизвестно.

- `scripts/cron_escrow.php` — автозакрытие сделок по `inspect_until`, таймеры возвратов.
- `scripts/cron_{vip,highlight,top_fixed,top_fixed_xl,ads,target_push,mass_push,business,contests}.php`.
- `bin/{ai_worker,ai_learning_worker,tryon_worker,auction_cron,ops_task_deadlines,ai_maintenance,sync_aml,cdek_order_poll,cdek_print_poll,cdek_reconcile_orders,cdek_sync_delivery_points,cdek_healthcheck,ai_healthcheck}.php`.

## 6. Деплой, CI, наблюдаемость

- `bin/deploy.sh` — `git pull` + `php bin/update-changelog.php --force`; без миграций, тестов, рестарта воркеров. [Ф]
- CI/CD — **отсутствует** (нет `.github/`, другого CI). [Ф]
- Мониторинг/crash reporting — **нет** (Sentry и т.п. не найдено). Логи: `ActivityLogger` (в БД), `error_log()`, `logs/*.log`. [Ф]
- Резервное копирование — скриптов/документов **не найдено**. [Ф] [?] на хостинге может быть.

## 7. Гигиена репозитория (зафиксировано, не исправлялось)

- В git отслеживаются `config/database.php` (литеральные — вероятно, локальные — учётные данные БД), `config/halyk.php`, `config/ai.php`, `config/aml.php`, `config/tryon.php`. Секреты Halyk/AI/Try-On берутся из env [Ф]; в `config/aml.php` — только имена ключей Redis, не секреты [Ф].
- В git отслеживаются ~202 файла из `public/uploads/**`, включая `public/uploads/business/*` (документы KYC бизнес-заявок). [Ф]
- В корне (web root) лежат посторонние файлы: `*.txt` с UUID-именами, `qa.zip`, `test_dmitry.mp3`, `tmp_home.html`, `_vas11_extract/`, `_vas12_extract/`. `.htaccess` закрывает расширения `md|txt|mp3|zip|sql|log|sh`, но `tmp_home.html` и каталоги `_vas*` — [?] не проверено, доступны ли.
- `openapi_api_v2_integration.json` — **спецификация стороннего API CDEK**, не внутреннего API Zakopeyki. [Ф]
- Схема БД частично создаётся в рантайме (`ensureTable/ensureColumns`, `SHOW COLUMNS` в ~52 моделях) — `schema.sql` + `migrate_*.sql` не являются полным источником истины. [Ф]

## 8. Существующая документация

`ARCHITECTURE_AUDIT.md` (2026-09-23, частично устарел: SSE/voice/confirm уже реализованы), `AI_IMPLEMENTATION_PLAN.md`, `docs/AI_*.md` (11 файлов, в целом соответствуют коду), `docs/cdek-*.md` (13 файлов; `cdek-payment-flow.md` устарел в части callback — описывает FreedomPay, тогда как по умолчанию Halyk), `docs/contests/`, `docs/tryon/`.
