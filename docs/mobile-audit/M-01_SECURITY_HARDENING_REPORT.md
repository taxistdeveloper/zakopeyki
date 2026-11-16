# M-01 — Security hardening backend: отчёт

> 2026-11-09 · ветка `feature/contests-module` · изменения **не закоммичены**. Основание: SECURITY_AUDIT.md (S1–S18), BACKEND_GAP_ANALYSIS.md (B0.1–B0.8).

## 1. Что исправлено

| ID | Проблема | Исправление | Файлы |
|---|---|---|---|
| B0.1 / S1 | Halyk postLink принимал поддельные callback'и, зачислял деньги в кошелёк | Callback принимается только при наличии серверного intent; обязательный `secret_hash` (hash_equals); сверка суммы (для успеха), валюты, терминала; `pg_order_id` и сумма из запроса больше не используются; атомарная защита от повторной доставки (`claimInvoice`); отказ — HTTP 400 + запись в журнал | `PaymentController::halykPostLink`, `HalykEpayGateway::validatePostLink` (новый), `IntentStore::claimInvoice` (новый) |
| B0.2 / S2 | `allow_simulated_payments=true`, `debug` в отслеживаемом конфиге | Флаги из env (`APP_ENV`, `APP_DEBUG`, `APP_ALLOW_SIMULATED_PAYMENTS`), безопасные значения по умолчанию; при `env=production` (по умолчанию) симуляция и debug принудительно выключены; локальные переопределения — `config/app.local.php` (в `.gitignore`) | `config/app.php`, `.gitignore`, `config/app.local.php` (локально, не в git) |
| B0.3 | Реальное пополнение кошелька — fatal error (несуществующий метод) | `createPaymentIntent` → `initPayment` | `WalletController::deposit` |
| B0.4 / S3 | Ссылка сброса пароля показывалась при `Host: localhost` | Только при `env=local` из конфигурации | `AuthController::forgotPassword` |
| B0.5 / S7 | Двойной расчёт escrow (гонка) | `settle()` блокирует строку заказа `FOR UPDATE` и перепроверяет статус в транзакции; `GET /orders/{id}` запускает обработку сроков только **после** проверки доступа | `EscrowService::settle`, `OrderController::show` |
| B0.6 / S4 | KYC-документы бизнеса публичны | `.htaccess` deny в `public/uploads/business/`; выдача через `GET /admin/business/document/{file}` (только admin, строгий regex имени); ссылка в админке обновлена | `public/uploads/business/.htaccess`, `AdminController::businessDocument`, `config/routes.php`, `app/Views/admin/business.php` |
| B0.7 / S9–S10 | Webhook Cloudflare без секрета принимал всё, неверный формат подписи; CDEK открыт в test_mode | CF: без секрета — 401; поддержан формат `time=…,sig1=…` над `time.body` с допуском 5 мин (старый формат hex сохранён для совместимости). CDEK: открытый test-режим запрещён при `env=production` | `DigitalController::webhook`, `CloudflareStreamClient::verifySignatureWithSecret` (новый), `CdekWebhookService::authorize` |
| B0.8 / S12 | Подмена IP через заголовки | Новый `App\Helpers\ClientIp`: `CF-Connecting-IP` — только с адресов Cloudflare; `X-Forwarded-For` — только от loopback или `APP_TRUSTED_PROXIES`, берётся крайний правый недоверенный адрес | `app/Helpers/ClientIp.php` (новый), `ActivityLogger::clientIp` |

| B0.9 (найдено 2026-11-10) | AML-проверки ИИН/БИН (перечень АФМ) отключались по заголовку `Host: localhost`/`127.0.0.1` — в production это обход проверки | Пропуск только при `env=local` (конфигурация); регрессионный тест в `tools/run_listing_service_tests.php` | `AMLService::skipChecks` |

## 2. Проверки (фактически выполнены)

- `php -l` всех изменённых файлов — без ошибок.
- Новый раннер `php tools/run_security_m01_tests.php` — **32/32** (postLink: 11 сценариев подделки и идемпотентности; конфиг; кошелёк; reset link; ClientIp ×6; webhooks ×6; uploads; escrow-гонка на локальной БД со стухшим снимком заказа — без движений по кошельку). Флаг `--unit` — без БД.
- Все 25 существующих раннеров `tools/run_*_tests.php` — зелёные (853 проверки, 1 skip в TTS Azure — нет ключа).
- E2E на локальном `php -S`: поддельный POST на `/payments/halyk/postlink` (старый эксплойт и вариант с `secret_hash`) → HTTP 400, число `wallet_transactions` не изменилось (90 → 90); главная → 200.
- **Не выполнялось:** PHPUnit (не установлен), реальный платёж в тестовом контуре Halyk, проверка на Apache (`.htaccess` не учитывается `php -S`).

## 3. Действия при деплое (обязательно)

1. **Production:** ничего не задавать или `APP_ENV=production` — симуляция платежей и debug будут выключены. ⚠️ Если на сервере сейчас используются симулированные оплаты (например, для демо в режиме заглушки), они перестанут работать — это намеренно.
2. **Локально/staging:** `config/app.local.php` уже создан локально (`env=local`, симуляция включена) — прежнее поведение сохранено. Для staging — `APP_ENV=staging` и при необходимости `APP_ALLOW_SIMULATED_PAYMENTS=1`.
3. **Cloudflare Stream webhook:** задать `cf_stream_webhook_secret` в настройках (админка/`settings`), иначе webhook'и курсов будут отклоняться (401).
4. **CDEK webhook:** в production задать `webhook_token` в `config/cdek.php` (или `test_mode=0` с токеном).
5. **За прокси, кроме Cloudflare:** задать `APP_TRUSTED_PROXIES` (IP/CIDR через запятую), иначе IP-лимиты будут считать всех пользователей по адресу прокси.
6. **Проверить в тестовом контуре Halyk**, что postLink содержит `secret_hash`, `amount`, `currency`, `terminal` (так описано в документации ePay; на это опирается проверка). Отклонённые callback'и видны в журнале действий как `payment.halyk.postlink` / `Rejected: <причина>`.
7. Просмотреть журнал `payment.halyk.postlink` и `wallet.deposit.gateway` за прошлый период на предмет зачислений без реальной оплаты (до исправления это было возможно).

## 4. Осознанно не сделано (остаточные риски)

| Риск | Причина | Рекомендация |
|---|---|---|
| Файлы курсов (`public/uploads/digital/`) и AI-изображения (`public/uploads/ai/`) публичны по прямому URL (64/48 бит случайности в имени) | Нужна авторизованная выдача с проверкой покупки курса — изменение UX/логики доступа | Отдельная задача (M-02/P1) |
| `AuthThrottle` fail-open при недоступности хранилища | Цепочка хранилищ заканчивается файлом; fail-closed заблокирует вход при сбое | Оставлено; мониторинг `security.throttle_unavailable` |
| KYC-файлы остаются в истории git (3 файла) | Переписывание истории — необратимое действие, решение владельца | `git filter-repo` по согласованию + ротация, если репозиторий кому-то передавался |
| Ленивая обработка сроков в `GET /orders/{id}` сохранена | Нет подтверждения, что cron `cron_escrow.php` настроен на сервере | Убрать после подтверждения cron |
| S5, S11, S13, S15–S17 (удаление аккаунта, сессии после смены пароля, AI guest-лимит, CSRF-исключения, TOTP replay, HSTS/CSP) | Не входят в P0; часть — в M-02 (мобильная авторизация) | См. BACKEND_GAP_ANALYSIS P1 |
| `courier_void` (S8), `direct` без комиссии | Бизнес-решения (Q4, Q6) | Ждут владельца |

## 5. Изменённые файлы

Изменены: `app/Controllers/{PaymentController,WalletController,AuthController,OrderController,AdminController,DigitalController}.php`, `app/Services/{EscrowService,Payment/HalykEpayGateway,HalykEpay/IntentStore,Streaming/CloudflareStreamClient,Cdek/CdekWebhookService}.php`, `app/Helpers/ActivityLogger.php`, `app/Views/admin/business.php`, `config/app.php`, `config/routes.php`, `.gitignore`.
Новые: `app/Helpers/ClientIp.php`, `public/uploads/business/.htaccess`, `tools/run_security_m01_tests.php`, `config/app.local.php` (только локально, игнорируется git).

> Примечание: `config/routes.php` уже содержал незакоммиченные изменения задачи Try-On (как и `Router.php`, `ProfileController`, `Product`, `User`, views, `lang/*` — их M-01 не трогал). Правки M-01 добавлены поверх, ничего чужого не откатывалось. При коммите разделять M-01 и Try-On по файлам/хункам.
